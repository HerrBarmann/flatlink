<?php
declare(strict_types=1);

require_once __DIR__ . '/qrlib.php';
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Vektor-Ausgabe für den Druck: EPS und PDF, wahlweise in CMYK.
 *
 * Warum überhaupt: Bis hierher entstand das PDF als eingebettetes JPEG. Auf
 * Papier fällt das erst auf, wenn jemand den Code groß zieht – und dann ist der
 * Aufkleber schon gedruckt. Eine Druckerei will Vektoren, und sie will CMYK,
 * weil ihre Maschine keine Bildschirmfarben kennt.
 *
 * Beide Formate bekommen dieselbe Vorlage: QrRenderer::vectorOps() liefert eine
 * Liste geometrischer Anweisungen in Modul-Einheiten, hier werden daraus zwei
 * Dateiformate. So kann eine neue Modulform nicht in einem Format erscheinen
 * und im anderen fehlen.
 *
 * Beide Formate rechnen von unten nach oben, die Vorlage von oben nach unten –
 * das Kippen der Y-Achse passiert an genau einer Stelle je Format.
 *
 * Schrift: Courier aus dem Standardvorrat beider Formate. Keine eingebettete
 * Datei, keine Lizenzfrage, und die Breite ist mit exakt 0,6 em bekannt –
 * damit lässt sich zentrieren, ohne die Schrift zu vermessen.
 */

/** Ein Punkt sind 1/72 Zoll; das ist die Maßeinheit beider Formate. */
const VEC_PT_PER_MM = 72.0 / 25.4;

/**
 * Farbe für die Ausgabe: entweder RGB aus einem #rrggbb oder CMYK.
 *
 * CMYK ist kein umgerechnetes RGB, sondern die Angabe, die der Anwender
 * gemacht hat – sie geht unverändert in die Datei. Die Umrechnung passiert nur
 * in die andere Richtung, für die Bildschirmvorschau, und ist dort eine
 * Näherung ohne Farbprofil.
 */
final class VecColor
{
    /** @param float[] $werte drei Werte für RGB, vier für CMYK, jeweils 0..1 */
    private function __construct(public readonly array $werte, public readonly bool $cmyk) {}

    public static function fromHex(string $hex): self
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (strlen($h) !== 6 || !ctype_xdigit($h)) $h = '000000';
        return new self([
            hexdec(substr($h, 0, 2)) / 255,
            hexdec(substr($h, 2, 2)) / 255,
            hexdec(substr($h, 4, 2)) / 255,
        ], false);
    }

    /** @param float[] $cmyk vier Werte 0..1 */
    public static function fromCmyk(array $cmyk): self
    {
        $v = [];
        for ($i = 0; $i < 4; $i++) $v[] = max(0.0, min(1.0, (float)($cmyk[$i] ?? 0)));
        return new self($v, true);
    }

    /**
     * CMYK in eine Bildschirmfarbe übersetzen – ausdrücklich eine Näherung.
     *
     * Ohne Farbprofil gibt es keine richtige Antwort; diese Formel ist die
     * gebräuchliche und liegt für kräftige Farben nah genug, um im Designer zu
     * zeigen, worum es geht. Verbindlich ist der CMYK-Wert in der Datei.
     */
    public static function cmykToHex(array $cmyk): string
    {
        [$c, $m, $y, $k] = array_map(fn($v) => max(0.0, min(1.0, (float)$v)), array_pad(array_slice($cmyk, 0, 4), 4, 0));
        $r = (int)round(255 * (1 - $c) * (1 - $k));
        $g = (int)round(255 * (1 - $m) * (1 - $k));
        $b = (int)round(255 * (1 - $y) * (1 - $k));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /** Mit Weiß aufhellen bzw. gegen eine andere Farbe mischen (0 = diese, 1 = andere) */
    public function mix(self $andere, float $anteil): self
    {
        // Nur innerhalb desselben Farbraums sinnvoll; sonst bleibt diese Farbe
        if ($this->cmyk !== $andere->cmyk) return $this;
        $neu = [];
        foreach ($this->werte as $i => $v) {
            $neu[] = $v + ($andere->werte[$i] - $v) * max(0.0, min(1.0, $anteil));
        }
        return new self($neu, $this->cmyk);
    }

    /** Operator und Werte für PDF ('rg' bzw. 'k') */
    public function pdf(): string
    {
        $z = implode(' ', array_map(fn($v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0', $this->werte));
        return $z . ' ' . ($this->cmyk ? 'k' : 'rg');
    }

    /** Operator und Werte für PostScript */
    public function eps(): string
    {
        $z = implode(' ', array_map(fn($v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0', $this->werte));
        return $z . ' ' . ($this->cmyk ? 'setcmykcolor' : 'setrgbcolor');
    }
}

/**
 * Text auf das übersetzen, was beide Formate ohne eingebettete Schrift können.
 *
 * Beide Standardvorräte reichen bis Latin-1. Was darüber hinausgeht – die
 * typografischen Anführungszeichen und der Gedankenstrich, die in deutschen
 * Texten ständig vorkommen – wird ersetzt statt weggeworfen: Ein fehlendes
 * Zeichen im Rahmentext fällt erst auf dem gedruckten Aufsteller auf.
 */
function vec_latin1(string $text): string
{
    $ersatz = [
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...',
        "\u{201E}" => '"', "\u{201C}" => '"', "\u{201D}" => '"',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{00A0}" => ' ',
        "\u{2192}" => '->', "\u{20AC}" => 'EUR',
    ];
    $t = strtr($text, $ersatz);
    $t = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $t);
    return $t === false ? preg_replace('/[^\x20-\x7E]/', '', $text) ?? '' : $t;
}

/** Zahl kurz und ohne Exponent – beide Formate verstehen keine Wissenschaft */
function vec_num(float $v): string
{
    $s = number_format($v, 3, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

// ---- Pfad-Bausteine ------------------------------------------------------
//
// Ein Pfad ist eine Liste von Teilpfaden; ein Teilpfad hat einen Startpunkt und
// Segmente ('l' = Linie, 'c' = kubische Kurve). Mehr braucht keine der Formen,
// die dieser Generator kennt – und beide Ausgabeformate können genau das.

/** Rechteck, wahlweise mit runden Ecken */
function vec_rect(float $x, float $y, float $w, float $h, float $r = 0.0): array
{
    $r = max(0.0, min($r, min($w, $h) / 2));
    if ($r <= 0.0) {
        return ['start' => [$x, $y], 'segments' => [
            ['l', $x + $w, $y], ['l', $x + $w, $y + $h], ['l', $x, $y + $h],
        ]];
    }
    $k = $r * 0.5522847498;
    return ['start' => [$x + $r, $y], 'segments' => [
        ['l', $x + $w - $r, $y],
        ['c', $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r],
        ['l', $x + $w, $y + $h - $r],
        ['c', $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h],
        ['l', $x + $r, $y + $h],
        ['c', $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r],
        ['l', $x, $y + $r],
        ['c', $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y],
    ]];
}

/** Kreis aus vier Kurven */
function vec_circle(float $cx, float $cy, float $r): array
{
    $k = $r * 0.5522847498;
    return ['start' => [$cx, $cy - $r], 'segments' => [
        ['c', $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy],
        ['c', $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r],
        ['c', $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy],
        ['c', $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r],
    ]];
}

// ---- Gemeinsame Helfer ---------------------------------------------------

/** Ein Logo als RGB-Pixelfeld, Alpha auf den Untergrund gerechnet */
function vec_logo_pixels(string $datei, VecColor $grund, int $kante = 256): ?array
{
    if (!function_exists('imagecreatefromstring')) return null;
    $roh = @file_get_contents($datei);
    if ($roh === false) return null;
    $bild = @imagecreatefromstring($roh);           // SVG kann GD nicht – dann null
    if ($bild === false) return null;

    $w = imagesx($bild); $h = imagesy($bild);
    $k = max(16, min($kante, max($w, $h)));
    $flach = imagecreatetruecolor($k, $k);
    // Durchsichtigkeit auf den Untergrund legen: Im Druck ist Alpha ein Ärgernis,
    // und hinter dem Logo liegt ohnehin die Hintergrundfarbe des Codes.
    $hex = $grund->cmyk ? VecColor::cmykToHex($grund->werte) : sprintf('#%02x%02x%02x',
        (int)round($grund->werte[0] * 255), (int)round($grund->werte[1] * 255), (int)round($grund->werte[2] * 255));
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    imagefilledrectangle($flach, 0, 0, $k - 1, $k - 1, imagecolorallocate($flach, (int)$r, (int)$g, (int)$b));
    imagecopyresampled($flach, $bild, 0, 0, 0, 0, $k, $k, $w, $h);

    $daten = '';
    for ($y = 0; $y < $k; $y++) {
        for ($x = 0; $x < $k; $x++) {
            $c = imagecolorat($flach, $x, $y);
            $daten .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
        }
    }
    return ['w' => $k, 'h' => $k, 'rgb' => $daten];
}

// ---- EPS -----------------------------------------------------------------

/**
 * PostScript-Datei mit Vorschau-freier EPS-Struktur.
 *
 * @param array{w:float,h:float,ops:array} $vec aus QrRenderer::vectorOps()
 * @param float $breiteMm gewünschte Breite auf dem Papier
 */
function vec_eps(array $vec, float $breiteMm = 80.0): string
{
    $bp = $breiteMm * VEC_PT_PER_MM;          // Breite in Punkten
    $s = $bp / $vec['w'];                      // Punkte je Modul
    $hp = $vec['h'] * $s;

    $ps = "%!PS-Adobe-3.0 EPSF-3.0\n"
        . "%%Creator: flatlink\n"
        . "%%Title: QR-Code\n"
        . '%%BoundingBox: 0 0 ' . (int)ceil($bp) . ' ' . (int)ceil($hp) . "\n"
        . '%%HiResBoundingBox: 0 0 ' . vec_num($bp) . ' ' . vec_num($hp) . "\n"
        . "%%LanguageLevel: 2\n%%Pages: 1\n%%EndComments\n"
        . "%%BeginProlog\n"
        // Die Standardschriften auf Latin-1 umstellen, sonst fehlen Umlaute
        . "/reenc { findfont dup length dict begin\n"
        . "  { 1 index /FID ne { def } { pop pop } ifelse } forall\n"
        . "  /Encoding ISOLatin1Encoding def currentdict end definefont pop } bind def\n"
        . "/Courier /Courier-L reenc\n/Courier-Bold /Courier-B-L reenc\n"
        . "%%EndProlog\n%%Page: 1 1\ngsave\n"
        // Y-Achse kippen: die Vorlage rechnet von oben, PostScript von unten
        . '[' . vec_num($s) . ' 0 0 ' . vec_num(-$s) . ' 0 ' . vec_num($hp) . "] concat\n";

    foreach ($vec['ops'] as $op) {
        if ($op[0] === 'path') {
            [$_, $pfade, $farbe, $evenOdd] = $op;
            $ps .= $farbe->eps() . "\nnewpath\n";
            foreach ($pfade as $tp) {
                $ps .= vec_num($tp['start'][0]) . ' ' . vec_num($tp['start'][1]) . " moveto\n";
                foreach ($tp['segments'] as $seg) {
                    $art = $seg[0];
                    $z = array_map('vec_num', array_slice($seg, 1));
                    $ps .= implode(' ', $z) . ($art === 'c' ? " curveto\n" : " lineto\n");
                }
                $ps .= "closepath\n";
            }
            $ps .= $evenOdd ? "eofill\n" : "fill\n";
        } elseif ($op[0] === 'text') {
            [$_, $cx, $cy, $fs, $text, $farbe, $fett] = $op;
            $t = vec_latin1($text);
            $esc = preg_replace_callback('/[\\\\()\\x00-\\x1F\\x7F-\\xFF]/', function ($m) {
                return '\\' . sprintf('%03o', ord($m[0]));
            }, $t);
            $ps .= $farbe->eps() . "\ngsave\n"
                . vec_num($cx) . ' ' . vec_num($cy) . " translate\n1 -1 scale\n"
                . '/' . ($fett ? 'Courier-B-L' : 'Courier-L') . ' findfont ' . vec_num($fs) . " scalefont setfont\n"
                // stringwidth misst die tatsächliche Breite – exakter als jede Schätzung
                . '(' . $esc . ') dup stringwidth pop 2 div neg ' . vec_num(-$fs * 0.33) . " moveto show\ngrestore\n";
        } elseif ($op[0] === 'image') {
            [$_, $x, $y, $w, $h, $datei] = $op;
            $grund = null;
            foreach ($vec['ops'] as $o2) if ($o2[0] === 'path') { $grund = $o2[2]; break; }
            $bild = vec_logo_pixels($datei, $grund ?? VecColor::fromHex('#ffffff'));
            if ($bild === null) continue;
            $ps .= "gsave\n" . vec_num($x) . ' ' . vec_num($y) . " translate\n"
                . vec_num($w) . ' ' . vec_num($h) . " scale\n"
                . '/DeviceRGB setcolorspace\n'
                . '<< /ImageType 1 /Width ' . $bild['w'] . ' /Height ' . $bild['h']
                . ' /BitsPerComponent 8 /Decode [0 1 0 1 0 1]'
                . ' /ImageMatrix [' . $bild['w'] . ' 0 0 ' . $bild['h'] . ' 0 0]'
                . " /DataSource currentfile /ASCIIHexDecode filter >> image\n"
                . chunk_split(bin2hex($bild['rgb']), 76, "\n") . ">\ngrestore\n";
        }
    }

    return $ps . "grestore\nshowpage\n%%EOF\n";
}

// ---- PDF -----------------------------------------------------------------

/**
 * PDF mit echten Vektoren – eine Seite, genau so groß wie der Code.
 *
 * Bis hierher war das PDF ein eingebettetes JPEG. Der Unterschied fällt erst
 * auf, wenn jemand den Code auf ein Plakat zieht; dann aber deutlich.
 *
 * @param array{w:float,h:float,ops:array} $vec aus QrRenderer::vectorOps()
 */
function vec_pdf(array $vec, float $breiteMm = 80.0): string
{
    $bp = $breiteMm * VEC_PT_PER_MM;
    $s = $bp / $vec['w'];
    $hp = $vec['h'] * $s;

    // Y-Achse kippen, danach wird in Modul-Einheiten gezeichnet
    $c = 'q ' . vec_num($s) . ' 0 0 ' . vec_num(-$s) . ' 0 ' . vec_num($hp) . " cm\n";
    $bilder = [];

    foreach ($vec['ops'] as $op) {
        if ($op[0] === 'path') {
            [$_, $pfade, $farbe, $evenOdd] = $op;
            $c .= $farbe->pdf() . "\n";
            foreach ($pfade as $tp) {
                $c .= vec_num($tp['start'][0]) . ' ' . vec_num($tp['start'][1]) . " m\n";
                foreach ($tp['segments'] as $seg) {
                    $art = $seg[0];
                    $z = implode(' ', array_map('vec_num', array_slice($seg, 1)));
                    $c .= $z . ($art === 'c' ? " c\n" : " l\n");
                }
                $c .= "h\n";
            }
            $c .= $evenOdd ? "f*\n" : "f\n";
        } elseif ($op[0] === 'text') {
            [$_, $cx, $cy, $fs, $text, $farbe, $fett] = $op;
            $t = vec_latin1($text);
            $esc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
            // Courier ist dickengleich mit 600/1000 em – Zentrieren ist Rechnen
            $breite = strlen($t) * 0.6 * $fs;
            $c .= $farbe->pdf() . "\nBT /" . ($fett ? 'F2' : 'F1') . ' ' . vec_num($fs) . " Tf\n"
                // Die 1 0 0 -1 dreht den Text im gekippten Koordinatensystem zurück
                . '1 0 0 -1 ' . vec_num($cx - $breite / 2) . ' ' . vec_num($cy + $fs * 0.33) . " Tm\n"
                . '(' . $esc . ") Tj ET\n";
        } elseif ($op[0] === 'image') {
            [$_, $x, $y, $w, $h, $datei] = $op;
            $grund = null;
            foreach ($vec['ops'] as $o2) if ($o2[0] === 'path') { $grund = $o2[2]; break; }
            $bild = vec_logo_pixels($datei, $grund ?? VecColor::fromHex('#ffffff'));
            if ($bild === null) continue;
            $nr = count($bilder) + 1;
            $bilder[] = $bild;
            // Bilder liegen im Einheitsquadrat; die Matrix bringt sie an ihren Platz.
            // Das zusätzliche Kippen hebt die globale Spiegelung wieder auf.
            $c .= "q\n" . vec_num($w) . ' 0 0 ' . vec_num(-$h) . ' ' . vec_num($x) . ' ' . vec_num($y + $h) . " cm\n"
                . "/Im$nr Do\nQ\n";
        }
    }
    $c .= "Q\n";

    // ---- Objekte zusammensetzen ----
    $objekte = [];
    $objekte[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objekte[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";

    $xobj = '';
    foreach ($bilder as $i => $b) {
        $xobj .= '/Im' . ($i + 1) . ' ' . (6 + $i) . " 0 R ";
    }
    $objekte[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . vec_num($bp) . ' ' . vec_num($hp) . ']'
        . ' /Resources << /Font << /F1 4 0 R /F2 5 0 R >>'
        . ($xobj !== '' ? ' /XObject << ' . $xobj . '>>' : '')
        . ' >> /Contents ' . (6 + count($bilder)) . " 0 R >>";
    $objekte[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>";
    $objekte[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>";
    foreach ($bilder as $i => $b) {
        $objekte[6 + $i] = "<< /Type /XObject /Subtype /Image /Width {$b['w']} /Height {$b['h']}"
            . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode"
            . ' /Length ' . strlen($z = (string)gzcompress($b['rgb'], 6)) . " >>\nstream\n" . $z . "\nendstream";
    }
    $inhaltNr = 6 + count($bilder);
    $strom = (string)gzcompress($c, 6);
    $objekte[$inhaltNr] = '<< /Length ' . strlen($strom) . " /Filter /FlateDecode >>\nstream\n" . $strom . "\nendstream";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    for ($i = 1; $i <= $inhaltNr; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= "$i 0 obj\n" . $objekte[$i] . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . ($inhaltNr + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $inhaltNr; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . ($inhaltNr + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $pdf;
}

/**
 * Rechteck mit Radius nur an bestimmten Ecken.
 *
 * Gebraucht für die Blattform der Augen: zwei gegenüberliegende Ecken rund,
 * zwei spitz. Die Ecken werden im Uhrzeigersinn ab oben links gezählt.
 *
 * @param array<int,int> $ecken vier Werte 0 oder 1
 */
function vec_rect_corners(float $x, float $y, float $w, float $h, float $r, array $ecken): array
{
    $r = max(0.0, min($r, min($w, $h) / 2));
    $k = $r * 0.5522847498;
    [$tl, $tr, $br, $bl] = array_map('intval', array_pad(array_slice($ecken, 0, 4), 4, 0));
    if ($r <= 0.0 || ($tl + $tr + $br + $bl) === 0) return vec_rect($x, $y, $w, $h, 0.0);

    $seg = [];
    $start = [$x + ($tl ? $r : 0), $y];
    // oben nach rechts
    $seg[] = ['l', $x + $w - ($tr ? $r : 0), $y];
    if ($tr) $seg[] = ['c', $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r];
    // rechts nach unten
    $seg[] = ['l', $x + $w, $y + $h - ($br ? $r : 0)];
    if ($br) $seg[] = ['c', $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h];
    // unten nach links
    $seg[] = ['l', $x + ($bl ? $r : 0), $y + $h];
    if ($bl) $seg[] = ['c', $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r];
    // links nach oben
    $seg[] = ['l', $x, $y + ($tl ? $r : 0)];
    if ($tl) $seg[] = ['c', $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y];

    return ['start' => $start, 'segments' => $seg];
}
