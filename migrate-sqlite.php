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

echo "Ziel: " . db_file() . "\n";
$z = sqlite_migrate();
printf("Konten:    %s übernommen\n", number_format($z['konten'], 0, ',', '.'));
printf("Kurzlinks: %s übernommen\n", number_format($z['links'], 0, ',', '.'));
echo "\nSteht 'storage' auf 'sqlite' (Vorgabe), gilt die Datenbank ab sofort.\n";
echo "Die JSON-Dateien bleiben als Sicherung liegen.\n";
