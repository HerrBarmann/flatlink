<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * SVG-Logos beim Upload bereinigen.
 *
 * Ein SVG ist ein Dokument, kein Bild: Es darf Skripte, Ereignis-Attribute
 * und Verweise auf fremde Adressen tragen. Gespeichert wird hier deshalb
 * nie das Original, sondern eine Neufassung, die ausschließlich aus einer
 * Liste erlaubter Elemente und Attribute besteht – eine Allowlist, keine
 * Blockliste: Was die Liste nicht kennt, existiert im Ergebnis nicht,
 * einschließlich allem, was ein künftiger SVG-Standard dazuerfinden mag.
 *
 * Das Neuschreiben ist zugleich die Antwort auf Polyglot-Dateien (eine
 * Datei, die als Bild UND als HTML durchgeht): Der Ausgang ist eine
 * kanonische Serialisierung des geparsten Baums, nicht die hochgeladenen
 * Bytes.
 *
 * Warum das reicht, obwohl die Liste kurz ist: Logos sind Flächen, Pfade,
 * Verläufe, allenfalls Text. Animationen, Filter, Fremdinhalte und Stile
 * mit Nachladeverhalten haben in einem Logo nichts verloren – wer so etwas
 * hochlädt, bekommt die Fehlermeldung und weiß, woran es liegt.
 */

/** Elemente, die ein Logo braucht – alles andere fliegt samt Inhalt */
const SVG_ELEMENTE = [
    'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline',
    'polygon', 'defs', 'lineargradient', 'radialgradient', 'stop',
    'clippath', 'mask', 'use', 'symbol', 'title', 'desc', 'text', 'tspan',
];

/** Attribute, die bleiben dürfen (href nur als interner #-Verweis) */
const SVG_ATTRIBUTE = [
    'id', 'd', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
    'cx', 'cy', 'r', 'rx', 'ry', 'points', 'offset', 'transform',
    'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width',
    'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
    'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity',
    'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
    'spreadmethod', 'clip-path', 'clip-rule', 'preserveaspectratio',
    'font-family', 'font-size', 'font-weight', 'text-anchor', 'xmlns',
    'version',
];

/** CSS-Eigenschaften, die ein style-Attribut behalten darf */
const SVG_STIL_EIGENSCHAFTEN = [
    'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width',
    'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'opacity',
    'stop-color', 'stop-opacity',
];

/**
 * Ein hochgeladenes SVG in eine sichere Neufassung überführen.
 *
 * @return ?string Das bereinigte SVG – oder null, wenn es keines ist oder
 *                 sich nicht sicher übernehmen lässt.
 */
function svg_clean(string $roh): ?string
{
    // Eigene Entity-Definitionen sind in einem Logo nie nötig, aber der
    // klassische Träger von XXE- und Aufblas-Angriffen. Hart ablehnen,
    // bevor überhaupt geparst wird. (Der Illustrator-Doctype ohne eigene
    // Entities bleibt erlaubt – er wird beim Neuschreiben ohnehin fallen.)
    if (stripos($roh, '<!ENTITY') !== false) return null;

    $dom = new DOMDocument();
    // NONET: keinerlei Netzzugriffe beim Parsen, egal was in der Datei steht
    $ok = @$dom->loadXML($roh, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    if (!$ok || $dom->documentElement === null) return null;
    if (strtolower($dom->documentElement->localName) !== 'svg') return null;

    svg_clean_knoten($dom->documentElement);

    $aus = $dom->saveXML($dom->documentElement);
    return is_string($aus) && $aus !== '' ? $aus : null;
}

/** Einen Knoten und seine Kinder gegen die Listen prüfen (in-place) */
function svg_clean_knoten(DOMElement $el): void
{
    // Kinder zuerst einsammeln – die Liste ändert sich beim Entfernen
    $kinder = [];
    foreach ($el->childNodes as $kind) $kinder[] = $kind;
    foreach ($kinder as $kind) {
        if ($kind instanceof DOMElement) {
            if (!in_array(strtolower($kind->localName), SVG_ELEMENTE, true)) {
                $el->removeChild($kind);   // samt allem, was darin steckt
                continue;
            }
            svg_clean_knoten($kind);
        } elseif (!($kind instanceof DOMText) && !($kind instanceof DOMCdataSection)) {
            // Kommentare, Verarbeitungsanweisungen: braucht kein Logo
            $el->removeChild($kind);
        }
    }

    // Attribute: was die Liste nicht kennt, fliegt – damit auch jedes on*
    $weg = [];
    foreach ($el->attributes as $attr) {
        $name = strtolower($attr->localName ?? $attr->name);
        if ($name === 'href') {
            // Nur interne Verweise (#kennung) – use und Verläufe brauchen sie;
            // alles mit Protokoll oder Pfad ist ein Nachladeversuch
            if (!str_starts_with(trim($attr->value), '#')) $weg[] = $attr;
            continue;
        }
        if ($name === 'style') {
            $sauber = svg_clean_stil($attr->value);
            if ($sauber === '') { $weg[] = $attr; } else { $attr->value = $sauber; }
            continue;
        }
        if (!in_array($name, SVG_ATTRIBUTE, true)) {
            $weg[] = $attr;
            continue;
        }
        // url(...) in Attributwerten (fill, stroke, clip-path, mask …) darf
        // ausschließlich auf interne Kennungen zeigen – url(#verlauf) ist der
        // normale Weg zu einem Farbverlauf, url(http://…) wäre ein Nachladen.
        // Geprüft wird jedes Vorkommen, nicht nur das erste.
        if (preg_match_all('/url\s*\(\s*(["\']?)(.)/i', $attr->value, $t) > 0) {
            foreach ($t[2] as $erstesZeichen) {
                if ($erstesZeichen !== '#') { $weg[] = $attr; break; }
            }
        }
    }
    foreach ($weg as $attr) {
        $el->removeAttributeNode($attr);
    }
}

/** Ein style-Attribut auf harmlose Eigenschaften eindampfen */
function svg_clean_stil(string $stil): string
{
    $behalten = [];
    foreach (explode(';', $stil) as $teil) {
        if (!str_contains($teil, ':')) continue;
        [$eigenschaft, $wert] = array_map('trim', explode(':', $teil, 2));
        if (!in_array(strtolower($eigenschaft), SVG_STIL_EIGENSCHAFTEN, true)) continue;
        // url(...) kann nachladen, Backslash-Tricks umgehen Filter – beides raus
        if (stripos($wert, 'url') !== false || str_contains($wert, '\\')) continue;
        $behalten[] = $eigenschaft . ':' . $wert;
    }
    return implode(';', $behalten);
}
