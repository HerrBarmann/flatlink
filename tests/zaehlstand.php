<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft den Umzug des Zählstands in die Datenbank (5.1).
 *
 * Bis 5.0 lag der verdichtete Zählstand als JSON-Datei neben dem
 * Anhang-Protokoll, mit einer Sperrdatei daneben: drei Inoden je
 * angeklicktem Link. Auf Shared Hosting ist nicht der Plattenplatz die
 * Obergrenze, sondern das Inode-Kontingent – bei üblichen 250.000 waren das
 * rund 83.000 angeklickte Links.
 *
 * Seit 5.1 stehen Basis und Tageszähler in den Tabellen clickbase und
 * clickdays. Hier steht fest, was dabei gilt:
 *
 *   1. Der Zählstand hat dieselbe Gestalt wie vorher – daran hängen
 *      Statistikseite, Datenexport, Schnittstelle und Listen.
 *   2. Ein Altbestand aus der Zeit davor wird vollständig übernommen:
 *      Besuche, Bot-Aufrufe, Tage, Bio-Ziele und Merkmalstöpfe.
 *   3. Die Übernahme ist wiederholbar – sie schreibt mit MAX, nicht mit
 *      Plus. Nur deshalb darf die Datei danach gelöscht werden.
 *   4. Danach liegt keine Basisdatei und keine Sperrdatei mehr da.
 *
 * Aufruf: php tests/zaehlstand.php
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

const ZS_CODE = 'zs-probe';
const ZS_ALT  = 'zs-altbestand';

foreach ([ZS_CODE, ZS_ALT] as $c) link_delete($c);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Chrome/120';
$_SERVER['HTTP_REFERER'] = 'https://such.example/';

echo "Der Zählstand in der Datenbank\n";

// ---- 1. Gestalt und Zählen ------------------------------------------------
link_create('https://example.org/z', ZS_CODE, null, 'custom', []);
for ($i = 0; $i < 12; $i++) clicks_bump(ZS_CODE);
for ($i = 0; $i < 3; $i++)  clicks_bump(ZS_CODE, 2);    // Bio-Ziel 2
for ($i = 0; $i < 4; $i++)  clicks_roh_bump(ZS_CODE);   // Bots: nur aufs Limit
$c = clicks_get(ZS_CODE);

pruefe('Besuche und Bot-Aufrufe stehen getrennt',
    (int)$c['n'] === 12 && (int)$c['n_roh'] === 16,
    sprintf('n=%d n_roh=%d', (int)$c['n'], (int)$c['n_roh']));
pruefe('Der Tageszähler steht auf heute',
    (int)($c['days'][date('Y-m-d')] ?? 0) === 12);
pruefe('Das Bio-Ziel hat seinen eigenen Stand',
    (int)($c['items']['2']['n'] ?? 0) === 3
    && (int)($c['items']['2']['days'][date('Y-m-d')] ?? 0) === 3);
pruefe('Die Merkmalstöpfe kommen mit',
    (int)($c['refs']['such.example'] ?? 0) === 12);
pruefe('… und die Bio-Ziel-Klicks zählen NICHT auf den Link',
    (int)$c['n'] === 12);

// ---- 2. Was noch auf der Platte liegt -------------------------------------
$dateien = array_map('basename', glob(data_path('clicks') . '/' . rawurlencode(ZS_CODE) . '*') ?: []);
pruefe('Nur noch das Anhang-Protokoll, keine Basis, keine Sperrdatei',
    $dateien === [rawurlencode(ZS_CODE) . '.log'],
    implode(', ', $dateien) ?: 'gar nichts');

// ---- 3. Das Protokoll darf verschwinden -----------------------------------
//
// Die Kehrwoche gibt leere Protokolle frei; der nächste Klick legt die Datei
// neu an. clicks_append() merkt am nlink, wenn ihm die Datei unter dem Griff
// weggezogen wurde.
$log = clicks_log_file(ZS_CODE);
@unlink($log);
for ($i = 0; $i < 5; $i++) clicks_bump(ZS_CODE);
pruefe('Ein gelöschtes Protokoll kostet keinen Klick',
    (int)clicks_get(ZS_CODE)['n'] === 17,
    'n=' . (int)clicks_get(ZS_CODE)['n']);

link_delete(ZS_CODE);

// ---- 4. Übernahme eines Altbestands ---------------------------------------
echo "\nÜbernahme einer Basisdatei von vor 5.1\n";

link_create('https://example.org/alt', ZS_ALT, null, 'custom', []);
// Genau die Gestalt, die bis 5.0 auf der Platte lag
$alt = [
    'n' => 250,
    'n_roh' => 312,
    'last' => '2026-06-15',
    'days' => ['2026-06-13' => 100, '2026-06-14' => 60, '2026-06-15' => 90],
    'items' => [
        '0' => ['n' => 40, 'n_roh' => 40, 'last' => '2026-06-15', 'days' => ['2026-06-15' => 40]],
        '1' => ['n' => 7,  'n_roh' => 7,  'last' => '2026-06-14', 'days' => ['2026-06-14' => 7]],
    ],
    'refs' => ['alt.example' => 180, '-' => 70],
    'devs' => ['mobile' => 150, 'desktop' => 100],
    'routes' => ['1' => 25],
];
file_put_contents(clicks_file(ZS_ALT), json_encode($alt));
file_put_contents(clicks_file(ZS_ALT) . '.lock', '');

$n = clicks_get(ZS_ALT);
pruefe('Besuche und Bot-Aufrufe kommen an',
    (int)$n['n'] === 250 && (int)$n['n_roh'] === 312,
    sprintf('n=%d n_roh=%d', (int)$n['n'], (int)$n['n_roh']));
pruefe('Alle Tage kommen an',
    $n['days'] === ['2026-06-13' => 100, '2026-06-14' => 60, '2026-06-15' => 90],
    json_encode($n['days']));
pruefe('Die letzte Nutzung kommt an', ($n['last'] ?? '') === '2026-06-15');
pruefe('Beide Bio-Ziele kommen an',
    (int)($n['items']['0']['n'] ?? 0) === 40 && (int)($n['items']['1']['n'] ?? 0) === 7
    && (int)($n['items']['1']['days']['2026-06-14'] ?? 0) === 7);
pruefe('Die Merkmalstöpfe kommen an – als Summe, nicht Zähler für Zähler',
    (int)($n['refs']['alt.example'] ?? 0) === 180
    && (int)($n['devs']['mobile'] ?? 0) === 150
    && (int)($n['routes']['1'] ?? 0) === 25);
pruefe('Basisdatei und Sperrdatei sind weg',
    !is_file(clicks_file(ZS_ALT)) && !is_file(clicks_file(ZS_ALT) . '.lock'));

// ---- 5. Wiederholbarkeit --------------------------------------------------
//
// Der Grund, aus dem die Datei gelöscht werden DARF: Ein zweites Einlesen
// derselben Datei ändert nichts, weil mit MAX geschrieben wird und nicht mit
// Plus. Ein Absturz zwischen Schreiben und Löschen kostet damit nichts.
file_put_contents(clicks_file(ZS_ALT), json_encode($alt));
clicks_uebernahme(ZS_ALT);
$w = clicks_get(ZS_ALT);
pruefe('Ein zweites Einlesen verdoppelt nichts',
    (int)$w['n'] === 250 && (int)$w['days']['2026-06-13'] === 100
    && (int)($w['refs']['alt.example'] ?? 0) === 180,
    sprintf('n=%d tag=%d refs=%d', (int)$w['n'], (int)$w['days']['2026-06-13'],
        (int)($w['refs']['alt.example'] ?? 0)));

// Und frische Klicks addieren sich trotzdem auf den übernommenen Stand
clicks_bump(ZS_ALT);
pruefe('Frische Klicks addieren sich auf den Altbestand',
    (int)clicks_get(ZS_ALT)['n'] === 251);

link_delete(ZS_ALT);
pruefe('Nach dem Löschen ist in allen drei Tabellen nichts mehr übrig',
    (int)clicks_get(ZS_ALT)['n'] === 0 && clicks_dims_of(ZS_ALT) === []
    && (int)db()->query("SELECT COUNT(*) FROM clickdays WHERE schluessel = '" . ZS_ALT . "'")->fetchColumn() === 0);

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
