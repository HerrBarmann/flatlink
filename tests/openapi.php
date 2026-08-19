<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die maschinenlesbare Schnittstellen-Beschreibung gegen den Code halten.
 *
 * `docs/openapi.yaml` wird von Hand gepflegt — das passt zu einem Projekt
 * ohne Build-Schritt, hat aber den bekannten Preis: Zwei Stellen, die
 * dasselbe beschreiben, laufen auseinander. In diesem Projekt ist genau das
 * schon zweimal passiert, und beide Male fiel es erst auf, als etwas kaputt
 * war.
 *
 * Dieser Test nimmt das Nachschauen ab. Er prüft drei Dinge:
 *
 *   1. Jeder Endpunkt, den `api.php` bedient, steht in der Beschreibung.
 *   2. Jeder Fehlercode, den `api.php` senden kann, ist dort aufgezählt.
 *   3. Die genannte Fassung passt zur neuesten Marke im Repository.
 *
 * Bewusst mit Mustersuche statt einem YAML-Leser: PHP bringt keinen mit, und
 * eine Abhängigkeit dafür wäre der falsche Preis. Für die drei Fragen oben
 * reicht es.
 *
 * Läuft ohne Server und ohne inc/config.php.
 *
 * Aufruf: php tests/openapi.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$spec = (string)file_get_contents(__DIR__ . '/../docs/openapi.yaml');
$code = (string)file_get_contents(__DIR__ . '/../api.php');

$fehler = 0;
$pruefe = function (string $was, bool $ok, string $detail = '') use (&$fehler): void {
    printf("  %-52s %s%s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN', $detail !== '' ? "  $detail" : '');
    if (!$ok) $fehler++;
};

// ---- 1. Endpunkte --------------------------------------------------------

preg_match_all('/^  (\/[^:\s]*):$/m', $spec, $m);
$beschrieben = $m[1];
$pruefe('die Beschreibung nennt Endpunkte', count($beschrieben) >= 7,
    count($beschrieben) . ' Pfade');

// Was api.php tatsächlich bedient – aus den Verzweigungen abgeleitet.
$erwartet = ['/health', '/me', '/links', '/links/{code}', '/links/{code}/stats',
             '/tags', '/tags/{tag}'];
foreach ($erwartet as $p) {
    $pruefe("Endpunkt $p ist beschrieben", in_array($p, $beschrieben, true));
}
foreach ($beschrieben as $p) {
    $pruefe("beschriebener Endpunkt $p existiert auch", in_array($p, $erwartet, true));
}

// ---- 2. Fehlercodes ------------------------------------------------------

preg_match_all("/api_fail\(\s*\d+\s*,\s*'([a-z_]+)'/", $code, $m2);
$gesendet = array_values(array_unique($m2[1]));
sort($gesendet);
$pruefe('api.php sendet Fehlercodes', count($gesendet) >= 10, count($gesendet) . ' verschiedene');

// Die Aufzählung im Schema „Fehler"
preg_match('/enum: \[([^\]]+)\]/s', substr($spec, strpos($spec, 'Fehler:') ?: 0), $m3);
$aufgezaehlt = array_map(fn($x) => trim($x), explode(',', str_replace(["\n", ' '], ['', ' '], $m3[1] ?? '')));
$aufgezaehlt = array_values(array_filter(array_map('trim', $aufgezaehlt)));

foreach ($gesendet as $c) {
    $pruefe("Fehlercode $c ist aufgezählt", in_array($c, $aufgezaehlt, true));
}
foreach ($aufgezaehlt as $c) {
    $pruefe("aufgezählter Code $c wird auch gesendet", in_array($c, $gesendet, true));
}

// ---- 3. Fassung ----------------------------------------------------------

preg_match('/^  version: (\S+)$/m', $spec, $m4);
$genannt = $m4[1] ?? '';
$tag = trim((string)@shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' describe --tags --abbrev=0 2>/dev/null'));
$tag = ltrim($tag, 'v');
$pruefe('eine Fassung ist genannt', $genannt !== '', $genannt);
if ($tag !== '') {
    // Voraus sein darf sie: Zwischen „Änderung fertig" und „Marke gesetzt"
    // liegt immer ein Moment, und in dem soll der Test nicht rot sein.
    // Hinterherhinken darf sie nicht – dann beschreibt sie einen Stand, den
    // niemand mehr bekommt.
    $pruefe("Fassung $genannt hinkt der Marke $tag nicht hinterher",
        version_compare($genannt, $tag, '>='));
}

echo $fehler === 0
    ? "\nBeschreibung und Code stimmen überein.\n"
    : "\n$fehler Abweichung(en) – bitte docs/openapi.yaml nachziehen.\n";
exit($fehler === 0 ? 0 : 1);
