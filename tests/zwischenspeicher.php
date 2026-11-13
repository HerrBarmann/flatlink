<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft, dass jede eigene CSS- und JS-Datei mit ihrem Zeitstempel eingebunden
 * wird (asset_v, 5.5.1).
 *
 * Der Anlass: Mit 5.5.0 kam neues Markup (das Menü links) und das CSS dazu.
 * custom.css trug schon immer ?v=<Zeitstempel>, style.css und app.js nicht –
 * und ohne Cache-Angaben des Servers heben Browser eine Datei etwa 10 % ihres
 * Alters auf. Wer die Seite kürzlich besucht hatte, sah das neue Markup mit
 * dem alten style.css: ein Menü aus Aufzählungspunkten quer über die Seite.
 *
 * Aufruf: php tests/zwischenspeicher.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}
$wurzel = dirname(__DIR__);

echo "asset_v()\n";
$v = filemtime($wurzel . '/assets/style.css');
pruefe('hängt den Zeitstempel der Datei an', asset_v('assets/style.css') === './assets/style.css?v=' . $v);
pruefe('mit Präfix aus admin/ heraus', asset_v('assets/app.js', '..') === '../assets/app.js?v=' . filemtime($wurzel . '/assets/app.js'));
pruefe('mit absoluter Basis, Schrägstrich am Ende egal',
    asset_v('/assets/style.css', 'https://example.org/') === 'https://example.org/assets/style.css?v=' . $v);
pruefe('fehlt die Datei, bleibt die Adresse ohne Anhang', asset_v('assets/gibt-es-nicht.js') === './assets/gibt-es-nicht.js');

echo "\nDie Seite\n";
$_SERVER['SCRIPT_NAME'] = '/index.php';
ob_start();
page_header('Prüfung');
page_script('assets/qroptions.js');
page_footer();
$html = (string)ob_get_clean();
foreach (['style.css', 'app.js', 'qroptions.js'] as $f) {
    pruefe("$f mit ?v=", (bool)preg_match('#assets/' . preg_quote($f, '#') . '\?v=\d+"#', $html));
}

// Der Wächter: Keine Seite bindet eine eigene Datei unter assets/ an asset_v()
// vorbei ein. Neue Seiten kopieren gern die Zeile von nebenan – die soll dann
// die richtige sein.
echo "\nKeine Einbindung ohne Zeitstempel\n";
$funde = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));
foreach ($it as $datei) {
    $pfad = substr((string)$datei, strlen($wurzel) + 1);
    if (!str_ends_with($pfad, '.php') || str_starts_with($pfad, 'tests/') || str_starts_with($pfad, 'data/')) continue;
    foreach (file((string)$datei) as $nr => $zeile) {
        if (preg_match('#<(script|link)\b[^>]*(src|href)="(\.\./)?assets/[^"?]+\.(js|css)"#', $zeile)) {
            $funde[] = $pfad . ':' . ($nr + 1);
        }
    }
}
pruefe('in keiner PHP-Datei ein <script>/<link> auf assets/*.js|css ohne Version',
    $funde === [], implode(', ', array_slice($funde, 0, 5)));

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
