<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Einreichungsfertige Pakete für die Erweiterungs-Läden bauen.
 *
 * Zwei Fassungen entstehen aus demselben Quelltext:
 *
 *   1. **generisch** – heißt „flatlink", weiß nichts über eine Instanz und
 *      fragt beim Einrichten nach Adresse und Schlüssel (oder nach einem
 *      Verbindungscode). Das ist die Fassung für die Läden, weil sie für
 *      jede Instanz taugt.
 *   2. **gebrandet** – trägt Namen, Adresse und Logo einer bestimmten
 *      Instanz. Die Adresse steht fest, also verlangt sie Zugriff auf genau
 *      diese eine statt auf „alle Seiten". Zu tun bleibt: Schlüssel
 *      eintragen oder Verbindungscode einlösen.
 *
 * Aufruf:
 *   php tools/store-build.php --out=/pfad/zum/ordner
 *   php tools/store-build.php --out=… --instanz=https://1337.kiwi \
 *       --name="1337.kiwi" --icon=/pfad/zu/icon-512.png
 *
 * Was NICHT hineingehört: ein Zugangsschlüssel. Ein Paket im Laden bekommen
 * alle – ein Schlüssel gehört einem. Den gibt es weiterhin nur über das
 * Profil der eigenen Instanz (siehe inc/extbuild.php).
 */
require_once __DIR__ . '/../inc/zip.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/i', $arg, $m) === 1) $opt[strtolower($m[1])] = $m[2];
}
$ziel = rtrim((string)($opt['out'] ?? sys_get_temp_dir() . '/flatlink-store'), '/');
$instanz = rtrim((string)($opt['instanz'] ?? ''), '/');
$name = (string)($opt['name'] ?? 'flatlink');
$iconQuelle = (string)($opt['icon'] ?? '');
// Akzentfarbe und Schrift darauf – sonst trägt der Hauptknopf das
// flatlink-Blau, auch wenn außen ein anderes Logo klebt.
$farbe = (string)($opt['farbe'] ?? '');
$farbeText = (string)($opt['farbetext'] ?? '#fff');
// Ohne Angabe gilt die Fassung aus dem Manifest – eine feste Vorgabe hier
// baut sonst irgendwann ein Paket mit veralteter Nummer.
$version = (string)($opt['version'] ?? '');
// Mozillas Pflichtangabe zur Datenerhebung. „none" heißt: Diese Erweiterung
// sammelt keine Daten – sie überträgt die Adresse, die der Nutzer kürzen
// will, an dessen eigene Instanz, so wie ein FTP-Programm Dateien überträgt.
// Wer das anders einschätzt, gibt hier die Kategorien an, die das
// AMO-Formular auflistet (kommagetrennt).
$daten = (string)($opt['daten'] ?? 'none');

$quelle = dirname(__DIR__) . '/extension';
if (!is_file($quelle . '/manifest.json')) {
    exit("extension/manifest.json nicht gefunden.\n");
}
if (!is_dir($ziel) && !@mkdir($ziel, 0700, true)) {
    exit("Zielordner lässt sich nicht anlegen: $ziel\n");
}

// ---- Manifest ------------------------------------------------------------

$manifest = json_decode((string)file_get_contents($quelle . '/manifest.json'), true);
if ($version === '') $version = (string)($manifest['version'] ?? '1.0.0');
$manifest['version'] = $version;

if ($instanz !== '') {
    $manifest['name'] = mb_substr($name, 0, 45);
    $manifest['description'] = mb_substr(
        "Die geöffnete Seite auf $name kürzen – ein Klick, fertig. Ohne fremden Dienst dazwischen.", 0, 132);
    $manifest['homepage_url'] = $instanz;
    // Feste Adresse heißt feste Berechtigung: Der Laden zeigt beim
    // Installieren „Zugriff auf 1337.kiwi" statt „auf alle Websites" – der
    // sichtbarste Unterschied zwischen den beiden Fassungen.
    unset($manifest['optional_host_permissions']);
    $manifest['host_permissions'] = [$instanz . '/*'];
    $manifest['browser_specific_settings']['gecko']['id'] =
        'flatlink-' . substr(hash('sha256', $instanz), 0, 12) . '@instanz';
} else {
    $manifest['name'] = 'flatlink';
    $manifest['description'] = mb_substr(
        'Kurzlinks auf deiner eigenen flatlink-Instanz anlegen – ein Klick in der Werkzeugleiste, ohne fremden Dienst.', 0, 132);
}

// Ohne dieses Feld weist addons.mozilla.org den Upload ab
// („The data_collection_permissions property is missing").
$manifest['browser_specific_settings']['gecko']['data_collection_permissions'] = [
    'required' => array_values(array_filter(array_map('trim', explode(',', $daten)))),
];

// ---- Symbole -------------------------------------------------------------

/** @return array<int,string> Kantenlänge => PNG */
function icons_bauen(string $quelle, string $ordner): array
{
    $out = [];
    if ($quelle !== '' && is_file($quelle) && extension_loaded('gd')) {
        $bild = @imagecreatefromstring((string)file_get_contents($quelle));
        if ($bild !== false) {
            $bw = imagesx($bild);
            $bh = imagesy($bild);
            foreach ([16, 32, 48, 128] as $n) {
                $z = imagecreatetruecolor($n, $n);
                imagealphablending($z, false);
                imagesavealpha($z, true);
                imagefill($z, 0, 0, imagecolorallocatealpha($z, 0, 0, 0, 127));
                imagecopyresampled($z, $bild, 0, 0, 0, 0, $n, $n, $bw, $bh);
                ob_start();
                imagepng($z, null, 9);
                $out[$n] = (string)ob_get_clean();
            }
            return $out;
        }
        fwrite(STDERR, "Warnung: $quelle ließ sich nicht lesen – nehme die mitgelieferten Symbole.\n");
    }
    foreach ([16, 32, 48, 128] as $n) {
        $p = $ordner . '/icons/' . $n . '.png';
        if (is_file($p)) $out[$n] = (string)file_get_contents($p);
    }
    return $out;
}

// ---- Paket schnüren ------------------------------------------------------

$zip = new ZipWriter();
$jetzt = time();

foreach (['popup.html', 'popup.css', 'popup.js', 'options.html', 'options.js'] as $datei) {
    $inhalt = (string)file_get_contents($quelle . '/' . $datei);
    if ($instanz !== '' && ($datei === 'popup.html' || $datei === 'options.html')) {
        // „deiner Instanz" ist die richtige Ansprache, solange die Fassung
        // keine kennt. Hier kennt sie eine – dann gehört ihr Name hin.
        $inhalt = str_replace(
            ['Adresse deiner Instanz', 'in deiner Instanz', 'In deiner Instanz', 'deiner Instanz'],
            ['Adresse', 'bei ' . $name, 'Bei ' . $name, $name],
            $inhalt
        );
        $inhalt = str_replace('<title>flatlink', '<title>' . $name, $inhalt);
        $inhalt = str_replace('<h1>flatlink</h1>', '<h1>' . $name . '</h1>', $inhalt);
    }
    if ($datei === 'popup.css' && $farbe !== '') {
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $farbe) !== 1) exit("--farbe erwartet einen Hex-Wert wie #7ABA1C\n");
        $inhalt = str_replace(
            ['--akzent: #3b6ea8;', '--akzentschrift: #fff;'],
            ['--akzent: ' . $farbe . ';', '--akzentschrift: ' . $farbeText . ';'],
            $inhalt
        );
        // Und der @supports-Block muss weg. Die neutrale Fassung holt sich
        // dort AccentColor, damit sie sich in den Browser einfügt – das ist
        // richtig, solange sie niemandem gehört. Bei einer gebrandeten
        // Fassung gewinnt sonst IMMER die Systemfarbe: AccentColor können
        // alle aktuellen Browser, der Rückfall darüber greift also nie.
        // Wer --farbe angibt, will sie sehen.
        $ohne = (string)preg_replace(
            '/@supports \(color: AccentColor\)\s*\{(?:[^{}]|\{[^{}]*\})*\}\s*/', '', $inhalt);
        // Der erklärende Kommentar darüber stimmt in dieser Fassung nicht mehr
        $ohne = str_replace(
            "    /* Rückfall zuerst: AccentColor gibt es erst in neueren Browsern, und ohne
"
            . "       Rückfall wäre der Hauptknopf dort durchsichtig – ein Knopf, den man
"
            . "       nicht als Knopf erkennt. Der Wert ist das Blau des neutralen
"
            . "       flatlink-Themes. */
",
            "    /* Die Farbe der Instanz, zu der diese Fassung gehört. Die neutrale
"
            . "       Fassung übernimmt hier die Akzentfarbe des Systems; wo ein Logo
"
            . "       klebt, gehört die Farbe dazu. */
",
            $ohne
        );
        if ($ohne === $inhalt || strpos($ohne, 'AccentColor') !== false) {
            exit("popup.css: Der @supports-Block ließ sich nicht entfernen – sonst\n"
               . "überschriebe die Systemfarbe die angegebene Markenfarbe.\n");
        }
        $inhalt = $ohne;
    }
    if ($instanz !== '' && ($datei === 'popup.js' || $datei === 'options.js')) {
        // Nur die Adresse, kein Schlüssel – siehe Kopf dieser Datei
        $vor = "// Fassung für $name. Die Adresse steht fest; der Zugangsschlüssel\n"
            . "// kommt aus deinem Profil dort (oder als Verbindungscode).\n"
            . "const VORGABE = { instanz: " . json_encode($instanz, JSON_UNESCAPED_SLASHES) . ", token: \"\" };\n\n";
        $inhalt = $vor . str_replace(
            ["const d = await chrome.storage.local.get(['instanz', 'token', 'pfad']);",
             "const d = await chrome.storage.local.get(['instanz', 'token', 'konto']);"],
            ["const d = await chrome.storage.local.get(['instanz', 'token', 'pfad']);\n    if (!d.instanz) d.instanz = VORGABE.instanz;",
             "const d = await chrome.storage.local.get(['instanz', 'token', 'konto']);\n    if (!d.instanz) d.instanz = VORGABE.instanz;"],
            $inhalt
        );
    }
    $zip->add($datei, $inhalt, $jetzt);
}

$zip->add('manifest.json', (string)json_encode(
    $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $jetzt);

foreach (icons_bauen($iconQuelle, $quelle) as $n => $png) {
    $zip->add('icons/' . $n . '.png', $png, $jetzt);
}

// Die Läden verlangen eine Lizenz im Paket nicht, aber sie gehört dazu:
// Der Quelltext ist AGPL, und das soll auch im Paket stehen.
$zip->add('LICENSE', (string)file_get_contents(dirname(__DIR__) . '/LICENSE'), $jetzt);

$dateiname = $ziel . '/' . ($instanz !== ''
    ? preg_replace('/[^a-z0-9]+/i', '-', strtolower($name))
    : 'flatlink') . '-' . $version . '.zip';
file_put_contents($dateiname, $zip->build());

printf("Paket gebaut: %s (%s KB)\n", $dateiname, number_format(filesize($dateiname) / 1024, 1));
printf("  Name:        %s\n", $manifest['name']);
printf("  Version:     %s\n", $manifest['version']);
printf("  Zugriff auf: %s\n", $instanz !== ''
    ? implode(', ', $manifest['host_permissions'])
    : 'nichts im Voraus (wird beim Einrichten erfragt)');
printf("  Symbole:     %s\n", $iconQuelle !== '' && is_file($iconQuelle) ? basename($iconQuelle) : 'mitgeliefert');
printf("  Akzent:      %s\n", $farbe !== '' ? $farbe . ' auf ' . $farbeText : 'Systemfarbe, sonst flatlink-Blau');
printf("  Datenangabe: %s\n", $daten);
