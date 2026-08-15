<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Das SQLite-Backend – die Vorgabe für Links und Konten.
 *
 * SQLite ist keine Infrastruktur: eine Datei unter data/, die Erweiterung
 * pdo_sqlite bringt praktisch jedes PHP mit, es gibt keinen Server, nichts
 * zu warten. Das Backup bleibt das Kopieren des Ordners. Wer die reine
 * Datei-Ablage will, stellt 'storage' => 'json' – sie bleibt vollwertig,
 * trägt aber ab einigen zehntausend Konten oder Listen über sehr große
 * Bestände schlechter (gemessen in docs/release-v2.3.0.md).
 *
 * WAS in die Datenbank wandert – und was nicht: Links und Konten, denn deren
 * Vollscans waren die gemessene Grenze. Klickzähler bleiben Einzeldateien
 * (der Weiterleitungspfad schreibt sie bei jedem Scan – gerade dort soll
 * kein gemeinsames Schreib-Lock entstehen), ebenso Einstellungen, Gruppen,
 * Logos, Rate-Limits und offene Bestätigungen: alles klein, nichts davon
 * wächst mit dem Bestand.
 *
 * WIE gespeichert wird: Der vollständige Datensatz liegt als JSON in der
 * Spalte `data` – dieselbe Wahrheit wie in der Datei-Ablage, nur anders
 * abgelegt. Die übrigen Spalten (owner, grp, type, created, email, role)
 * sind daraus abgeleitete Kopien für WHERE und ORDER BY; geschrieben werden
 * sie ausschließlich zusammen mit `data`, nie einzeln. Ein Wechsel zurück
 * zur Datei-Ablage ist damit jederzeit ein simples Auslesen.
 */

/**
 * Die Verbindung dieser Anfrage – oder null, wenn die Instanz auf der
 * Datei-Ablage läuft. Alle Backend-Weichen im Projekt hängen an diesem null.
 */
function db(): ?PDO
{
    static $pdo = null;
    static $aus = false;
    if ($aus) return null;
    if ($pdo !== null) return $pdo;

    if ((string)cfg('storage') !== 'sqlite') {
        $aus = true;
        return null;
    }

    $datei = db_file();

    // Die Datenbank gilt erst, wenn es sie gibt. Eine Bestandsinstanz, die
    // mit dem neuen Standard aktualisiert wird, läuft so unverändert auf
    // ihren Dateien weiter, bis die Migration gelaufen ist – unter
    // *Einstellungen → Ablage* oder per `php migrate-sqlite.php`. Ohne diese
    // Weiche gälte nach dem Update sofort eine leere Datenbank: keine Links,
    // keine Konten, keine Möglichkeit mehr, sich für die Migration überhaupt
    // anzumelden. Nur eine frische Instanz (keine Bestandsdaten) legt die
    // Datei unmittelbar an.
    if (!is_file($datei) && db_migration_offen()) {
        $aus = true;
        return null;
    }

    $pdo = new PDO('sqlite:' . $datei, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: Leser blockieren keine Schreiber und umgekehrt – das Gegenstück
    // zum Sperren einzelner Ablagen. busy_timeout wartet kurze Konflikte ab,
    // statt sie als Fehler an den Besucher durchzureichen.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    db_schema($pdo);
    return $pdo;
}

/** Das Schema – bei jedem Start geprüft, CREATE IF NOT EXISTS ist billig */
function db_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS links (
        code    TEXT PRIMARY KEY,
        owner   TEXT,
        grp     TEXT,
        type    TEXT NOT NULL DEFAULT \'random\',
        created TEXT,
        data    TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS links_owner ON links(owner)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS links_grp ON links(grp)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS links_created ON links(created)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        name  TEXT PRIMARY KEY,
        email TEXT,
        role  TEXT,
        data  TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS users_email ON users(email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS users_role ON users(role)');
}

// ---- Links ----------------------------------------------------------------

/** Einen Link-Datensatz mitsamt der abgeleiteten Spalten schreiben */
function db_link_put(PDO $pdo, string $code, array $l): void
{
    $st = $pdo->prepare('REPLACE INTO links (code, owner, grp, type, created, data)
        VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([
        $code,
        isset($l['owner']) && is_string($l['owner']) && $l['owner'] !== '' ? $l['owner'] : null,
        isset($l['group']) && is_string($l['group']) && $l['group'] !== '' ? $l['group'] : null,
        (string)($l['type'] ?? 'random'),
        (string)($l['created'] ?? ''),
        json_encode($l, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

/** @return array<string,array> code => Datensatz */
function db_links_rows(PDOStatement $st): array
{
    $out = [];
    while (($zeile = $st->fetch()) !== false) {
        $d = json_decode((string)$zeile['data'], true);
        if (is_array($d)) $out[(string)$zeile['code']] = $d;
    }
    return $out;
}

// ---- Konten ---------------------------------------------------------------

function db_user_put(PDO $pdo, string $name, array $u): void
{
    $st = $pdo->prepare('REPLACE INTO users (name, email, role, data) VALUES (?, ?, ?, ?)');
    $st->execute([
        $name,
        isset($u['email']) && is_string($u['email']) && $u['email'] !== '' ? strtolower($u['email']) : null,
        (string)($u['role'] ?? 'user'),
        json_encode($u, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function db_user_get(PDO $pdo, string $name): ?array
{
    $st = $pdo->prepare('SELECT data FROM users WHERE name = ?');
    $st->execute([$name]);
    $zeile = $st->fetch();
    if ($zeile === false) return null;
    $d = json_decode((string)$zeile['data'], true);
    return is_array($d) ? $d : null;
}

/** @return array<string,array> name => Datensatz */
function db_users_all(PDO $pdo): array
{
    $out = [];
    $st = $pdo->query('SELECT name, data FROM users');
    while (($zeile = $st->fetch()) !== false) {
        $d = json_decode((string)$zeile['data'], true);
        if (is_array($d)) $out[(string)$zeile['name']] = $d;
    }
    return $out;
}

/** Pfad der Datenbank-Datei (leer konfiguriert = data/flatlink.sqlite) */
function db_file(): string
{
    $datei = (string)cfg('sqlite_file');
    return $datei !== '' ? $datei : data_path() . '/flatlink.sqlite';
}

/**
 * Wartet diese Instanz noch auf die Migration? Ja, wenn SQLite eingestellt
 * ist, die Datenbank fehlt und Bestandsdaten in Dateien liegen.
 *
 * Bewusst ohne data_path(): das legt fehlende Verzeichnisse an und würde
 * die Prüfung verfälschen – dasselbe Muster wie in links_sharded().
 */
function db_migration_offen(): bool
{
    if ((string)cfg('storage') !== 'sqlite') return false;
    if (is_file(db_file())) return false;
    $dir = (string)cfg('data_dir');
    $base = $dir !== '' ? rtrim($dir, '/') : dirname(__DIR__) . '/data';
    return is_file($base . '/users.json') || is_file($base . '/links.json')
        || is_file($base . '/links/.aufgeteilt');
}

/**
 * Die Migration selbst: Konten und Links aus den Dateien in die Datenbank.
 *
 * Von zwei Stellen gerufen – dem Knopf unter *Einstellungen → Ablage* und
 * `migrate-sqlite.php` auf der Kommandozeile. Idempotent: Ein zweiter Lauf
 * überschreibt anhand von Kennung bzw. Code und löscht nichts. Die
 * JSON-Dateien bleiben als Sicherung liegen.
 *
 * Liest die Dateien unmittelbar, nicht über die Ablage-Funktionen – die
 * könnten bereits auf die (noch leere) Datenbank zeigen.
 *
 * @return array{konten:int,links:int}
 */
function sqlite_migrate(): array
{
    $pdo = new PDO('sqlite:' . db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    db_schema($pdo);

    $konten = json_read(data_path() . '/users.json');
    $pdo->exec('BEGIN');
    foreach ($konten as $name => $u) {
        db_user_put($pdo, (string)$name, $u);
    }
    $pdo->exec('COMMIT');

    $dateien = glob(data_path('links') . '/[0-9a-f][0-9a-f].json') ?: [];
    if ($dateien === [] && is_file(data_path() . '/links.json')) {
        $dateien = [data_path() . '/links.json'];
    }
    $n = 0;
    foreach ($dateien as $f) {
        $pdo->exec('BEGIN');
        foreach (json_read($f) as $code => $l) {
            db_link_put($pdo, (string)$code, $l);
            $n++;
        }
        $pdo->exec('COMMIT');
    }
    return ['konten' => count($konten), 'links' => $n];
}

/**
 * Geänderte Konten zurückschreiben – als Unterschied zwischen vorher und
 * nachher, nicht als Komplett-Neuschrieb: Ein Vorgang, der ein Konto
 * anfasst, soll nicht hunderttausend unveränderte Zeilen schreiben.
 */
function db_users_diff(PDO $pdo, array $vorher, array $nachher): void
{
    foreach ($vorher as $name => $u) {
        if (!isset($nachher[$name])) {
            $st = $pdo->prepare('DELETE FROM users WHERE name = ?');
            $st->execute([(string)$name]);
        }
    }
    foreach ($nachher as $name => $u) {
        if (!isset($vorher[$name]) || $vorher[$name] !== $u) {
            db_user_put($pdo, (string)$name, $u);
        }
    }
}
