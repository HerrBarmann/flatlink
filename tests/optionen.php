<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Kommt jede Gestaltungsoption bei qr.php auch wirklich an?
 *
 * Der Anlass für diese Datei war ein Fehler, den ein anderer Test nicht finden
 * konnte: Vier neue Modulformen wurden im Renderer gebaut und im Designer
 * angeboten, aber die Prüfliste in qr.php kannte sie nicht – und qp() setzt
 * einen unbekannten Wert stillschweigend auf die Vorgabe zurück. Wer „Raute"
 * wählte, bekam ein Quadrat, ohne ein Wort dazu.
 *
 * Der bisherige Test fragte nur, ob sich das Ergebnis scannen lässt. Ein Code,
 * dessen Form unterwegs verworfen wurde, lässt sich ebenfalls scannen – die
 * Frage war also falsch gestellt.
 *
 * Diese Datei stellt sie richtig: Sie erzeugt dasselbe Bild zweimal, einmal
 * unmittelbar über den Renderer und einmal über die Adresse von qr.php, und
 * vergleicht sie Byte für Byte. Weicht etwas ab, ist die Option unterwegs
 * verlorengegangen.
 *
 * Aufruf (der eingebaute Server genügt):
 *   php -S localhost:8080 -t .
 *   php tests/optionen.php http://localhost:8080
 */
require_once __DIR__ . '/../inc/qrlib.php';

$basis = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$text = 'https://kurz.example/herbst';

// Vorgaben von qr.php – sonst vergleicht man Farben statt Formen
$grund = ['margin' => 4, 'size' => 400, 'fg' => '#16181D', 'bg' => '#ffffff'];

/** Option im Renderer => Name des Parameters in der Adresse */
$parameter = [
    'style' => 'style', 'eye' => 'eye', 'eyeCore' => 'eyecore', 'eyeFg' => 'eyefg',
    'eyeCoreFg' => 'eyecorefg', 'grad' => 'grad', 'gradTo' => 'fg2', 'gradAngle' => 'ga',
    'bg' => 'bg', 'fg' => 'fg', 'margin' => 'margin',
];

$faelle = [];
foreach (['square', 'rounded', 'smooth', 'dot', 'diamond', 'bars-v', 'bars-h'] as $v) {
    $faelle["Modulform $v"] = ['style' => $v];
}
foreach (['square', 'rounded', 'circle', 'leaf'] as $v) {
    $faelle["Augenform $v"] = ['eye' => $v];
    $faelle["Augenkern $v"] = ['eyeCore' => $v];
}
$faelle['Augenring gefärbt'] = ['eyeFg' => '#C0392B'];
$faelle['Augenkern gefärbt'] = ['eyeCoreFg' => '#F1C40F'];
$faelle['Verlauf linear'] = ['grad' => 'linear', 'gradTo' => '#7ABA1C', 'gradAngle' => 30];
$faelle['Verlauf radial'] = ['grad' => 'radial', 'gradTo' => '#7ABA1C'];
$faelle['ohne Hintergrund'] = ['bg' => 'none'];
$faelle['breiter Rand'] = ['margin' => 8];

$fehler = [];
foreach ($faelle as $name => $opt) {
    $soll = (new QrRenderer(QrCode::encode($text, QrCode::ECC_M), $opt + $grund))->png();

    $q = ['t' => 'url', 'u' => $text, 'format' => 'png'] + $grund + ['size' => 400];
    unset($q['margin']);
    $q['margin'] = $opt['margin'] ?? $grund['margin'];
    foreach ($opt as $k => $v) {
        if (isset($parameter[$k])) $q[$parameter[$k]] = $v;
    }
    $ist = @file_get_contents($basis . '/qr.php?' . http_build_query($q));
    if ($ist === false) {
        $fehler[] = "$name: qr.php nicht erreichbar";
        continue;
    }
    if (md5($soll) !== md5($ist)) $fehler[] = $name;
}

printf("%d von %d Optionen kommen unverändert bei qr.php an\n", count($faelle) - count($fehler), count($faelle));
foreach ($fehler as $f) echo "  ✗ $f\n";
exit($fehler === [] ? 0 : 1);
