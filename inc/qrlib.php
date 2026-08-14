<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Purer PHP-QR-Code-Generator, keine Abhängigkeiten.
 * Byte-Mode, Versionen 1–40, ECC L/M/Q/H, Maskenwahl per Penalty-Score.
 * Algorithmus nach ISO/IEC 18004.
 *
 * Aus der Norm abgetippt sind nur zwei Zahlenreihen je Fehlerkorrektur-Stufe:
 * ECC-Codewörter je Block und Anzahl Blöcke (Tabelle 9). Alles andere ergibt
 * sich daraus rechnerisch – Gesamtzahl der Codewörter aus der Geometrie der
 * Matrix, die Aufteilung in kurze und lange Blöcke aus der Division, die Lage
 * der Ausrichtungsmuster aus der Schrittweiten-Regel. Eine Tabelle mit 320
 * handgetippten Werten wäre die wahrscheinlichere Fehlerquelle gewesen.
 */
final class QrCode
{
    public const ECC_L = 0, ECC_M = 1, ECC_Q = 2, ECC_H = 3;

    /** @var bool[][] Matrix [zeile][spalte], true = dunkel */
    public array $modules = [];
    public int $size;
    public int $version;

    /** @var bool[][] */
    private array $isFunction = [];
    private int $ecc;

    // Format-Info-Bits je ECC-Level (L,M,Q,H)
    private const ECC_FORMAT = [1, 0, 3, 2];

    /** Höchste unterstützte Version – 40 ist das Ende der Norm */
    public const MAX_VERSION = 40;

    // ---- Die beiden Reihen aus ISO/IEC 18004, Tabelle 9 (Index = Version) ----

    /** ECC-Codewörter je Block, je Fehlerkorrektur-Stufe */
    private const ECC_PER_BLOCK = [
        [0, 7,10,15,20,26,18,20,24,30,18,20,24,26,30,22,24,28,30,28,28,28,28,30,30,26,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        [0,10,16,26,18,24,16,18,22,22,26,30,22,22,24,24,28,28,26,26,26,26,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28],
        [0,13,22,18,26,18,24,18,22,20,24,28,26,24,20,30,24,28,28,26,30,28,30,30,30,30,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        [0,17,28,22,16,22,28,26,26,24,28,24,28,22,24,24,30,28,28,26,28,30,24,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
    ];

    /** Anzahl Blöcke, je Fehlerkorrektur-Stufe */
    private const BLOCK_COUNT = [
        [0,1,1,1,1,1,2,2,2,2,4,4,4,4,4,6,6,6,6,7,8,8,9,9,10,12,12,12,13,14,15,16,17,18,19,19,20,21,22,24,25],
        [0,1,1,1,2,2,4,4,4,5,5,5,8,9,9,10,10,11,13,14,16,17,17,18,20,21,23,25,26,28,29,31,33,35,37,38,40,43,45,47,49],
        [0,1,1,2,2,4,4,6,6,8,8,8,10,12,16,12,17,16,18,21,20,23,23,25,27,29,34,34,35,38,40,43,45,48,51,53,56,59,62,65,68],
        [0,1,1,2,4,4,4,5,6,8,8,11,11,16,16,18,16,19,21,25,25,25,34,30,32,35,37,40,42,45,48,51,54,57,60,63,66,70,74,77,81],
    ];

    /**
     * Rohe Datenmodule einer Version, in Bit.
     *
     * Fläche der Matrix abzüglich aller Funktionsmuster: Sucher mit Trennern,
     * Timing-Linien, Ausrichtungsmuster (mit ihren Überschneidungen) und ab
     * Version 7 die zweimal 18 Bit Versionsinformation.
     */
    private static function rawDataBits(int $v): int
    {
        $bits = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $n = intdiv($v, 7) + 2;
            $bits -= (25 * $n - 10) * $n - 55;
            if ($v >= 7) $bits -= 36;
        }
        return $bits;
    }

    /** Gesamtzahl der Codewörter einer Version */
    private static function totalCodewords(int $v): int
    {
        return intdiv(self::rawDataBits($v), 8);
    }

    /**
     * Blockaufteilung: kurze Blöcke zuerst, danach die um eins längeren.
     *
     * Die Norm gibt nur Blockzahl und ECC-Länge vor; wie sich die Datenbytes
     * darauf verteilen, ist schlicht eine Division mit Rest.
     *
     * @return array{0:int,1:array<int,array{0:int,1:int}>} [ECC je Block, [[Anzahl, Datenbytes], …]]
     */
    private static function blockLayout(int $v, int $ecc): array
    {
        $count = self::BLOCK_COUNT[$ecc][$v];
        $eccLen = self::ECC_PER_BLOCK[$ecc][$v];
        $data = self::totalCodewords($v) - $eccLen * $count;
        $short = intdiv($data, $count);
        $numShort = $count - $data % $count;
        $groups = [];
        if ($numShort > 0) $groups[] = [$numShort, $short];
        if ($count - $numShort > 0) $groups[] = [$count - $numShort, $short + 1];
        return [$eccLen, $groups];
    }

    /**
     * Mittelpunkte der Ausrichtungsmuster.
     *
     * Erstes bei 6, letztes sieben Module vor dem Rand, dazwischen gleichmäßig
     * mit gerader Schrittweite. Version 32 fällt aus der Regel und steht auch
     * in der Norm als Ausnahme.
     *
     * @return int[]
     */
    private static function alignPositions(int $v): array
    {
        if ($v === 1) return [];
        $n = intdiv($v, 7) + 2;
        $step = $v === 32 ? 26 : intdiv($v * 4 + $n * 2 + 1, $n * 2 - 2) * 2;
        $out = [];
        for ($p = 17 + 4 * $v - 7; count($out) < $n - 1; $p -= $step) array_unshift($out, $p);
        array_unshift($out, 6);
        return $out;
    }

    private static array $gfExp = [];
    private static array $gfLog = [];

    public static function encode(string $text, int $ecc = self::ECC_M): self
    {
        $bytes = array_values(unpack('C*', $text));
        $len = count($bytes);

        // Kleinste Version wählen, die die Daten fasst
        $version = 0;
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            $needed = 4 + ($v <= 9 ? 8 : 16) + 8 * $len;
            if ($needed <= self::dataCodewordCount($v, $ecc) * 8) { $version = $v; break; }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('Text zu lang für einen QR-Code (Grenze: '
                . self::maxBytes($ecc) . ' Byte bei dieser Fehlerkorrektur).');
        }

        // Bitstrom: Mode 0100, Zeichenzahl, Daten
        $bits = '0100' . str_pad(decbin($len), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $capacity = self::dataCodewordCount($version, $ecc) * 8;
        // Terminator (max. 4 Nullbits), auf Byte-Grenze auffüllen
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        // Pad-Bytes 0xEC / 0x11 im Wechsel
        $pad = ['11101100', '00010001'];
        for ($i = 0; strlen($bits) < $capacity; $i++) {
            $bits .= $pad[$i % 2];
        }
        $codewords = array_map('bindec', str_split($bits, 8));

        $qr = new self($version, $ecc);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($codewords));
        $qr->applyBestMask();
        return $qr;
    }

    private function __construct(int $version, int $ecc)
    {
        $this->version = $version;
        $this->ecc = $ecc;
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    private static function dataCodewordCount(int $version, int $ecc): int
    {
        return self::totalCodewords($version)
            - self::ECC_PER_BLOCK[$ecc][$version] * self::BLOCK_COUNT[$ecc][$version];
    }

    /**
     * Wie viele Byte passen bei dieser Fehlerkorrektur höchstens hinein?
     *
     * Gebraucht für Fehlermeldungen und für die Oberfläche, die eine zu lange
     * Eingabe abfangen soll, bevor der Encoder sie ablehnt.
     */
    public static function maxBytes(int $ecc = self::ECC_M): int
    {
        // Version 40 hat 16 Bit Zeichenzahl, dazu 4 Bit Modus
        return intdiv(self::dataCodewordCount(self::MAX_VERSION, $ecc) * 8 - 4 - 16, 8);
    }

    // ---- Reed-Solomon über GF(256), Polynom 0x11D ----

    private static function gfInit(): void
    {
        if (self::$gfExp !== []) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$gfExp[self::$gfLog[$a] + self::$gfLog[$b]];
    }

    /** @param int[] $data @return int[] ECC-Codewörter */
    private static function reedSolomon(array $data, int $degree): array
    {
        self::gfInit();
        // Generatorpolynom: Produkt (x - a^i) für i = 0..degree-1
        $gen = array_fill(0, $degree, 0);
        $gen[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $gen[$j] = self::gfMul($gen[$j], $root);
                if ($j + 1 < $degree) $gen[$j] ^= $gen[$j + 1];
            }
            $root = self::gfMul($root, 2);
        }
        $result = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($gen as $j => $coef) {
                $result[$j] ^= self::gfMul($coef, $factor);
            }
        }
        return $result;
    }

    /** @param int[] $data @return int[] */
    private function addEccAndInterleave(array $data): array
    {
        [$eccLen, $groups] = self::blockLayout($this->version, $this->ecc);
        $blocks = [];
        $pos = 0;
        foreach ($groups as [$count, $dataLen]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $pos, $dataLen);
                $pos += $dataLen;
                $blocks[] = [$chunk, self::reedSolomon($chunk, $eccLen)];
            }
        }
        $result = [];
        $maxData = max(array_map(fn($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b[0])) $result[] = $b[0][$i];
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as $b) {
                $result[] = $b[1][$i];
            }
        }
        return $result;
    }

    // ---- Funktionsmuster ----

    private function setFunction(int $col, int $row, bool $dark): void
    {
        $this->modules[$row][$col] = $dark;
        $this->isFunction[$row][$col] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $n = $this->size;
        // Timing-Pattern
        for ($i = 0; $i < $n; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        // Finder-Patterns mit Separatoren
        $this->drawFinder(3, 3);
        $this->drawFinder($n - 4, 3);
        $this->drawFinder(3, $n - 4);
        // Alignment-Patterns (die drei Ecken mit Findern auslassen)
        $align = self::alignPositions($this->version);
        $last = count($align) - 1;
        foreach ($align as $i => $r) {
            foreach ($align as $j => $c) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($c + $dx, $r + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // Format-Bereiche reservieren (Dummy), Versionsinfo, dunkles Modul
        $this->drawFormatBits(0);
        $this->drawVersionInfo();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx; $y = $cy + $dy;
                if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) continue;
                $dist = max(abs($dx), abs($dy));
                $this->setFunction($x, $y, $dist !== 2 && $dist !== 4);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        // 5 Datenbits + 10 BCH-Bits, XOR-Maske 0x5412
        $data = self::ECC_FORMAT[$this->ecc] << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        $n = $this->size;
        $bit = fn(int $i): bool => (($bits >> $i) & 1) !== 0;
        // Kopie 1 (um den Finder oben links)
        for ($i = 0; $i <= 5; $i++) $this->setFunction(8, $i, $bit($i));
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i <= 14; $i++) $this->setFunction(14 - $i, 8, $bit($i));
        // Kopie 2 (unten links + oben rechts)
        for ($i = 0; $i <= 7; $i++) $this->setFunction($n - 1 - $i, 8, $bit($i));
        for ($i = 8; $i <= 14; $i++) $this->setFunction(8, $n - 15 + $i, $bit($i));
        // Dunkles Modul
        $this->setFunction(8, $n - 8, true);
    }

    private function drawVersionInfo(): void
    {
        if ($this->version < 7) return;
        // 6 Datenbits + 12 BCH-Bits (Generator 0x1F25)
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = $this->version << 12 | $rem;
        $n = $this->size;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $n - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    /** @param int[] $codewords */
    private function drawCodewords(array $codewords): void
    {
        $n = $this->size;
        $i = 0;
        $total = count($codewords) * 8;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $n - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    // ---- Maskierung ----

    private function applyMask(int $mask): void
    {
        $n = $this->size;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->isFunction[$y][$x]) continue;
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    private function applyBestMask(): void
    {
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $p = $this->penalty();
            if ($p < $bestPenalty) { $bestPenalty = $p; $best = $mask; }
            $this->applyMask($mask); // XOR ist selbstinvers
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $dark = 0;

        // Zeilen- und Spalten-Strings für Regel 1 und 3
        $rows = [];
        $cols = array_fill(0, $n, '');
        for ($y = 0; $y < $n; $y++) {
            $row = '';
            for ($x = 0; $x < $n; $x++) {
                $c = $this->modules[$y][$x] ? '1' : '0';
                $row .= $c;
                $cols[$x] .= $c;
                if ($c === '1') $dark++;
            }
            $rows[] = $row;
        }
        foreach (array_merge($rows, $cols) as $line) {
            // Regel 1: Läufe >= 5 gleichfarbiger Module
            foreach (preg_split('/(?<=0)(?=1)|(?<=1)(?=0)/', $line) as $run) {
                $len = strlen($run);
                if ($len >= 5) $score += 3 + ($len - 5);
            }
            // Regel 3: Finder-ähnliche Muster
            foreach (['10111010000', '00001011101'] as $pattern) {
                $off = 0;
                while (($off = strpos($line, $pattern, $off)) !== false) {
                    $score += 40;
                    $off++;
                }
            }
        }
        // Regel 2: 2x2-Blöcke gleicher Farbe
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // Regel 4: Abweichung vom 50%-Dunkelanteil
        $percent = $dark * 100 / ($n * $n);
        $score += 10 * intdiv((int)abs($percent - 50), 5);
        return $score;
    }
}

/**
 * Rendert eine QrCode-Matrix als SVG oder PNG mit Styling-Optionen.
 */
final class QrRenderer
{
    /** @var array{style:string,eye:string,fg:string,bg:string,size:int,margin:int,logo:?string,logoScale:float} */
    private array $opt;
    private QrCode $qr;

    public function __construct(QrCode $qr, array $options = [])
    {
        $this->qr = $qr;
        $this->opt = array_merge([
            'style'     => 'square',   // square | rounded | dot
            'eye'       => 'square',   // square | rounded | circle
            'fg'        => '#000000',
            'bg'        => '#ffffff',
            'size'      => 512,        // Pixel-Kantenlänge
            'margin'    => 4,          // Quiet-Zone in Modulen
            'logo'      => null,       // Dateipfad
            'logoScale' => 0.22,       // Anteil an der Kantenlänge
            'frameText' => null,       // Rahmen mit Text unter dem Code (null = kein Rahmen)
            'brandText' => null,       // kleine Absender-Zeile (unter dem Code oder im Band)
            // Optionales Bildzeichen links neben der Absenderzeile. Zwei Wege,
            // weil Vektor- und Rasterausgabe verschiedene Quellen brauchen:
            // eine eigenständige SVG-Datei (mit eigener viewBox) fürs SVG und
            // eine PNG-Maske fürs Raster, die auf die Textfarbe eingefärbt wird.
            'brandGlyphSvg' => null,   // Dateipfad
            'brandGlyphPng' => null,   // Dateipfad
            // Druckfarben. Wenn gesetzt, gehen sie unverändert in EPS und PDF;
            // 'fg'/'bg' bleiben die Bildschirm-Näherung für SVG und PNG.
            'fgColor'   => null,       // ?VecColor
            'bgColor'   => null,       // ?VecColor
            // Farbverlauf über die Module. 'fg' ist der Anfang, 'gradTo' das
            // Ende. Null heißt: einfarbig wie bisher.
            'grad'      => null,       // null | 'linear' | 'radial'
            'gradTo'    => '#3B6EA8',
            'gradAngle' => 45,         // Grad, nur bei 'linear'
            // Augen: Ring und Kern lassen sich getrennt formen und färben.
            // Leer heißt jeweils „wie das darüber": Der Kern nimmt die Form und
            // Farbe des Rings, der Ring die Farbe der Datenmodule. So bleibt
            // die Vorgabe genau das, was sie vorher war.
            'eyeCore'   => '',         // '' | square | rounded | circle | leaf
            'eyeFg'     => '',         // '' | #rrggbb
            'eyeCoreFg' => '',         // '' | #rrggbb
        ], $options);
    }

    /**
     * Farbe eines Punktes im Verlauf – oder schlicht die Vordergrundfarbe.
     *
     * **Warum modulweise und nicht als echter Verlauf des jeweiligen Formats:**
     * SVG und PDF könnten einen glatten Verlauf, PNG und EPS (Level 2) nicht.
     * Vier Formate mit zwei verschiedenen Verfahren wären vier Ergebnisse, die
     * sich im Detail unterscheiden – und ausgerechnet beim Druckexport will
     * niemand herausfinden, warum die Datei anders aussieht als die Vorschau.
     * Ein QR-Code besteht ohnehin aus Kacheln; eine Farbe je Kachel ist bei
     * jeder vernünftigen Größe von einem glatten Verlauf nicht zu
     * unterscheiden.
     *
     * @param float $mx Modul-Koordinaten, Mitte des einzufärbenden Elements
     * @param int   $total Kantenlänge des Codes in Modulen (mit Rand)
     */
    private function colorAt(float $mx, float $my, int $total): string
    {
        $o = $this->opt;
        if ($o['grad'] === null) return (string)$o['fg'];

        $c = $total / 2;
        if ($o['grad'] === 'radial') {
            $d = sqrt(($mx - $c) ** 2 + ($my - $c) ** 2);
            $u = $d / (M_SQRT1_2 * $total);            // Ecke = 1.0
        } else {
            $a = deg2rad((float)$o['gradAngle']);
            $proj = ($mx - $c) * cos($a) + ($my - $c) * sin($a);
            // Ausdehnung der Projektion des Quadrats auf die Verlaufsachse
            $halb = (abs(cos($a)) + abs(sin($a))) * $total / 2;
            $u = $halb > 0 ? 0.5 + $proj / (2 * $halb) : 0.5;
        }
        return self::mixColor((string)$o['fg'], (string)$o['gradTo'], max(0.0, min(1.0, $u)));
    }

    /** Läuft über diesen Code ein Verlauf? */
    private function hasGrad(): bool
    {
        return $this->opt['grad'] !== null;
    }

    /** Die drei 7x7-Finder-Bereiche (oben links / oben rechts / unten links) */
    private function eyeOrigins(): array
    {
        $n = $this->qr->size;
        return [[0, 0], [$n - 7, 0], [0, $n - 7]];
    }

    private function inEye(int $x, int $y): bool
    {
        foreach ($this->eyeOrigins() as [$ex, $ey]) {
            if ($x >= $ex && $x < $ex + 7 && $y >= $ey && $y < $ey + 7) return true;
        }
        return false;
    }

    // ---- SVG ----

    public function svg(): string
    {
        $o = $this->opt;
        $total = $this->qr->size + 2 * $o['margin'];
        $crisp = $o['style'] === 'square' ? ' shape-rendering="crispEdges"' : '';
        if ($o['frameText'] !== null) {
            return $this->svgFramed($total, $crisp);
        }
        if ($o['brandText'] !== null) {
            return $this->svgBranded($total, $crisp);
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' width="' . $o['size'] . '" height="' . $o['size'] . '"' . $crisp . '>'
            . $this->svgInner($total) . '</svg>';
    }

    /** Rahmenloser Code mit dezenter Absender-Zeile unter dem Code (Free-Tarif) */
    private function svgBranded(int $total, string $crisp): string
    {
        $o = $this->opt;
        $strip = 2.2;
        $h = $total + $strip;
        $muted = self::mixColor($o['fg'], $o['bg'], 0.45);
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $h . '"'
            . ' width="' . $o['size'] . '" height="' . (int)round($o['size'] * $h / $total) . '">'
            . '<rect width="' . $total . '" height="' . $h . '" fill="' . htmlspecialchars($o['bg']) . '"/>'
            . '<g' . $crisp . '>' . $this->svgInner($total) . '</g>'
            . $this->svgBrandLine($total / 2, $total + $strip / 2, 1.05, (string)$o['brandText'], $muted)
            . '</svg>';
    }

    /** Absender-Zeile, horizontal um $cx zentriert, vertikale Mitte $cy */
    /**
     * Inhalt und Seitenverhältnis einer eigenständigen SVG-Datei lesen.
     * @return ?array{0:string,1:string,2:float} [innerer Inhalt, viewBox, Breite/Höhe]
     */
    private static function glyphSvg(?string $file): ?array
    {
        if ($file === null || !is_file($file)) return null;
        static $cache = [];
        if (isset($cache[$file])) return $cache[$file];

        $svg = (string)@file_get_contents($file);
        if ($svg === '' || preg_match('/viewBox="([^"]+)"/', $svg, $m) !== 1) return $cache[$file] = null;
        $vb = array_map('floatval', preg_split('/[\s,]+/', trim($m[1])) ?: []);
        if (count($vb) !== 4 || $vb[3] <= 0) return $cache[$file] = null;
        // Alles zwischen öffnendem und schließendem <svg> ist der Zeichen-Inhalt
        if (preg_match('#<svg[^>]*>(.*)</svg>#is', $svg, $c) !== 1) return $cache[$file] = null;
        // <title> würde als Tooltip im Code auftauchen
        $inner = (string)preg_replace('#<title>.*?</title>#is', '', $c[1]);
        return $cache[$file] = [trim($inner), trim($m[1]), $vb[2] / $vb[3]];
    }

    /** Absender-Zeile, optional mit Bildzeichen davor, um $cx zentriert */
    private function svgBrandLine(float $cx, float $cy, float $fs, string $text, string $color, float $opacity = 1.0): string
    {
        $font = ' font-family="&#39;Courier New&#39;, monospace" font-weight="500"'
            . ' font-size="' . round($fs, 2) . '" dominant-baseline="central"';
        $col = htmlspecialchars($color);
        $wrap = fn(string $inner): string =>
            $opacity < 1.0 ? '<g opacity="' . $opacity . '">' . $inner . '</g>' : $inner;

        $glyph = self::glyphSvg($this->opt['brandGlyphSvg'] ?? null);
        if ($glyph === null) {
            return $wrap('<text x="' . round($cx, 2) . '" y="' . round($cy, 2) . '" fill="' . $col . '"'
                . $font . ' text-anchor="middle">' . htmlspecialchars($text) . '</text>');
        }

        [$inner, $viewBox, $ratio] = $glyph;
        $gh = $fs * 1.35;
        $gw = $gh * $ratio;
        $gap = $fs * 0.45;
        // Monospace läuft mit rund 0.62 em je Zeichen – genau genug zum Zentrieren
        $textW = max(1, mb_strlen($text)) * $fs * 0.62;
        $x0 = $cx - ($gw + $gap + $textW) / 2;

        return $wrap(
            '<svg x="' . round($x0, 2) . '" y="' . round($cy - $gh / 2, 2) . '"'
            . ' width="' . round($gw, 2) . '" height="' . round($gh, 2) . '"'
            . ' viewBox="' . htmlspecialchars($viewBox) . '" preserveAspectRatio="xMidYMid meet">'
            . '<g fill="' . $col . '" color="' . $col . '">' . $inner . '</g></svg>'
            . '<text x="' . round($x0 + $gw + $gap, 2) . '" y="' . round($cy, 2) . '" fill="' . $col . '"'
            . $font . ' text-anchor="start">' . htmlspecialchars($text) . '</text>'
        );
    }

    /** Zwei Hex-Farben mischen ($t = Anteil von $b) – für gedämpfte Absender-Zeilen */
    private static function mixColor(string $a, string $b, float $t): string
    {
        $p = function (string $hex): array {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            $v = hexdec($hex);
            return [($v >> 16) & 0xFF, ($v >> 8) & 0xFF, $v & 0xFF];
        };
        [$ar, $ag, $ab] = $p($a);
        [$br, $bg2, $bb] = $p($b);
        return sprintf('#%02x%02x%02x',
            (int)round($ar + ($br - $ar) * $t),
            (int)round($ag + ($bg2 - $ag) * $t),
            (int)round($ab + ($bb - $ab) * $t));
    }

    /** Rahmen in fg-Farbe um den Code, Textband unten (Text in bg-Farbe) */
    private function svgFramed(int $total, string $crisp): string
    {
        $o = $this->opt;
        $brand = $o['brandText'] !== null ? (string)$o['brandText'] : null;
        $b = 1.4; $rad = 1.8;                       // Rahmenstärke, Eckradius in Modulen
        $band = $brand === null ? 4.4 : 5.4;        // Bandhöhe (mit Absender-Zeile etwas höher)
        $w = $total + 2 * $b;
        $h = $total + 2 * $b + $band;
        $scale = $o['size'] / $total;
        $fg = htmlspecialchars($o['fg']);
        $bg = htmlspecialchars($o['bg']);
        // Mono-Zeichenbreite ≈ 0,62 em: Schrift so groß wie möglich, aber im Band
        $len = max(1, mb_strlen((string)$o['frameText']));
        $fs = min(3.0, ($w * 0.88) / ($len * 0.62));
        $font = ' font-family="&#39;Courier New&#39;, monospace"';
        $textY = $brand === null ? $b + $total + $band / 2 : $b + $total + 2.0;

        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '"'
            . ' width="' . (int)round($w * $scale) . '" height="' . (int)round($h * $scale) . '">'
            . '<rect width="' . $w . '" height="' . $h . '" rx="' . $rad . '" fill="' . $fg . '"/>'
            . '<rect x="' . $b . '" y="' . $b . '" width="' . $total . '" height="' . $total . '" rx="0.8" fill="' . $bg . '"/>'
            . '<g transform="translate(' . $b . ' ' . $b . ')"' . $crisp . '>' . $this->svgInner($total) . '</g>'
            . '<text x="' . ($w / 2) . '" y="' . $textY . '" fill="' . $bg . '"' . $font
            . ' font-weight="700" font-size="' . round($fs, 2) . '" text-anchor="middle" dominant-baseline="central">'
            . htmlspecialchars((string)$o['frameText']) . '</text>';
        if ($brand !== null) {
            $out .= $this->svgBrandLine($w / 2, $b + $total + $band - 1.0, 1.0, $brand, $o['bg'], 0.75);
        }
        return $out . '</svg>';
    }

    // ---- Vektor-Vorlage für EPS und PDF ---------------------------------

    /**
     * Die Zeichnung als Liste geometrischer Anweisungen, in Modul-Einheiten.
     *
     * Dieselbe Geometrie wie im SVG, nur ohne Dateiformat drumherum. EPS und
     * PDF setzen sie jeweils um; so kann eine neue Form nicht in einem Format
     * auftauchen und im anderen fehlen.
     *
     * Anweisungen:
     *   ['path', [Teilpfade], Farbe, evenOdd]
     *   ['text', cx, cy, Größe, Text, Farbe, fett]
     *   ['image', x, y, w, h, Dateipfad]
     *
     * Durchsichtigkeit gibt es hier nicht: Wo das SVG die Absenderzeile mit
     * 75 % Deckkraft setzt, wird die Farbe stattdessen gegen den Untergrund
     * gemischt. Auf einer deckenden Fläche sieht das gleich aus – und erspart
     * beiden Formaten die Zusatzmaschinerie für Alpha, die PostScript in
     * Level 2 ohnehin nicht hat.
     *
     * @return array{w:float,h:float,ops:array<int,array>}
     */
    public function vectorOps(): array
    {
        require_once __DIR__ . '/vector.php';
        $o = $this->opt;
        $total = $this->qr->size + 2 * $o['margin'];

        $fg = $o['fgColor'] ?? VecColor::fromHex((string)$o['fg']);
        $bg = $o['bgColor'] ?? VecColor::fromHex((string)$o['bg']);

        if ($o['frameText'] !== null) {
            $brand = $o['brandText'] !== null ? (string)$o['brandText'] : null;
            $b = 1.4; $rad = 1.8;
            $band = $brand === null ? 4.4 : 5.4;
            $w = $total + 2 * $b;
            $h = $total + 2 * $b + $band;
            $len = max(1, mb_strlen((string)$o['frameText']));
            $fs = min(3.0, ($w * 0.88) / ($len * 0.62));
            $textY = $brand === null ? $b + $total + $band / 2 : $b + $total + 2.0;

            $ops = [
                ['path', [vec_rect(0, 0, $w, $h, $rad)], $fg, false],
                ['path', [vec_rect($b, $b, $total, $total, 0.8)], $bg, false],
            ];
            foreach ($this->codeOps($total, $fg, $bg) as $op) {
                $ops[] = self::shiftOp($op, $b, $b);
            }
            $ops[] = ['text', $w / 2, $textY, $fs, (string)$o['frameText'], $bg, true];
            if ($brand !== null) {
                $ops[] = ['text', $w / 2, $b + $total + $band - 1.0, 1.0, $brand, $bg->mix($fg, 0.25), false];
            }
            return ['w' => $w, 'h' => $h, 'ops' => $ops];
        }

        if ($o['brandText'] !== null) {
            $strip = 2.2;
            $h = $total + $strip;
            $ops = [['path', [vec_rect(0, 0, $total, $h)], $bg, false]];
            foreach ($this->codeOps($total, $fg, $bg) as $op) $ops[] = $op;
            $ops[] = ['text', $total / 2, $total + $strip / 2, 1.05,
                (string)$o['brandText'], $fg->mix($bg, 0.45), false];
            return ['w' => (float)$total, 'h' => $h, 'ops' => $ops];
        }

        return ['w' => (float)$total, 'h' => (float)$total, 'ops' => $this->codeOps($total, $fg, $bg)];
    }

    /** Eine Anweisung um dx/dy verschieben */
    private static function shiftOp(array $op, float $dx, float $dy): array
    {
        if ($op[0] === 'path') {
            foreach ($op[1] as $i => $tp) {
                $op[1][$i]['start'] = [$tp['start'][0] + $dx, $tp['start'][1] + $dy];
                foreach ($tp['segments'] as $j => $seg) {
                    $art = array_shift($seg);
                    foreach ($seg as $k => $v) $seg[$k] = $v + ($k % 2 === 0 ? $dx : $dy);
                    $op[1][$i]['segments'][$j] = array_merge([$art], array_values($seg));
                }
            }
            return $op;
        }
        if ($op[0] === 'text')  { $op[1] += $dx; $op[2] += $dy; return $op; }
        if ($op[0] === 'image') { $op[1] += $dx; $op[2] += $dy; return $op; }
        return $op;
    }

    /**
     * Grund, Module, Augen und Logo – der Code selbst, ohne Rahmen und Band.
     *
     * @return array<int,array>
     */
    private function codeOps(int $total, VecColor $fg, VecColor $bg): array
    {
        $o = $this->opt;
        $n = $this->qr->size;
        $m = $o['margin'];
        $ops = [['path', [vec_rect(0, 0, $total, $total)], $bg, false]];

        $verlauf = $this->hasGrad();
        $formen = [];
        $einzeln = [];
        foreach ($this->modulePositions() as [$x, $y]) {
            $px = $x + $m; $py = $y + $m;
            switch ($o['style']) {
                case 'dot':     $form = vec_circle($px + 0.5, $py + 0.5, 0.42); break;
                case 'rounded': $form = vec_rect($px + 0.04, $py + 0.04, 0.92, 0.92, 0.28); break;
                default:        $form = vec_rect($px, $py, 1, 1); break;
            }
            if ($verlauf) {
                $einzeln[] = ['path', [$form],
                    VecColor::fromHex($this->colorAt($px + 0.5, $py + 0.5, $total)), false];
            } else {
                $formen[] = $form;
            }
        }
        if ($formen !== []) $ops[] = ['path', $formen, $fg, false];
        foreach ($einzeln as $e) $ops[] = $e;

        foreach ($this->eyeOrigins() as [$ex, $ey]) {
            $mx = $ex + $m + 3.5; $my = $ey + $m + 3.5;
            $ringF = VecColor::fromHex($this->eyeRingColor($mx, $my, $total));
            $kernF = VecColor::fromHex($this->eyeCoreColor($mx, $my, $total));
            [$ring, $kern] = $this->eyeOps($ex + $m, $ey + $m);
            $ops[] = ['path', $ring, $ringF, true];
            $ops[] = ['path', $kern, $kernF, false];
        }

        if ($o['logo'] !== null && is_file($o['logo'])) {
            $lw = $total * $o['logoScale'];
            $ops[] = ['image', ($total - $lw) / 2, ($total - $lw) / 2, $lw, $lw, $o['logo']];
        }
        return $ops;
    }

    /**
     * Alle dunklen Datenmodule (ohne die Augenbereiche).
     *
     * Eigene Methode, damit SVG und Vektor-Ausgabe dieselbe Auswahl treffen.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function modulePositions(): array
    {
        $out = [];
        $n = $this->qr->size;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->qr->modules[$y][$x] && !$this->inEye($x, $y)) $out[] = [$x, $y];
            }
        }
        return $out;
    }

    /**
     * Maße einer Augenform: Radien für Ring außen, Ring innen und Kern, dazu
     * die Ecken, an denen der Radius überhaupt greift.
     *
     * Der innere Radius ist immer der äußere minus die Wandstärke von einem
     * Modul – dadurch bleibt der Ring überall gleich dick, und für den Kreis
     * fällt genau 3,5 und 2,5 heraus. Die Werte für die drei alten Formen
     * stehen ausgeschrieben statt hergeleitet, damit ihre Ausgabe Zeichen für
     * Zeichen die bisherige bleibt.
     *
     * @return array{0:float,1:float,2:float,3:array<int,int>} [außen, innen, Kern, Ecken oben-links im Uhrzeigersinn]
     */
    private static function eyeShape(string $name): array
    {
        return match ($name) {
            'rounded' => [2.0, 1.0, 0.85, [1, 1, 1, 1]],
            'circle'  => [3.5, 2.5, 1.5,  [1, 1, 1, 1]],
            // Blattform: zwei gegenüberliegende Ecken rund, zwei spitz.
            // Der Radius ist bewusst derselbe wie bei der abgerundeten Form
            // und nicht der halbe Ring: Das Suchmuster muss entlang jeder
            // Abtastlinie durch seine Mitte das Verhältnis 1:1:3:1:1 halten.
            // Mit einem Radius von 3,5 – also einer halb weggeschnittenen Ecke –
            // fiel der Code bei mehreren Rastergrößen durch, während die
            // übrigen Formen bei denselben Größen sauber lasen. Gestaltung darf
            // einen Code nicht unlesbar machen.
            'leaf'    => [2.0, 1.0, 0.85, [1, 0, 1, 0]],
            default   => [0.0, 0.0, 0.0,  [0, 0, 0, 0]],
        };
    }

    /** Farbe des Augenrings an dieser Stelle */
    private function eyeRingColor(float $cx, float $cy, int $total): string
    {
        $e = trim((string)$this->opt['eyeFg']);
        return $e !== '' ? $e : $this->colorAt($cx, $cy, $total);
    }

    /** Farbe des Augenkerns – erbt vom Ring, wenn nichts gesetzt ist */
    private function eyeCoreColor(float $cx, float $cy, int $total): string
    {
        $e = trim((string)$this->opt['eyeCoreFg']);
        return $e !== '' ? $e : $this->eyeRingColor($cx, $cy, $total);
    }

    /** Welche Form hat der Kern? Leer heißt: dieselbe wie der Ring. */
    private function coreShapeName(): string
    {
        $c = trim((string)$this->opt['eyeCore']);
        return $c !== '' ? $c : (string)$this->opt['eye'];
    }

    /**
     * Ein Auge als Pfadgruppen: Ring (mit Loch, deshalb even-odd) und Kern.
     *
     * @return array<int,array<int,array>>
     */
    private function eyeOps(int $x, int $y): array
    {
        $cx = $x + 3.5; $cy = $y + 3.5;
        [$ra, $ri, , $ecken] = self::eyeShape((string)$this->opt['eye']);
        [, , $rk, $keck] = self::eyeShape($this->coreShapeName());

        $ring = $this->opt['eye'] === 'circle'
            ? [vec_circle($cx, $cy, 3.5), vec_circle($cx, $cy, 2.5)]
            : [vec_rect_corners($x, $y, 7, 7, $ra, $ecken),
               vec_rect_corners($x + 1, $y + 1, 5, 5, $ri, $ecken)];

        $kern = $this->coreShapeName() === 'circle'
            ? [vec_circle($cx, $cy, 1.5)]
            : [vec_rect_corners($x + 2, $y + 2, 3, 3, $rk, $keck)];

        return [$ring, $kern];
    }

    /** Alles zwischen den äußeren SVG-Tags (Grund, Module, Augen, Logo) */
    private function svgInner(int $total): string
    {
        $o = $this->opt;
        $n = $this->qr->size;
        $m = $o['margin'];
        $fg = htmlspecialchars($o['fg']);

        $parts = [];
        $parts[] = '<rect width="' . $total . '" height="' . $total . '" fill="' . htmlspecialchars($o['bg']) . '"/>';

        // Datenmodule (Finder-Bereiche werden separat als Augen gezeichnet).
        // Ohne Verlauf hängen alle in einer Gruppe mit gemeinsamer Farbe – das
        // hält die Datei klein. Mit Verlauf trägt jedes Modul seine eigene.
        $verlauf = $this->hasGrad();
        $path = '';
        $shapes = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!$this->qr->modules[$y][$x] || $this->inEye($x, $y)) continue;
                $px = $x + $m; $py = $y + $m;
                $f = $verlauf ? ' fill="' . htmlspecialchars($this->colorAt($px + 0.5, $py + 0.5, $total)) . '"' : '';
                switch ($o['style']) {
                    case 'dot':
                        $shapes[] = '<circle cx="' . ($px + 0.5) . '" cy="' . ($py + 0.5) . '" r="0.42"' . $f . '/>';
                        break;
                    case 'rounded':
                        $shapes[] = '<rect x="' . ($px + 0.04) . '" y="' . ($py + 0.04) . '" width="0.92" height="0.92" rx="0.28"' . $f . '/>';
                        break;
                    default:
                        if ($verlauf) {
                            $shapes[] = '<path d="M' . $px . ' ' . $py . 'h1v1h-1z"' . $f . '/>';
                        } else {
                            $path .= 'M' . $px . ' ' . $py . 'h1v1h-1z';
                        }
                }
            }
        }
        $parts[] = '<g fill="' . $fg . '">';
        if ($path !== '') $parts[] = '<path d="' . $path . '"/>';
        $parts[] = implode('', $shapes);

        // Augen. Ohne eigene Farben und ohne Verlauf bleiben sie in der Gruppe
        // und erben deren Füllung – dann sieht die Datei aus wie bisher.
        $eigen = trim((string)$o['eyeFg']) !== '' || trim((string)$o['eyeCoreFg']) !== '';
        foreach ($this->eyeOrigins() as [$ex, $ey]) {
            $mx = $ex + $m + 3.5; $my = $ey + $m + 3.5;
            [$ring, $kern] = $this->svgEyeParts($ex + $m, $ey + $m);
            if (!$verlauf && !$eigen) {
                $parts[] = $ring . $kern;
                continue;
            }
            $parts[] = '<g fill="' . htmlspecialchars($this->eyeRingColor($mx, $my, $total)) . '">' . $ring . '</g>'
                . '<g fill="' . htmlspecialchars($this->eyeCoreColor($mx, $my, $total)) . '">' . $kern . '</g>';
        }
        $parts[] = '</g>';

        // Logo-Overlay
        if ($o['logo'] !== null && is_file($o['logo'])) {
            $parts[] = $this->svgLogo($total);
        }

        return implode('', $parts);
    }

    /**
     * Ring und Kern eines Auges getrennt, damit beide eigene Farben bekommen
     * können. Äußerer Ring 7×7 mit Wandstärke 1, Kern 3×3.
     *
     * @return array{0:string,1:string} [Ring, Kern]
     */
    private function svgEyeParts(int $x, int $y): array
    {
        $eye = (string)$this->opt['eye'];
        $kernForm = $this->coreShapeName();
        $cx = $x + 3.5; $cy = $y + 3.5;

        if ($eye === 'circle') {
            $ring = '<path fill-rule="evenodd" d="'
                . self::circlePath($cx, $cy, 3.5) . self::circlePath($cx, $cy, 2.5) . '"/>';
        } else {
            [$ra, $ri, , $ecken] = self::eyeShape($eye);
            $ring = '<path fill-rule="evenodd" d="'
                . self::cornerRectPath($x, $y, 7, $ra, $ecken)
                . self::cornerRectPath($x + 1, $y + 1, 5, $ri, $ecken) . '"/>';
        }

        if ($kernForm === 'circle') {
            $kern = '<circle cx="' . $cx . '" cy="' . $cy . '" r="1.5"/>';
        } else {
            [, , $rk, $keck] = self::eyeShape($kernForm);
            $kern = $keck === [1, 1, 1, 1] || $rk <= 0
                ? '<rect x="' . ($x + 2) . '" y="' . ($y + 2) . '" width="3" height="3" rx="' . $rk . '"/>'
                : '<path d="' . self::cornerRectPath($x + 2, $y + 2, 3, $rk, $keck) . '"/>';
        }
        return [$ring, $kern];
    }

    /**
     * Quadrat mit Radius nur an ausgewählten Ecken.
     *
     * Für den Sonderfall „alle vier Ecken" fällt genau der Pfad heraus, den
     * roundedRectPath() bisher erzeugt hat – deshalb bleibt die Ausgabe der
     * bekannten Formen unverändert.
     *
     * @param array<int,int> $ecken oben-links im Uhrzeigersinn
     */
    private static function cornerRectPath(float $x, float $y, float $w, float $r, array $ecken): string
    {
        if ($ecken === [1, 1, 1, 1] || $r <= 0) return self::roundedRectPath($x, $y, $w, $r);
        [$tl, $tr, $br, $bl] = $ecken;
        $b = fn(float $x1, float $y1, float $x2, float $y2): string =>
            'a' . $r . ' ' . $r . ' 0 0 1 ' . ($x2 - $x1) . ' ' . ($y2 - $y1);
        $p = 'M' . ($x + ($tl ? $r : 0)) . ' ' . $y;
        $p .= 'L' . ($x + $w - ($tr ? $r : 0)) . ' ' . $y;
        if ($tr) $p .= $b($x + $w - $r, $y, $x + $w, $y + $r);
        $p .= 'L' . ($x + $w) . ' ' . ($y + $w - ($br ? $r : 0));
        if ($br) $p .= $b($x + $w, $y + $w - $r, $x + $w - $r, $y + $w);
        $p .= 'L' . ($x + ($bl ? $r : 0)) . ' ' . ($y + $w);
        if ($bl) $p .= $b($x + $r, $y + $w, $x, $y + $w - $r);
        $p .= 'L' . $x . ' ' . ($y + ($tl ? $r : 0));
        if ($tl) $p .= $b($x, $y + $r, $x + $r, $y);
        return $p . 'z';
    }

    private static function circlePath(float $cx, float $cy, float $r): string
    {
        return 'M' . ($cx - $r) . ' ' . $cy
            . 'a' . $r . ' ' . $r . ' 0 1 0 ' . (2 * $r) . ' 0'
            . 'a' . $r . ' ' . $r . ' 0 1 0 ' . (-2 * $r) . ' 0z';
    }

    private static function roundedRectPath(float $x, float $y, float $w, float $r): string
    {
        if ($r <= 0) {
            return 'M' . $x . ' ' . $y . 'h' . $w . 'v' . $w . 'h' . (-$w) . 'z';
        }
        $s = $w - 2 * $r;
        return 'M' . ($x + $r) . ' ' . $y
            . 'h' . $s . 'a' . $r . ' ' . $r . ' 0 0 1 ' . $r . ' ' . $r
            . 'v' . $s . 'a' . $r . ' ' . $r . ' 0 0 1 ' . (-$r) . ' ' . $r
            . 'h' . (-$s) . 'a' . $r . ' ' . $r . ' 0 0 1 ' . (-$r) . ' ' . (-$r)
            . 'v' . (-$s) . 'a' . $r . ' ' . $r . ' 0 0 1 ' . $r . ' ' . (-$r) . 'z';
    }

    private function svgLogo(int $total): string
    {
        $o = $this->opt;
        $mime = self::logoMime($o['logo']);
        if ($mime === null) return '';
        $data = base64_encode((string)file_get_contents($o['logo']));
        $w = $total * $o['logoScale'];
        $pad = $w * 0.12;
        $bx = ($total - $w) / 2 - $pad;
        $bw = $w + 2 * $pad;
        return '<rect x="' . $bx . '" y="' . $bx . '" width="' . $bw . '" height="' . $bw . '" rx="' . ($w * 0.12) . '" fill="' . htmlspecialchars($o['bg']) . '"/>'
            . '<image x="' . (($total - $w) / 2) . '" y="' . (($total - $w) / 2) . '" width="' . $w . '" height="' . $w . '"'
            . ' preserveAspectRatio="xMidYMid meet" href="data:' . $mime . ';base64,' . $data . '"/>';
    }

    public static function logoMime(string $file): ?string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    // ---- PNG (GD) ----

    public function png(): string
    {
        ob_start();
        imagepng($this->image());
        return (string)ob_get_clean();
    }

    /** Fertig komponiertes GD-Bild (inkl. Rahmen, falls gesetzt) – auch für JPEG/PDF-Export */
    public function image(): \GdImage
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD-Erweiterung fehlt – Raster-Export nicht verfügbar');
        }
        $o = $this->opt;
        $n = $this->qr->size;
        $totalModules = $n + 2 * $o['margin'];
        $scale = max(1, intdiv($o['size'], $totalModules));
        $px = $totalModules * $scale;

        $img = imagecreatetruecolor($px, $px);
        $bg = self::gdColor($img, $o['bg']);
        $fg = self::gdColor($img, $o['fg']);
        imagefilledrectangle($img, 0, 0, $px - 1, $px - 1, $bg);

        $m = $o['margin'];
        $verlauf = $this->hasGrad();
        // Farben je Modul kosten sonst Tausende Aufrufe von imagecolorallocate
        $cache = [];
        $farbe = function (float $mx, float $my) use ($img, $o, $totalModules, $verlauf, $fg, &$cache): int {
            if (!$verlauf) return $fg;
            $hex = $this->colorAt($mx, $my, $totalModules);
            return $cache[$hex] ??= self::gdColor($img, $hex);
        };
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!$this->qr->modules[$y][$x] || $this->inEye($x, $y)) continue;
                $x0 = ($x + $m) * $scale;
                $y0 = ($y + $m) * $scale;
                $fg = $farbe($x + $m + 0.5, $y + $m + 0.5);
                switch ($o['style']) {
                    case 'dot':
                        $d = (int)round($scale * 0.84);
                        imagefilledellipse($img, $x0 + intdiv($scale, 2), $y0 + intdiv($scale, 2), $d, $d, $fg);
                        break;
                    case 'rounded':
                        self::gdRoundedRect($img, $x0, $y0, $scale, (int)round($scale * 0.28), $fg);
                        break;
                    default:
                        imagefilledrectangle($img, $x0, $y0, $x0 + $scale - 1, $y0 + $scale - 1, $fg);
                }
            }
        }
        foreach ($this->eyeOrigins() as [$ex, $ey]) {
            $mx = $ex + $m + 3.5; $my = $ey + $m + 3.5;
            $ringHex = $this->eyeRingColor($mx, $my, $totalModules);
            $kernHex = $this->eyeCoreColor($mx, $my, $totalModules);
            $this->gdEye($img, ($ex + $m) * $scale, ($ey + $m) * $scale, $scale,
                $cache[$ringHex] ??= self::gdColor($img, $ringHex),
                $cache[$kernHex] ??= self::gdColor($img, $kernHex), $bg);
        }
        if ($o['logo'] !== null && is_file($o['logo'])) {
            $this->gdLogo($img, $px);
        }
        if ($o['frameText'] !== null) {
            return $this->gdFramed($img, $px, $scale);
        }
        if ($o['brandText'] !== null) {
            return $this->gdBranded($img, $px, $scale);
        }

        return $img;
    }

    /** Rahmenloser Code mit dezenter Absender-Zeile (Geometrie wie in svgBranded) */
    private function gdBranded(\GdImage $qr, int $px, int $scale): \GdImage
    {
        $o = $this->opt;
        $strip = max(12, (int)round(2.2 * $scale));
        $img = imagecreatetruecolor($px, $px + $strip);
        imagefilledrectangle($img, 0, 0, $px - 1, $px + $strip - 1, self::gdColor($img, $o['bg']));
        imagecopy($img, $qr, 0, 0, 0, 0, $px, $px);
        $muted = self::gdColor($img, self::mixColor($o['fg'], $o['bg'], 0.45));
        $this->gdBrandLine($img, $px, $px, $strip, $muted, (string)$o['brandText']);
        return $img;
    }

    /** Rahmen + Textband um das fertige QR-Bild (Geometrie wie in svgFramed) */
    private function gdFramed(\GdImage $qr, int $px, int $scale): \GdImage
    {
        $o = $this->opt;
        $brand = $o['brandText'] !== null ? (string)$o['brandText'] : null;
        $b = max(2, (int)round(1.4 * $scale));
        $band = max(16, (int)round(($brand === null ? 4.4 : 5.4) * $scale));
        $rad = (int)round(1.8 * $scale);
        $w = $px + 2 * $b;
        $h = $px + 2 * $b + $band;

        $img = imagecreatetruecolor($w, $h);
        // Ecken außerhalb der Rundung: transparent – so liegt der Rahmen auch auf
        // dunklen Untergründen sauber auf (PDF/JPEG flatten später auf Weiß)
        imagealphablending($img, false);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        self::gdRoundedRectWH($img, 0, 0, $w, $h, $rad, self::gdColor($img, $o['fg']));
        imagecopy($img, $qr, $b, $b, 0, 0, $px, $px);

        $color = self::gdColor($img, $o['bg']);
        if ($brand === null) {
            $this->gdBandText($img, $w, $b + $px, $band, $color, (string)$o['frameText'], 0.60);
        } else {
            $mainH = (int)round($band * 0.72);
            $this->gdBandText($img, $w, $b + $px, $mainH, $color, (string)$o['frameText'], 0.62);
            $this->gdBrandLine($img, $w, $b + $px + $mainH, $band - $mainH, $color, $brand);
        }
        imagesavealpha($img, true);
        return $img;
    }

    /**
     * Absender-Zeile im Raster: optionales Bildzeichen (eingefärbte PNG-Maske)
     * plus Text, zusammen zentriert. Ohne Maske oder ohne TrueType-Schrift
     * übernimmt gdBandText.
     */
    private function gdBrandLine(\GdImage $img, int $w, int $top, int $bandH, int $color, string $text): void
    {
        $font = self::bandFont();
        $glyphFile = $this->opt['brandGlyphPng'] ?? null;
        if ($font === null || !function_exists('imagettftext')
            || $glyphFile === null || !is_file($glyphFile)) {
            $this->gdBandText($img, $w, $top, $bandH, $color, $text, 0.62);
            return;
        }

        $size = max(3.0, $bandH * 0.55 * 72 / 96);
        while ($size > 3.0) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (($box[2] - $box[0]) <= $w * 0.7 && ($box[1] - $box[7]) <= $bandH * 0.62) break;
            $size *= 0.92;
        }
        $box = imagettfbbox($size, 0, $font, $text);
        $tw = $box[2] - $box[0];
        $th = $box[1] - $box[7];

        $glyph = @imagecreatefrompng($glyphFile);
        if ($glyph === false) {
            $this->gdBandText($img, $w, $top, $bandH, $color, $text, 0.62);
            return;
        }
        $gh = max(6, (int)round($th * 1.35));
        $gw = (int)round($gh * imagesx($glyph) / max(1, imagesy($glyph)));
        $gap = (int)round($th * 0.45);
        $x0 = (int)round(($w - ($gw + $gap + $tw)) / 2);
        $cy = $top + intdiv($bandH, 2);

        // Die Maske ist einfarbig mit Alphakanal – Einfärben auf die Textfarbe
        imagefilter($glyph, IMG_FILTER_COLORIZE, ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF);
        imagecopyresampled($img, $glyph, $x0, $cy - intdiv($gh, 2), 0, 0,
            $gw, $gh, imagesx($glyph), imagesy($glyph));

        $y = (int)round($cy - $th / 2 - $box[7]);
        imagettftext($img, $size, 0, $x0 + $gw + $gap - $box[0], $y, $color, $font, $text);
    }

    /**
     * TrueType-Schrift für Rahmen- und Absendertexte im PNG.
     * Genommen wird die erste .ttf-Datei in assets/fonts/ – wer dort eine
     * Datei ablegt, bekommt saubere Beschriftung; ohne Datei greift ein
     * grober GD-Systemfont als Rückfall. (SVG braucht das nicht.)
     */
    private static function bandFont(): ?string
    {
        static $f = false;
        if ($f === false) {
            $hits = glob(dirname(__DIR__) . '/assets/fonts/*.ttf') ?: [];
            $f = $hits === [] ? null : $hits[0];
        }
        return $f;
    }

    /** Text horizontal zentriert in einen Bandbereich setzen (größtmögliche Schrift) */
    private function gdBandText(\GdImage $img, int $w, int $top, int $bandH, int $color, string $text, float $hFactor): void
    {
        if ($text === '' || $bandH < 6) return;
        $font = self::bandFont();

        if ($font !== null && function_exists('imagettftext')) {
            // Größte Schrift, die in 88 % der Breite und $hFactor der Bandhöhe passt
            $size = max(3.0, $bandH * 0.55 * 72 / 96);
            while ($size > 3.0) {
                $box = imagettfbbox($size, 0, $font, $text);
                if (($box[2] - $box[0]) <= $w * 0.88 && ($box[1] - $box[7]) <= $bandH * $hFactor) break;
                $size *= 0.92;
            }
            $box = imagettfbbox($size, 0, $font, $text);
            $tw = $box[2] - $box[0];
            $th = $box[1] - $box[7];
            $x = (int)round(($w - $tw) / 2 - $box[0]);
            $y = (int)round($top + ($bandH - $th) / 2 - $box[7]);
            imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
            return;
        }

        // Fallback ohne FreeType: GD-Bitmap-Schrift hochskaliert (Pixel-Look)
        $f = 5;
        $tw = imagefontwidth($f) * strlen($text);
        $th = imagefontheight($f);
        if ($tw < 1) return;
        $tmp = imagecreatetruecolor($tw, $th);
        $key = imagecolorallocate($tmp, 1, 2, 3);
        imagefill($tmp, 0, 0, $key);
        imagecolortransparent($tmp, $key);
        imagestring($tmp, $f, 0, 0, $text, imagecolorallocate($tmp, ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF));
        $factor = max(1, min((int)(($w * 0.88) / $tw), (int)(($bandH * $hFactor) / $th)));
        $dw = $tw * $factor; $dh = $th * $factor;
        imagecopyresized($img, $tmp, intdiv($w - $dw, 2), $top + intdiv($bandH - $dh, 2), 0, 0, $dw, $dh, $tw, $th);
    }

    private static function gdRoundedRectWH(\GdImage $img, int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        if ($r <= 0) {
            imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $color);
            return;
        }
        $r = min($r, intdiv(min($w, $h), 2));
        imagefilledrectangle($img, $x + $r, $y, $x + $w - 1 - $r, $y + $h - 1, $color);
        imagefilledrectangle($img, $x, $y + $r, $x + $w - 1, $y + $h - 1 - $r, $color);
        foreach ([[$x + $r, $y + $r], [$x + $w - 1 - $r, $y + $r], [$x + $r, $y + $h - 1 - $r], [$x + $w - 1 - $r, $y + $h - 1 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }

    private function gdEye(\GdImage $img, int $x, int $y, int $s, int $ringFg, int $kernFg, int $bg): void
    {
        $eye = (string)$this->opt['eye'];
        $kernForm = $this->coreShapeName();
        $cx = $x + intdiv(7 * $s, 2); $cy = $y + intdiv(7 * $s, 2);

        // Ring
        if ($eye === 'circle') {
            imagefilledellipse($img, $cx, $cy, 7 * $s, 7 * $s, $ringFg);
            imagefilledellipse($img, $cx, $cy, 5 * $s, 5 * $s, $bg);
        } else {
            [$ra, $ri, , $ecken] = self::eyeShape($eye);
            if ($ecken === [1, 1, 1, 1] || $ra <= 0) {
                self::gdRoundedRect($img, $x, $y, 7 * $s, (int)round($ra * $s), $ringFg);
                self::gdRoundedRect($img, $x + $s, $y + $s, 5 * $s, (int)round($ri * $s), $bg);
            } else {
                self::gdCornerRect($img, $x, $y, 7 * $s, $ra * $s, $ecken, $ringFg);
                self::gdCornerRect($img, $x + $s, $y + $s, 5 * $s, $ri * $s, $ecken, $bg);
            }
        }

        // Kern
        if ($kernForm === 'circle') {
            imagefilledellipse($img, $cx, $cy, 3 * $s, 3 * $s, $kernFg);
            return;
        }
        [, , $rk, $keck] = self::eyeShape($kernForm);
        if ($keck === [1, 1, 1, 1] || $rk <= 0) {
            self::gdRoundedRect($img, $x + 2 * $s, $y + 2 * $s, 3 * $s, (int)round($rk * $s), $kernFg);
        } else {
            self::gdCornerRect($img, $x + 2 * $s, $y + 2 * $s, 3 * $s, $rk * $s, $keck, $kernFg);
        }
    }

    /**
     * Quadrat mit Radius nur an ausgewählten Ecken, als Vieleck gefüllt.
     *
     * Für die gleichmäßigen Formen bleibt der alte Weg über Rechtecke und
     * Ellipsen bestehen – dessen Ausgabe ist bekannt und soll sich nicht
     * ändern. Dieser Weg kommt nur für die Blattform dazu.
     *
     * @param array<int,int> $ecken oben-links im Uhrzeigersinn
     */
    private static function gdCornerRect(\GdImage $img, int $x, int $y, int $w, float $r, array $ecken, int $color): void
    {
        $r = max(0.0, min($r, $w / 2));
        [$tl, $tr, $br, $bl] = $ecken;
        $pts = [];
        $bogen = function (float $cx, float $cy, float $von, float $bis) use (&$pts, $r) {
            for ($i = 0; $i <= 8; $i++) {
                $w2 = $von + ($bis - $von) * $i / 8;
                $pts[] = $cx + $r * cos($w2);
                $pts[] = $cy + $r * sin($w2);
            }
        };
        $x2 = $x + $w; $y2 = $y + $w;
        if ($tl) $bogen($x + $r, $y + $r, M_PI, 1.5 * M_PI); else { $pts[] = $x; $pts[] = $y; }
        if ($tr) $bogen($x2 - $r, $y + $r, 1.5 * M_PI, 2 * M_PI); else { $pts[] = $x2; $pts[] = $y; }
        if ($br) $bogen($x2 - $r, $y2 - $r, 0, 0.5 * M_PI); else { $pts[] = $x2; $pts[] = $y2; }
        if ($bl) $bogen($x + $r, $y2 - $r, 0.5 * M_PI, M_PI); else { $pts[] = $x; $pts[] = $y2; }
        imagefilledpolygon($img, array_map('intval', $pts), $color);
    }

    private static function gdRoundedRect(\GdImage $img, int $x, int $y, int $w, int $r, int $color): void
    {
        if ($r <= 0) {
            imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $w - 1, $color);
            return;
        }
        imagefilledrectangle($img, $x + $r, $y, $x + $w - 1 - $r, $y + $w - 1, $color);
        imagefilledrectangle($img, $x, $y + $r, $x + $w - 1, $y + $w - 1 - $r, $color);
        foreach ([[$x + $r, $y + $r], [$x + $w - 1 - $r, $y + $r], [$x + $r, $y + $w - 1 - $r], [$x + $w - 1 - $r, $y + $w - 1 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }

    private function gdLogo(\GdImage $img, int $px): void
    {
        $o = $this->opt;
        $mime = self::logoMime($o['logo']);
        // SVG-Logos kann GD nicht rastern – nur im SVG-Export sichtbar
        if ($mime === null || $mime === 'image/svg+xml') return;
        $logo = match ($mime) {
            'image/png' => @imagecreatefrompng($o['logo']),
            'image/jpeg' => @imagecreatefromjpeg($o['logo']),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($o['logo']) : false,
        };
        if ($logo === false) return;
        imagesavealpha($img, true);

        $w = (int)round($px * $o['logoScale']);
        $pad = (int)round($w * 0.12);
        $bx = intdiv($px - $w, 2) - $pad;
        self::gdRoundedRect($img, $bx, $bx, $w + 2 * $pad, (int)round($w * 0.12), self::gdColor($img, $o['bg']));

        $lw = imagesx($logo); $lh = imagesy($logo);
        // Seitenverhältnis erhalten, in w×w einpassen
        $ratio = min($w / $lw, $w / $lh);
        $dw = (int)round($lw * $ratio); $dh = (int)round($lh * $ratio);
        $dx = intdiv($px - $dw, 2); $dy = intdiv($px - $dh, 2);
        imagecopyresampled($img, $logo, $dx, $dy, 0, 0, $dw, $dh, $lw, $lh);
    }

    private static function gdColor(\GdImage $img, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $v = hexdec($hex);
        return imagecolorallocate($img, ($v >> 16) & 0xFF, ($v >> 8) & 0xFF, $v & 0xFF);
    }
}
