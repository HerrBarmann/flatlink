<?php
declare(strict_types=1);

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

$type = qp('t', 'link', '/^(link|wifi|vcard|event)$/');

if ($type !== 'link') {
    // Statische Codes: Payload direkt aus Formularfeldern, nichts wird gespeichert.
    $in = fn(string $k, int $max): string => mb_strimwidth(
        trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)(qin($k) ?? ''))), 0, $max, '');
    // Escaping für vCard/iCal (Backslash, Semikolon, Komma, Zeilenumbruch)
    $vesc = fn(string $s): string => strtr($s, ['\\' => '\\\\', ';' => '\\;', ',' => '\\,', "\n" => '\\n']);

    if ($type === 'wifi') {
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
    } else { // event
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
    $payload = short_url($code);
    $owner = $link['owner'] ?? null;
}

$format = qp('format', 'svg', '/^(svg|png|pdf)$/');
$style  = qp('style', 'square', '/^(square|rounded|dot)$/');
$eye    = qp('eye', 'square', '/^(square|rounded|circle)$/');
$fg     = qp('fg', '#16181D', '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/');
$bg     = qp('bg', '#ffffff', '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/');
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
if ($logoId !== '') {
    $file = data_path('logos') . '/' . $logoId;
    if (is_file($file)) $logo = $file;
}
// Mit Logo braucht es hohe Fehlerkorrektur
if ($logo !== null) $ecc = 'H';

$eccLevel = ['L' => QrCode::ECC_L, 'M' => QrCode::ECC_M, 'Q' => QrCode::ECC_Q, 'H' => QrCode::ECC_H][$ecc];

// Byte-Kapazität unserer Versionen 1–10 je Fehlerkorrektur-Level
$byteCap = ['L' => 271, 'M' => 213, 'Q' => 151, 'H' => 119];
if (strlen($payload) > $byteCap[$ecc]) {
    http_response_code(400);
    exit('Zu viele Zeichen für einen QR-Code – bitte Angaben kürzen.');
}

$qr = QrCode::encode($payload, $eccLevel);
$renderer = new QrRenderer($qr, [
    'style' => $style, 'eye' => $eye, 'fg' => $fg, 'bg' => $bg,
    'size' => $size, 'margin' => $margin, 'logo' => $logo, 'logoScale' => $ls,
    'frameText' => $ftext, 'brandText' => $brand,
    'brandGlyphSvg' => $glyphSvg, 'brandGlyphPng' => $glyphPng,
]);

$disposition = qin('download') !== null ? 'attachment' : 'inline';
header('Cache-Control: public, max-age=300');
if ($format === 'png') {
    header('Content-Type: image/png');
    header("Content-Disposition: $disposition; filename=\"qr-$filename.png\"");
    echo $renderer->png();
} elseif ($format === 'pdf') {
    require_once __DIR__ . '/inc/pdf.php';
    $img = $renderer->image();
    // JPEG kennt kein Alpha: transparente Ecken auf Papierweiß flatten
    $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
    imagefilledrectangle($flat, 0, 0, imagesx($img) - 1, imagesy($img) - 1, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
    ob_start();
    imagejpeg($flat, null, 92);
    $jpeg = (string)ob_get_clean();
    $img = $flat;
    header('Content-Type: application/pdf');
    header("Content-Disposition: $disposition; filename=\"qr-$filename.pdf\"");
    echo pdf_single_image($jpeg, imagesx($img), imagesy($img), 80.0);
} else {
    header('Content-Type: image/svg+xml');
    header("Content-Disposition: $disposition; filename=\"qr-$filename.svg\"");
    echo $renderer->svg();
}
