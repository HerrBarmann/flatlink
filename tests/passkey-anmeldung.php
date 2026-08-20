<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft die Anmeldung mit Passkey OHNE Passwort.
 *
 * Seit der Passkey nicht mehr zweite Stufe, sondern Ersatz fürs Passwort ist,
 * hängt an einem einzigen Bit die ganze Sicherheit des Kontos: Bit 0x04 in den
 * Authenticator-Daten sagt, ob das Gerät geprüft hat, WER es gerade benutzt
 * (Fingerabdruck, Gesicht, PIN) — oder ob bloß jemand darauf getippt hat.
 *
 * Als zweite Stufe genügte das Tippen: Das Passwort war der Wissensnachweis.
 * Als Passwortersatz wäre ein liegengelassenes, entsperrtes Telefon die ganze
 * Anmeldung. Deshalb muss dieselbe Antwort je nach Weg unterschiedlich
 * ausgehen, und genau das steht hier fest.
 *
 * Der Test spielt das Gerät selbst: Er erzeugt ein Schlüsselpaar, trägt den
 * öffentlichen Teil am Konto ein und unterschreibt danach wie ein echter
 * Authenticator. Kein Browser, kein Server, kein Netz nötig.
 *
 * Aufruf: php tests/passkey-anmeldung.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

$_SESSION = [];
$fehler = 0;

function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? " – $detail" : '');
}

// ---- Das Gerät ------------------------------------------------------------

/** Ein frisches Schlüsselpaar, wie es ein Authenticator beim Einrichten anlegt. */
function geraet_anlegen(): array
{
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($k === false) exit("Kein EC-Schlüssel möglich – openssl fehlt?\n");
    return ['priv' => $k, 'pub' => openssl_pkey_get_details($k)['key'], 'id' => b64u_encode(random_bytes(16))];
}

/** Passkey am Konto eintragen – der Weg über CBOR ist hier nicht der Prüfling. */
function geraet_eintragen(string $user, array $g, int $count = 0): void
{
    users_update(function (array $users) use ($user, $g, $count) {
        $users[$user]['passkeys'] = [[
            'id' => $g['id'], 'pubkey' => $g['pub'], 'alg' => -7,
            'sign_count' => $count, 'label' => 'Prüfgerät',
            'created' => date('c'), 'last_used' => null,
        ]];
        return $users;
    }, $user);
}

/**
 * Eine Antwort bauen, wie sie der Browser schickt.
 *
 * $flags: 0x01 = anwesend (getippt), 0x04 = geprüft (Fingerabdruck/PIN).
 */
function antwort(array $g, string $challenge, int $flags = 0x05, int $count = 1, ?string $rpId = null): array
{
    $client = json_encode([
        'type' => 'webauthn.get',
        'challenge' => $challenge,
        'origin' => webauthn_origin(),
    ], JSON_UNESCAPED_SLASHES);
    $authData = hash('sha256', $rpId ?? webauthn_rp_id(), true) . chr($flags) . pack('N', $count);
    openssl_sign($authData . hash('sha256', $client, true), $sig, $g['priv'], OPENSSL_ALGO_SHA256);
    return [
        'id' => $g['id'],
        'clientDataJSON' => b64u_encode($client),
        'authenticatorData' => b64u_encode($authData),
        'signature' => b64u_encode($sig),
    ];
}

// ---- Vorbereitung ---------------------------------------------------------

$a = 'test-passkey-a';
$b = 'test-passkey-b';
foreach ([$a, $b] as $n) {
    if (user_get($n) === null) {
        $e = user_add($n, 'Pruef-Passwort-123!', 'user');
        if ($e !== null) exit("Konto $n ließ sich nicht anlegen: $e\n");
    }
}
$gA = geraet_anlegen();
$gB = geraet_anlegen();
geraet_eintragen($a, $gA);
geraet_eintragen($b, $gB);

echo "Passkey als Passwortersatz\n\n";

// ---- Der Kern: das Bit für die Nutzerprüfung ------------------------------

$c = webauthn_challenge('login');
pruefe('Geprüftes Gerät darf ohne Passwort hinein',
    passkey_verify($a, antwort($gA, $c, 0x05), true) === null);

$c = webauthn_challenge('login');
$err = passkey_verify($a, antwort($gA, $c, 0x01, 2), true);
pruefe('Nur getippt, nicht geprüft: kein Passwortersatz',
    $err !== null && str_contains((string)$err, 'nicht geprüft'), (string)$err);

$c = webauthn_challenge('login');
pruefe('Dasselbe Gerät als ZWEITE Stufe: in Ordnung',
    passkey_verify($a, antwort($gA, $c, 0x01, 3)) === null);

// ---- Was sonst nicht durchkommen darf ------------------------------------

$c = webauthn_challenge('login');
$err = passkey_verify($b, antwort($gA, $c, 0x05, 4), true);
pruefe('Fremdes Gerät am fremden Konto abgewiesen', $err !== null, (string)$err);

$c = webauthn_challenge('login');
$err = passkey_verify($a, antwort($gA, $c, 0x05, 5, 'boese.example'), true);
pruefe('Andere Adresse abgewiesen', $err !== null, (string)$err);

$c = webauthn_challenge('login');
$gut = antwort($gA, $c, 0x05, 6);
pruefe('Erste Vorlage geht durch', passkey_verify($a, $gut, true) === null);
pruefe('Dieselbe Antwort ein zweites Mal abgewiesen', passkey_verify($a, $gut, true) !== null);

$c = webauthn_challenge('login');
$err = passkey_verify($a, antwort($gA, $c, 0x05, 2), true);
pruefe('Rückwärts laufender Zähler abgewiesen',
    $err !== null && str_contains((string)$err, 'kopiert'), (string)$err);

$c = webauthn_challenge('login');
$falsch = antwort($gA, $c, 0x05, 9);
$falsch['signature'] = b64u_encode(random_bytes(70));
pruefe('Erfundene Unterschrift abgewiesen', passkey_verify($a, $falsch, true) !== null);

// ---- Der Weg ohne vorher bekannte Kennung --------------------------------

$handle = passkey_user_handle($a);
pruefe('Konto über das Geräte-Handle gefunden', passkey_user_by_handle($handle) === $a);
pruefe('Erfundenes Handle findet nichts', passkey_user_by_handle('AAAAAAAAAAAAAAAAAAAAAA') === null);
pruefe('Leeres Handle findet nichts', passkey_user_by_handle('') === null);

// Ein Konto ohne Passkey darf über diesen Weg nicht auftauchen: Der Vorschlag
// im Namensfeld gibt es sonst als „hat einen Passkey" aus, obwohl es keinen hat.
$hB = passkey_user_handle($b);
users_update(function (array $users) use ($b) { unset($users[$b]['passkeys']); return $users; }, $b);
pruefe('Konto ohne Passkey wird über sein Handle nicht gefunden',
    passkey_user_by_handle($hB) === null);

// ---- Aufräumen ------------------------------------------------------------

foreach ([$a, $b] as $n) user_delete($n);
pruefe('Testkonten wieder entfernt', user_get($a) === null && user_get($b) === null);

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
