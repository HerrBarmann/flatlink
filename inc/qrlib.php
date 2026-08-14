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
        ], $options);
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

    /** Alles zwischen den äußeren SVG-Tags (Grund, Module, Augen, Logo) */
    private function svgInner(int $total): string
    {
        $o = $this->opt;
        $n = $this->qr->size;
        $m = $o['margin'];
        $fg = htmlspecialchars($o['fg']);

        $parts = [];
        $parts[] = '<rect width="' . $total . '" height="' . $total . '" fill="' . htmlspecialchars($o['bg']) . '"/>';

        // Datenmodule (Finder-Bereiche werden separat als Augen gezeichnet)
        $path = '';
        $shapes = [];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!$this->qr->modules[$y][$x] || $this->inEye($x, $y)) continue;
                $px = $x + $m; $py = $y + $m;
                switch ($o['style']) {
                    case 'dot':
                        $shapes[] = '<circle cx="' . ($px + 0.5) . '" cy="' . ($py + 0.5) . '" r="0.42"/>';
                        break;
                    case 'rounded':
                        $shapes[] = '<rect x="' . ($px + 0.04) . '" y="' . ($py + 0.04) . '" width="0.92" height="0.92" rx="0.28"/>';
                        break;
                    default:
                        $path .= 'M' . $px . ' ' . $py . 'h1v1h-1z';
                }
            }
        }
        $parts[] = '<g fill="' . $fg . '">';
        if ($path !== '') $parts[] = '<path d="' . $path . '"/>';
        $parts[] = implode('', $shapes);

        // Augen
        foreach ($this->eyeOrigins() as [$ex, $ey]) {
            $parts[] = $this->svgEye($ex + $m, $ey + $m);
        }
        $parts[] = '</g>';

        // Logo-Overlay
        if ($o['logo'] !== null && is_file($o['logo'])) {
            $parts[] = $this->svgLogo($total);
        }

        return implode('', $parts);
    }

    private function svgEye(int $x, int $y): string
    {
        $eye = $this->opt['eye'];
        // Äußerer Ring 7x7 (Wandstärke 1) + innerer Kern 3x3
        if ($eye === 'circle') {
            $cx = $x + 3.5; $cy = $y + 3.5;
            return '<path fill-rule="evenodd" d="'
                . self::circlePath($cx, $cy, 3.5) . self::circlePath($cx, $cy, 2.5) . '"/>'
                . '<circle cx="' . $cx . '" cy="' . $cy . '" r="1.5"/>';
        }
        $rxOuter = $eye === 'rounded' ? 2.0 : 0;
        $rxInner = $eye === 'rounded' ? 0.85 : 0;
        $ring = '<path fill-rule="evenodd" d="'
            . self::roundedRectPath($x, $y, 7, $rxOuter)
            . self::roundedRectPath($x + 1, $y + 1, 5, max(0, $rxOuter - 1)) . '"/>';
        $core = '<rect x="' . ($x + 2) . '" y="' . ($y + 2) . '" width="3" height="3" rx="' . $rxInner . '"/>';
        return $ring . $core;
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
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!$this->qr->modules[$y][$x] || $this->inEye($x, $y)) continue;
                $x0 = ($x + $m) * $scale;
                $y0 = ($y + $m) * $scale;
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
            $this->gdEye($img, ($ex + $m) * $scale, ($ey + $m) * $scale, $scale, $fg, $bg);
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

    private function gdEye(\GdImage $img, int $x, int $y, int $s, int $fg, int $bg): void
    {
        $eye = $this->opt['eye'];
        if ($eye === 'circle') {
            $cx = $x + intdiv(7 * $s, 2); $cy = $y + intdiv(7 * $s, 2);
            imagefilledellipse($img, $cx, $cy, 7 * $s, 7 * $s, $fg);
            imagefilledellipse($img, $cx, $cy, 5 * $s, 5 * $s, $bg);
            imagefilledellipse($img, $cx, $cy, 3 * $s, 3 * $s, $fg);
            return;
        }
        $rOuter = $eye === 'rounded' ? 2 * $s : 0;
        $rInner = $eye === 'rounded' ? (int)round(0.85 * $s) : 0;
        self::gdRoundedRect($img, $x, $y, 7 * $s, $rOuter, $fg);
        self::gdRoundedRect($img, $x + $s, $y + $s, 5 * $s, max(0, $rOuter - $s), $bg);
        self::gdRoundedRect($img, $x + 2 * $s, $y + 2 * $s, 3 * $s, $rInner, $fg);
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
