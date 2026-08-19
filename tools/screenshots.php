<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Bühnen für die Bildschirmfotos der Erweiterungs-Läden bauen.
 *
 * Die Läden wollen Bilder in 1280×800. Naheliegend wäre, sie zu malen – aber
 * ein gemaltes Bild zeigt, was man verspricht, und nicht, was man ausliefert.
 * Deshalb rendert dieses Werkzeug das **echte** Popup: Es nimmt `popup.html`
 * und `popup.css` aus einem entpackten Paket, setzt genau das, was sonst
 * `popup.js` zur Laufzeit setzt (Sichtbarkeit der Abschnitte, Feldwerte), und
 * stellt es in eine Bühne. Was auf dem Bild steht, steht so auch im Paket.
 *
 *   php tools/screenshots.php --paket=/pfad/zum/entpackten/paket --out=/pfad
 *       [--name="1337.kiwi"] [--instanz=https://1337.kiwi]
 *       [--mono=/pfad/zu/mono.ttf] [--logo=/pfad/zu/logo.png]
 *       [--farbe=#7ABA1C --farbetext=#101408 --farbetief=#507A14]
 *
 * Heraus kommen HTML-Dateien in 1024×640 CSS-Pixeln. Das Rendern selbst macht
 * ein Browser – unter macOS reicht Quick Look, das WebKit benutzt:
 *
 *   qlmanage -t -s 2560 -o . szene-1.html          # ergibt 2560×2560
 *   magick szene-1.html.png -crop 2560x1600+0+0 \
 *          -resize 1280x800 szene-1.png            # der Rest ist Füllung
 *
 * Der Faktor: Quick Look rendert mit 1024 CSS-Pixeln Breite und skaliert auf
 * die angegebene Kantenlänge – 2560 sind also 2,5×, und 640 CSS-Pixel Höhe
 * landen bei 1600. Herunterrechnen auf 1280×800 gibt saubere Kanten.
 */
require_once __DIR__ . '/../inc/zip.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/i', $arg, $m) === 1) $opt[strtolower($m[1])] = $m[2];
}
$paket = rtrim((string)($opt['paket'] ?? dirname(__DIR__) . '/extension'), '/');
$ziel = rtrim((string)($opt['out'] ?? sys_get_temp_dir() . '/flatlink-screens'), '/');
$name = (string)($opt['name'] ?? 'flatlink');
$instanz = rtrim((string)($opt['instanz'] ?? ''), '/');
$monoDatei = (string)($opt['mono'] ?? '');
// Wie in store-build.php – für Instanzen, die nicht 1337.kiwi sind.
$farbe = (string)($opt['farbe'] ?? '');
$farbeText = (string)($opt['farbetext'] ?? '#fff');
$logoDatei = (string)($opt['logo'] ?? '');
// Die Sprache der Bühne. Die Oberfläche im Bild kommt aus _locales/ des
// Pakets – dieselben Texte, die der Browser einsetzen würde. Ein Laden-Eintrag
// auf Englisch braucht englische Bilder, sonst verspricht die Beschreibung
// etwas, was das erste Bildschirmfoto sofort widerlegt.
$sprache = strtolower((string)($opt['sprache'] ?? 'de'));
$nachrichtenDatei = $paket . '/_locales/' . $sprache . '/messages.json';
if (!is_file($nachrichtenDatei)) {
    exit("Für --sprache=$sprache liegt im Paket keine _locales/$sprache/messages.json.\n");
}
$nachrichten = json_decode((string)file_get_contents($nachrichtenDatei), true);
if (!is_array($nachrichten)) exit("$nachrichtenDatei ist kein gültiges JSON.\n");

if (!is_file($paket . '/popup.html') || !is_file($paket . '/popup.css')) {
    exit("In $paket liegen keine popup.html/popup.css.\n");
}
if (!is_dir($ziel) && !@mkdir($ziel, 0700, true)) exit("Zielordner geht nicht: $ziel\n");

$gebrandet = $instanz !== '';
$host = $gebrandet ? (string)parse_url($instanz, PHP_URL_HOST) : 'kurz.example.org';
$basis = $gebrandet ? $instanz : 'https://kurz.example.org';

// ---- Palette -------------------------------------------------------------
// Die gebrandete Bühne trägt die Farben der Instanz, die neutrale bleibt
// neutral. Beide sind hell: Die Läden zeigen die Bilder auf weißem Grund,
// ein dunkles Bild sähe dort aus wie ein Loch.

$p = $gebrandet
    ? ['papier' => '#FAFCF6', 'flaeche' => '#F1F6E9', 'tinte' => '#101408',
       'leise' => '#5A6350', 'linie' => '#DCE4D0', 'signal' => '#7ABA1C', 'tief' => '#507A14']
    : ['papier' => '#FBFCFD', 'flaeche' => '#EFF3F8', 'tinte' => '#10151C',
       'leise' => '#59606B', 'linie' => '#DCE3EB', 'signal' => '#3B6EA8', 'tief' => '#2E5885'];
// Die Signalfarbe darf die Marke sein; die Zeile über der Überschrift ist
// Text und braucht Kontrast auf hellem Grund. Deshalb zwei Werte: Ein
// Kiwi-Grün taugt als Fläche, aber nicht als Schrift auf Papier.
if ($farbe !== '') $p['signal'] = $farbe;
if (($opt['farbetief'] ?? '') !== '') $p['tief'] = (string)$opt['farbetief'];

$monoFace = '';
$monoStack = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
if ($monoDatei !== '' && is_file($monoDatei)) {
    // Eingebettet statt verlinkt: Beim Rendern über Quick Look gibt es kein
    // Wurzelverzeichnis, auf das sich ein relativer Pfad beziehen könnte.
    $monoFace = "@font-face{font-family:'Buehne Mono';font-weight:700;src:url(data:font/ttf;base64,"
        . base64_encode((string)file_get_contents($monoDatei)) . ") format('truetype')}\n";
    $monoStack = "'Buehne Mono', " . $monoStack;
}
// Ohne --logo gilt das Symbol aus dem Paket selbst: Im Bild soll dasselbe
// Zeichen stehen, das der Laden zeigt und das später in der Werkzeugleiste
// klebt – nicht zwei Ersatzbuchstaben.
$logoDatei = ($logoDatei === '' && is_file($paket . '/icons/128.png'))
    ? $paket . '/icons/128.png' : $logoDatei;
$logo = ($logoDatei !== '' && is_file($logoDatei))
    ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoDatei))
    : '';

// ---- Das echte Popup vorbereiten -----------------------------------------

/**
 * Lädt popup.html und macht daran, was sonst popup.js zur Laufzeit macht.
 *
 * @param array<string,mixed> $szene
 * @param array<string,mixed> $nachrichten Inhalt von _locales/<sprache>/messages.json
 */
function seite_html(string $paket, string $datei, array $szene, array $nachrichten): string
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . (string)file_get_contents($paket . '/' . $datei));
    libxml_clear_errors();
    $x = new DOMXPath($doc);

    // Zuerst die Sprache: Im Browser füllt i18n.js die data-i18n-Elemente,
    // hier tun wir dasselbe. Muss VOR den Szenenwerten laufen, damit die das
    // letzte Wort behalten.
    $text = static function (string $schluessel) use ($nachrichten): string {
        return (string)($nachrichten[$schluessel]['message'] ?? '');
    };
    foreach ([['data-i18n', null], ['data-i18n-placeholder', 'placeholder'],
              ['data-i18n-alt', 'alt']] as [$attribut, $ziel]) {
        $treffer = $x->query("//*[@$attribut]");
        if ($treffer === false) continue;
        foreach ($treffer as $n) {
            /** @var DOMElement $n */
            $wert = $text($n->getAttribute($attribut));
            if ($wert === '') continue;
            if ($ziel === null) $n->textContent = $wert;
            else $n->setAttribute($ziel, $wert);
        }
    }

    /** @return DOMElement|null */
    $el = static function (string $id) use ($x) {
        $n = $x->query("//*[@id='" . $id . "']");
        return ($n !== false && $n->length > 0) ? $n->item(0) : null;
    };

    // Sichtbarkeit: genau ein Abschnitt, wie im echten Ablauf
    foreach (['setup', 'kuerzen', 'fertig'] as $abschnitt) {
        $s = $el($abschnitt);
        if ($s === null) continue;
        if ($abschnitt === ($szene['abschnitt'] ?? '')) $s->removeAttribute('hidden');
        else $s->setAttribute('hidden', 'hidden');
    }
    // Die Einstellungsseite trägt den Projektnamen als Überschrift; in einer
    // gebrandeten Fassung gehört dort der Name der Instanz hin.
    if (isset($szene['ueberschrift'])) {
        $h = $x->query('//body/h1');
        if ($h !== false && $h->length > 0) $h->item(0)->textContent = (string)$szene['ueberschrift'];
    }
    foreach ((array)($szene['zeigen'] ?? []) as $id) {
        $n = $el($id);
        if ($n !== null) $n->removeAttribute('hidden');
    }
    foreach ((array)($szene['text'] ?? []) as $id => $wert) {
        $n = $el((string)$id);
        if ($n !== null) $n->textContent = (string)$wert;
    }
    foreach ((array)($szene['wert'] ?? []) as $id => $wert) {
        $n = $el((string)$id);
        if ($n !== null) $n->setAttribute('value', (string)$wert);
    }
    // Beliebige Attribute – gebraucht für die Bildquelle des QR-Codes, der
    // im echten Ablauf von qr.php kommt.
    foreach ((array)($szene['attribut'] ?? []) as $id => $paare) {
        $n = $el((string)$id);
        if ($n === null) continue;
        foreach ((array)$paare as $name => $wert) $n->setAttribute((string)$name, (string)$wert);
    }
    // Aufgeklappte Details, damit das Bild zeigt, dass es sie gibt
    foreach ((array)($szene['offen'] ?? []) as $id) {
        $n = $el((string)$id);
        if ($n !== null) $n->setAttribute('open', 'open');
    }

    $body = $x->query('//body');
    $out = '';
    if ($body !== false && $body->length > 0) {
        foreach ($body->item(0)->childNodes as $kind) {
            if ($kind->nodeName !== 'script') $out .= (string)$doc->saveHTML($kind);
        }
    }
    return $out;
}

/**
 * Bindet ein Stylesheet an einen Container.
 *
 * Nötig, weil die Bühne und das Popup dieselben Namen benutzen – beide haben
 * ein h1, beide setzen Regeln auf body. Ungebunden gewinnt schlicht das
 * spätere Stylesheet, und das Popup zöge die Bühne auf 22rem zusammen.
 */
function css_binden(string $css, string $an): string
{
    $css = (string)preg_replace('#/\*.*?\*/#s', '', $css);
    $out = '';
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
        $auf = strpos($css, '{', $i);
        if ($auf === false) break;
        $kopf = trim(substr($css, $i, $auf - $i));

        // Klammern zählen: @supports & Co. tragen einen ganzen Block in sich
        $tiefe = 0;
        $j = $auf;
        for (; $j < $len; $j++) {
            if ($css[$j] === '{') $tiefe++;
            elseif ($css[$j] === '}') { $tiefe--; if ($tiefe === 0) break; }
        }
        $rumpf = substr($css, $auf + 1, $j - $auf - 1);
        $i = $j + 1;

        if ($kopf !== '' && $kopf[0] === '@') {
            $out .= (stripos($kopf, '@font-face') === 0 || stripos($kopf, '@keyframes') !== false)
                ? $kopf . '{' . $rumpf . "}\n"
                : $kopf . '{' . css_binden($rumpf, $an) . "}\n";
            continue;
        }

        $neu = [];
        foreach (explode(',', $kopf) as $sel) {
            $sel = trim($sel);
            if ($sel === '') continue;
            // :root und body sind im Popup das Fenster selbst – hier der
            // Container. Ein nachgestelltes * bleibt ein Nachfahren-Selektor.
            if ($sel === ':root' || $sel === 'html' || $sel === 'body') $neu[] = $an;
            elseif ($sel === '*') $neu[] = $an . ', ' . $an . ' *';
            else $neu[] = $an . ' ' . $sel;
        }
        if ($neu !== []) $out .= implode(', ', $neu) . '{' . trim($rumpf) . "}\n";
    }
    return $out;
}

/**
 * Ein echter QR-Code als data:-URI – mit dem Encoder der Instanz, nicht
 * nachgezeichnet. Abgescannt führt er dorthin, wohin der Kurzlink daneben
 * führt; das Bild behauptet also nichts, was das Paket nicht kann.
 */
function qr_datenuri(string $ziel): string
{
    require_once dirname(__DIR__) . '/inc/qrlib.php';
    $svg = (new QrRenderer(QrCode::encode($ziel, 1), [
        'size' => 300, 'margin' => 2, 'style' => 'square', 'eye' => 'square',
    ]))->svg();
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// ---- Szenen --------------------------------------------------------------
// Ein echter Fall, keine Blindtexte: eine lange Behörden-Adresse, wie sie
// wirklich zum Kürzen taugt.

$lang = 'https://www.hamburg.de/politik-und-verwaltung/behoerden/'
      . 'behoerde-fuer-stadtentwicklung-und-wohnen/programme/rise-991418';
$kurz = $basis . '/rise-2026';

// Die Texte neben dem Bild. Die Oberfläche IM Bild kommt aus _locales/; das
// hier ist der Werbetext daneben, der nicht im Paket steht und deshalb hier
// zweisprachig liegt.
$tr = static fn(string $de, string $en): string => $sprache === 'en' ? $en : $de;

$wo = $gebrandet
    ? $tr("auf $name", "on $name")
    : $tr('auf deinem eigenen flatlink-Server', 'on your own flatlink server');
$dort = $gebrandet
    ? $tr("bei $name", "at $name")
    : $tr('auf deinem flatlink-Server', 'on your flatlink server');

$szenen = [
    [
        'datei' => '1-kuerzen',
        'eyebrow' => $tr('Browser-Erweiterung', 'Browser extension'),
        'h1' => $tr('Ein Klick, ein Kurzlink.', 'One click, one short link.'),
        'lead' => $tr(
            "Die Seite, auf der du gerade bist – gekürzt $wo, ohne den Umweg über "
            . 'die Verwaltung und ohne fremden Dienst dazwischen.',
            "The page you are on – shortened $wo, without the detour through the "
            . 'admin interface and with no third-party service in between.'),
        'punkte' => [
            $tr('Adresse und Titel stehen schon da', 'Address and title are already there'),
            $tr('Wunsch-Name statt Zufallscode', 'A name you choose instead of a random code'),
            $tr('Schlagwörter und Ablaufdatum, wenn du sie brauchst',
                'Tags and an expiry date when you need them'),
        ],
        'abschnitt' => 'kuerzen',
        'offen' => ['mehr'],
        'text' => ['ziel' => $lang],
        'wert' => ['titel' => $tr('Förderprogramm RISE', 'RISE funding programme'),
                   'code' => 'rise-2026',
                   'tags' => $tr('stadtentwicklung, 2026', 'urban-development, 2026')],
    ],
    [
        'datei' => '2-fertig',
        'eyebrow' => $tr('Ergebnis', 'Result'),
        'h1' => $tr('Kopiert, bevor du den Tab wechselst.', 'Copied before you switch tabs.'),
        'lead' => $tr(
            'Der Kurzlink liegt in der Zwischenablage. Von hier geht es weiter in den '
            . "QR-Designer oder in die Linkverwaltung – beides $dort.",
            'The short link is on the clipboard. From here it goes on to the QR '
            . "designer or to the link overview – both $dort."),
        'punkte' => [
            $tr('Ein Klick kopiert', 'One click copies'),
            $tr('QR-Code zum Abscannen, auch als PNG', 'A QR code to scan, as a PNG too'),
            $tr('Weiter in den Designer: Farben, Formen, Logo, Druckdatei',
                'On to the designer: colours, shapes, logo, print file'),
        ],
        'abschnitt' => 'fertig',
        'zeigen' => ['kopiert', 'qr-block'],
        'text' => ['kurzlink' => preg_replace('#^https?://#', '', $kurz)],
        // Der Code im Bild ist ein echter, mit demselben Encoder erzeugt, den
        // die Instanz benutzt – abgescannt führt er auf den gezeigten Kurzlink.
        'attribut' => ['qr-bild' => ['src' => qr_datenuri($kurz)]],
    ],
    [
        'datei' => '3-schon-gekuerzt',
        'eyebrow' => $tr('Keine Dubletten', 'No duplicates'),
        'h1' => $tr('Merkt, was du schon gekürzt hast.',
                    'Remembers what you have already shortened.'),
        'lead' => $tr(
            'Beim Öffnen fragt die Erweiterung nach, ob es für diese Adresse längst '
            . 'einen Kurzlink gibt. Wenn ja, steht er da – zum Kopieren statt zum '
            . 'zweiten Mal Anlegen.',
            'When it opens, the extension checks whether this address has a short '
            . 'link already. If it does, there it is – to copy instead of creating '
            . 'a second one.'),
        'punkte' => [
            $tr('Kein Wildwuchs aus fünf Links auf dieselbe Seite',
                'No thicket of five links to the same page'),
            $tr('Zählt weiter auf denselben Kurzlink', 'Keeps counting on the same short link'),
            $tr('Kein Zwang: neu anlegen geht trotzdem',
                'No compulsion: creating a new one still works'),
        ],
        'abschnitt' => 'kuerzen',
        'zeigen' => ['schon'],
        'text' => ['ziel' => $lang, 'schon-link' => preg_replace('#^https?://#', '', $kurz)],
        'wert' => ['titel' => $tr('Förderprogramm RISE', 'RISE funding programme')],
    ],
    [
        'datei' => '4-einrichten',
        'eyebrow' => $tr('Einrichten', 'Setting up'),
        'h1' => $gebrandet
            ? $tr('Ein Code, und sie gehört dir.', 'One code, and it is yours.')
            : $tr('Ein Code, und sie weiß, wohin.', 'One code, and it knows where to go.'),
        'lead' => $gebrandet
            ? $tr(
                'Die Adresse steht in dieser Fassung schon fest. Es fehlt nur der '
                . "Zugangsschlüssel – und den holt ein Verbindungscode aus deinem Profil bei $name.",
                'In this build the address is already fixed. All that is missing is the '
                . "API key – and a pairing code fetches it from your profile at $name.")
            : $tr(
                'Auf deinem flatlink-Server einen Verbindungscode erzeugen, hier einfügen: '
                . 'Adresse und Zugangsschlüssel stehen darin. Beides geht auch von Hand.',
                'Generate a pairing code on your flatlink server and paste it here: it holds the '
                . 'address and the API key. Both can also be entered by hand.'),
        'punkte' => [
            $gebrandet
                ? $tr("Spricht ausschließlich mit $name", "Talks to $name and nowhere else")
                : $tr('Spricht nur mit dem Server, den du einträgst',
                      'Talks only to the server you enter'),
            $tr('Der Schlüssel bleibt im lokalen Speicher – nicht in der Synchronisierung',
                'The key stays in local storage – not in browser sync'),
            $tr('Jederzeit in deinem Profil zurückziehbar',
                'Revocable in your profile at any time'),
        ],
        'abschnitt' => 'setup',
        'optionen' => true,
        'ueberschrift' => $name,
        // In der gebrandeten Fassung füllt options.js die Adresse aus der
        // Vorgabe – das Bild muss das zeigen, sonst widerspricht es dem Text
        // daneben.
        'wert' => $gebrandet ? ['instanz' => $instanz] : [],
        // Die Einstellungsseite ist länger als die Bühne hoch ist. Statt sie
        // zu stauchen, endet sie im Anschnitt – wie eine Seite, die weitergeht.
        'anschnitt' => true,
    ],
];

// ---- Bühne ---------------------------------------------------------------

$css = (string)file_get_contents($paket . '/popup.css');
// Die Bühne ist hell; das Popup übernimmt sonst das Schema des rendernden
// Systems und stünde als dunkler Block im hellen Bild.
// Die Breite des Fensterrahmens kommt aus dem Popup selbst – sonst müsste
// man sie zweimal pflegen und sähe es erst am schiefen Bild.
$breite = preg_match('/body\s*\{[^}]*\bwidth:\s*([0-9.]+rem)/', $css, $m) === 1 ? $m[1] : '22rem';
$css = css_binden(str_replace('color-scheme: light dark;', 'color-scheme: light;', $css), '.popup');

$optCss = '';
if (is_file($paket . '/options.html')) {
    $roh = (string)file_get_contents($paket . '/options.html');
    if (preg_match('#<style>(.*?)</style>#s', $roh, $m) === 1) {
        // Die Einstellungsseite ist breiter als das Popup – in der Bühne
        // bekommt sie die Breite des Popups, sonst sprengt sie den Rahmen.
        $optCss = css_binden(str_replace('width: 30rem;', 'width: 22rem;', $m[1]), '.popup');
    }
}

foreach ($szenen as $s) {
    $inhalt = seite_html($paket, empty($s['optionen']) ? 'popup.html' : 'options.html', $s, $nachrichten);

    $anschnitt = empty($s['anschnitt']) ? '' :
        '.fenster{max-height:' . (640 - 2 * 44) . 'px;overflow:hidden;'
        . '-webkit-mask-image:linear-gradient(#000 84%,transparent)}';

    $punkte = '';
    foreach ($s['punkte'] as $t) {
        $punkte .= '<li>' . htmlspecialchars($t) . "</li>\n";
    }

    $html = '<!doctype html>
<html lang="' . htmlspecialchars($sprache) . '"><head><meta charset="utf-8"><title>' . htmlspecialchars($s['h1']) . '</title>
<style>
' . $monoFace . '
/* --- Die Bühne ------------------------------------------------------- */
*{box-sizing:border-box}
html,body{width:1024px;height:640px;margin:0;overflow:hidden}
body{
    position:relative;
    display:grid;
    grid-template-columns:1fr 27rem;
    background:' . $p['papier'] . ';
    color:' . $p['tinte'] . ';
    font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;
}
/* Rechts eine abgesetzte Fläche: der Browser steht auf einem Tisch, der
   Text liegt daneben auf dem Papier. */
.tisch{
    position:relative;
    background:' . $p['flaeche'] . ';
    border-left:1px solid ' . $p['linie'] . ';
    display:flex;align-items:flex-start;justify-content:center;
    padding-top:2.6rem;
    background-image:radial-gradient(' . $p['linie'] . ' 1px, transparent 1px);
    background-size:14px 14px;
}
.text{padding:0 2.6rem 0 3.2rem;align-self:center}
.eyebrow{
    font-family:' . $monoStack . ';font-weight:700;
    font-size:0.7rem;text-transform:uppercase;letter-spacing:0.11em;
    color:' . $p['tief'] . ';margin:0 0 0.5rem;
}
h1{font-size:2.15rem;line-height:1.12;letter-spacing:-0.022em;margin:0 0 0.7rem;text-wrap:balance}
.lead{margin:0 0 1.35rem;color:' . $p['leise'] . ';font-size:1.02rem}
ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:0.45rem}
li{position:relative;padding-left:1.4rem;font-size:0.95rem;color:' . $p['tinte'] . '}
li::before{
    content:"";position:absolute;left:0;top:0.52em;
    width:0.55rem;height:0.55rem;border-radius:2px;background:' . $p['signal'] . ';
}
.marke{
    position:absolute;left:3.2rem;bottom:2.4rem;
    font-family:' . $monoStack . ';font-weight:700;font-size:0.68rem;
    letter-spacing:0.09em;color:' . $p['leise'] . ';text-transform:uppercase;
}

/* --- Der angedeutete Browser ------------------------------------------ */
.fenster{width:calc(' . $breite . ' + 2px);filter:drop-shadow(0 16px 34px rgba(16,20,8,.16))}
.leiste{
    display:flex;align-items:center;gap:0.45rem;
    background:' . $p['papier'] . ';border:1px solid ' . $p['linie'] . ';border-bottom:0;
    border-radius:9px 9px 0 0;padding:0.5rem 0.6rem;
}
.ampel{display:flex;gap:0.28rem;margin-right:0.2rem}
.ampel i{width:0.52rem;height:0.52rem;border-radius:50%;background:' . $p['linie'] . '}
.adresse{
    flex:1;min-width:0;background:' . $p['flaeche'] . ';border-radius:5px;
    padding:0.22rem 0.5rem;font-size:0.66rem;color:' . $p['leise'] . ';
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
/* Das Symbol in der Werkzeugleiste, an dem das Popup hängt – markiert,
   weil genau dieser Klick die ganze Bedienung ist. */
.symbol{
    width:1.5rem;height:1.5rem;border-radius:5px;
    background:' . ($logo !== '' ? 'url(' . $logo . ') center/72% no-repeat, ' : '') . $p['flaeche'] . ';
    outline:2px solid ' . $p['signal'] . ';outline-offset:1px;
    display:flex;align-items:center;justify-content:center;
    font-family:' . $monoStack . ';font-weight:700;font-size:0.7rem;color:' . $p['tief'] . ';
}

/* --- Das echte Popup, unverändert aus dem Paket ------------------------ */
' . $css . '
' . $optCss . '
/* Die neutrale Fassung holt sich die Akzentfarbe des Systems. Das ist im
   Browser richtig, auf einem Bildschirmfoto aber Zufall: Es zeigte sonst die
   Systemfarbe des Rechners, auf dem gebaut wurde. Hier steht deshalb der
   dokumentierte Rückfall aus popup.css – bzw. die Farbe der Instanz. */
.popup{--akzent:' . $p['signal'] . ';--akzentschrift:' . ($farbe !== '' ? $farbeText : '#fff') . '}
' . $anschnitt . '
/* Die Einbettung: im Browser ist das Popup das ganze Fenster, hier sitzt es
   in einem Kasten. Seine Breite bestimmt die des Fensters. */
.popup{border:1px solid ' . $p['linie'] . ';border-radius:0 0 9px 9px;background:#fff}
</style></head>
<body>
<div class="text">
  <p class="eyebrow">' . htmlspecialchars($s['eyebrow']) . '</p>
  <h1>' . htmlspecialchars($s['h1']) . '</h1>
  <p class="lead">' . htmlspecialchars($s['lead']) . '</p>
  <ul>
' . $punkte . '  </ul>
</div>
<p class="marke">' . htmlspecialchars($gebrandet ? $host : $tr('flatlink · quelloffen, AGPL', 'flatlink · open source, AGPL')) . '</p>
<div class="tisch">
  <div class="fenster">
    <div class="leiste">
      <span class="ampel"><i></i><i></i><i></i></span>
      <span class="adresse">' . htmlspecialchars(preg_replace('#^https?://#', '', $lang) ?? '') . '</span>
      <span class="symbol">' . ($logo === '' ? 'fl' : '') . '</span>
    </div>
    <div class="popup">' . $inhalt . '</div>
  </div>
</div>
</body></html>';

    file_put_contents($ziel . '/' . $s['datei'] . '.html', $html);
    printf("  %s.html – %s\n", $s['datei'], $s['h1']);
}

printf("\n%d Bühnen in %s\n", count($szenen), $ziel);
echo "Rendern (macOS): qlmanage -t -s 2560 -o . *.html, dann\n"
   . "magick X.html.png -crop 2560x1600+0+0 -resize 1280x800 X.png\n";
