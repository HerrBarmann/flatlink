<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Rendert die öffentlichen Seiten auf Englisch und schlägt an, wenn
 * deutscher Text durchschlägt.
 *
 * Der Anlass (Review 5.2.3, F1): Die vier QR-Generatoren waren seitenweise
 * fest auf Deutsch verdrahtet. Solange auch `lang="de"` fest stand, war das
 * nur unübersetzt – seit die Sprachauszeichnung stimmt, stand auf einer
 * englischen Instanz `lang="en"` ÜBER deutschem Text: die deklarierte
 * Sprache war die falsche (WCAG 3.1.1), ausgerechnet auf den öffentlichen
 * Werbeseiten, und das englische README verspricht wörtlich „interface in
 * German or English".
 *
 * Der Maßstab: Jede Seite wird mit lang('en') gerendert, und kein deutscher
 * Wörterbuchschlüssel, dessen Übersetzung sich vom Original unterscheidet,
 * darf wörtlich in der Ausgabe stehen. Das fängt genau die Klasse „ein
 * Pfad wurde beim Übersetzen ausgelassen" – dieselbe, die schon den
 * Sicherungs-Zwilling (5.2.0) und die Bio-Seite (5.2.2) traf.
 *
 * Grenze des Verfahrens, damit niemand mehr hineinliest: Ein NEUER harter
 * String, der noch nie im Wörterbuch stand, wird nicht gefunden. Dafür ist
 * der Test frei von Fehlalarmen und braucht keine Pflege-Allowlist.
 *
 * Aufruf: php tests/uebersetzung.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/lang.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}

// Die deutschen Schlüssel, deren Auftauchen in einer englischen Seite einen
// ausgelassenen Pfad verrät. Kurze (< 4 Zeichen) und unveränderte
// Übersetzungen („Website", „Status") tragen nichts bei und fallen raus.
$wb = require __DIR__ . '/../inc/lang/en.php';
// Ein Marker taugt nur, wenn er in ECHTEM Englisch nicht vorkommen kann.
// „Module" ist deutsch – und steht zugleich in der englischen Übersetzung
// „Module shape". Solche Wörter melden korrekte Seiten als falsch. Statt
// einer Pflegeliste: Jeder Kandidat, der als ganzes Wort in irgendeiner
// englischen Übersetzung des Wörterbuchs auftaucht, fällt raus.
$enGesamt = implode("\n", array_map('strval', array_values($wb)));
$marker = [];
foreach ($wb as $de => $en) {
    if (!is_string($de) || !is_string($en) || $de === $en) continue;
    if (mb_strlen($de) < 4 || str_contains($de, '%') || str_contains($de, "\n")) continue;
    if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($de, '/') . '(?![\p{L}\p{N}])/u', $enGesamt) === 1) continue;
    $marker[] = $de;
}
printf("Öffentliche Seiten auf Englisch, geprüft gegen %d Wörterbuch-Marker\n\n", count($marker));

$seiten = ['index.php', 'qr-designer.php', 'wlan-qr.php', 'vcard-qr.php',
           'termin-qr.php', 'gs1-qr.php', 'report.php'];

foreach ($seiten as $seite) {
    // Jede Seite in einem EIGENEN Prozess: Sie bauen Sitzung und Kopfzeilen
    // auf, und zwei Seiten im selben Prozess kämen sich damit in die Quere.
    $lauf = 'chdir(' . var_export(dirname(__DIR__), true) . ');'
        . '$_SERVER["HTTP_HOST"] = "test.example";'
        . '$_SERVER["REQUEST_URI"] = "/' . $seite . '";'
        . '$_SERVER["SCRIPT_NAME"] = "/' . $seite . '";'
        . '$_SERVER["REQUEST_METHOD"] = "GET";'
        . 'require "inc/lang.php"; lang("en");'
        . 'ob_start(); include "' . $seite . '"; echo "";';
    $html = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($lauf) . ' 2>/dev/null');

    // Nur der sichtbare Text zählt. Skript- und Stilblöcke fliegen SAMT
    // Inhalt raus – im lang-js-Block stehen die deutschen Schlüssel als
    // JSON und wären lauter Fehlalarme; strip_tags() allein ließe den
    // Inhalt stehen.
    $sichtbar = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html);
    $sichtbar = html_entity_decode(strip_tags((string)$sichtbar), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $treffer = [];
    foreach ($marker as $de) {
        // Als ganzes Wort bzw. ganze Wendung, nicht als Teilstring – sonst
        // meldete „Ende" jedes „Kalender".
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($de, '/') . '(?![\p{L}\p{N}])/u', $sichtbar) === 1) {
            $treffer[] = $de;
        }
        if (count($treffer) >= 3) break;
    }
    pruefe("$seite ist auf Englisch frei von deutschem Text",
        $treffer === [] && strlen($html) > 1000,
        $treffer !== [] ? '„' . implode('“, „', $treffer) . '“' : strlen($html) . ' B');
}

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
