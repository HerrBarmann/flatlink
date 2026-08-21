<?php
declare(strict_types=1);

// Nur auf der Kommandozeile – wie alle Werkzeuge hier. Drittes Review in
// Folge mit demselben Hinweis; jetzt steht er drin.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Gemeinsame Grundlage der beiden Wege, die Erweiterung zu packen: welche
 * Dateien hineingehören und wie die Sprachdateien auf eine Instanz lauten.
 *
 * Gebraucht von `tools/store-build.php`, dem einzigen Bauweg. Eine Zeitlang
 * gab es einen zweiten – die Instanz konnte selbst packen –, und die beiden
 * liefen prompt auseinander: Beim Lokalisieren bekam nur der eine `i18n.js`
 * mit. Der zweite Weg ist entfallen, die Trennung hier bleibt: Sie hält den
 * Bau lesbar und macht die Markenregeln prüfbar.
 *
 * Ersetzt werden zwei Dinge: der Produktname und die Stellen, an denen die
 * neutrale Fassung „deinem flatlink-Server" sagt. Wer eine Sprache ergänzt,
 * ergänzt hier eine Regel – fehlt sie, bricht der Bau ab. Das ist Absicht:
 * Eine Fassung, die unter fremdem Namen weiter „dein flatlink-Server" sagt,
 * fällt niemandem auf, bis ein Nutzer sie liest.
 */

/**
 * Welche Dateien in jedes Paket gehören, in dieser Reihenfolge.
 *
 * Stand einmal doppelt, in zwei Bauwegen. Als beim Lokalisieren `i18n.js`
 * dazukam, wurde nur die eine Liste ergänzt; der andere Weg baute daraufhin
 * Pakete, in denen popup.html ein Skript lud, das nicht mitkam – alle Texte
 * blieben leer. Den zweiten Weg gibt es nicht mehr; die Liste bleibt an einer
 * Stelle.
 *
 * @return string[]
 */
function ext_paketdateien(): array
{
    return ['popup.html', 'popup.css', 'i18n.js', 'popup.js', 'options.html', 'options.js'];
}

/**
 * Suchen/Ersetzen je Sprache. Längeres zuerst – sonst frisst das Kürzere es
 * auf. NAME ist der Platzhalter für den Namen der Instanz.
 *
 * @return array<string,array{0:string[],1:string[],2:string}>
 */
function ext_marken_regeln(): array
{
    return [
        'de' => [
            ['Adresse deines flatlink-Servers', 'Auf deinem flatlink-Server', 'auf deinem flatlink-Server',
             'deinem eigenen flatlink-Server', 'deinem flatlink-Server', 'diesem Server'],
            ['Adresse', 'Bei NAME', 'bei NAME', 'NAME', 'NAME', 'NAME'],
            'Die geöffnete Seite auf NAME kürzen – ein Klick, fertig. Ohne fremden Dienst dazwischen.',
        ],
        'en' => [
            ['Address of your flatlink server', 'your own flatlink server', 'on your flatlink server',
             'your flatlink server', 'this server'],
            ['Address', 'NAME', 'at NAME', 'NAME', 'NAME'],
            'Shorten the page you are on to NAME – one click, done. No third-party service in between.',
        ],
    ];
}

/**
 * Alle Sprachdateien einer Erweiterungsquelle laden und auf $name umschreiben.
 *
 * @param string $quelle Ordner mit `_locales/`
 * @param string $name   Name der Instanz; leer = neutrale Fassung, nichts wird ersetzt
 * @return array<string,array<string,mixed>> Sprache => Inhalt der messages.json
 */
function ext_locales(string $quelle, string $name = ''): array
{
    $regeln = ext_marken_regeln();
    $out = [];
    foreach (glob($quelle . '/_locales/*/messages.json') ?: [] as $pfad) {
        $sprache = basename(dirname($pfad));
        $texte = json_decode((string)file_get_contents($pfad), true);
        if (!is_array($texte)) {
            throw new RuntimeException("_locales/$sprache/messages.json ist kein gültiges JSON.");
        }
        if ($name !== '') {
            if (!isset($regeln[$sprache])) {
                throw new RuntimeException(
                    "Für _locales/$sprache/ fehlt eine Markenregel in inc/extlocale.php — sonst "
                    . "bliebe die Fassung dort „dein flatlink-Server\" sagen, obwohl sie $name heißt.");
            }
            [$suchen, $ersetzen, $kurz] = $regeln[$sprache];
            $ersetzen = str_replace('NAME', $name, $ersetzen);
            foreach ($texte as &$eintrag) {
                if (!isset($eintrag['message'])) continue;
                $eintrag['message'] = str_replace($suchen, $ersetzen, (string)$eintrag['message']);
            }
            unset($eintrag);
            $texte['extName']['message'] = mb_substr($name, 0, 45);
            $texte['extDescription']['message'] = mb_substr(str_replace('NAME', $name, $kurz), 0, 132);
        }
        $out[$sprache] = $texte;
    }
    return $out;
}

/** Wie die Sprachdateien im Paket stehen sollen (hübsch, UTF-8 unangetastet) */
function ext_locale_json(array $texte): string
{
    return (string)json_encode($texte, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
