<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die LDAP-Anbindung Schritt für Schritt prüfen.
 *
 * „Anmeldung fehlgeschlagen" kann acht Ursachen haben, und im Browser sieht
 * man keine davon – das ist Absicht, denn wer sich anmeldet, soll nicht
 * erfahren, ob eine Kennung existiert. Wer die Instanz einrichtet, braucht
 * aber genau diese Auskunft. Dieses Werkzeug geht die Kette von vorne durch
 * und hält an der ersten Stelle an, die nicht stimmt.
 *
 * Aufruf auf dem Server:
 *   php tools/ldap-check.php                 # nur Verbindung und Suche
 *   php tools/ldap-check.php kennung         # zusätzlich: wird sie gefunden?
 *   php tools/ldap-check.php kennung -p      # zusätzlich: Passwortprüfung
 *
 * Das Passwort wird abgefragt, nicht als Argument übergeben – sonst stünde es
 * in der Prozessliste und in der Shell-Historie.
 */
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/sso.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$kennung = $argv[1] ?? '';
$mitPasswort = in_array('-p', $argv, true);
$fehler = 0;

function schritt(string $was): void { printf("\n%s\n", $was); }
function ok(string $text): void { printf("  ✓ %s\n", $text); }
function fehlt(string $text, string $rat = ''): void
{
    global $fehler;
    $fehler++;
    printf("  ✗ %s\n", $text);
    if ($rat !== '') printf("      → %s\n", $rat);
}

echo "LDAP-Anbindung prüfen\n=====================\n";

// ---- 1. Erweiterung -------------------------------------------------------
schritt('1. PHP-Erweiterung');
if (extension_loaded('ldap')) {
    ok('ldap ist geladen');
} else {
    fehlt('Die Erweiterung ldap fehlt', 'apt install php-ldap, dann Apache neu laden');
    exit(1);
}

// ---- 2. Konfiguration -----------------------------------------------------
schritt('2. Konfiguration');
$c = ldap_cfg();
if (!$c['enabled']) {
    fehlt("'enabled' steht auf false", "in inc/config.php im Block 'ldap' auf true setzen");
    exit(1);
}
ok('eingeschaltet');
foreach (['uri' => 'Adresse', 'base_dn' => 'Basis-DN', 'user_filter' => 'Filter'] as $k => $label) {
    if (trim((string)$c[$k]) === '') fehlt("$label ($k) ist leer");
    else ok(sprintf('%-10s %s', $label . ':', (string)$c[$k]));
}
printf("  · %-10s %s\n", 'Dienstkonto:', $c['bind_dn'] !== '' ? (string)$c['bind_dn'] : 'keines (anonyme Suche)');
printf("  · %-10s %s\n", 'Passwort:', $c['bind_pass'] !== '' ? 'gesetzt' : 'leer');
printf("  · %-10s %s\n", 'START_TLS:', $c['start_tls'] ? 'ja' : 'nein');
if ($fehler > 0) exit(1);

// ---- 3. Verbindung --------------------------------------------------------
schritt('3. Verbindung');
$conn = @ldap_connect((string)$c['uri']);
if ($conn === false) {
    fehlt('ldap_connect() lehnt die Adresse ab', 'Format: ldaps://host:636 oder ldap://host:389');
    exit(1);
}
ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)$c['timeout']);
ok('Adresse ist gültig (die Verbindung entsteht erst beim Bind)');

if ($c['start_tls']) {
    if (@ldap_start_tls($conn)) ok('START_TLS steht');
    else {
        fehlt('START_TLS abgelehnt: ' . ldap_error($conn),
              'Zertifikat der Gegenstelle prüfbar? Sonst ldaps:// auf Port 636 nehmen');
        exit(1);
    }
}

// ---- 4. Bind für die Suche ------------------------------------------------
schritt('4. Anmeldung für die Suche');
$bindOk = @ldap_bind($conn, $c['bind_dn'] !== '' ? (string)$c['bind_dn'] : null,
                            $c['bind_dn'] !== '' ? (string)$c['bind_pass'] : null);
if (!$bindOk) {
    $meldung = ldap_error($conn);
    fehlt('Bind fehlgeschlagen: ' . $meldung, match (true) {
        str_contains($meldung, "Can't contact") => 'Server oder Port falsch, oder eine Firewall dazwischen',
        str_contains($meldung, 'Invalid credentials') => 'bind_dn oder bind_pass stimmt nicht',
        str_contains($meldung, 'Strong') => 'Der Server verlangt Verschlüsselung – ldaps:// oder start_tls',
        default => 'Meldung oben wörtlich nachschlagen',
    });
    exit(1);
}
ok($c['bind_dn'] !== '' ? 'Dienstkonto angenommen' : 'Anonyme Suche erlaubt');

// ---- 5. Suche -------------------------------------------------------------
if ($kennung === '') {
    echo "\nSoweit steht alles. Für den Rest eine Kennung mitgeben:\n";
    echo "  php tools/ldap-check.php " . 'kennung' . "\n";
    exit(0);
}

schritt('5. Suche nach der Kennung');
$safe = ldap_escape($kennung, '', LDAP_ESCAPE_FILTER);
$filter = str_replace('%s', $safe, (string)$c['user_filter']);
printf("  · Filter:    %s\n", $filter);
printf("  · unterhalb: %s\n", (string)$c['base_dn']);
$res = @ldap_search($conn, (string)$c['base_dn'], $filter,
    array_values(array_filter([(string)$c['mail_attr'], (string)$c['name_attr'], 'memberOf'])), 0, 5, (int)$c['timeout']);
if ($res === false) {
    fehlt('Suche fehlgeschlagen: ' . ldap_error($conn), 'Stimmt die Basis-DN?');
    exit(1);
}
$e = @ldap_get_entries($conn, $res);
$n = is_array($e) ? (int)($e['count'] ?? 0) : 0;
if ($n === 0) {
    fehlt('Kein Treffer', 'Filter prüfen: bei Active Directory (sAMAccountName=%s), bei OpenLDAP meist (uid=%s). Oder die Basis-DN ist zu eng.');
    exit(1);
}
if ($n > 1) {
    fehlt("$n Treffer – die Kennung ist nicht eindeutig", 'Filter enger fassen; flatlink lehnt mehrdeutige Kennungen ab');
    exit(1);
}
ok('genau ein Treffer');
printf("  · DN:        %s\n", (string)($e[0]['dn'] ?? '—'));
foreach (['mail_attr' => 'E-Mail', 'name_attr' => 'Klarname'] as $k => $label) {
    $attr = strtolower((string)$c[$k]);
    if ($attr === '') continue;
    $wert = $e[0][$attr][0] ?? null;
    if (is_string($wert)) ok(sprintf('%-10s %s', $label . ':', $wert));
    else fehlt("$label ($attr) kommt nicht mit", 'Attributname prüfen oder Freigabe im Verzeichnis');
}

// ---- 6. Passwortprüfung ---------------------------------------------------
if (!$mitPasswort) {
    echo "\nFür die Passwortprüfung noch einmal mit -p aufrufen.\n";
    exit($fehler === 0 ? 0 : 1);
}

schritt('6. Passwortprüfung');
echo '  Passwort für ' . $kennung . ': ';
@shell_exec('stty -echo 2>/dev/null');
$pass = trim((string)fgets(STDIN));
@shell_exec('stty echo 2>/dev/null');
echo "\n";
if ($pass === '') {
    fehlt('Kein Passwort eingegeben');
    exit(1);
}
if (@ldap_bind($conn, (string)$e[0]['dn'], $pass)) {
    ok('Passwort angenommen – die Anmeldung sollte in flatlink funktionieren');
} else {
    fehlt('Passwort abgelehnt: ' . ldap_error($conn),
          'Wenn das Passwort stimmt: Erlaubt das Verzeichnis den Bind dieses Kontos?');
}

echo "\n", $fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Punkt(e) offen.\n";
exit($fehler === 0 ? 0 : 1);
