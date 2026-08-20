<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft, WANN die Anmeldung einen Passkey vorschlägt – und wann nicht.
 *
 * Ein Vorschlag, den man wegklickt und der am nächsten Tag wieder dasteht, ist
 * kein Vorschlag mehr, sondern eine Tür, die klemmt. An dieser Entscheidung
 * hängt deshalb mehr, als sie aussieht: der Abstand von einem Monat, das
 * endgültige Nein, und die Einstellung, mit der ein Betreiber das Angebot auf
 * lokale Konten begrenzen oder ganz abschalten kann.
 *
 * Aufruf: php tests/passkey-hinweis.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

// Auf der Kommandozeile rät base_url() aus dem Skriptpfad – aus tests/ wird
// dabei „http://localhosttests", und webauthn_possible() sagt zu Recht Nein.
// Die Prüfung gilt der Entscheidungsregel, nicht dieser Kuriosität; also
// bekommt sie eine Umgebung, wie eine echte Anfrage sie mitbringt.
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

$_SESSION = [];

// Unterprozess: Beantwortet für EINE Einstellung, ob gefragt würde. settings()
// hält seinen Stand in einer statischen Variablen – im Betrieb gilt je Anfrage
// genau eine Einstellung, und genauso wird hier geprüft.
if (($argv[1] ?? '') !== '') {
    echo passkey_hint_due($argv[1]) ? "ja\n" : "nein\n";
    exit;
}

$fehler = 0;
function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? " – $detail" : '');
}

echo "Wann schlägt die Anmeldung einen Passkey vor?\n\n";

if (!webauthn_possible()) exit("Unerwartet: webauthn_possible() sagt Nein trotz localhost.\n");

$name = 'test-pk-hinweis';
if (user_get($name) === null) {
    $err = user_add($name, 'Pruef-Passwort-123!', 'user');
    if ($err !== null) exit("Konto ließ sich nicht anlegen: $err\n");
}
// Ein zweites Konto, das aus dem Verzeichnis stammt – für die Einstellung
// „nur lokalen Konten".
$extern = 'test-pk-hinweis-ldap';
if (user_get($extern) === null) {
    $err = user_add($extern, 'Pruef-Passwort-123!', 'user');
    if ($err !== null) exit("Konto ließ sich nicht anlegen: $err\n");
}
users_update(function (array $users) use ($extern) {
    $users[$extern]['auth'] = 'ldap';
    unset($users[$extern]['pass']);
    return $users;
}, $extern);

$sicherung = settings();

/** Das Feld am Konto direkt setzen – schneller als 30 Tage warten. */
function stand(string $name, string $wert): void
{
    users_update(function (array $users) use ($name, $wert) {
        $users[$name]['pk_hint'] = $wert;
        return $users;
    }, $name);
}

// ---- Der Rhythmus ---------------------------------------------------------

pruefe('Frisches Konto ohne Passkey: wird gefragt', passkey_hint_due($name) === true);

passkey_hint_seen($name);
pruefe('Direkt danach nicht noch einmal', passkey_hint_due($name) === false);

stand($name, date('c', time() - 29 * 86400));
pruefe('Nach 29 Tagen noch nicht', passkey_hint_due($name) === false);

stand($name, date('c', time() - 31 * 86400));
pruefe('Nach einem Monat wieder', passkey_hint_due($name) === true);

// ---- Das endgültige Nein --------------------------------------------------

passkey_hint_seen($name, true);
pruefe('„Nicht mehr fragen“ steht als „nie“ am Konto', (user_get($name)['pk_hint'] ?? '') === 'nie');
pruefe('… und hält, egal wie lange es her ist', passkey_hint_due($name) === false);

// ---- Wer schon einen hat, wird nicht gefragt ------------------------------

stand($name, '');
users_update(function (array $users) use ($name) {
    $users[$name]['passkeys'] = [[
        'id' => 'AAAA', 'pubkey' => '', 'alg' => -7, 'sign_count' => 0,
        'label' => 'Prüfgerät', 'created' => date('c'), 'last_used' => null,
    ]];
    return $users;
}, $name);
pruefe('Mit vorhandenem Passkey kein Vorschlag', passkey_hint_due($name) === false);

users_update(function (array $users) use ($name) {
    unset($users[$name]['passkeys']);
    return $users;
}, $name);
pruefe('Ohne ihn wieder', passkey_hint_due($name) === true);

// ---- Ein kaputtes Datum sperrt niemanden aus ------------------------------

stand($name, 'kein Datum');
pruefe('Unlesbarer Stand gilt als „lange her“, nicht als Sperre',
    passkey_hint_due($name) === true);
stand($name, '');

// ---- Die Einstellung ------------------------------------------------------

$php = PHP_BINARY;
$hier = escapeshellarg(__FILE__);
foreach ([
    ['on',    $name,  'ja',   'an: lokales Konto bekommt den Vorschlag'],
    ['on',    $extern, 'ja',   'an: auch ein Verzeichniskonto'],
    ['local', $name,  'ja',   'nur lokal: lokales Konto bekommt ihn'],
    ['local', $extern, 'nein', 'nur lokal: Verzeichniskonto bleibt außen vor'],
    ['off',   $name,  'nein', 'aus: niemand'],
    ['off',   $extern, 'nein', 'aus: auch kein Verzeichniskonto'],
    ['',      $name,  'ja',   'leerer Wert gilt als „an“ (Instanz ohne die Option)'],
] as [$wert, $konto, $erwartet, $titel]) {
    settings_save(['passkey_hint' => $wert] + $sicherung);
    $out = trim((string)shell_exec("$php $hier " . escapeshellarg($konto)));
    pruefe($titel, $out === $erwartet, $out !== $erwartet ? "bekam „$out“" : '');
}

// ---- Aufräumen ------------------------------------------------------------

settings_save($sicherung);
user_delete($name);
user_delete($extern);
pruefe('Testkonten wieder entfernt', user_get($name) === null && user_get($extern) === null);

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
