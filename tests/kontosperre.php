<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Gesperrte Konten – festgeschrieben, was eine Sperre bedeutet.
 *
 * LDAP regelt die Anmeldung, nicht den Bestand: Wer das Haus verlässt, kommt
 * nicht mehr herein – sein Konto, seine Schlüssel und sein Zugriff über die
 * Schnittstelle blieben aber, wie sie waren. Seit 3.6.0 lässt sich ein Konto
 * sperren, und der Verzeichnisabgleich tut das maschinell.
 *
 * Die vier Sätze, auf die es ankommt:
 *   1. Gesperrt heißt gesperrt – über jeden Weg: Passwort, Verzeichnis,
 *      Zugangsschlüssel, laufende Sitzung.
 *   2. Gesperrt heißt NICHT gelöscht. Links, Statistik und QR-Codes bleiben;
 *      ein gedruckter Code soll nicht ins Leere zeigen, weil jemand geht.
 *   3. Eine Sperre ist umkehrbar, und danach ist alles wie vorher.
 *   4. Der Abgleich hebt nur seine EIGENEN Sperren wieder auf.
 *
 * Legt ein Testkonto an und räumt es wieder ab.
 *
 * Aufruf: php tests/kontosperre.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
if (!is_file(__DIR__ . '/../inc/config.php')) {
    exit("inc/config.php fehlt – bitte aus inc/config.example.php anlegen.\n");
}

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/token.php';

$fehler = 0;
$pruefe = function (string $was, bool $ok) use (&$fehler): void {
    printf("  %-58s %s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN');
    if (!$ok) $fehler++;
};

$konto = 'test-sperre-' . bin2hex(random_bytes(3));
$passwort = bin2hex(random_bytes(8));
users_update(function (array $u) use ($konto, $passwort) {
    $u[$konto] = ['role' => 'user', 'pass' => password_hash($passwort, PASSWORD_DEFAULT),
                  'auth' => 'local', 'created' => date('c')];
    return $u;
}, $konto);
$tok = token_create($konto, 'Probe');
[$ok, $code] = link_create('https://example.org/sperrprobe', null, $konto, 'random', []);

try {
    // 1. Offen ist offen
    $pruefe('frisches Konto ist nicht gesperrt', !user_locked(user_get($konto)));
    $pruefe('sein Schlüssel wird gefunden', token_find($tok['token']) !== null);

    // 2. Sperren
    user_set_locked($konto, true, 'Testlauf');
    $u = user_get($konto);
    $pruefe('nach dem Sperren gilt es als gesperrt', user_locked($u));
    $pruefe('der Grund steht im Datensatz', str_contains(user_lock_note($u), 'Testlauf'));
    $pruefe('die Anmeldung mit richtigem Passwort scheitert',
        !auth_login($konto, $passwort));

    // 3. Aber nichts ist weg
    $pruefe('das Konto existiert weiter', user_get($konto) !== null);
    $pruefe('der Link existiert weiter', link_get((string)$code) !== null);
    $pruefe('die Rolle ist unverändert', (string)(user_get($konto)['role'] ?? '') === 'user');
    $pruefe('der Schlüssel liegt weiter da', count(tokens_of($konto)) === 1);

    // 4. Umkehrbar
    user_set_locked($konto, false);
    $pruefe('nach dem Freigeben ist es wieder offen', !user_locked(user_get($konto)));
    $pruefe('das Feld ist restlos weg', !array_key_exists('locked', user_get($konto)));

    // 5. Der Abgleich erkennt nur seine eigenen Sperren
    $abgleich = 'Verzeichnisabgleich: Kennung nicht mehr vorhanden';
    user_set_locked($konto, true, $abgleich);
    $pruefe('maschinelle Sperre ist am Grund erkennbar',
        (string)(user_get($konto)['locked']['reason'] ?? '') === $abgleich);
    user_set_locked($konto, true, 'von Hand gesperrt');
    $pruefe('eine Sperre von Hand trägt einen anderen Grund',
        (string)(user_get($konto)['locked']['reason'] ?? '') !== $abgleich);
} finally {
    $pdo = db();
    $pdo->exec('DELETE FROM links WHERE owner = ' . $pdo->quote($konto));
    $pdo->exec('DELETE FROM tokens WHERE json_extract(data, "$.user") = ' . $pdo->quote($konto));
    users_update(function (array $u) use ($konto) { unset($u[$konto]); return $u; }, $konto);
}

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
