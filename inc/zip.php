<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Ein ZIP-Archiv schreiben – ohne die Erweiterung `zip`.
 *
 * PHP bringt `ZipArchive` mit, aber nicht überall ist sie eingeschaltet, und
 * sie will eine echte Datei auf der Platte: erst schreiben, dann ausliefern,
 * dann aufräumen. Auf günstigem Hosting mit knappem Schreibrecht ist das genau
 * der Fall, der beim Kunden scheitert und beim Entwickler nie.
 *
 * Das Format selbst ist überschaubar, solange man auf das verzichtet, was wir
 * ohnehin nicht brauchen: keine Verschlüsselung, keine geteilten Archive, kein
 * ZIP64. Geschrieben wird in den Arbeitsspeicher, weil die Größen dann vorab
 * feststehen – so entfallen die „Data Descriptor"-Blöcke, die man beim Streamen
 * bräuchte, und das Archiv bleibt für jeden Leser das einfachste mögliche.
 *
 * Aufbau (APPNOTE 6.3.x, Abschnitte 4.3.6 ff.):
 *
 *     [lokaler Kopf + Daten] …          je Eintrag
 *     [zentrales Verzeichnis] …         je Eintrag noch einmal, mit Position
 *     [Ende des Verzeichnisses]         Anzahl und Position des Verzeichnisses
 *
 * Gelesen wird am Ende: Wer ein ZIP öffnet, springt zuerst ans Dateiende, holt
 * sich das Verzeichnis und weiß dann, wo jeder Eintrag liegt. Deshalb muss die
 * Reihenfolge stimmen, auch wenn sie beim Schreiben unnötig erscheint.
 */

final class ZipWriter
{
    /** @var array<int,array{name:string,data:string,crc:int,csize:int,usize:int,method:int,time:int}> */
    private array $eintraege = [];

    /**
     * Eine Datei aufnehmen.
     *
     * Verdichtet wird mit `gzdeflate()` aus zlib – praktisch überall vorhanden.
     * Fehlt es oder wird die Datei dadurch nicht kleiner (bei PNG der Regelfall,
     * das ist bereits verdichtet), wird unverändert abgelegt. Beides ist im
     * Format vorgesehen; kein Leser merkt einen Unterschied.
     */
    public function add(string $name, string $inhalt, ?int $zeit = null): void
    {
        $name = self::cleanName($name);
        if ($name === '') return;

        $roh = strlen($inhalt);
        $daten = $inhalt;
        $methode = 0;                                   // 0 = gespeichert
        if ($roh > 256 && function_exists('gzdeflate')) {
            $kl = @gzdeflate($inhalt, 6);
            if (is_string($kl) && strlen($kl) < $roh) {
                $daten = $kl;
                $methode = 8;                           // 8 = deflate
            }
        }

        $this->eintraege[] = [
            'name' => $name,
            'data' => $daten,
            'crc' => crc32($inhalt),
            'csize' => strlen($daten),
            'usize' => $roh,
            'method' => $methode,
            'time' => $zeit ?? time(),
        ];
    }

    public function count(): int
    {
        return count($this->eintraege);
    }

    /** Das fertige Archiv */
    public function build(): string
    {
        $lokal = '';
        $zentral = '';

        foreach ($this->eintraege as $e) {
            $offset = strlen($lokal);
            [$dosZeit, $dosDatum] = self::dosTime($e['time']);
            // Bit 11 (0x0800) sagt: Der Name ist UTF-8. Ohne dieses Bit deutet
            // Windows Umlaute als CP437 – aus „Größe" würde „GrĂ¶Ăźe".
            $flags = 0x0800;

            $kopf = pack('VvvvvvVVVvv',
                0x04034b50,          // Signatur "PK\x03\x04"
                20,                  // benötigte Version (2.0 = deflate)
                $flags,
                $e['method'],
                $dosZeit, $dosDatum,
                $e['crc'], $e['csize'], $e['usize'],
                strlen($e['name']), 0
            );
            $lokal .= $kopf . $e['name'] . $e['data'];

            $zentral .= pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,          // Signatur "PK\x01\x02"
                0x031E,              // erzeugt von: Unix, Version 3.0
                20,                  // benötigte Version
                $flags,
                $e['method'],
                $dosZeit, $dosDatum,
                $e['crc'], $e['csize'], $e['usize'],
                strlen($e['name']),
                0, 0,                // Zusatzfeld, Kommentar
                0,                   // Datenträger
                0,                   // interne Attribute
                0x81A40000,          // externe Attribute: reguläre Datei, 0644
                $offset
            ) . $e['name'];
        }

        $ende = pack('VvvvvVVv',
            0x06054b50,              // Signatur "PK\x05\x06"
            0, 0,                    // Datenträger
            count($this->eintraege),
            count($this->eintraege),
            strlen($zentral),
            strlen($lokal),
            0                        // Archiv-Kommentar
        );
        return $lokal . $zentral . $ende;
    }

    /**
     * Einen Dateinamen für das Archiv säubern.
     *
     * Nicht nur Kosmetik: Ein Name mit `../` darin ist der klassische Weg, beim
     * Entpacken aus dem Zielverzeichnis auszubrechen. Unsere Namen kommen zwar
     * aus eigenen Daten, aber ein Kurzcode darf einen Schrägstrich enthalten
     * (Namensräume), und was einmal in eine ZIP-Datei geschrieben wurde,
     * entpackt irgendwann jemand anders.
     */
    public static function cleanName(string $name): string
    {
        $n = str_replace('\\', '/', $name);
        $n = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $n);
        $teile = [];
        foreach (explode('/', $n) as $t) {
            $t = trim($t);
            if ($t === '' || $t === '.' || $t === '..') continue;
            $teile[] = $t;
        }
        return mb_substr(implode('/', $teile), 0, 200);
    }

    /**
     * Zeitstempel in das Format von MS-DOS.
     *
     * Zwei 16-Bit-Wörter aus dem Jahr 1980, die das ZIP-Format bis heute
     * mitschleppt: Sekunden in Zweierschritten, Jahr als Abstand zu 1980.
     *
     * @return array{0:int,1:int} [Zeit, Datum]
     */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        if ($d['year'] < 1980) return [0, 0x21];   // 1980-01-01, der kleinste gültige Wert
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | ($d['seconds'] >> 1),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
