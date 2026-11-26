<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Wacht darüber, dass im Markup kein style-Attribut steht.
 *
 * Ein style-Attribut schlägt jede Regel: Eine Instanz mit eigenem
 * Stylesheet (assets/custom.css) kommt nicht dagegen an, und Gestaltungs-
 * systeme mit strenger CSP (style-src 'self') verbieten es ganz. Bis 5.6
 * standen 67 davon im Kern; seitdem gibt es dafür Klassen am Ende von
 * assets/style.css, und Skripte blenden mit hidden statt style.display.
 *
 * Werte, die sich aus Zahlen ergeben (Balkenbreiten), gehören in ein SVG.
 *
 * Aufruf: php tests/stilattribute.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

$wurzel = dirname(__DIR__);
$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}

// Alles, was die Weboberfläche ausliefert. Nicht: Tests, Werkzeuge für die
// Entwicklung, die Browser-Erweiterung (eigenes Paket), Übersetzungen.
$aus = ['tests/', 'tools/', 'extension/', 'inc/lang/', 'data/', '.git/'];
$dateien = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($wurzel) + 1);
    if (!str_ends_with($rel, '.php')) continue;
    foreach ($aus as $a) if (str_starts_with($rel, $a)) continue 2;
    if (in_array($rel, ['inc/config.php', 'inc/local.php'], true)) continue;
    $dateien[] = $rel;
}
sort($dateien);

echo "style-Attribute im Markup\n";
$funde = [];
foreach ($dateien as $rel) {
    foreach (file($wurzel . '/' . $rel) as $nr => $zeile) {
        if (preg_match('/\sstyle\s*=\s*["\']/i', $zeile)) $funde[] = $rel . ':' . ($nr + 1);
    }
}
pruefe(count($dateien) . ' PHP-Dateien ohne style-Attribut', $funde === [], implode(', ', array_slice($funde, 0, 8)));

echo "\nSkripte blenden mit hidden\n";
$js = [];
foreach (glob($wurzel . '/assets/*.js') ?: [] as $f) {
    if (preg_match('/\.style\.display\s*=/', (string)file_get_contents($f))) $js[] = basename($f);
}
pruefe('kein .style.display in assets/*.js', $js === [], implode(', ', $js));
$css = (string)file_get_contents($wurzel . '/assets/style.css');
pruefe('[hidden] gilt auch gegen eine Anzeige-Regel (.flash)',
    preg_match('/\[hidden\]\s*\{\s*display:\s*none\s*!important/', $css) === 1);

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
