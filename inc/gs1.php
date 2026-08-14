<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * GS1 Digital Link: aus einer Artikelnummer eine Webadresse bauen.
 *
 * Hintergrund: Bis Ende 2027 sollen die Kassensysteme großer Händler
 * zweidimensionale Codes lesen können („Sunrise 2027"). Auf der Verpackung
 * steht dann statt oder neben dem Strichcode ein QR-Code, der einen GS1
 * Digital Link enthält – eine gewöhnliche https-Adresse, in deren Pfad die
 * Artikelnummer und optional Charge, Haltbarkeit und Seriennummer stehen.
 *
 * Was wir hier tun, ist die Erzeugung eines korrekt aufgebauten Codes. Was wir
 * ausdrücklich NICHT tun, ist der Betrieb eines Resolvers – also des Dienstes,
 * der die Adresse beim Scannen in Produktdaten übersetzt. Das ist Sache des
 * Markeninhabers oder eines darauf spezialisierten Anbieters; wer eine eigene
 * Domain einträgt, muss dort selbst dafür sorgen.
 *
 * Reihenfolge und Schreibweise richten sich nach der GS1-Digital-Link-Syntax:
 * zuerst der Primärschlüssel (01 = GTIN), danach die Qualifizierer in fester
 * Folge (22, 10, 21), Datenattribute wie das Haltbarkeitsdatum (17) als
 * Abfrageparameter. Die Reihenfolge ist nicht Geschmackssache – Lesegeräte
 * verlassen sich darauf.
 */

/** Anwendungsbezeichner, die wir unterstützen, in der vorgeschriebenen Reihenfolge */
const GS1_QUALIFIER = ['22' => 'CPV', '10' => 'Charge', '21' => 'Seriennummer'];

/**
 * Prüfziffer nach GS1 (Modulo 10) berechnen.
 *
 * Von rechts nach links abwechselnd mit 3 und 1 gewichtet, ohne die Prüfziffer
 * selbst. Gilt für GTIN-8, -12, -13 und -14 gleichermaßen.
 */
function gs1_check_digit(string $ohnePruefziffer): int
{
    $summe = 0;
    $ziffern = array_reverse(str_split($ohnePruefziffer));
    foreach ($ziffern as $i => $z) {
        $summe += (int)$z * ($i % 2 === 0 ? 3 : 1);
    }
    return (10 - $summe % 10) % 10;
}

/**
 * GTIN prüfen und auf 14 Stellen bringen.
 *
 * Im Digital Link steht die GTIN immer 14-stellig; kürzere werden links mit
 * Nullen aufgefüllt. Die Prüfziffer wird dabei nachgerechnet – eine falsche
 * Nummer würde sonst als QR-Code auf einer Palette landen und dort auffallen.
 *
 * @return array{0:?string,1:string} [Fehlermeldung|null, GTIN-14]
 */
function gs1_normalize_gtin(string $roh): array
{
    $g = preg_replace('/[^0-9]/', '', $roh) ?? '';
    if ($g === '') return ['Bitte eine Artikelnummer (GTIN) angeben.', ''];
    if (!in_array(strlen($g), [8, 12, 13, 14], true)) {
        return ['Eine GTIN hat 8, 12, 13 oder 14 Ziffern – diese hat ' . strlen($g) . '.', ''];
    }
    $soll = gs1_check_digit(substr($g, 0, -1));
    if ((int)substr($g, -1) !== $soll) {
        return ['Die Prüfziffer stimmt nicht: erwartet ' . $soll . ', angegeben ' . substr($g, -1)
            . '. Meist ist eine Ziffer vertauscht.', ''];
    }
    return [null, str_pad($g, 14, '0', STR_PAD_LEFT)];
}

/**
 * Zeichen, die im Pfad eines Digital Link maskiert werden müssen.
 *
 * Charge und Seriennummer dürfen laut GS1 fast alle druckbaren Zeichen
 * enthalten, auch Schrägstrich und Prozentzeichen – unmaskiert würden die den
 * Pfad zerlegen.
 */
function gs1_esc(string $wert): string
{
    return rawurlencode($wert);
}

/**
 * Vollständigen Digital Link zusammensetzen.
 *
 * @param array{22?:string,10?:string,21?:string,17?:string} $extra
 * @return array{0:?string,1:string} [Fehlermeldung|null, Adresse]
 */
function gs1_digital_link(string $gtinRoh, array $extra = [], string $basis = 'https://id.gs1.org'): array
{
    [$err, $gtin] = gs1_normalize_gtin($gtinRoh);
    if ($err !== null) return [$err, ''];

    $basis = rtrim(trim($basis), '/');
    if ($basis === '') $basis = 'https://id.gs1.org';
    if (!str_starts_with($basis, 'http://') && !str_starts_with($basis, 'https://')) {
        $basis = 'https://' . $basis;
    }
    if (filter_var($basis, FILTER_VALIDATE_URL) === false) {
        return ['Die Adresse des Auflösungsdienstes ist ungültig.', ''];
    }

    $url = $basis . '/01/' . $gtin;
    foreach (array_keys(GS1_QUALIFIER) as $ai) {
        $wert = trim((string)($extra[$ai] ?? ''));
        if ($wert === '') continue;
        if (mb_strlen($wert) > 20) {
            return [GS1_QUALIFIER[$ai] . ': höchstens 20 Zeichen.', ''];
        }
        $url .= '/' . $ai . '/' . gs1_esc($wert);
    }

    // Haltbarkeitsdatum ist ein Datenattribut, kein Qualifizierer: Es gehört
    // hinter das Fragezeichen, nicht in den Pfad.
    $mhd = trim((string)($extra['17'] ?? ''));
    if ($mhd !== '') {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $mhd);
        if ($d === false) return ['Das Haltbarkeitsdatum ist ungültig.', ''];
        $url .= '?17=' . $d->format('ymd');
    }
    return [null, $url];
}
