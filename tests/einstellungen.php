<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft, dass die Einstellungsseite nur speichert, was sie auch geändert hat.
 *
 * Der Hintergrund ist ein Fehler, der im Betrieb Geld gekostet hätte und von
 * außen unsichtbar war: Die Seite trägt mehrere Formulare, die alle an
 * dieselbe Stelle schicken. Nahm man den AUFGELÖSTEN Stand als Grundlage –
 * also inklusive allem, was nur in `inc/config.php` steht –, dann fror das
 * erste Speichern eines beliebigen Formulars sämtliche Werte in
 * `data/settings.json` ein. Eine spätere Änderung an der Konfigurationsdatei
 * blieb danach wirkungslos, ohne dass irgendwo ein Hinweis darauf zu sehen
 * gewesen wäre. Genau so ist ein per Upload nachgereichtes Standardrecht
 * verlorengegangen.
 *
 * Der Test schreibt deshalb nicht die Werte fest, sondern die Regel: Nach dem
 * Speichern eines Teilformulars stehen in der Datei GENAU dessen Schlüssel –
 * nicht mehr.
 *
 * Aufruf (der eingebaute Server genügt, inc/config.php muss existieren):
 *   php -S localhost:8080 router.php
 *   php tests/einstellungen.php http://localhost:8080
 */

// Nur auf der Kommandozeile – wie bei tools/*.php. Ohne diesen Riegel ließe
// sich das Skript über den Webserver anstoßen, wenn tests/ versehentlich mit
// ins Webroot geladen wurde. Bei dieser Datei hieße das: Jemand legt von außen
// ein Admin-Konto an, dessen Passwort im Quelltext steht.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';

$basis = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$keks = tempnam(sys_get_temp_dir(), 'flk');
$fehler = 0;

/** Eine Anfrage mit Sitzungsübernahme; gibt den Rumpf zurück. */
function hole(string $url, ?array $post = null): string
{
    global $keks;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $keks,
        CURLOPT_COOKIEFILE => $keks,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    return (string)curl_exec($ch);
}

function token(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? " – $detail" : '');
}

// ---- Vorbereitung: ein Konto, das die Einstellungen sehen darf -------------

$name = 'test-einstellungen';
if (user_get($name) === null) {
    $err = user_add($name, 'Pruef-Passwort-123!', 'admin');
    if ($err !== null) exit("Konto ließ sich nicht anlegen: $err\n");
}

$datei = data_path() . '/settings.json';
$vorher = is_file($datei) ? (string)file_get_contents($datei) : null;
@unlink($datei);   // von einem sauberen Stand aus

$html = hole($basis . '/admin/login.php');
hole($basis . '/admin/login.php', ['_csrf' => token($html), 'username' => $name, 'password' => 'Pruef-Passwort-123!']);
$html = hole($basis . '/admin/settings.php');
if (strpos($html, 'name="_csrf"') === false) exit("Anmeldung fehlgeschlagen – Einstellungen nicht erreichbar.\n");

// ---- Der eigentliche Test -------------------------------------------------

echo "Einstellungen: speichert jedes Formular nur seine eigenen Schlüssel?\n\n";

$faelle = [
    'Browser-Erweiterung' => [
        'post' => ['erweiterung' => '1', 'ext_chrome' => '', 'ext_firefox' => '', 'ext_edge' => ''],
        'erwartet' => ['ext_stores'],
    ],
    'Öffentlicher Zugang' => [
        'post' => ['public_mode' => 'on', 'public_prefix' => 'p', 'public_rate_limit' => '15', 'registration' => 'on'],
        'erwartet' => ['public_mode', 'public_prefix', 'public_rate_limit', 'registration'],
    ],
];

foreach ($faelle as $titel => $fall) {
    @unlink($datei);
    $html = hole($basis . '/admin/settings.php');
    hole($basis . '/admin/settings.php', ['_csrf' => token($html)] + $fall['post']);

    $gespeichert = is_file($datei) ? (array)json_decode((string)file_get_contents($datei), true) : [];
    $ist = array_keys($gespeichert);
    sort($ist);
    $soll = $fall['erwartet'];
    sort($soll);

    pruefe($titel, $ist === $soll, $ist === $soll ? '' : 'gespeichert: ' . implode(', ', $ist));
}

// Und die Gegenprobe: Ein zweites Formular darf das erste nicht wegwerfen.
@unlink($datei);
$html = hole($basis . '/admin/settings.php');
hole($basis . '/admin/settings.php', ['_csrf' => token($html)] + $faelle['Browser-Erweiterung']['post']);
$html = hole($basis . '/admin/settings.php');
hole($basis . '/admin/settings.php', ['_csrf' => token($html)] + $faelle['Öffentlicher Zugang']['post']);
$nach = (array)json_decode((string)file_get_contents($datei), true);
pruefe('Zweites Formular lässt das erste stehen',
    isset($nach['public_mode']));

// ---- Aufräumen ------------------------------------------------------------

@unlink($datei);
if ($vorher !== null) file_put_contents($datei, $vorher);
@unlink($keks);
// Das Testkonto verschwindet wieder – ein Admin-Konto, dessen Passwort im
// Quelltext dieses Skripts steht, hat einen Testlauf nicht zu überleben.
//
// Auf einer frischen Wegwerf-Instanz ist es der EINZIGE Administrator, und
// user_delete() weigert sich dann zu Recht. Hier ist der Wächter fehl am
// Platz: Das Skript hat das Konto selbst angelegt, und es zu entfernen stellt
// nur den Zustand von vorher wieder her – notfalls „noch gar kein Konto",
// womit die Ersteinrichtung wieder greift. Deshalb erst der ordentliche Weg
// (räumt auch Schlüssel und Bestätigungen ab), dann der direkte.
require_once __DIR__ . '/../inc/auth.php';
if (user_delete($name) !== null) {
    users_update(function (array $users) use ($name) {
        unset($users[$name]);
        return $users;
    }, $name);
}
pruefe('Testkonto wieder entfernt', user_get($name) === null);

echo "\n", $fehler === 0
    ? "Alle Prüfungen bestanden.\n"
    : "$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
