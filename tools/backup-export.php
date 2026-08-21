<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Den Datenbestand in ein Verzeichnis schreiben – für Sicherungen, die nicht
 * als Archiv im Download-Ordner landen, sondern in rsync, borg oder ein
 * Git-Repository laufen.
 *
 * Der Knopf in der Verwaltung baut ein ZIP. Für ein Werkzeug, das *Versionen*
 * verwaltet, ist ein Archiv das falsche Format: Es ist ein Binärklumpen, und
 * jeder Lauf erzeugt einen komplett neuen. Ein Repository wächst damit um die
 * volle Größe je Sicherung, obwohl sich vielleicht drei Zeilen geändert haben.
 *
 * Deshalb hier: Die Datenbank kommt als **SQL-Text** heraus, nicht als Datei.
 * Textzeilen kann git gegeneinander rechnen – ein Tag Betrieb sind ein paar
 * Kilobyte statt ein paar Megabyte. Die JSON-Dateien werden eingerückt
 * geschrieben, damit ein Diff zeigt, *was* sich geändert hat, statt eine
 * einzige sehr lange Zeile.
 *
 * Gleicher Datenstand ergibt gleiche Bytes: keine Zeitstempel im Inhalt, feste
 * Reihenfolge, sortierte Schlüssel. Sonst meldete jeder Lauf eine Änderung,
 * und die Historie bestünde aus Rauschen.
 *
 * Aufruf:
 *   php tools/backup-export.php /pfad/zum/ziel
 *   php tools/backup-export.php /pfad/zum/ziel --mit-config
 *
 * ACHTUNG: Der Export enthält Passwort-Hashes, das Instanz-Geheimnis, gültige
 * Sitzungs- und Reset-Token und die E-Mail-Adressen aller Konten. Das Ziel
 * gehört entsprechend geschützt – bei einem Repository heißt das: privat, und
 * mit einem Personenkreis, der diese Daten ohnehin sehen dürfte.
 *
 * `inc/config.php` bleibt ohne `--mit-config` draußen: Sie enthält
 * Zugangsdaten zu anderen Systemen (Mail-Relay, LDAP-Dienstkonto), ändert sich
 * selten und wird üblicherweise von Hand gesichert.
 */
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/backup.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$ziel = rtrim((string)($argv[1] ?? ''), '/');
$mitConfig = in_array('--mit-config', $argv, true);

if ($ziel === '' || str_starts_with($ziel, '-')) {
    fwrite(STDERR, "Aufruf: php tools/backup-export.php /pfad/zum/ziel [--mit-config]\n");
    exit(2);
}
if (!is_dir($ziel) && !@mkdir($ziel, 0700, true)) {
    fwrite(STDERR, "Zielverzeichnis lässt sich nicht anlegen: $ziel\n");
    exit(1);
}
if (!is_writable($ziel)) {
    fwrite(STDERR, "Zielverzeichnis ist nicht beschreibbar: $ziel\n");
    exit(1);
}

/** Schreiben, aber nur wenn sich der Inhalt geändert hat – schont die mtime. */
function schreibe(string $pfad, string $inhalt): int
{
    if (is_file($pfad) && file_get_contents($pfad) === $inhalt) return strlen($inhalt);
    @mkdir(dirname($pfad), 0700, true);
    file_put_contents($pfad, $inhalt, LOCK_EX);
    @chmod($pfad, 0600);
    return strlen($inhalt);
}

/** Einen SQLite-Wert als SQL-Literal ausdrücken. */
function sql_wert(mixed $v): string
{
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    $s = (string)$v;
    // Was sich nicht als UTF-8 lesen lässt, ist ein Blob und gehört hexadezimal
    // in den Dump – sonst zerbricht die Datei am ersten Bild.
    if (!mb_check_encoding($s, 'UTF-8')) return "X'" . bin2hex($s) . "'";
    return "'" . str_replace("'", "''", $s) . "'";
}

/**
 * Die Datenbank als SQL-Text.
 *
 * Gearbeitet wird auf einer über VACUUM INTO gezogenen Kopie, nicht auf der
 * laufenden Datei: Diese kann mitten in einer Transaktion stehen, und ihre
 * WAL-Datei enthält dann Teile, die in der Hauptdatei noch fehlen.
 */
function db_dump(): string
{
    $tmp = data_path() . '/.export-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        db()->prepare('VACUUM INTO ?')->execute([$tmp]);
        $pdo = new PDO('sqlite:' . $tmp, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        ]);

        $out = "-- flatlink · Datenbank als SQL\n"
             . "-- Zurückspielen: sqlite3 links.db < datenbank.sql\n"
             . "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n";

        $tabellen = $pdo->query(
            "SELECT name, sql FROM sqlite_master WHERE type='table'
             AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll();

        foreach ($tabellen as [$name, $ddl]) {
            $out .= "\n" . rtrim((string)$ddl, ";\n") . ";\n";
            // rowid gibt die stabile Reihenfolge; ohne sie (WITHOUT ROWID)
            // sortiert die erste Spalte, damit der Diff nicht springt.
            $spalten = $pdo->query('PRAGMA table_info("' . $name . '")')->fetchAll();
            $hatRowid = true;
            try {
                $pdo->query('SELECT rowid FROM "' . $name . '" LIMIT 1');
            } catch (PDOException) {
                $hatRowid = false;
            }
            $order = $hatRowid ? 'rowid' : '"' . (string)($spalten[0][1] ?? '1') . '"';
            $zeilen = $pdo->query('SELECT * FROM "' . $name . '" ORDER BY ' . $order);
            foreach ($zeilen as $zeile) {
                $out .= 'INSERT INTO "' . $name . '" VALUES('
                      . implode(',', array_map('sql_wert', $zeile)) . ");\n";
            }
        }

        // Indizes, Sichten und Trigger nach den Daten – schneller beim
        // Zurückspielen, und die Reihenfolge ist damit auch festgelegt.
        $rest = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type IN ('index','view','trigger')
             AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll();
        if ($rest !== []) $out .= "\n";
        foreach ($rest as [$ddl]) $out .= rtrim((string)$ddl, ";\n") . ";\n";

        return $out . "COMMIT;\n";
    } finally {
        if (isset($pdo)) $pdo = null;
        if (is_file($tmp)) @unlink($tmp);
    }
}

// ---- Schreiben ------------------------------------------------------------
$bytes = 0;
$posten = [];

$bytes += $n = schreibe($ziel . '/datenbank.sql', db_dump());
$posten['datenbank.sql'] = $n;

// Die kleinen Zustandsdateien. JSON wird eingerückt und nach Schlüsseln
// sortiert abgelegt: Der Inhalt ist derselbe, der Diff aber lesbar.
foreach (backup_dateien() as $name) {
    $pfad = data_path() . '/' . $name;
    if (!is_file($pfad)) continue;
    $inhalt = (string)file_get_contents($pfad);
    if (str_ends_with($name, '.json')) {
        $daten = json_decode($inhalt, true);
        if (is_array($daten)) {
            ksort($daten);
            $inhalt = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                                        | JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    $bytes += $n = schreibe($ziel . '/' . $name, $inhalt);
    $posten[$name] = $n;
}

// Klickzähler, Logos, Meldungen, offene Bestätigungen
foreach (['clicks', 'logos', 'reports'] as $ordner) {
    $quelle = data_path() . '/' . $ordner;
    if (!is_dir($quelle)) continue;
    // Zählstände aus den alten Ablagen einlesen, bevor exportiert wird:
    // gesichert werden Summen, kein Anhang-Protokoll (Review 4.2.0, F1).
    // Seit 5.2 räumt das die Dateien gleich mit ab – was hier durchläuft,
    // braucht nach dem Export niemand mehr.
    //
    // Der Zwilling dieser Schleife steht in inc/backup.php. Beim Wegfall des
    // Protokolls (5.2) wurde nur der nachgezogen, und dieser hier rief
    // weiter clicks_fold() – eine Funktion, die es nicht mehr gab. Das
    // Werkzeug starb mit einem Fatal Error, nachdem es datenbank.sql und
    // secret.key schon geschrieben hatte: ein halber Sicherungsstand, und
    // das ausgerechnet direkt nach einem Umbau der Datenhaltung.
    if ($ordner === 'clicks') {
        foreach (glob($quelle . '/*.json') ?: [] as $bf) {
            clicks_altbasis(rawurldecode(basename($bf, '.json')));
        }
        foreach (glob($quelle . '/*.log') ?: [] as $lf) {
            clicks_altlog(rawurldecode(basename($lf, '.log')));
        }
        clearstatcache();
    }
    $dateien = array_filter(glob($quelle . '/*') ?: [],
        fn($d) => is_file($d) && !str_ends_with($d, '.lock') && !(str_ends_with($d, '.log') && filesize($d) === 0));
    sort($dateien);
    $summe = 0;
    foreach ($dateien as $datei) {
        $summe += schreibe($ziel . '/' . $ordner . '/' . basename($datei),
                           (string)file_get_contents($datei));
    }
    // Was am Ziel liegt, aber in der Quelle nicht mehr: gelöschte Logos und
    // erledigte Meldungen sollen auch in der Sicherung verschwinden.
    foreach (glob($ziel . '/' . $ordner . '/*') ?: [] as $alt) {
        if (!is_file($quelle . '/' . basename($alt))) @unlink($alt);
    }
    if ($dateien !== []) {
        $bytes += $summe;
        $posten[$ordner . '/ (' . count($dateien) . ')'] = $summe;
    }
}

if ($mitConfig) {
    $cfg = dirname(__DIR__) . '/inc/config.php';
    if (is_file($cfg)) {
        $bytes += $n = schreibe($ziel . '/config.php.txt', (string)file_get_contents($cfg));
        $posten['config.php.txt'] = $n;
    }
}

// Ein Zettel, wie sich das zurückspielen lässt – eine Sicherung, die niemand
// wiederherstellen kann, ist keine.
$dbName = basename(db_file());
schreibe($ziel . '/WIEDERHERSTELLEN.md', <<<TEXT
# Diesen Stand zurückspielen

1. flatlink in der passenden Fassung aufspielen und `inc/config.php` anlegen
   (Adresse, Mail, Anmeldung – sie ist hier nur enthalten, wenn der Export mit
   `--mit-config` lief, dann als `config.php.txt`).

2. Datenverzeichnis füllen: alles aus diesem Ordner **außer** `datenbank.sql`,
   `config.php.txt` und dieser Datei nach `data/` kopieren.

3. Datenbank aufbauen:

   ```
   sqlite3 data/$dbName < datenbank.sql
   ```

4. Rechte setzen: Das Datenverzeichnis gehört dem Webserver-Benutzer und ist
   für andere gesperrt (`chown -R www-data: data && chmod 700 data`).

`secret.key` ist Teil der Sicherung und muss mitkommen: Ohne die Datei stimmen
die Prüfsummen der IP-Hashes nicht mehr, und alle Sitzungen sind ungültig.
TEXT);

printf("Export nach %s\n", $ziel);
foreach ($posten as $name => $n) printf("  %-28s %9s\n", $name, number_format($n, 0, ',', '.') . ' B');
printf("  %-28s %9s\n", 'zusammen', number_format($bytes, 0, ',', '.') . ' B');
