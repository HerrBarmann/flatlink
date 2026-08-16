<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Browser-Erweiterung fertig eingerichtet ausliefern.
 *
 * Der Ordner `extension/` enthält die Erweiterung im Rohzustand: Wer sie so
 * lädt, muss Adresse und Zugangsschlüssel von Hand eintragen. Das ist für
 * Entwickler in Ordnung und für alle anderen eine Hürde – zumal der Schlüssel
 * erst im Profil angelegt werden will.
 *
 * Diese Datei baut daraus ein Archiv, in dem alles schon steht: die Adresse
 * dieser Instanz, ihr Name, ihre Symbole und auf Wunsch ein eigens dafür
 * erzeugter Zugangsschlüssel. Laden, fertig.
 *
 * Zum Schlüssel: Er wird **neu** angelegt, nicht wiederverwendet, und trägt
 * eine erkennbare Bezeichnung. Damit lässt er sich im Profil einzeln
 * zurückziehen, ohne dass andere Programme stehenbleiben – und wer das Archiv
 * weitergibt, gibt einen Schlüssel weiter, den er gezielt widerrufen kann.
 * Genau deshalb liegt die Entscheidung beim Herunterladen und nicht in einer
 * Voreinstellung.
 */
require_once __DIR__ . '/zip.php';
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
    $schluessel = (string)(token_create($konto, 'Browser-Erweiterung ' . date('d.m.Y'))['token'] ?? '');
    $roh = json_encode([
        'u' => base_url(true) ?: base_url(),
        't' => $schluessel,
        'n' => (string)cfg('site_name'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // base64url, damit der Code per Doppelklick am Stück markiert wird und
    // in keiner Adresszeile zerbricht
    return 'flc_' . rtrim(strtr(base64_encode((string)$roh), '+/', '-_'), '=');
}

/** Steht der Ordner mit den Quelldateien überhaupt zur Verfügung? */
function ext_available(): bool
{
    return is_file(ext_dir() . '/manifest.json');
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

/**
 * Darf die Instanz das Archiv zum Selbstladen anbieten?
 *
 * Für eine Instanz ohne Store-Eintrag ist es der einzige Weg. Wer in den
 * Läden ist, schaltet es ab: Ein Archiv, das man von Hand entpacken und im
 * Entwicklermodus laden muss, ist neben einem Ein-Klick-Knopf keine
 * Alternative, sondern eine Zumutung – und es aktualisiert sich nie.
 */
function ext_download_erlaubt(): bool
{
    return ext_available() && (bool)(settings()['ext_download'] ?? true);
}

function ext_dir(): string
{
    return dirname(__DIR__) . '/extension';
}

/**
 * Das Archiv bauen.
 *
 * @param string $konto    Für wen der Schlüssel erzeugt wird
 * @param bool   $mitKey   Schlüssel einbetten?
 * @return array{0:string,1:?string} [ZIP-Inhalt, Klartext-Schlüssel oder null]
 */
function ext_build(string $konto, bool $mitKey): array
{
    $zip = new ZipWriter();
    $jetzt = time();
    $basis = base_url(true) ?: base_url();
    $name = (string)cfg('site_name');

    $schluessel = null;
    if ($mitKey) {
        // Eigene Bezeichnung mit Datum: In der Liste im Profil ist damit auf
        // einen Blick zu sehen, welcher Schlüssel zu welchem Browser gehört.
        $schluessel = (string)(token_create($konto, 'Browser-Erweiterung ' . date('d.m.Y'))['token'] ?? '');
    }

    foreach (['popup.html', 'popup.css', 'popup.js', 'options.html', 'options.js'] as $datei) {
        $inhalt = (string)file_get_contents(ext_dir() . '/' . $datei);
        if ($datei === 'popup.js' || $datei === 'options.js') {
            $inhalt = ext_vorbelegen($inhalt, $basis, $schluessel);
        }
        $zip->add($datei, $inhalt, $jetzt);
    }

    $zip->add('manifest.json', ext_manifest($name, $basis), $jetzt);
    foreach (ext_icons() as $groesse => $png) {
        $zip->add('icons/' . $groesse . '.png', $png, $jetzt);
    }
    $zip->add('README.txt', ext_anleitung($name, $basis, $schluessel !== null), $jetzt);

    return [$zip->build(), $schluessel];
}

/**
 * Adresse und Schlüssel in die Skripte schreiben.
 *
 * Beide Dateien lesen ihre Einstellungen über chrome.storage. Statt den Code
 * umzubauen, wird eine Vorbelegung vorangestellt: Fehlt ein Wert im Speicher,
 * tritt der eingebaute an seine Stelle. Damit bleibt die Erweiterung
 * identisch mit der aus dem Ordner – und der Nutzer kann in den Einstellungen
 * trotzdem alles ändern.
 */
function ext_vorbelegen(string $js, string $basis, ?string $schluessel): string
{
    $vor = "// Von " . addslashes((string)cfg('site_name')) . " vorbereitet: Adresse"
        . ($schluessel !== null ? " und Zugangsschlüssel" : "") . " stehen schon drin.\n"
        . "// Änderbar bleibt beides in den Einstellungen der Erweiterung.\n"
        . "const VORGABE = {\n"
        . "    instanz: " . json_encode($basis, JSON_UNESCAPED_SLASHES) . ",\n"
        . "    token: " . json_encode((string)$schluessel, JSON_UNESCAPED_SLASHES) . ",\n"
        . "};\n\n";

    // In beiden Dateien wird chrome.storage.local.get benutzt; die Vorgabe
    // greift genau dort, wo ein Wert fehlt.
    $js = str_replace(
        "const d = await chrome.storage.local.get(['instanz', 'token', 'pfad']);",
        "const d = await chrome.storage.local.get(['instanz', 'token', 'pfad']);\n"
        . "    if (!d.instanz) d.instanz = VORGABE.instanz;\n"
        . "    if (!d.token) d.token = VORGABE.token;",
        $js
    );
    $js = str_replace(
        "const d = await chrome.storage.local.get(['instanz', 'token', 'konto']);",
        "const d = await chrome.storage.local.get(['instanz', 'token', 'konto']);\n"
        . "    if (!d.instanz) d.instanz = VORGABE.instanz;\n"
        . "    if (!d.token) d.token = VORGABE.token;",
        $js
    );
    return $vor . $js;
}

/** Das Manifest mit dem Namen dieser Instanz */
function ext_manifest(string $name, string $basis): string
{
    $roh = json_decode((string)file_get_contents(ext_dir() . '/manifest.json'), true);
    if (!is_array($roh)) $roh = [];

    $roh['name'] = mb_substr($name, 0, 45);
    $roh['description'] = mb_substr(t('Die geöffnete Seite auf %s kürzen – ein Klick, fertig.', $name), 0, 132);
    $roh['homepage_url'] = $basis;
    // Der Host steht fest, also darf die Berechtigung fest stehen: Die
    // vorbereitete Fassung fragt nicht nach „allen Seiten", sondern nur nach
    // dieser einen Adresse. Das ist der eigentliche Gewinn gegenüber der
    // Fassung zum Selbsteinrichten.
    unset($roh['optional_host_permissions']);
    $roh['host_permissions'] = [rtrim($basis, '/') . '/*'];
    // Eine eigene Kennung je Instanz, sonst hält Firefox zwei vorbereitete
    // Erweiterungen für dieselbe.
    $roh['browser_specific_settings']['gecko']['id'] =
        'flatlink-' . substr(hash('sha256', $basis), 0, 12) . '@instanz';

    return (string)json_encode($roh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Die Symbole der Erweiterung: das Logo der Instanz, sonst die mitgelieferten.
 *
 * Gesucht wird in assets/ nach dem, was die Instanz ohnehin als Symbol führt
 * (`icons`-Konfiguration, dann die üblichen Namen). Ein quadratisches PNG
 * genügt; skaliert wird hier.
 *
 * @return array<int,string> Kantenlänge => PNG-Inhalt
 */
function ext_icons(): array
{
    $quelle = ext_icon_quelle();
    if ($quelle === null || !extension_loaded('gd')) {
        // Rückfall: die Symbole aus dem Ordner, unverändert
        $out = [];
        foreach ([16, 32, 48, 128] as $n) {
            $p = ext_dir() . '/icons/' . $n . '.png';
            if (is_file($p)) $out[$n] = (string)file_get_contents($p);
        }
        return $out;
    }

    $bild = @imagecreatefromstring((string)file_get_contents($quelle));
    if ($bild === false) return ext_icons_standard();

    $out = [];
    $bw = imagesx($bild);
    $bh = imagesy($bild);
    foreach ([16, 32, 48, 128] as $n) {
        $ziel = imagecreatetruecolor($n, $n);
        imagealphablending($ziel, false);
        imagesavealpha($ziel, true);
        imagefill($ziel, 0, 0, imagecolorallocatealpha($ziel, 0, 0, 0, 127));
        imagecopyresampled($ziel, $bild, 0, 0, 0, 0, $n, $n, $bw, $bh);
        ob_start();
        imagepng($ziel, null, 9);
        $out[$n] = (string)ob_get_clean();
    }
    return $out;
}

/** @return array<int,string> */
function ext_icons_standard(): array
{
    $out = [];
    foreach ([16, 32, 48, 128] as $n) {
        $p = ext_dir() . '/icons/' . $n . '.png';
        if (is_file($p)) $out[$n] = (string)file_get_contents($p);
    }
    return $out;
}

/** Ein quadratisches PNG dieser Instanz – oder null */
function ext_icon_quelle(): ?string
{
    $assets = dirname(__DIR__) . '/assets/';
    $kandidaten = [];
    foreach ((array)cfg('icons') as $wert) {
        if (is_string($wert) && str_ends_with(strtolower($wert), '.png')) $kandidaten[] = $wert;
    }
    $ogImage = (string)cfg('og_image');
    if ($ogImage !== '' && str_ends_with(strtolower($ogImage), '.png')) $kandidaten[] = $ogImage;
    // Die üblichen Namen, falls nichts konfiguriert ist
    array_push($kandidaten, 'icon-512.png', 'icon-192.png', 'apple-touch-icon.png', 'favicon-96.png');

    foreach ($kandidaten as $k) {
        $p = $assets . basename($k);
        if (is_file($p)) {
            $masse = @getimagesize($p);
            // Quadratisch muss es sein, sonst wird das Symbol verzerrt
            if ($masse !== false && $masse[0] === $masse[1]) return $p;
        }
    }
    return null;
}

/** Der Zettel im Archiv */
function ext_anleitung(string $name, string $basis, bool $mitKey): string
{
    $text = "$name – Browser-Erweiterung\n"
        . str_repeat('=', mb_strlen($name) + 24) . "\n\n"
        . "Kürzt die geöffnete Seite auf $basis.\n\n";

    $text .= $mitKey
        ? "Adresse und Zugangsschlüssel stehen schon drin. Nach dem Laden ist\n"
        . "die Erweiterung sofort benutzbar.\n\n"
        . "Der Schlüssel gehört zu deinem Konto und steht in deinem Profil\n"
        . "unter „Zugangsschlüssel“. Dort lässt er sich jederzeit zurückziehen –\n"
        . "danach fragt die Erweiterung nach einem neuen. Gib dieses Archiv\n"
        . "deshalb nicht weiter: Wer es hat, kann in deinem Namen Kurzlinks\n"
        . "anlegen.\n\n"
        : "Die Adresse steht schon drin. Beim ersten Öffnen fragt die\n"
        . "Erweiterung nach einem Zugangsschlüssel – den legst du in deinem\n"
        . "Profil unter „Zugangsschlüssel“ an.\n\n";

    return $text
        . "LADEN\n\n"
        . "Chrome, Edge, Brave, Vivaldi:\n"
        . "  1. Archiv entpacken\n"
        . "  2. chrome://extensions öffnen\n"
        . "  3. Entwicklermodus einschalten\n"
        . "  4. „Entpackte Erweiterung laden“ → den entpackten Ordner wählen\n\n"
        . "Firefox:\n"
        . "  1. Archiv entpacken\n"
        . "  2. about:debugging#/runtime/this-firefox öffnen\n"
        . "  3. „Temporäres Add-on laden“ → manifest.json im Ordner wählen\n"
        . "  Firefox vergisst temporäre Add-ons beim Beenden. Dauerhaft geht es\n"
        . "  nur signiert (addons.mozilla.org) oder in ESR/Developer Edition.\n\n"
        . "WAS SIE DARF\n\n"
        . "Die Adresse des Tabs, in dem du auf das Symbol klickst – nur dann.\n"
        . "Verbindung ausschließlich zu $basis. Keine Seiteninhalte, keine\n"
        . "Skripte in Seiten, kein Hintergrundprozess, kein anderer Server.\n";
}
