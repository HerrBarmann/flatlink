<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Sitzungsprüfung bei zentraler Anmeldung (ab 5.10, 'sso' => ['session_check' => true]).
 *
 * Eine Sitzung, die über SSO entstand, gilt nur, solange der Webserver dieselbe
 * Kennung meldet. Sonst bliebe man nach dem Abmelden beim Identity Provider
 * (auch über Single Logout aus einem anderen Dienst) hier angemeldet, und am
 * geteilten Rechner sähe die nächste Person die Sitzung der vorigen.
 *
 * Aufruf: php tests/sso-sitzung.php
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

// ---- 1. Die Rechnung ------------------------------------------------------
$pruefe('Prüfung aus: Sitzung gilt, auch ohne Meldung (wie bis 5.7)', sso_sitzung_gilt(true, false, null, 'mt1234'));
$pruefe('SSO aus: Sitzung gilt', sso_sitzung_gilt(false, true, null, 'mt1234'));
$pruefe('Prüfung an, Server meldet dieselbe Kennung: gilt', sso_sitzung_gilt(true, true, 'mt1234', 'mt1234'));
$pruefe('… auch in anderer Schreibweise', sso_sitzung_gilt(true, true, 'MT1234', 'mt1234'));
$pruefe('Prüfung an, Server meldet niemanden (beim IdP abgemeldet): endet', !sso_sitzung_gilt(true, true, null, 'mt1234'));
$pruefe('Prüfung an, Server meldet eine andere Kennung: endet', !sso_sitzung_gilt(true, true, 'mt9999', 'mt1234'));

// ---- 2. Vorgabe und Einbau ------------------------------------------------
$sso = (string)file_get_contents(__DIR__ . '/../inc/sso.php');
$pruefe('Vorgabe: Prüfung aus (bestehende Instanzen merken nichts)',
    (bool)preg_match("/function sso_cfg\\(\\).*?'session_check' => false,/s", $sso));
$auth = (string)file_get_contents(__DIR__ . '/../inc/auth.php');
$pruefe('auth_user() beendet SSO-Sitzungen über sso_session_ok()',
    (bool)preg_match("/function auth_user\\(\\).*?'auth_source'.*?=== 'sso'.*?sso_session_ok\\(\\\$name\\).*?auth_logout\\(\\)/s", $auth));
$pruefe('lokale Sitzungen werden nicht geprüft (Rückweg bei gestörtem IdP)',
    str_contains($auth, "(\$_SESSION['auth_source'] ?? 'local') === 'sso'"));

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
