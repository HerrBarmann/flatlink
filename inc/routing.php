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

/**
 * Ist die Anfrage ein Vorschau-Abruf (Chat, soziales Netz, Suchmaschine)?
 *
 * Wer einen Kurzlink in einen Chat klebt, löst dort einen Abruf aus, der nur
 * die Vorschau bauen will. Der bekommt eine kleine Seite mit den Angaben statt
 * einer Weiterleitung – sonst zeigt die Vorschau, was das Ziel hergibt, und
 * bei manchen Zielen ist das nichts.
 *
 * Das ist ausdrücklich **kein Cloaking**: Die Vorschau-Seite nennt dasselbe
 * Ziel, auf das auch jeder Mensch geleitet wird, und verlinkt es sichtbar.
 * Cloaking wäre, dem Abruf etwas anderes zu zeigen, um eine Prüfung zu
 * täuschen – hier bekommt er dieselbe Wahrheit, nur als Seite statt als
 * Umleitung. Ohne eigene Angaben am Link passiert gar nichts: Dann wird auch
 * ein Vorschau-Abruf ganz normal weitergeleitet.
 */
function route_ist_vorschau(): bool
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return false;
    return preg_match(
        '#facebookexternalhit|Twitterbot|Slackbot|Discordbot|WhatsApp|TelegramBot|LinkedInBot'
        . '|Mastodon|Pleroma|SkypeUriPreview|redditbot|Applebot|Googlebot|bingbot|DuckDuckBot'
        . '|Embedly|Iframely|vkShare|W3C_Validator|SignalBot|Threema#i', $ua) === 1;
}

/**
 * Die Vorschau-Seite ausliefern und beenden.
 *
 * Bewusst eine vollständige, lesbare Seite und keine leere Hülle mit
 * Meta-Angaben: Landet hier doch einmal ein Mensch – ein Vorschau-Dienst mit
 * ungewöhnlicher Kennung, ein Textbrowser –, soll er nicht vor einer weißen
 * Fläche stehen, sondern das Ziel sehen und anklicken können.
 */
function preview_render(string $code, array $link, string $ziel): void
{
    $titel = (string)($link['og_title'] ?? '');
    $text = (string)($link['og_text'] ?? '');
    $bild = (string)($link['og_image'] ?? '');
    $kurz = short_url($code, (string)($link['domain'] ?? ''));

    nosniff_header();
    header('Content-Type: text/html; charset=UTF-8');
    // Nicht zwischenspeichern lassen: Ein geändertes Ziel soll beim nächsten
    // Teilen sofort stimmen.
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="' . e(lang()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($titel) . '</title>'
        . '<meta property="og:title" content="' . e($titel) . '">'
        . '<meta property="og:url" content="' . e($kurz) . '">'
        . '<meta property="og:type" content="website">'
        . '<meta name="twitter:card" content="' . ($bild !== '' ? 'summary_large_image' : 'summary') . '">';
    if ($text !== '') {
        echo '<meta name="description" content="' . e($text) . '">'
            . '<meta property="og:description" content="' . e($text) . '">';
    }
    if ($bild !== '') {
        echo '<meta property="og:image" content="' . e($bild) . '">';
    }
    echo '</head><body style="font-family:system-ui,sans-serif;max-width:38rem;margin:3rem auto;padding:0 1rem">'
        . '<h1>' . e($titel) . '</h1>';
    if ($text !== '') echo '<p>' . e($text) . '</p>';
    // Das echte Ziel steht sichtbar da – wer hier landet, sieht dasselbe,
    // wohin auch jeder andere geleitet wird.
    echo '<p><a href="' . e($ziel) . '" rel="noopener">' . e($ziel) . '</a></p>'
        . '</body></html>';
    exit;
}

/** Wie viele Weichen ein Link haben darf */
const ROUTE_MAX = 8;

/** Die Merkmale, nach denen sich verzweigen lässt */
function route_kriterien(): array
{
    return [
        'device' => t('Gerät'),
        'lang' => t('Sprache'),
        'country' => t('Land'),
        'split' => t('Anteil (A/B)'),
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
/**
 * Welche Sprach-Weiche greift – wenn überhaupt?
 *
 * Sprach-Weichen lassen sich nicht einzeln beantworten, sondern nur
 * gegeneinander. Zwei Fehlversuche liegen hinter dieser Funktion:
 *
 * Bis 2.9.3 traf eine Weiche auf „en", sobald Englisch irgendwo in der Liste
 * des Browsers stand – und dort steht es bei fast jedem als Zweitwunsch. Wer
 * Deutsch bevorzugte, landete trotzdem auf der englischen Seite.
 *
 * Danach zählte nur noch die bevorzugte Sprache. Damit landete ein Student mit
 * chinesischem Browser und Englisch als Zweitsprache auf der **deutschen**
 * Seite – obwohl die englische für ihn die bessere gewesen wäre.
 *
 * Beides lässt sich nur auflösen, wenn bekannt ist, welche Sprache das
 * Hauptziel spricht. Steht sie am Link, wird richtig verhandelt: Die
 * Sprachwünsche des Browsers werden der Reihe nach durchgegangen, und der
 * erste, der entweder das Hauptziel oder eine Weiche trifft, gewinnt.
 *
 *   Hauptziel de, Weiche en …
 *   · Browser de, en   → de trifft zuerst  → Hauptziel (deutsch)
 *   · Browser zh, en   → zh trifft nichts, dann en → Weiche (englisch)
 *   · Browser en       → en trifft         → Weiche (englisch)
 *   · Browser fr       → nichts trifft     → Hauptziel
 *
 * Ohne Angabe der Zielsprache bleibt es bei der strengen Regel: Nur wer die
 * Sprache bevorzugt, wird umgeleitet. Das ist der sichere Rückfall – lieber
 * jemand bleibt auf dem Hauptziel, als dass eine Weiche alle einsammelt.
 *
 * @param array<int,array> $regeln alle Weichen des Links
 * @param string $zielsprache Sprache des Hauptziels, leer = unbekannt
 */
function route_lang_gewinner(array $regeln, string $zielsprache): ?string
{
    $weichen = [];
    foreach ($regeln as $r) {
        if ((string)($r['wenn'] ?? '') === 'lang') $weichen[] = strtolower((string)($r['ist'] ?? ''));
    }
    if ($weichen === []) return null;

    $ziel = strtolower(trim($zielsprache));
    if ($ziel === '') {
        $bevorzugt = route_sprache();
        return $bevorzugt !== null && in_array($bevorzugt, $weichen, true) ? $bevorzugt : null;
    }
    foreach (route_sprachen() as $s) {
        if ($s === $ziel) return null;                        // Hauptziel gewinnt
        if (in_array($s, $weichen, true)) return $s;          // diese Weiche gewinnt
    }
    return null;
}

function route_target(array $link): array
{
    $regeln = array_values((array)($link['rules'] ?? []));
    // Sprach-Weichen werden nicht einzeln geprüft, sondern gegeneinander –
    // siehe route_lang_gewinner(). Null heißt: keine von ihnen greift.
    $sprachSieger = route_lang_gewinner($regeln, (string)($link['lang'] ?? ''));
    foreach ($regeln as $i => $r) {
        $wenn = (string)($r['wenn'] ?? '');
        $ist = (string)($r['ist'] ?? '');
        $trifft = $wenn === 'lang'
            ? ($sprachSieger !== null && $sprachSieger === strtolower($ist))
            : route_trifft($wenn, $ist);
        if ($trifft) {
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
        // Nur die bevorzugte Sprache zählt – siehe route_sprache(). „en"
        // trifft weiterhin auch bei „en-GB", weil auf zwei Buchstaben gekürzt
        // wird; ein Zweitwunsch löst aber nichts mehr aus.
        'lang' => route_sprache() === $ist,
        'country' => route_land() === strtolower($ist),
        // A/B: Der Würfel fällt je Aufruf neu. Wiedererkennung wäre die
        // sauberere Statistik – derselbe Mensch sähe immer dieselbe Variante –,
        // kostet aber genau das, was dieses Projekt nicht ausgibt: eine
        // Markierung, die den Besucher über Aufrufe hinweg identifiziert. Also
        // Zufall ohne Gedächtnis. Über viele Aufrufe stimmt das Verhältnis, und
        // um mehr geht es bei einem Split nicht.
        'split' => random_int(1, 100) <= max(1, min(99, (int)$ist)),
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
    // Nach Gewicht sortieren, nicht nach Reihenfolge: Die Reihenfolge im
    // Header ist üblicherweise schon absteigend, verlangt ist es aber nicht.
    // Ohne q gilt 1.0 – das ist der Normalfall für die erste Angabe.
    $mit = [];
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $i => $teil) {
        $stuecke = explode(';', $teil);
        $code = substr(strtolower(trim($stuecke[0])), 0, 2);
        if (preg_match('/^[a-z]{2}$/', $code) !== 1) continue;
        $q = 1.0;
        foreach (array_slice($stuecke, 1) as $p) {
            if (preg_match('/^\s*q\s*=\s*([0-9.]+)/i', $p, $m) === 1) $q = (float)$m[1];
        }
        // Bei gleichem Gewicht entscheidet die Reihenfolge im Header
        if (!isset($mit[$code]) || $q > $mit[$code][0]) $mit[$code] = [$q, $i];
    }
    uasort($mit, fn($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
    $l = array_keys($mit);
    return $l;
}

/**
 * Die bevorzugte Sprache des Browsers – oder null, wenn er keine nennt.
 *
 * Genau diese eine zählt für eine Sprach-Weiche. Bis 2.9.3 traf eine Weiche
 * auf „en", sobald Englisch irgendwo in der Liste stand – und dort steht es
 * bei fast jedem Browser als Zweitwunsch. Wer Deutsch bevorzugte, landete
 * trotzdem auf der englischen Seite: Die Weiche fing praktisch alle ab, statt
 * die englischsprachigen auszusortieren.
 */
function route_sprache(): ?string
{
    $l = route_sprachen();
    return $l === [] ? null : $l[0];
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
        if ($w === 'split') {
            if (preg_match('/^\d{1,2}$/', $wert) !== 1 || (int)$wert < 1 || (int)$wert > 99) {
                return [t('Der Anteil ist eine Zahl zwischen 1 und 99 (Prozent).'), []];
            }
        } elseif ($w !== 'device' && preg_match('/^[a-z]{2}$/i', $wert) !== 1) {
            return [t('Sprache und Land werden mit zwei Buchstaben angegeben (z. B. „en“ oder „at“).'), []];
        } elseif ($w === 'device' && !isset(route_werte('device')[$wert])) {
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
    if (($r['wenn'] ?? '') === 'split') return $wenn . ' ' . (int)$ist . ' %';
    $werte = route_werte((string)($r['wenn'] ?? ''));
    return $wenn . ' = ' . ($werte[$ist] ?? strtoupper($ist));
}
