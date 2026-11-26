<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft, dass die zweistufige Anmeldemaske nicht verrät, welche Konten es
 * gibt (Review 4.2.0, F2).
 *
 * Die Auflösung der Eingabe – Schreibweise normalisieren, E-Mail auf den
 * Kontonamen abbilden – wird serverseitig gebraucht und darf trotzdem nie
 * zurück auf den Bildschirm: Sonst ist Schritt 2 ein Orakel („dieses Konto
 * gibt es", „diese Adresse gehört zu Kennung X"). Hier steht fest: Angezeigt
 * wird immer die Roheingabe, und intern funktioniert die Auflösung weiter.
 *
 * Aufruf (eingebauter Server genügt):
 *   php -S localhost:8080 router.php
 *   php tests/anmeldeorakel.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }
require_once __DIR__ . '/../inc/auth.php';

$basis = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$fehler = 0;

function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? " – $detail" : '');
}

/** Schritt 1 absenden und die Maske von Schritt 2 zurückgeben (frische Sitzung). */
function schritt2(string $basis, string $eingabe): string
{
    $keks = tempnam(sys_get_temp_dir(), 'flk');
    $hole = function (string $url, ?array $post = null) use ($keks): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $keks,
            CURLOPT_COOKIEFILE => $keks, CURLOPT_TIMEOUT => 10]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        return (string)curl_exec($ch);
    };
    $html = $hole($basis . '/admin/login.php');
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
    $hole($basis . '/admin/login.php', ['_csrf' => $m[1] ?? '', 'schritt' => 'kennung', 'username' => $eingabe]);
    $seite = $hole($basis . '/admin/login.php');
    @unlink($keks);
    return $seite;
}

// ---- Vorbereitung: ein Konto mit bekannter E-Mail-Adresse ------------------

$name = 'test-orakel';
if (user_get($name) === null) {
    $err = user_add($name, 'Pruef-Passwort-123!', 'user');
    if ($err !== null) exit("Konto ließ sich nicht anlegen: $err\n");
}
users_update(function (array $users) use ($name) {
    $users[$name]['email'] = 'orakel@example.org';
    return $users;
}, $name);

echo "Verrät die Anmeldemaske, welche Konten es gibt?\n\n";

// ---- Die beiden Orakel aus dem Review --------------------------------------

$seite = schritt2($basis, 'TEST-ORAKEL');
pruefe('Andere Schreibweise: angezeigt wird die Eingabe, nicht das Konto',
    str_contains($seite, 'TEST-ORAKEL') && !preg_match('/>test-orakel</', $seite));

$seite = schritt2($basis, 'orakel@example.org');
pruefe('E-Mail-Adresse: der interne Kontoname bleibt unsichtbar',
    str_contains($seite, 'orakel@example.org') && !str_contains($seite, 'test-orakel'));

// ---- Unbekannt und bekannt müssen gleich aussehen --------------------------

$bekannt = schritt2($basis, 'test-orakel');
$unbekannt = schritt2($basis, 'konto-das-es-nie-gab');
$form = fn(string $s) => preg_match_all('/<(form|label|input|button)\b/', $s);
pruefe('Bekannt und unbekannt zeigen dieselbe Maske (gleiche Formularstruktur)',
    $form($bekannt) === $form($unbekannt));
pruefe('Auch die unbekannte Eingabe wird wortgleich zurückgezeigt',
    str_contains($unbekannt, 'konto-das-es-nie-gab'));

// ---- Ein Passkey ändert an der Seite nichts (seit 5.6) ---------------------
//
// Bis 5.5 bekamen nur Konten mit Passkey den Knopf – und damit war an Schritt 2
// abzulesen, wer einen hinterlegt hatte. Jetzt steht er bei jeder Kennung.

$mitPk = 'test-orakel-pk';
if (user_get($mitPk) === null) user_add($mitPk, 'Pruef-Passwort-123!', 'user');
users_update(function (array $users) use ($mitPk) {
    // Ein Passkey, der nie prüfen muss: Hier zählt nur, dass das Konto einen hat.
    $users[$mitPk]['passkeys'] = [['id' => 'dGVzdA', 'pubkey' => 'x', 'label' => 'Test', 'created' => date('c')]];
    $users[$mitPk]['wa_handle'] = 'aGFuZGxl';
    return $users;
}, $mitPk);
$mitSchluessel = schritt2($basis, $mitPk);
$ohne = schritt2($basis, 'test-orakel');
$ohneKennung = fn(string $s) => str_replace([$mitPk, 'test-orakel'], 'X', preg_replace('/(value|data-csrf)="[^"]{20,}"/', '$1=""', $s));
pruefe('Mit und ohne Passkey zeigt Schritt 2 dieselbe Seite',
    $ohneKennung($mitSchluessel) === $ohneKennung($ohne));
pruefe('Der Passkey-Knopf fragt ohne Schlüsselliste (data-passkey="any")',
    str_contains($ohne, 'data-passkey="any"') && !str_contains($ohne, 'data-passkey="login"'));

// ---- Ein Werkzeug für die übrigen Prüfungen: eine Sitzung mit Keksdose ----

// Vorher die Fehlversuchs-Bremse leeren: Auf einer Maschine, auf der gerade
// die ganze Testreihe lief, ist das Stundenkontingent der eigenen Adresse
// sonst längst verbraucht.
$bremseLeeren = function (): void {
    @unlink(data_path('ratelimit') . '/login-global.json');
    foreach (glob(data_path('ratelimit') . '/loginfail-*.json') ?: [] as $f) @unlink($f);
    // Die Sperre je Kennung hängt an der Adresse, die der Server sieht – von
    // hier aus nicht nachzubilden. Auf einer Testinstanz dürfen alle weg.
    foreach (glob(data_path('ratelimit') . '/login-*.json') ?: [] as $f) @unlink($f);
};
$bremseLeeren();

function klient(): array
{
    $keks = tempnam(sys_get_temp_dir(), 'flk');
    $hole = function (string $url, ?array $post = null) use ($keks): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $keks,
            CURLOPT_COOKIEFILE => $keks, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $antwort = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $antwort];
    };
    return [$hole, $keks];
}
function csrf_aus(string $html): string
{
    preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}
/** Kennung, dann Passwort; gibt [Status, Antwort] des zweiten Schritts zurück */
function anmelden(string $basis, string $kennung, string $passwort, string $start = '/admin/login.php'): array
{
    [$hole, $keks] = klient();
    [, $html] = $hole($basis . $start);
    $hole($basis . '/admin/login.php', ['_csrf' => csrf_aus($html), 'schritt' => 'kennung', 'username' => $kennung]);
    [, $html] = $hole($basis . '/admin/login.php');
    $r = $hole($basis . '/admin/login.php', ['_csrf' => csrf_aus($html), 'password' => $passwort]);
    @unlink($keks);
    return $r;
}
$ziel = fn(string $antwort) => preg_match('/^Location: (\S+)/mi', $antwort, $m) ? $m[1] : '';

// ---- Intern muss die Auflösung weiter funktionieren ------------------------

[$code, $antwort] = anmelden($basis, 'ORAKEL@EXAMPLE.ORG', 'Pruef-Passwort-123!');
// Erfolg heißt 303 in den Verwaltungsbereich – je nach Konto auf die
// Linkliste oder auf den Passkey-Vorschlag (Konten ohne Passkey bekommen
// den einmal im Monat, siehe login_ziel()).
pruefe('Anmeldung über die E-Mail-Adresse führt trotzdem ins Konto',
    $code === 303 && preg_match('#/admin/(index|passkey)\.php$#', $ziel($antwort)) === 1,
    $code . ' ' . ($ziel($antwort) ?: 'keine Weiterleitung'));

// ---- Eine Meldung für jeden Fehlschlag --------------------------------------

$meldung = fn(string $s) => preg_match('/<div class="flash flash-err"[^>]*>([^<]*)</', $s, $m) ? $m[1] : '';
$bremseLeeren();
[$c1, $falsch] = anmelden($basis, 'test-orakel', 'falsches-Passwort');
[$c2, $unbekannt] = anmelden($basis, 'konto-das-es-nie-gab', 'falsches-Passwort');
pruefe('Falsches Passwort und unbekannte Kennung: dieselbe Meldung, derselbe Status',
    $meldung($falsch) !== '' && $meldung($falsch) === $meldung($unbekannt) && $c1 === $c2,
    "$c1/$c2 „" . $meldung($falsch) . '"');
for ($i = 0; $i < 6; $i++) [$c3, $gebremst] = anmelden($basis, 'test-orakel', 'falsches-Passwort');
pruefe('Gebremst sieht genauso aus wie falsch (kein 429, keine Wartezeit)',
    $c3 === $c1 && $meldung($gebremst) === $meldung($falsch), "$c3 „" . $meldung($gebremst) . '"');
$bremseLeeren();

// ---- Rückweg nach der Anmeldung ---------------------------------------------

// Ohne Passkey-Vorschlag dazwischen: Der käme sonst einmal im Monat zuerst.
users_update(function (array $users) use ($name) {
    $users[$name]['pk_hint'] = 'nie';
    return $users;
}, $name);

[$hole, $keks] = klient();
[$c, $antwort] = $hole($basis . '/admin/groups.php?x=1');
$zurAnmeldung = $ziel($antwort);
@unlink($keks);
pruefe('Ohne Anmeldung führt eine Seite zur Anmeldung und nennt sich als Rückweg',
    str_contains($zurAnmeldung, '/admin/login.php?next=' . rawurlencode('/admin/groups.php?x=1')), $zurAnmeldung);
$pfad = (string)parse_url($zurAnmeldung, PHP_URL_PATH) . '?' . (string)parse_url($zurAnmeldung, PHP_URL_QUERY);
[$code, $antwort] = anmelden($basis, $name, 'Pruef-Passwort-123!', $pfad);
pruefe('Nach der Anmeldung geht es dorthin zurück',
    $code === 303 && str_ends_with($ziel($antwort), '/admin/groups.php?x=1'), $ziel($antwort));
[$code, $antwort] = anmelden($basis, $name, 'Pruef-Passwort-123!', '/admin/login.php?next=' . rawurlencode('//boese.example/'));
pruefe('Ein fremder Rückweg (//boese.example) wird ignoriert',
    str_ends_with($ziel($antwort), '/admin/index.php'), $ziel($antwort));

[$hole, $keks] = klient();
[, $antwort] = $hole($basis . '/admin/login.php');
@unlink($keks);
pruefe('Die Anmeldung landet in keinem Zwischenspeicher und gibt keinen Referrer mit',
    preg_match('/^Cache-Control: no-store/mi', $antwort) === 1 && preg_match('/^Referrer-Policy: same-origin/mi', $antwort) === 1);

// ---- Aufräumen --------------------------------------------------------------

user_delete($name);
user_delete($mitPk);
pruefe('Testkonten wieder entfernt', user_get($name) === null && user_get($mitPk) === null);

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
