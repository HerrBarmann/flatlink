<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * LDAP nur als Verzeichnis (ab 5.7, 'ldap' => ['login' => false]).
 *
 * Eine Hochschule meldet über ihren Identity Provider an (SSO), will Konten
 * aber vorab aus dem Verzeichnis anlegen und Gruppen vergeben. Drei Regeln,
 * ohne Verzeichnis und ohne Konten geprüft:
 *
 *   1. Aus dem Verzeichnis angelegte Konten werden SSO-Konten, sobald niemand
 *      sich über LDAP anmeldet, wohl aber über SSO. Sonst kämen sie nirgends
 *      herein.
 *   2. Der Verzeichnisabgleich prüft dann auch SSO-Konten – aber nur dann.
 *      Mit eingeschalteter LDAP-Anmeldung kommen über SSO auch Fremde einer
 *      Föderation, die in keinem eigenen Verzeichnis stehen.
 *   3. Das Formular nimmt kein Verzeichnispasswort an, wenn 'login' aus ist –
 *      geprüft an der Stelle, die es entscheidet.
 *
 * Aufruf: php tests/ldap-nur-verzeichnis.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
if (!is_file(__DIR__ . '/../inc/config.php')) {
    exit("inc/config.php fehlt – bitte aus inc/config.example.php anlegen.\n");
}
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/sso.php';

$fehler = 0;
$pruefe = function (string $was, bool $ok) use (&$fehler): void {
    printf("  %-66s %s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN');
    if (!$ok) $fehler++;
};

// ---- 1. Herkunft eines Kontos aus dem Verzeichnis -------------------------
$pruefe('LDAP-Anmeldung an, ohne SSO: LDAP-Konto', ldap_konto_herkunft(true, false) === 'ldap');
$pruefe('LDAP-Anmeldung an, mit SSO: LDAP-Konto (wie bisher)', ldap_konto_herkunft(true, true) === 'ldap');
$pruefe('nur Verzeichnis, mit SSO: SSO-Konto', ldap_konto_herkunft(false, true) === 'sso');
$pruefe('nur Verzeichnis, ohne SSO: LDAP-Konto (keine andere Anmeldung da)', ldap_konto_herkunft(false, false) === 'ldap');

// ---- 2. Was der Abgleich prüft ------------------------------------------
$pruefe('mit LDAP-Anmeldung: nur LDAP-Konten', ldap_bestand_herkunft(true, true) === ['ldap']);
$pruefe('ohne SSO: nur LDAP-Konten', ldap_bestand_herkunft(false, false) === ['ldap']);
$pruefe('nur Verzeichnis mit SSO: LDAP- und SSO-Konten', ldap_bestand_herkunft(false, true) === ['ldap', 'sso']);

// ---- 3. Vorgabe und Riegel ------------------------------------------------
$sso = (string)file_get_contents(__DIR__ . '/../inc/sso.php');
$pruefe('Vorgabe bleibt: LDAP-Anmeldung an (bestehende Instanzen merken nichts)',
    (bool)preg_match("/function ldap_cfg\\(\\).*?'login' => true,/s", $sso));
$login = (string)file_get_contents(__DIR__ . '/../admin/login.php');
$pruefe('das Formular fragt ldap_login_enabled(), nicht ldap_enabled()',
    str_contains($login, 'ldap_login_enabled() && ldap_login(') && !str_contains($login, 'ldap_enabled() && ldap_login('));
$pruefe('ldap_login() selbst verweigert ohne LDAP-Anmeldung',
    (bool)preg_match('/function ldap_login\(.*?\{\s*(?:\/\/[^\n]*\n\s*)*if \(!ldap_login_enabled\(\)\) return/s', $sso));
$abgleich = (string)file_get_contents(__DIR__ . '/../tools/ldap-abgleich.php');
$pruefe('der Abgleich nimmt seine Anmeldearten aus ldap_bestand_herkunft()',
    str_contains($abgleich, 'ldap_bestand_herkunft(ldap_login_enabled(), sso_enabled())'));
$users = (string)file_get_contents(__DIR__ . '/../admin/users.php');
$pruefe('„Aus dem Verzeichnis anlegen“ nimmt die Herkunft aus ldap_konto_herkunft()',
    str_contains($users, 'ldap_konto_herkunft(ldap_login_enabled(), sso_enabled())'));

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
