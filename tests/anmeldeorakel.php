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

// ---- Und intern muss die Auflösung weiter funktionieren --------------------

// Vorher die Fehlversuchs-Bremse leeren: Auf einer Maschine, auf der gerade
// die ganze Testreihe lief, ist das Stundenkontingent der eigenen Adresse
// sonst längst verbraucht – und der Anmeldeversuch bekäme 429 statt 302.
@unlink(data_path('ratelimit') . '/login-global.json');
foreach (glob(data_path('ratelimit') . '/loginfail-*.json') ?: [] as $f) @unlink($f);

$keks = tempnam(sys_get_temp_dir(), 'flk');
$hole = function (string $url, ?array $post = null) use ($keks): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $keks,
        CURLOPT_COOKIEFILE => $keks, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $antwort = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, $antwort];
};
[, $html] = $hole($basis . '/admin/login.php');
preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
$hole($basis . '/admin/login.php', ['_csrf' => $m[1], 'schritt' => 'kennung', 'username' => 'ORAKEL@EXAMPLE.ORG']);
[, $html] = $hole($basis . '/admin/login.php');
preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);
[, $antwort] = $hole($basis . '/admin/login.php', ['_csrf' => $m[1], 'password' => 'Pruef-Passwort-123!']);
// Erfolg heißt 302 in den Verwaltungsbereich – je nach Konto auf die
// Linkliste oder auf den Passkey-Vorschlag (Konten ohne Passkey bekommen
// den einmal im Monat, siehe login_ziel()).
preg_match('/Location: (\S+)/', $antwort, $ziel);
pruefe('Anmeldung über die E-Mail-Adresse führt trotzdem ins Konto',
    str_contains($antwort, '302')
    && (str_contains((string)($ziel[1] ?? ''), 'index.php') || str_contains((string)($ziel[1] ?? ''), 'passkey.php')),
    (string)($ziel[1] ?? 'keine Weiterleitung'));
@unlink($keks);

// ---- Aufräumen --------------------------------------------------------------

user_delete($name);
pruefe('Testkonto wieder entfernt', user_get($name) === null);

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
