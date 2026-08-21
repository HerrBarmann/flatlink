<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Sprachschicht.
 *
 * Deutsch ist die Quellsprache und steht als Schlüssel unmittelbar im Code:
 * `t('Anmelden')`. Eine weitere Sprache ist eine Datei unter `inc/lang/`, die
 * ein Wörterbuch zurückgibt – `['Anmelden' => 'Sign in', …]`. Eingestellt wird
 * die Sprache je Instanz über `'language'` in der Konfiguration oder unter
 * *Einstellungen*; sie gilt für die ganze Instanz, nicht je Besucher.
 *
 * **Warum Deutsch als Schlüssel und keine Kennungen wie `login.title`:**
 * Kennungen zwingen jeden, der eine Vorlage liest, zum Nachschlagen in einer
 * zweiten Datei – und dieses Projekt lebt davon, dass man es lesen kann.
 * Der deutsche Text an Ort und Stelle IST die Vorlage. Der Preis: Ändert sich
 * ein deutscher Satz, muss der Schlüssel im Wörterbuch mitziehen, sonst fällt
 * die Stelle auf Deutsch zurück. Genau das ist als Ausfallverhalten gewollt –
 * ein deutscher Satz auf einer englischen Seite ist sichtbar und damit
 * auffindbar; ein leerer String oder eine nackte Kennung wäre beides nicht.
 *
 * Platzhalter laufen über sprintf: `t('Noch %d Versuche', $n)`. Übersetzt wird
 * vor dem Einsetzen, damit das Wörterbuch die Satzstellung der Zielsprache
 * bestimmen kann.
 */

/**
 * Sprache dieser Instanz, zwei Kleinbuchstaben; 'de' ist die Quellsprache.
 *
 * $setzen übersteuert sie für DIESE EINE Anfrage – gedacht für öffentliche
 * Seiten, die ihre Sprache aus dem Browser aushandeln (eine Projektseite,
 * eine Landingpage). Muss vor der ersten Ausgabe stehen; t() bemerkt den
 * Wechsel und lädt das passende Wörterbuch nach.
 *
 * Bewusst kein Speichern in der Sitzung: Der Weiterleitungspfad startet
 * keine, und eine Sprache, die je nach Vorgeschichte anders ausfällt, wäre
 * für Zwischenspeicher und Suchmaschinen ein Ärgernis.
 */
function lang(?string $setzen = null): string
{
    static $l = null;
    if ($setzen !== null && preg_match('/^[a-z]{2}$/', $setzen) === 1) {
        return $l = $setzen;
    }
    if ($l === null) {
        $kandidat = (string)(settings()['language'] ?? '');
        $l = preg_match('/^[a-z]{2}$/', $kandidat) === 1 ? $kandidat : 'de';
    }
    return $l;
}

/**
 * Die vom Browser bevorzugte Sprache, beschränkt auf das, was da ist.
 *
 * Wertet Accept-Language mitsamt Gewichtung aus (`de-AT;q=0.9`). Kennt die
 * Installation keine davon, bleibt es bei $vorgabe.
 */
function lang_aus_browser(string $vorgabe = 'en'): string
{
    $kopf = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($kopf === '') return $vorgabe;
    $wunsch = [];
    foreach (explode(',', $kopf) as $teil) {
        $stueck = explode(';q=', trim($teil));
        $kuerzel = strtolower(substr(trim($stueck[0]), 0, 2));
        if (preg_match('/^[a-z]{2}$/', $kuerzel) !== 1) continue;
        $gewicht = isset($stueck[1]) ? (float)$stueck[1] : 1.0;
        // Beim ersten Vorkommen bleiben: „de,de-AT" nennt dieselbe Sprache
        // zweimal, das zweite Gewicht soll das erste nicht verdrängen.
        if (!isset($wunsch[$kuerzel])) $wunsch[$kuerzel] = $gewicht;
    }
    arsort($wunsch);
    $da = lang_available();
    foreach (array_keys($wunsch) as $kuerzel) {
        if (isset($da[$kuerzel])) return $kuerzel;
    }
    return $vorgabe;
}

/** Welche Sprachen diese Installation mitbringt @return array<string,string> Kürzel => Eigenname */
function lang_available(): array
{
    $out = ['de' => 'Deutsch'];
    foreach (glob(__DIR__ . '/lang/*.php') ?: [] as $datei) {
        $k = basename($datei, '.php');
        if (preg_match('/^[a-z]{2}$/', $k) !== 1) continue;
        // Der Eigenname steht als Eintrag im Wörterbuch selbst
        $w = (array)require $datei;
        $out[$k] = (string)($w['__name__'] ?? strtoupper($k));
    }
    return $out;
}

/**
 * Einen Text in die Sprache der Instanz übersetzen.
 *
 * Ohne Wörterbucheintrag bleibt der deutsche Text stehen. Weitere Argumente
 * werden per vsprintf eingesetzt – nur dann, damit ein Prozentzeichen in
 * argumentlosen Texten kein Formatzeichen ist.
 */
function t(string $text, ...$args): string
{
    static $woerterbuch = null;
    static $geladenFuer = null;
    // Auch die geladene Sprache merken: Eine Seite darf sie je Anfrage
    // übersteuern (siehe lang()), und dann muss das Wörterbuch mitziehen.
    if ($woerterbuch === null || $geladenFuer !== lang()) {
        $geladenFuer = lang();
        $datei = __DIR__ . '/lang/' . $geladenFuer . '.php';
        $woerterbuch = $geladenFuer !== 'de' && is_file($datei) ? (array)require $datei : [];
    }
    $uebersetzt = (string)($woerterbuch[$text] ?? $text);
    return $args === [] ? $uebersetzt : vsprintf($uebersetzt, $args);
}

/**
 * Übersetzungen für die Skripte im Browser.
 *
 * Die CSP verbietet ausführbare Inline-Skripte; ein JSON-Datenblock ist keins
 * und bleibt erlaubt. Die Skripte lesen ihn über seine id und schlagen ihre
 * Texte dort nach – fehlt der Block (deutsche Instanz), bleibt es beim
 * deutschen Text im Skript. Ausgegeben wird nur, was Skripte auch benutzen.
 */
function lang_js(): string
{
    if (lang() === 'de') return '';
    $texte = [
        // assets/passkey.js
        'Dieser Browser kennt keine Passkeys.',
        'Warte auf dein Gerät …',
        'Folge der Abfrage deines Geräts …',
        'Abgebrochen oder zu lange gewartet.',
        'Dieses Gerät ist hier bereits hinterlegt.',
        'Passkeys brauchen eine gesicherte Verbindung (HTTPS).',
        'Es hat nicht geklappt.',
        // assets/app.js
        'Kopiert',
        // assets/qrdesign.js
        'Scan mich!',
    ];
    $map = [];
    foreach ($texte as $satz) {
        $u = t($satz);
        if ($u !== $satz) $map[$satz] = $u;
    }
    if ($map === []) return '';
    return '<script type="application/json" id="lang-js">'
        . json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
}
