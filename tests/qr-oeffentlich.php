<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft die optionale öffentliche Verfügbarkeit der QR-Werkzeuge (5.3).
 *
 * Zwei Dinge lassen sich jetzt getrennt von der Link-Erstellung freigeben:
 *
 *   1. die statischen QR-Generatoren (WLAN, Kontakt, Termin, GS1, Designer
 *      ohne Kürzen) über die Grundregel qr_public = auto | on | off;
 *   2. einzelne Logos über ein public-Kennzeichen, sodass sie auch ohne
 *      Anmeldung in den Generatoren zur Wahl stehen.
 *
 * Der Anlass: eine Instanz, die Kurzlinks den Konten vorbehält (public_mode
 * = off), aber allen im Haus QR-Codes anbieten will – mit dem Hauslogo.
 *
 * Aufruf: php tests/qr-oeffentlich.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/qrpanel.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}

// settings() zur Laufzeit umstellbar machen, ohne an die Datenbank zu gehen:
// qr_static_offen() liest settings(), das seinen Stand aus der DB nimmt.
function setze(string $mode, string $qr): void
{
    $s = settings_stored();
    $s['public_mode'] = $mode;
    $s['qr_public'] = $qr;
    settings_save($s);
}
$vorher = settings_stored();

echo "Die Zugangsmatrix von qr_static_offen()\n";

// mode × qr_public → offen für Gäste?
$faelle = [
    ['on',  'auto', true],   ['off', 'auto', false],
    ['on',  'off',  false],  ['off', 'on',   true],
    ['prefix', 'auto', true],['off', 'off',  false],
];
foreach ($faelle as [$mode, $qr, $soll]) {
    setze($mode, $qr);
    // frischer Prozess-Zustand: settings() cacht statisch, deshalb je Fall
    // über einen Unterprozess prüfen
    $ist = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'chdir(' . var_export(__DIR__ . '/..', true) . ');'
        . 'require "inc/store.php"; require "inc/qrpanel.php";'
        . 'echo qr_static_offen() ? "1" : "0";'))) === '1';
    pruefe(sprintf('public_mode=%-6s qr_public=%-4s → %s', $mode, $qr, $soll ? 'offen' : 'zu'),
        $ist === $soll, $ist ? 'offen' : 'zu');
}

echo "\nÖffentliche Logos in der Auswahl\n";

// Ein Logo direkt in die Metadaten schreiben (ohne echte Datei – wir prüfen
// nur die Auswahl-Logik, nicht das Rendern).
$id = 'aaaaaaaaaaaaaaaa.png';
logo_meta_set($id, 'Testlogo', 'chef');

// Gast ohne öffentliche Freigabe: sieht nichts
$gastLeer = qr_logo_choices(null);
pruefe('Ohne Freigabe sieht ein Gast kein Logo', !isset($gastLeer[$id]));

// Öffentlich stellen
logo_public_set($id, true);
$gastVoll = qr_logo_choices(null);
pruefe('Öffentlich gestellt, sieht der Gast es', isset($gastVoll[$id]),
    implode(', ', array_keys($gastVoll)));

// logo_visible_for: öffentlich sticht auch ohne Gruppe
pruefe('Ein Fremdkonto sieht das öffentliche Logo',
    logo_visible_for(logos_meta()[$id], 'irgendwer', 'user', []));

// Zurücknehmen
logo_public_set($id, false);
pruefe('Zurückgenommen, wieder unsichtbar für Gäste',
    !isset(qr_logo_choices(null)[$id])
    && !logo_visible_for(logos_meta()[$id], 'irgendwer', 'user', []));

// Aufräumen
logo_meta_delete($id);
settings_save($vorher);
pruefe('Grundregeln wiederhergestellt',
    (settings_stored()['public_mode'] ?? '') === ($vorher['public_mode'] ?? ''));

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
