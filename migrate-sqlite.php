<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Umzug der Links und Konten von der Datei-Ablage nach SQLite.
 *
 *     php migrate-sqlite.php
 *
 * Liest users.json und die Link-Ablagen und schreibt beides in die
 * SQLite-Datei (Vorgabe: data/flatlink.sqlite, änderbar über 'sqlite_file').
 * Danach in inc/config.php den Schalter umlegen:
 *
 *     'storage' => 'sqlite',
 *
 * Die Reihenfolge ist Absicht – erst füllen, dann umschalten: Zwischen den
 * beiden Schritten läuft die Instanz unverändert auf den Dateien weiter.
 * Was in dieser Lücke noch geschrieben wird, holt ein zweiter Lauf des
 * Skripts nach (es überschreibt Bestehendes anhand des Codes bzw. der
 * Kennung, löscht aber nichts).
 *
 * Die JSON-Dateien bleiben unangetastet liegen – als Sicherung und für den
 * Weg zurück. Klickzähler, Einstellungen, Gruppen, Logos und offene
 * Bestätigungen bleiben ohnehin Dateien; sie ziehen nicht um.
 *
 * Nur von der Kommandozeile: Über das Netz aufgerufen bricht das Skript ab –
 * ein Umzug gehört in eine Shell, nicht hinter eine URL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur von der Kommandozeile: php migrate-sqlite.php\n");
}

require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/auth.php';

// Absichtlich NICHT über db(): Die Instanz darf noch auf 'json' stehen –
// genau dann läuft dieser Umzug ja. Verbindung und Schema von Hand.
$datei = (string)cfg('sqlite_file');
if ($datei === '') $datei = data_path() . '/flatlink.sqlite';

$pdo = new PDO('sqlite:' . $datei, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA journal_mode = WAL');
db_schema($pdo);

echo "Ziel: $datei\n";

// Konten – gelesen über die Datei-Ablage, egal was 'storage' sagt
$konten = json_read(users_file());
$pdo->exec('BEGIN');
foreach ($konten as $name => $u) {
    db_user_put($pdo, (string)$name, $u);
}
$pdo->exec('COMMIT');
printf("Konten:    %s übernommen\n", number_format(count($konten), 0, ',', '.'));

// Links – Ablage für Ablage, damit der Speicher flach bleibt
$n = 0;
foreach (link_store_files() as $f) {
    $pdo->exec('BEGIN');
    foreach (json_read($f) as $code => $l) {
        db_link_put($pdo, (string)$code, $l);
        $n++;
    }
    $pdo->exec('COMMIT');
}
printf("Kurzlinks: %s übernommen\n", number_format($n, 0, ',', '.'));

echo "\nJetzt in inc/config.php umstellen:  'storage' => 'sqlite',\n";
echo "Die JSON-Dateien bleiben als Sicherung liegen.\n";
