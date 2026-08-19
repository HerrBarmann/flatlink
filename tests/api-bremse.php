<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Bremse gegen das Durchprobieren von Zugangsschlüsseln – festgeschrieben.
 *
 * Sie zählte einmal JEDE Anfrage statt nur der fehlgeschlagenen. Wirkung: Die
 * Schnittstelle war nach 60 Aufrufen je Stunde und Adresse dicht, gleich was
 * 'api_rate_limit' erlaubte – und die Fehlermeldung sprach von „zu vielen
 * fehlgeschlagenen Anmeldungen", während in Wahrheit lauter erfolgreiche
 * gezählt worden waren. Aufgefallen ist das erst unter Last, nicht im Alltag,
 * weil 60 Aufrufe je Stunde von Hand kaum jemand erreicht.
 *
 * Deshalb hier die zwei Sätze, auf die es ankommt:
 *   1. Rechtmäßige Aufrufe verbrauchen das Fehlversuchs-Kontingent NICHT.
 *   2. Falsche Schlüssel verbrauchen es sehr wohl.
 *
 * Läuft ohne Server; inc/config.php muss existieren, weil die Zähler im
 * Datenverzeichnis der Instanz liegen. Die Testdateien werden am Ende
 * wieder entfernt.
 *
 * Aufruf: php tests/api-bremse.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
if (!is_file(__DIR__ . '/../inc/config.php')) {
    exit("inc/config.php fehlt – bitte aus inc/config.example.php anlegen.\n");
}

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/token.php';

$fehler = 0;
$pruefe = function (string $was, bool $ok) use (&$fehler): void {
    printf("  %-58s %s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN');
    if (!$ok) $fehler++;
};

// api_source_ok() liest nur – mehrfaches Aufrufen darf nichts verbrauchen.
$vorher = api_source_ok();
for ($i = 0; $i < 200; $i++) api_source_ok();
$pruefe('200 rechtmäßige Prüfungen lassen die Quelle offen', $vorher && api_source_ok());

// bucket_rate_ok() zählt – nach dem Kontingent ist Schluss.
for ($i = 0; $i < 60; $i++) bucket_rate_ok('apiauth', 60);
$pruefe('60 Fehlversuche schöpfen das Kontingent aus', !api_source_ok());

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";

// Aufräumen: nur die Zähler, die dieser Lauf angelegt hat
@unlink(data_path('ratelimit') . '/apiauth-' . ip_hash() . '.json');
@unlink(data_path('ratelimit') . '/apiauth-' . ip_hash() . '.json.lock');
exit($fehler === 0 ? 0 : 1);
