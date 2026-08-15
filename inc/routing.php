<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Weichen: ein Kurzlink, mehrere Ziele.
 *
 * Ein Plakat hängt einmal, aber die Leute davor sind verschieden. Wer den Code
 * mit einem iPhone scannt, soll in den App Store; wer aus dem Ausland kommt,
 * auf die englische Seite. Bisher blieb dafür nur, mehrere Kurzlinks zu
 * drucken – also mehrere Plakate.
 *
 * Der springende Punkt für dieses Projekt: **Es wird nichts gespeichert.**
 * Die Regeln werden zur Anfragezeit ausgewertet und die Antwort danach
 * vergessen; was ein einzelner Besucher für ein Gerät hatte oder aus welchem
 * Land er kam, steht danach nirgends. Genau das unterscheidet eine Weiche von
 * dem, was die kommerziellen Anbieter „Targeting" nennen: Dort ist die Weiche
 * der Anlass, ein Profil anzulegen. Hier ist sie eine Fallunterscheidung, die
 * so spurlos ist wie ein `if`.
 *
 * Datenmodell am Link:
 *   'rules' => [
 *       ['wenn' => 'device', 'ist' => 'mobile', 'url' => 'https://apps.apple…'],
 *       ['wenn' => 'lang',   'ist' => 'en',     'url' => 'https://…/en'],
 *   ]
 * Die erste zutreffende Regel gewinnt; trifft keine zu, gilt das Hauptziel.
 * Die Reihenfolge ist damit die ganze Logik – kein Und/Oder, keine
 * Verschachtelung. Wer mehr braucht, braucht kein Kurzlink-Werkzeug.
 */

/** Wie viele Weichen ein Link haben darf */
const ROUTE_MAX = 8;

/** Die Merkmale, nach denen sich verzweigen lässt */
function route_kriterien(): array
{
    return [
        'device' => t('Gerät'),
        'lang' => t('Sprache'),
        'country' => t('Land'),
    ];
}

/** Die Auswahl je Merkmal; leer = freie Eingabe */
function route_werte(string $wenn): array
{
    return match ($wenn) {
        'device' => ['mobile' => t('Handy'), 'tablet' => t('Tablet'), 'desktop' => t('Rechner')],
        default => [],
    };
}

/**
 * Das Land der Anfrage – oder null, wenn es sich nicht feststellen lässt.
 *
 * flatlink bringt keine Geo-Datenbank mit und lädt auch keine: Eine
 * IP-zu-Land-Tabelle wäre ein Vielfaches der ganzen Anwendung, müsste
 * gepflegt werden und passt nicht zu einem Projekt, das man per FTP
 * hochlädt. Wohl aber liefern viele Vorschaltdienste das Land schon fertig
 * mit – Cloudflare als `CF-IPCountry`, andere als `X-Country-Code`.
 *
 * Gelesen wird das nur hinter einem als vertrauenswürdig eingetragenen Proxy
 * (`trusted_proxies`). Ohne diese Prüfung könnte jeder Besucher sein Land
 * frei behaupten, indem er die Kopfzeile selbst mitschickt – und eine Weiche,
 * die sich von der Gegenseite stellen lässt, ist keine.
 */
function route_land(): ?string
{
    static $land = null;
    if ($land !== null) return $land === '' ? null : $land;
    $land = '';

    $trusted = (array)cfg('trusted_proxies');
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($trusted === [] || !ip_in_list($remote, $trusted)) return null;

    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY', 'GEOIP_COUNTRY_CODE'] as $k) {
        $v = strtolower(trim((string)($_SERVER[$k] ?? '')));
        // „xx" schickt Cloudflare für Anfragen ohne erkennbares Land
        if (preg_match('/^[a-z]{2}$/', $v) === 1 && $v !== 'xx') {
            $land = $v;
            return $land;
        }
    }
    return null;
}

/** Steht die Landerkennung auf dieser Instanz überhaupt zur Verfügung? */
function route_land_moeglich(): bool
{
    return (array)cfg('trusted_proxies') !== [];
}

/**
 * Das Ziel für diese Anfrage.
 *
 * @return array{0:string,1:?int} [Ziel-URL, Nummer der greifenden Regel oder null]
 */
function route_target(array $link): array
{
    $regeln = (array)($link['rules'] ?? []);
    foreach (array_values($regeln) as $i => $r) {
        if (route_trifft((string)($r['wenn'] ?? ''), (string)($r['ist'] ?? ''))) {
            $url = (string)($r['url'] ?? '');
            if ($url !== '') return [$url, $i];
        }
    }
    return [(string)($link['url'] ?? ''), null];
}

/** Trifft ein einzelnes Merkmal auf die laufende Anfrage zu? */
function route_trifft(string $wenn, string $ist): bool
{
    if ($ist === '') return false;
    return match ($wenn) {
        'device' => route_geraet() === $ist,
        // Die Sprachliste wird der Reihe nach geprüft: „en" trifft auch bei
        // „en-GB", und ein Zweitwunsch zählt, wenn der Erstwunsch nicht passt.
        'lang' => in_array($ist, route_sprachen(), true),
        'country' => route_land() === strtolower($ist),
        default => false,
    };
}

/** Gerätegattung der laufenden Anfrage */
function route_geraet(): string
{
    static $g = null;
    if ($g !== null) return $g;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $g = preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $ua) === 1 ? 'tablet'
        : (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua) === 1 ? 'mobile' : 'desktop');
    return $g;
}

/** Die Sprachwünsche der Anfrage, in ihrer Reihenfolge @return string[] */
function route_sprachen(): array
{
    static $l = null;
    if ($l !== null) return $l;
    $l = [];
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $teil) {
        $code = strtolower(trim(explode(';', $teil)[0]));
        $code = substr($code, 0, 2);
        if (preg_match('/^[a-z]{2}$/', $code) === 1 && !in_array($code, $l, true)) $l[] = $code;
    }
    return $l;
}

/**
 * Weichen aus dem Formular übernehmen.
 *
 * @return array{0:?string,1:array} [Fehler oder null, geprüfte Regeln]
 */
function route_from_form(array $wenn, array $ist, array $urls): array
{
    $regeln = [];
    foreach ($wenn as $i => $w) {
        $w = (string)$w;
        $wert = trim((string)($ist[$i] ?? ''));
        $url = url_normalize(trim((string)($urls[$i] ?? '')));
        // Eine Zeile, in der nichts steht, ist keine Regel, sondern eine
        // leere Zeile – sie fällt still weg statt einen Fehler zu erzeugen.
        if ($wert === '' && $url === '') continue;
        if (!isset(route_kriterien()[$w])) {
            return [t('Unbekanntes Merkmal in einer Weiche.'), []];
        }
        if ($wert === '') {
            return [t('Zu jeder Weiche gehört ein Wert – sonst ist nicht klar, wann sie greift.'), []];
        }
        if ($w !== 'device' && preg_match('/^[a-z]{2}$/i', $wert) !== 1) {
            return [t('Sprache und Land werden mit zwei Buchstaben angegeben (z. B. „en" oder „at").'), []];
        }
        if ($w === 'device' && !isset(route_werte('device')[$wert])) {
            return [t('Unbekannte Geräteart in einer Weiche.'), []];
        }
        $grund = url_reject_reason($url);
        if ($grund !== null) return [$grund, []];
        $regeln[] = ['wenn' => $w, 'ist' => strtolower($wert), 'url' => $url];
        if (count($regeln) > ROUTE_MAX) {
            return [t('Höchstens %d Weichen je Link.', ROUTE_MAX), []];
        }
    }
    return [null, $regeln];
}

/** Eine Weiche in einem Satz, für Listen und Protokoll */
function route_label(array $r): string
{
    $kriterien = route_kriterien();
    $wenn = $kriterien[$r['wenn'] ?? ''] ?? (string)($r['wenn'] ?? '');
    $ist = (string)($r['ist'] ?? '');
    $werte = route_werte((string)($r['wenn'] ?? ''));
    return $wenn . ' = ' . ($werte[$ist] ?? strtoupper($ist));
}
