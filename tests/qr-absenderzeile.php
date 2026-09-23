<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft, WER über die Absenderzeile auf einem QR-Code entscheidet.
 *
 * Die Regel hat zwei Hälften, und bis 5.4.2 fehlte die zweite:
 *
 *   Kurzlink  – der BESITZER des Links. Entschiede der Aufrufer, ließe sich
 *               die Zeile abstreifen, indem ein anderes Konto das Bild holt.
 *   statisch  – der AUFRUFER, denn WLAN-, Kontakt-, Termin- und GS1-Codes und
 *               der Designer „ohne Kürzen" hängen an keinem Konto.
 *
 * Der Fehler: qr.php stellte für BEIDE Fälle die Besitzerfrage. Bei einem
 * statischen Code gibt es keinen Besitzer, die Prüfung fiel auf null und damit
 * immer auf „Zeile drauf" – auch bei einem Konto, das genau für deren Wegfall
 * bezahlt, und selbst beim Admin.
 *
 * Geprüft wird die REGEL (wer darf sie weglassen), nicht der Text: OB eine
 * Instanz überhaupt eine Zeile führt, ist `qr_brand_text` und eine andere
 * Frage. Nur deshalb kommt dieser Test ohne Griff in die Konfiguration aus.
 *
 * Aufruf: php tests/qr-absenderzeile.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/qrpanel.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}

const AB_FREI  = 'ab-frei';       // Konto ohne das Recht
const AB_ZAHLT = 'ab-zahlt';      // Konto MIT dem Recht (Gruppe)
const AB_CHEF  = 'ab-chef';       // Admin – darf qua Rolle alles
const AB_GRP   = 'ab-ohnezeile';

// ---- Aufbau ---------------------------------------------------------------

foreach ([AB_FREI, AB_ZAHLT, AB_CHEF] as $k) user_delete($k);
group_delete(AB_GRP);

group_save(AB_GRP, 'Ohne Zeile', ['qr_unbranded']);
user_add(AB_FREI,  'Pruef-Passwort-123!', 'user');
user_add(AB_ZAHLT, 'Pruef-Passwort-123!', 'user');
user_add(AB_CHEF,  'Pruef-Passwort-123!', 'admin');
user_set_groups(AB_ZAHLT, [AB_GRP]);

// ---- 1. Kurzlink: der Besitzer entscheidet --------------------------------
echo "\nKurzlink – der Besitzer entscheidet\n";

pruefe('Link eines gewöhnlichen Kontos trägt die Zeile',
    qr_ohne_absenderzeile('link', AB_FREI, null) === false);
pruefe('Link eines Kontos mit dem Recht trägt sie nicht',
    qr_ohne_absenderzeile('link', AB_ZAHLT, null) === true);
pruefe('Link eines Admins trägt sie nicht',
    qr_ohne_absenderzeile('link', AB_CHEF, null) === true);

// Das ist die Hälfte, die absichtlich NICHT am Aufrufer hängt: Sonst holte
// man das Bild eines fremden Links mit dem eigenen Pro-Konto und hätte die
// Zeile los.
pruefe('… und ein Pro-Aufrufer streift die Zeile eines fremden Links NICHT ab',
    qr_ohne_absenderzeile('link', AB_FREI, AB_ZAHLT) === false);
pruefe('… auch ein Admin nicht',
    qr_ohne_absenderzeile('link', AB_FREI, AB_CHEF) === false);
pruefe('Herrenloser Link (anonym angelegt) trägt sie immer',
    qr_ohne_absenderzeile('link', null, AB_CHEF) === false);

// ---- 2. Statischer Code: der Aufrufer entscheidet -------------------------
//
// Hier saß der Fehler. Ohne Besitzer gab es niemanden zu fragen, und die
// Antwort war immer „Zeile drauf".
echo "\nStatischer Code – der Aufrufer entscheidet\n";

foreach (['wlan', 'vcard', 'termin', 'gs1', 'text'] as $typ) {
    pruefe(sprintf('%-6s Admin bekommt KEINE Zeile', $typ),
        qr_ohne_absenderzeile($typ, null, AB_CHEF) === true);
}
pruefe('Konto mit dem Recht bekommt keine Zeile',
    qr_ohne_absenderzeile('wlan', null, AB_ZAHLT) === true);
pruefe('Gewöhnliches Konto bekommt die Zeile',
    qr_ohne_absenderzeile('wlan', null, AB_FREI) === false);
pruefe('Gast bekommt die Zeile',
    qr_ohne_absenderzeile('wlan', null, null) === false);

// Ein Besitzer ist bei statischen Typen bedeutungslos – es gibt keinen.
// Sollte doch einer durchgereicht werden, darf er die Antwort nicht drehen.
pruefe('Ein durchgereichter Besitzer ändert bei statischen Typen nichts',
    qr_ohne_absenderzeile('wlan', AB_ZAHLT, AB_FREI) === false);

// ---- Aufräumen ------------------------------------------------------------
foreach ([AB_FREI, AB_ZAHLT, AB_CHEF] as $k) user_delete($k);
group_delete(AB_GRP);
pruefe('Prüfkonten und -gruppe wieder entfernt',
    user_get(AB_FREI) === null && user_get(AB_ZAHLT) === null
    && user_get(AB_CHEF) === null && group_get(AB_GRP) === null);

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Pruefung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
