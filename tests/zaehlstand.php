<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft den Zählstand in der Datenbank (5.2).
 *
 * Bis 5.0 belegte ein angeklickter Link drei Dateien: die verdichtete Basis,
 * deren Sperrdatei und das Anhang-Protokoll. Auf Shared Hosting ist nicht der
 * Plattenplatz die Obergrenze, sondern das Inode-Kontingent – bei üblichen
 * 250.000 waren das rund 83.000 angeklickte Links.
 *
 * Seit 5.2 kostet ein angeklickter Link gar keine Inode mehr. Hier steht
 * fest, was dabei gilt:
 *
 *   1. Der Zählstand hat dieselbe Gestalt wie vorher – daran hängen
 *      Statistikseite, Datenexport, Schnittstelle und Listen.
 *   2. Zählen legt keine Datei an.
 *   3. Beide früheren Ablagen werden vollständig übernommen: die Basisdatei
 *      (bis 5.0) samt Bio-Zielen und Merkmalstöpfen, und das Anhang-Protokoll
 *      (bis 5.1) samt seiner Zeilenformate.
 *   4. Die Übernahme der Basis ist wiederholbar – sie schreibt mit MAX, nicht
 *      mit Plus. Nur deshalb darf die Datei danach gelöscht werden.
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
//
// Seit 5.2 gar nichts. Das ist der eigentliche Punkt der ganzen Übung: Auf
// Shared Hosting ist das Inode-Kontingent die Obergrenze, und ein
// angeklickter Link kostet jetzt keine einzige Inode mehr.
$dateien = array_map('basename', glob(data_path('clicks') . '/' . rawurlencode(ZS_CODE) . '*') ?: []);
pruefe('Ein angeklickter Link belegt keine einzige Datei',
    $dateien === [], implode(', ', $dateien) ?: 'gar nichts');

link_delete(ZS_CODE);

// ---- 3. Übernahme einer Basisdatei von vor 5.1 ----------------------------
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

// ---- 4. Wiederholbarkeit --------------------------------------------------
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

// ---- 5. Übernahme eines Anhang-Protokolls von vor 5.2 ---------------------
//
// Das Protokoll ist ein ZUWACHS, kein Stand: Es wird mit Plus eingelesen und
// danach gelöscht, damit es nicht ein zweites Mal zählt. Geprüft wird
// zusätzlich, dass die Reihenfolge stimmt – erst die Basis (MAX), dann das
// Protokoll (Plus). Andersherum verschluckte das MAX den Zuwachs.
echo "\nÜbernahme eines Anhang-Protokolls von vor 5.2\n";
$heute = date('Y-m-d');
// Eigenständig: Der Abschnitt davor hat den Stand schon bewegt, und Zahlen,
// die aus einer Vorgeschichte folgen, prüfen sich schlecht.
link_delete(ZS_ALT);
link_create('https://example.org/alt', ZS_ALT, null, 'custom', []);
file_put_contents(clicks_file(ZS_ALT), json_encode($alt));
file_put_contents(clicks_log_file(ZS_ALT),
    str_repeat(json_encode(['d' => $heute]) . "\n", 9)
    . json_encode(['d' => $heute, 'i' => '0']) . "\n"
    . json_encode(['roh' => 1]) . "\n"
    . json_encode(['d' => $heute, 'h' => ['refs' => 'tupel.example'], 'w' => 3]) . "\n");
$m = clicks_get(ZS_ALT);
pruefe('Basis UND Protokoll kommen zusammen an',
    (int)$m['n'] === 250 + 9 + 1 && (int)$m['days'][$heute] === 10,
    sprintf('n=%d heute=%d', (int)$m['n'], (int)($m['days'][$heute] ?? 0)));
pruefe('Der Bot-Aufruf zählt nur aufs Limit',
    (int)$m['n_roh'] === 312 + 9 + 1 + 1,
    'n_roh=' . (int)$m['n_roh']);
pruefe('Das Bio-Ziel aus dem Protokoll kommt an',
    (int)($m['items']['0']['n'] ?? 0) === 41);
pruefe('Die Tupelzeile bringt ihr Merkmal und ihre Weiche mit',
    (int)($m['refs']['tupel.example'] ?? 0) === 1 && (int)($m['routes']['3'] ?? 0) === 1);
pruefe('Beide Dateien sind danach weg',
    !is_file(clicks_file(ZS_ALT)) && !is_file(clicks_log_file(ZS_ALT)));
pruefe('Ein zweites Lesen zählt das Protokoll nicht erneut',
    (int)clicks_get(ZS_ALT)['n'] === 260);

link_delete(ZS_ALT);
pruefe('Nach dem Löschen ist in allen drei Tabellen nichts mehr übrig',
    (int)clicks_get(ZS_ALT)['n'] === 0 && clicks_dims_of(ZS_ALT) === []
    && (int)db()->query("SELECT COUNT(*) FROM clickdays WHERE schluessel = '" . ZS_ALT . "'")->fetchColumn() === 0);

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
