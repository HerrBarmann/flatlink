<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Browser-Erweiterung mit dieser Instanz verbinden.
 *
 * Zwei Dinge stehen hier: die Adressen, unter denen die Erweiterung in den
 * Läden zu finden ist, und der Verbindungscode, mit dem sich eine schon
 * installierte Erweiterung ohne Abtippen einrichtet.
 *
 * Hier stand einmal mehr. Die Instanz konnte die Erweiterung auch selbst
 * packen – mit ihrer Adresse, ihrem Namen, ihren Symbolen und auf Wunsch
 * einem eingebetteten Schlüssel – und als Archiv zum Selbstladen anbieten.
 * Gedacht war das für Instanzen ohne Eintrag in den Läden. Nur steht die
 * neutrale Fassung inzwischen selbst dort: Sie fragt beim ersten Öffnen nach
 * der Adresse, und der Verbindungscode von hier trägt beides in einem Zug
 * ein. Damit war das Archiv der schlechtere Weg zum selben Ziel – von Hand
 * entpacken, im Entwicklermodus laden, und aktualisieren tut es sich nie.
 * Entfallen im August 2026; die Pakete für die Läden baut weiterhin
 * `tools/store-build.php`, und das ist seither der einzige Bauweg.
 *
 * Folge davon: Der Ordner `extension/` wird auf einem Server nicht mehr
 * gebraucht. Er gehört ins Repository, nicht in den Webbereich.
 */
require_once __DIR__ . '/token.php';

/**
 * Ein Verbindungscode für eine schon installierte Erweiterung.
 *
 * Aus den Läden (Chrome Web Store, addons.mozilla.org) kommt die Erweiterung
 * ohne Wissen über diese Instanz – die Adresse dort steht für alle gleich.
 * Statt beides abzutippen, gibt es diesen Code: Adresse und ein frisch
 * erzeugter Schlüssel in einer Zeichenkette, die sich mit einem Klick
 * kopieren und in der Erweiterung mit einem Klick einfügen lässt.
 *
 * Kein Geheimnisträger mehr als der Schlüssel selbst: Der Code ist bloß
 * base64 und verbirgt nichts – er ist eine Transportform, keine
 * Verschlüsselung. Wer ihn weitergibt, gibt den Schlüssel weiter; das steht
 * auch daneben.
 */
function ext_connect_code(string $konto): string
{
    // Die Erweiterung liest (Konto, Limits, Dubletten) und legt an – löschen
    // muss sie nie. Der Code wandert per Zwischenablage und landet mitunter
    // in einem Chatfenster; ein Schlüssel, der damit nichts löschen kann, ist
    // der kleinere Schaden.
    $schluessel = (string)(token_create($konto, 'Browser-Erweiterung ' . date('d.m.Y'),
        TOKEN_SCHREIB)['token'] ?? '');
    $roh = json_encode([
        'u' => base_url(true) ?: base_url(),
        't' => $schluessel,
        'n' => (string)cfg('site_name'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // base64url, damit der Code per Doppelklick am Stück markiert wird und
    // in keiner Adresszeile zerbricht
    return 'flc_' . rtrim(strtr(base64_encode((string)$roh), '+/', '-_'), '=');
}

/**
 * Die Erweiterung in den Läden – Adressen, die wirklich dorthin führen.
 *
 * @return array<string,string> Laden => Adresse, leere Einträge fallen weg
 */
function ext_stores(): array
{
    $out = [];
    foreach ((array)(settings()['ext_stores'] ?? []) as $laden => $url) {
        if (ext_store_gueltig((string)$laden, (string)$url)) $out[$laden] = trim((string)$url);
    }
    return $out;
}

/**
 * Die Läden, die flatlink kennt – Schlüssel und Anzeigename.
 *
 * @return array<string,string>
 */
function ext_laden_namen(): array
{
    return [
        'chrome' => t('Chrome Web Store'),
        'firefox' => t('Firefox-Add-ons'),
        'edge' => t('Edge-Add-ons'),
    ];
}

/**
 * Zeigt diese Adresse wirklich in diesen Laden?
 *
 * Nur https und nur die Häuser, die es gibt. Das Feld steht in der Verwaltung
 * offen, und ein Knopf „Installieren" ist eine Empfehlung – die soll nicht
 * irgendwohin zeigen können.
 */
function ext_store_gueltig(string $laden, string $url): bool
{
    $erlaubt = [
        'chrome' => ['chromewebstore.google.com', 'chrome.google.com'],
        'firefox' => ['addons.mozilla.org'],
        'edge' => ['microsoftedge.microsoft.com'],
    ];
    $url = trim($url);
    if ($url === '' || !isset($erlaubt[$laden])) return false;
    return parse_url($url, PHP_URL_SCHEME) === 'https'
        && in_array(strtolower((string)parse_url($url, PHP_URL_HOST)), $erlaubt[$laden], true);
}
