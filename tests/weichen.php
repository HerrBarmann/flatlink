<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Sprachverhandlung der Weichen – gegen künftige Umbauten festgeschrieben.
 *
 * route_lang_gewinner() ist die dritte Fassung derselben Logik, und beide
 * Vorgänger waren auf je eigene Art falsch: Erst fing eine „en“-Weiche fast
 * jeden ab (Englisch steht bei den meisten Browsern als Zweitwunsch), dann
 * bekam ein chinesischer Browser mit Englisch als Zweitsprache die deutsche
 * Seite. Solche mehrfach umgebauten Stellen sind erfahrungsgemäß die, an denen
 * beim nächsten Umbau ein Randfall kippt – deshalb stehen die Fälle hier fest.
 *
 * Läuft ohne Server: Die Funktionen sind rein, der Header wird gestellt.
 *
 * Aufruf: php tests/weichen.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/routing.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' && !$ok ? " ($detail)" : '');
}

/**
 * Einen Fall in einem eigenen Prozess rechnen: route_sprachen() hält seinen
 * Zwischenspeicher statisch, im selben Prozess sähe jeder Fall die Liste des
 * ersten.
 */
function ziel(string $acceptLang, array $link): string
{
    $code = sprintf(
        'require %s; require %s; $_SERVER["HTTP_ACCEPT_LANGUAGE"] = %s; [$u, ] = route_target(%s); echo $u;',
        var_export(__DIR__ . '/../inc/store.php', true),
        var_export(__DIR__ . '/../inc/routing.php', true),
        var_export($acceptLang, true),
        var_export($link, true)
    );
    return trim((string)shell_exec('php -r ' . escapeshellarg($code)));
}

echo "Sprachverhandlung der Weichen\n=============================\n\n";

// Der Aufbau aus der Praxis: deutsches Hauptziel, englische Alternative
$mitZielsprache = [
    'url' => 'https://ziel.test/de',
    'lang' => 'de',
    'rules' => [['wenn' => 'lang', 'ist' => 'en', 'url' => 'https://ziel.test/en']],
];

echo "Mit Zielsprache (de), Weiche en:\n";
pruefe('deutscher Browser mit Englisch als Zweitsprache bleibt auf Deutsch',
    ziel('de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7', $mitZielsprache) === 'https://ziel.test/de');
pruefe('chinesischer Browser mit Englisch als Zweitsprache bekommt Englisch',
    ziel('zh-CN,zh;q=0.9,en;q=0.8', $mitZielsprache) === 'https://ziel.test/en');
pruefe('englischer Browser bekommt Englisch',
    ziel('en-GB,en;q=0.9', $mitZielsprache) === 'https://ziel.test/en');
pruefe('en-GB trifft die en-Weiche (Kürzung auf zwei Buchstaben)',
    ziel('en-GB', $mitZielsprache) === 'https://ziel.test/en');
pruefe('französischer Browser ohne Englisch bleibt auf dem Hauptziel',
    ziel('fr-FR,fr;q=0.9', $mitZielsprache) === 'https://ziel.test/de');
pruefe('nur Chinesisch bleibt auf dem Hauptziel',
    ziel('zh-CN,zh', $mitZielsprache) === 'https://ziel.test/de');
pruefe('leerer Header bleibt auf dem Hauptziel',
    ziel('', $mitZielsprache) === 'https://ziel.test/de');
pruefe('Gewichte schlagen die Reihenfolge (de;q=0.7,en;q=0.9 ist englisch)',
    ziel('de;q=0.7,en;q=0.9', $mitZielsprache) === 'https://ziel.test/en');

// Ohne Zielsprache gilt die strenge Regel: nur die bevorzugte Sprache leitet um
$ohneZielsprache = [
    'url' => 'https://ziel.test/de',
    'rules' => [['wenn' => 'lang', 'ist' => 'en', 'url' => 'https://ziel.test/en']],
];

echo "\nOhne Zielsprache (strenge Regel):\n";
pruefe('Zweitwunsch Englisch leitet NICHT um',
    ziel('de-DE,de;q=0.9,en;q=0.8', $ohneZielsprache) === 'https://ziel.test/de');
pruefe('bevorzugtes Englisch leitet um',
    ziel('en-US,en;q=0.9,de;q=0.8', $ohneZielsprache) === 'https://ziel.test/en');
pruefe('chinesischer Browser mit Englisch als Zweitsprache bleibt (kein Wissen, keine Umleitung)',
    ziel('zh-CN,zh;q=0.9,en;q=0.8', $ohneZielsprache) === 'https://ziel.test/de');

// Nicht-Sprach-Weichen bleiben von der Verhandlung unberührt
$gemischt = [
    'url' => 'https://ziel.test/de',
    'lang' => 'de',
    'rules' => [
        ['wenn' => 'lang', 'ist' => 'en', 'url' => 'https://ziel.test/en'],
        ['wenn' => 'split', 'ist' => '100', 'url' => 'https://ziel.test/split'],
    ],
];
echo "\nZusammenspiel:\n";
pruefe('Sprach-Weiche gewinnt vor einer späteren Split-Weiche',
    ziel('en-GB,en', $gemischt) === 'https://ziel.test/en');

echo "\n", $fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
