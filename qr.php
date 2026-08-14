<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * QR-Code-Endpoint.
 *   Kurzlink:  /qr.php?c=<code>&format=svg|png|pdf&style=...&eye=...&fg=...&bg=...
 *              &ecc=L|M|Q|H&size=512&margin=4&logo=<id>&ls=22&ftext=Scan+mich&download=1
 *   WLAN:      /qr.php?t=wifi mit ssid, pw, enc=WPA|WEP|nopass, hidden=1 (auch per POST,
 *              damit Passwörter nicht in URL-/Server-Logs landen; nichts wird gespeichert)
 */
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/groups.php';

/**
 * Eingaben ausdrücklich aus GET und POST – nicht aus $_REQUEST.
 * Dessen Inhalt hängt von der Einstellung request_order ab und kann je nach
 * Konfiguration auch Cookies enthalten; die haben hier nichts zu suchen.
 * POST wird gebraucht, damit WLAN-Passwörter nicht in Adresszeilen landen.
 */
function qin(string $key): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? null;
}

function qp(string $key, string $default, string $pattern): string
{
    $v = qin($key) ?? '';
    return is_string($v) && preg_match($pattern, $v) ? $v : $default;
}

$type = qp('t', 'link', '/^(link|url|wifi|vcard|event|gs1)$/');

if ($type !== 'link') {
    // Statische Codes: Payload direkt aus Formularfeldern, nichts wird gespeichert.
    $in = fn(string $k, int $max): string => mb_strimwidth(
        trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)(qin($k) ?? ''))), 0, $max, '');
    // Escaping für vCard/iCal (Backslash, Semikolon, Komma, Zeilenumbruch)
    $vesc = fn(string $s): string => strtr($s, ['\\' => '\\\\', ';' => '\\;', ',' => '\\,', "\n" => '\\n']);

    if ($type === 'url') {
        // Eine Adresse oder ein beliebiger Text, unmittelbar im Code.
        //
        // Der Gegenentwurf zum Kurzlink: Nichts wird gespeichert, nichts läuft
        // über diesen Dienst, der Code funktioniert auch dann noch, wenn es uns
        // nicht mehr gibt. Der Preis ist, dass das Ziel feststeht – wer es
        // später ändern will, braucht einen Kurzlink.
        //
        // Steuerzeichen fliegen raus, sonst bleibt der Text unangetastet: Ein
        // mailto:, ein tel: oder eine Zeile Text sind ebenso gültige Inhalte
        // wie eine Webadresse.
        $payload = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
            '', (string)(qin('u') ?? '')));
        if ($payload === '') {
            http_response_code(400);
            exit('Bitte eine Adresse oder einen Text angeben.');
        }
        // Fehlendes Schema ergänzen, aber nur wenn es nach einer Domain aussieht
        if (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(/|$|\?)#i', $payload) === 1) {
            $payload = 'https://' . $payload;
        }
        $filename = 'code';
        if (preg_match('~^https?://([^/?\#]+)~i', $payload, $m) === 1) {
            $filename = preg_replace('/[^a-z0-9.-]/i', '', $m[1]) ?: 'code';
        }
    } elseif ($type === 'wifi') {
        $ssid = (string)(qin('ssid') ?? '');
        $pw = (string)(qin('pw') ?? '');
        $enc = qp('enc', 'WPA', '/^(WPA|WEP|nopass)$/');
        $hidden = (qin('hidden') ?? '') === '1';
        if ($ssid === '' || strlen($ssid) > 32 || strlen($pw) > 63) {
            http_response_code(400);
            exit('Ungültige WLAN-Angaben.');
        }
        // Sonderzeichen gemäß WIFI-Schema escapen (hier zusätzlich Doppelpunkt/Anführungszeichen)
        $wesc = fn(string $s): string => strtr($s, ['\\' => '\\\\', ';' => '\\;', ',' => '\\,', ':' => '\\:', '"' => '\\"']);
        $payload = 'WIFI:T:' . $enc . ';S:' . $wesc($ssid) . ';'
            . ($enc !== 'nopass' && $pw !== '' ? 'P:' . $wesc($pw) . ';' : '')
            . ($hidden ? 'H:true;' : '') . ';';
        $filename = 'wlan';
    } elseif ($type === 'vcard') {
        $vn = $in('vorname', 48);
        $nn = $in('nachname', 48);
        $firma = $in('firma', 48);
        $tel = $in('tel', 32);
        $mailAdr = $in('email', 64);
        $web = $in('url', 96);
        if ($vn === '' && $nn === '') {
            http_response_code(400);
            exit('Bitte mindestens einen Namen angeben.');
        }
        $payload = "BEGIN:VCARD\r\nVERSION:3.0\r\n"
            . 'N:' . $vesc($nn) . ';' . $vesc($vn) . ";;;\r\n"
            . 'FN:' . $vesc(trim($vn . ' ' . $nn)) . "\r\n"
            . ($firma !== '' ? 'ORG:' . $vesc($firma) . "\r\n" : '')
            . ($tel !== '' ? 'TEL:' . $vesc($tel) . "\r\n" : '')
            . ($mailAdr !== '' ? 'EMAIL:' . $vesc($mailAdr) . "\r\n" : '')
            . ($web !== '' ? 'URL:' . $vesc($web) . "\r\n" : '')
            . 'END:VCARD';
        $filename = 'kontakt';
    } elseif ($type === 'event') {
        $titel = $in('titel', 64);
        $ort = $in('ort', 64);
        $dt = function (string $k): ?string {
            $v = (string)(qin($k) ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v) !== 1) return null;
            return str_replace(['-', ':'], '', $v) . '00'; // 2026-09-01T18:00 → 20260901T180000
        };
        $start = $dt('start');
        $ende = $dt('ende');
        if ($titel === '' || $start === null) {
            http_response_code(400);
            exit('Bitte Titel und Startzeit angeben.');
        }
        $payload = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
            . 'SUMMARY:' . $vesc($titel) . "\r\n"
            . ($ort !== '' ? 'LOCATION:' . $vesc($ort) . "\r\n" : '')
            . 'DTSTART:' . $start . "\r\n"
            . ($ende !== null ? 'DTEND:' . $ende . "\r\n" : '')
            . "END:VEVENT\r\nEND:VCALENDAR";
        $filename = 'termin';
    } else { // gs1
        require_once __DIR__ . '/inc/gs1.php';
        [$gErr, $payload] = gs1_digital_link(
            (string)(qin('gtin') ?? ''),
            [
                '10' => $in('lot', 20),
                '21' => $in('serial', 20),
                '22' => $in('cpv', 20),
                '17' => $in('mhd', 10),
            ],
            $in('resolver', 200)
        );
        if ($gErr !== null) {
            http_response_code(400);
            exit($gErr);
        }
        $filename = 'gs1-' . preg_replace('/[^0-9]/', '', (string)(qin('gtin') ?? ''));
    }
    $owner = null; // statischer Code, kein Konto-Bezug
} else {
    $code = $_GET['c'] ?? '';
    $link = is_string($code) && lookup_code_ok($code) ? link_get($code) : null;
    if ($link === null) {
        http_response_code(404);
        exit('Unbekannter Kurzlink.');
    }
    $filename = str_replace('/', '-', $code);
    $payload = short_url($code, (string)($link['domain'] ?? ''));
    $owner = $link['owner'] ?? null;
}

$format = qp('format', 'svg', '/^(svg|png|pdf|eps)$/');
$style  = qp('style', 'square', '/^(square|rounded|dot)$/');
$eye    = qp('eye', 'square', '/^(square|rounded|circle|leaf)$/');
$eyeCore = qp('eyecore', '', '/^(square|rounded|circle|leaf)$/');
$eyeFg  = qp('eyefg', '', '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/');
$eyeCoreFg = qp('eyecorefg', '', '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/');
$fg     = qp('fg', '#16181D', '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/');
// 'none' ist erlaubt und heißt: kein Hintergrund. Im PNG wird die Fläche
// durchsichtig, im SVG bleibt sie leer, in PDF und EPS scheint das Papier durch.
$bg     = qp('bg', '#ffffff', '/^(#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?|none)$/');

// Druckfarben in CMYK, je vier Zahlen 0–100. Sie ersetzen die Bildschirmfarbe
// nicht, sondern treten neben sie: In EPS und PDF steht der CMYK-Wert genau so,
// wie er hier ankommt; SVG, PNG und die Vorschau zeigen eine Umrechnung, die
// ohne Farbprofil nur eine Näherung sein kann. Verbindlich ist die Druckdatei.
require_once __DIR__ . '/inc/vector.php';
$cmykIn = function (string $key): ?array {
    $v = qin($key);
    if (!is_string($v) || preg_match('/^\d{1,3}(,\d{1,3}){3}$/', $v) !== 1) return null;
    $t = array_map(fn($x) => min(100, (int)$x) / 100, explode(',', $v));
    return $t;
};
$fgCmyk = $cmykIn('fgc');
$bgCmyk = $cmykIn('bgc');
if ($fgCmyk !== null) $fg = VecColor::cmykToHex($fgCmyk);
if ($bgCmyk !== null) $bg = VecColor::cmykToHex($bgCmyk);
// Breite auf dem Papier – nur für die Vektorformate von Belang
$druckMm = max(10.0, min(1000.0, (float)(qin('mm') ?? 80)));

// Farbverlauf über die Module. Mit CMYK verträgt er sich nicht: Ein Verlauf im
// Vierfarbdruck ist eine Entscheidung für sich (Rasterung, Farbauftrag), und
// ein stillschweigend umgerechneter Verlauf wäre keine gute Antwort darauf.
// Deshalb gewinnt hier die Druckfarbe, und die Oberfläche sagt es auch.
$grad = qp('grad', '', '/^(linear|radial)$/');
$gradTo = qp('fg2', '#3B6EA8', '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/');
$gradAngle = max(0, min(359, (int)(qin('ga') ?? 45)));
if ($fgCmyk !== null) $grad = '';
$ecc    = qp('ecc', 'M', '/^[LMQH]$/');
$size   = max(64, min(2048, (int)(qin('size') ?? 512)));
$margin = max(0, min(10, (int)(qin('margin') ?? 4)));
$ls     = max(10, min(35, (int)(qin('ls') ?? 22))) / 100;
// PDF ist für den Druck: immer in hoher Auflösung rastern
if ($format === 'pdf') $size = max($size, 2048);

// Rahmen-Text (leer = kein Rahmen): Steuerzeichen raus, max. 24 Zeichen
$ftext = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)(qin('ftext') ?? '')));
$ftext = $ftext === '' ? null : mb_strimwidth($ftext, 0, 24, '');

// Absender-Zeile (mit Rahmen im Band, ohne Rahmen als dezente Zeile unter dem
// Code). Sie entfällt, wenn das Konto des Link-Besitzers das Recht
// 'qr_unbranded' hat – entscheidend ist also der Besitzer, nicht der Aufrufer.
// Bewusst nicht per URL-Parameter steuerbar.
$brandText = (string)cfg('qr_brand_text');
$unbranded = $owner !== null && user_can((string)$owner, 'qr_unbranded');
$brand = ($brandText === '' || $unbranded) ? null : $brandText;

// Optionales Bildzeichen neben der Absenderzeile
$glyphSvg = (string)cfg('qr_brand_glyph_svg');
$glyphPng = (string)cfg('qr_brand_glyph_png');
$glyphSvg = $glyphSvg !== '' ? __DIR__ . '/assets/' . basename($glyphSvg) : null;
$glyphPng = $glyphPng !== '' ? __DIR__ . '/assets/' . basename($glyphPng) : null;

$logo = null;
$logoId = qp('logo', '', '/^[a-f0-9]{16}\.(png|jpe?g|webp|svg)$/');
$logoPad = max(0.0, min(0.5, (float)(qin('lpad') ?? 0.12)));
$logoShape = qp('lshape', 'rounded', '/^(rounded|square|circle|none)$/');
if ($logoId !== '') {
    $file = data_path('logos') . '/' . $logoId;
    if (is_file($file)) $logo = $file;
}
// Mit Logo braucht es hohe Fehlerkorrektur
if ($logo !== null) $ecc = 'H';

$eccLevel = ['L' => QrCode::ECC_L, 'M' => QrCode::ECC_M, 'Q' => QrCode::ECC_Q, 'H' => QrCode::ECC_H][$ecc];

// Byte-Kapazität unserer Versionen 1–10 je Fehlerkorrektur-Level
// Grenze der Norm bei Version 40, je nach Fehlerkorrektur-Stufe
if (strlen($payload) > QrCode::maxBytes($eccLevel)) {
    http_response_code(400);
    exit('Zu viele Zeichen für einen QR-Code: ' . strlen($payload) . ' Byte, möglich sind '
        . QrCode::maxBytes($eccLevel) . ' bei dieser Fehlerkorrektur.');
}

$qr = QrCode::encode($payload, $eccLevel);
$renderer = new QrRenderer($qr, [
    'style' => $style, 'eye' => $eye, 'fg' => $fg, 'bg' => $bg,
    'fgColor' => $fgCmyk !== null ? VecColor::fromCmyk($fgCmyk) : null,
    'bgColor' => $bgCmyk !== null ? VecColor::fromCmyk($bgCmyk) : null,
    'grad' => $grad === '' ? null : $grad, 'gradTo' => $gradTo, 'gradAngle' => $gradAngle,
    'logoPad' => $logoPad, 'logoShape' => $logoShape,
    'eyeCore' => $eyeCore, 'eyeFg' => $eyeFg, 'eyeCoreFg' => $eyeCoreFg,
    'size' => $size, 'margin' => $margin, 'logo' => $logo, 'logoScale' => $ls,
    'frameText' => $ftext, 'brandText' => $brand,
    'brandGlyphSvg' => $glyphSvg, 'brandGlyphPng' => $glyphPng,
]);

// Der Designer fragt dieselbe Gestaltung zusätzlich als Prüfung ab und zeigt
// die Hinweise neben der Vorschau. Bewusst hier und nicht im Browser: Die
// Schwellen gehören zu den Regeln des Dienstes, nicht in ein Skript, das jeder
// abschalten kann – und beim Serien-Download gibt es gar kein Skript.
if ((qin('check') ?? '') !== '') {
    require_once __DIR__ . '/inc/qrcheck.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'module' => $qr->size + 2 * $margin,
        'version' => $qr->version,
        'hinweise' => qr_readability([
            'fg' => $fg, 'bg' => $bg, 'margin' => $margin,
            'grad' => $grad === '' ? null : $grad, 'gradTo' => $gradTo,
            'eyeFg' => $eyeFg, 'eyeCoreFg' => $eyeCoreFg,
            'logo' => $logo, 'logoScale' => $ls, 'logoPad' => $logoPad, 'ecc' => $ecc,
            'sizePx' => $format === 'png' ? $size : 0,
            'printMm' => in_array($format, ['pdf', 'eps'], true) ? $druckMm : 0,
        ], $qr->size + 2 * $margin),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Bilder brauchen keine Skripte, keine Formulare, keine Einbettung. Ein SVG
// wird bei 'inline' als eigenes Dokument gerendert – die strenge Richtlinie
// fängt dort einen künftigen Fehler auf, bevor er wirken kann.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
nosniff_header();

$disposition = qin('download') !== null ? 'attachment' : 'inline';
header('Cache-Control: public, max-age=300');
if ($format === 'png') {
    header('Content-Type: image/png');
    header("Content-Disposition: $disposition; filename=\"qr-$filename.png\"");
    echo $renderer->png();
} elseif ($format === 'pdf') {
    // Echte Vektoren statt eines eingebetteten Bildes: Das PDF lässt sich
    // beliebig groß ziehen, bleibt winzig und trägt bei Bedarf CMYK.
    header('Content-Type: application/pdf');
    header("Content-Disposition: $disposition; filename=\"qr-$filename.pdf\"");
    echo vec_pdf($renderer->vectorOps(), $druckMm);
} elseif ($format === 'eps') {
    header('Content-Type: application/postscript');
    header("Content-Disposition: $disposition; filename=\"qr-$filename.eps\"");
    echo vec_eps($renderer->vectorOps(), $druckMm);
} else {
    header('Content-Type: image/svg+xml');
    header("Content-Disposition: $disposition; filename=\"qr-$filename.svg\"");
    echo $renderer->svg();
}
