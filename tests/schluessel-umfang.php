<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Der Umfang eines Zugangsschlüssels – festgeschrieben, was er darf.
 *
 * Ein Schlüssel wandert weiter als ein Passwort: Er steckt im Kassensystem,
 * in einem Skript, in einem Verbindungscode, den jemand per Zwischenablage
 * weiterreicht. Lange konnte er alles, was das Konto konnte. Seit 3.5.3 trägt
 * er einen Umfang und kann *weniger*.
 *
 * Die Sätze, auf die es ankommt:
 *   1. Ohne Angabe gilt der volle Umfang — Schlüssel aus der Zeit davor haben
 *      kein Feld und dürfen dadurch nicht plötzlich ausgesperrt sein.
 *   2. „Nur lesen" heißt GET und HEAD, sonst nichts.
 *   3. „Anlegen und ändern" erlaubt alles außer DELETE.
 *   4. Die Herkunftsbindung ist unabhängig vom Umfang.
 *
 * Läuft ohne Server; es wird nichts geschrieben.
 *
 * Aufruf: php tests/schluessel-umfang.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
if (!is_file(__DIR__ . '/../inc/config.php')) {
    exit("inc/config.php fehlt – bitte aus inc/config.example.php anlegen.\n");
}

require_once __DIR__ . '/../inc/token.php';

$fehler = 0;
$pruefe = function (string $was, bool $ok) use (&$fehler): void {
    printf("  %-58s %s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN');
    if (!$ok) $fehler++;
};

$voll   = ['scope' => TOKEN_VOLL];
$schreib= ['scope' => TOKEN_SCHREIB];
$lesen  = ['scope' => TOKEN_LESEN];
$alt    = ['id' => 'abc'];              // aus der Zeit vor dem Feld
$krumm  = ['scope' => 'phantasie'];     // unbekannter Wert

// 1. Altbestand und Unfug fallen auf den vollen Umfang zurück
foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $m) {
    $pruefe("Schlüssel ohne Umfang darf $m", token_darf($alt, $m));
    $pruefe("unbekannter Umfang darf $m", token_darf($krumm, $m));
}

// 2. Voller Zugriff
foreach (['GET', 'HEAD', 'POST', 'PATCH', 'PUT', 'DELETE'] as $m) {
    $pruefe("voller Zugriff darf $m", token_darf($voll, $m));
}

// 3. Nur lesen
foreach (['GET', 'HEAD'] as $m) $pruefe("nur lesen darf $m", token_darf($lesen, $m));
foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $m) {
    $pruefe("nur lesen darf KEIN $m", !token_darf($lesen, $m));
}

// 4. Anlegen und ändern
foreach (['GET', 'HEAD', 'POST', 'PATCH', 'PUT'] as $m) {
    $pruefe("anlegen/ändern darf $m", token_darf($schreib, $m));
}
$pruefe('anlegen/ändern darf KEIN DELETE', !token_darf($schreib, 'DELETE'));

// 5. Kleinschreibung darf nichts durchlassen
$pruefe('auch "delete" klein geschrieben ist verboten', !token_darf($schreib, 'delete'));

// 6. Herkunftsbindung, unabhängig vom Umfang
$pruefe('ohne Feld: keine Bindung', !token_nur_eigene($voll));
$pruefe('own_only = true bindet', token_nur_eigene(['own_only' => true]));
$pruefe('own_only = null bindet nicht', !token_nur_eigene(['own_only' => null]));
$pruefe('Bindung gilt auch bei "nur lesen"',
    token_nur_eigene(['scope' => TOKEN_LESEN, 'own_only' => true]));

// 7. Die drei Stufen sind vollständig beschriftet
$u = token_umfaenge();
$pruefe('drei Stufen mit Beschriftung',
    count($u) === 3 && isset($u[TOKEN_VOLL], $u[TOKEN_SCHREIB], $u[TOKEN_LESEN]));

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
