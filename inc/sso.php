<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/groups.php';

/**
 * Zentrale Anmeldung: LDAP und SSO über den Webserver (Shibboleth, SAML, OIDC).
 *
 * Zwei getrennte Wege, beide optional und standardmäßig aus:
 *
 * 1. LDAP – flatlink fragt selbst beim Verzeichnis nach. Braucht die
 *    PHP-Erweiterung ldap. Nutzername und Passwort werden im eigenen
 *    Login-Formular eingegeben.
 *
 * 2. SSO über den Webserver – die eigentliche Anmeldung erledigt ein
 *    Servermodul (mod_shib für Shibboleth, mod_auth_mellon für SAML,
 *    mod_auth_openidc für OpenID Connect). flatlink liest nur, wen der
 *    Server bereits authentifiziert hat. Das ist der Weg für einen
 *    Shibboleth-IdP.
 *
 * Zur Sicherheit von Weg 2 siehe sso_server_var(): Werte aus HTTP-Headern
 * sind fälschbar und werden nur hinter einer konfigurierten Proxy-Allowlist
 * akzeptiert.
 */

// ---------------------------------------------------------------- gemeinsam

function sso_cfg(): array
{
    return (array)(cfg('sso') ?? []) + [
        'enabled' => false, 'user_var' => 'REMOTE_USER', 'mail_var' => '', 'name_var' => '', 'group_var' => '',
        'group_separator' => ';', 'group_map' => [], 'auto_create' => true,
        'allowed_scopes' => [], 'require_group' => false, 'approval_queue' => true,
        'default_groups' => [], 'login_url' => '', 'logout_url' => '',
        'trusted_proxies' => [], 'button_label' => 'Mit institutionellem Konto anmelden',
    ];
}

function ldap_cfg(): array
{
    return (array)(cfg('ldap') ?? []) + [
        'enabled' => false, 'uri' => '', 'start_tls' => false, 'base_dn' => '',
        'bind_dn' => '', 'bind_pass' => '', 'user_filter' => '(uid=%s)',
        'mail_attr' => 'mail', 'name_attr' => 'displayName',
        'group_mode' => 'memberof', 'group_attr' => 'cn',
        'group_base_dn' => '', 'group_filter' => '(&(objectClass=groupOfNames)(member=%s))',
        'group_map' => [], 'auto_create' => true, 'require_group' => false,
        'approval_queue' => true, 'default_groups' => [], 'timeout' => 5,
    ];
}

function sso_enabled(): bool
{
    return (bool)sso_cfg()['enabled'];
}

function ldap_enabled(): bool
{
    return (bool)ldap_cfg()['enabled'] && function_exists('ldap_connect');
}

/**
 * Externe Gruppennamen auf lokale Gruppen-IDs abbilden.
 *
 * Ist eine Zuordnungstabelle konfiguriert, gilt ausschließlich sie – was nicht
 * darin steht, wird verworfen. Ist sie leer, wird ein externer Name
 * übernommen, wenn es lokal eine gleichnamige Gruppe gibt. Beides ist
 * bewusst restriktiv: Aus dem Verzeichnis kommende Namen dürfen keine
 * Gruppen erfinden.
 *
 * @param string[] $external
 * @return string[] lokale Gruppen-IDs
 */
function sso_map_groups(array $external, array $map, array $defaults): array
{
    $known = groups_all();
    $out = [];
    foreach ($external as $name) {
        $name = trim($name);
        if ($name === '') continue;
        if ($map !== []) {
            if (!isset($map[$name])) continue;
            foreach ((array)$map[$name] as $g) {
                if (isset($known[$g])) $out[] = (string)$g;
            }
        } else {
            $slug = strtolower($name);
            if (isset($known[$slug])) $out[] = $slug;
        }
    }
    foreach ($defaults as $g) {
        if (isset($known[$g])) $out[] = (string)$g;
    }
    return array_values(array_unique($out));
}

// ------------------------------------------------------- Zugangskontrolle

/**
 * Darf sich diese Kennung überhaupt anmelden?
 *
 * In einer Föderation authentifiziert der IdP-Verbund weit mehr Menschen als
 * die eigene Einrichtung – ohne Einschränkung bekäme jedes Mitglied jeder
 * beteiligten Hochschule ein Konto. Zwei Bremsen, beide optional:
 *
 * - allowed_scopes: nur Kennungen aus den genannten Einrichtungen. Greift bei
 *   Kennungen der Form name@einrichtung.de (eppn). Undurchsichtige Kennungen
 *   (persistent-id) tragen keine Einrichtung – dort hilft require_group.
 * - require_group: die Person muss über die Zuordnung in mindestens einer
 *   lokalen Gruppe landen.
 *
 * @param string[] $groups bereits abgebildete lokale Gruppen
 * @return ?string Ablehnungsgrund oder null, wenn zugelassen
 */
function access_denied_reason(string $username, array $groups, array $c): ?string
{
    $scopes = (array)($c['allowed_scopes'] ?? []);
    if ($scopes !== []) {
        $at = strrpos($username, '@');
        $scope = $at === false ? '' : strtolower(substr($username, $at + 1));
        $ok = false;
        foreach ($scopes as $s) {
            if ($scope !== '' && strtolower((string)$s) === $scope) { $ok = true; break; }
        }
        if (!$ok) return 'Diese Kennung gehört nicht zu einer zugelassenen Einrichtung.';
    }

    $req = $c['require_group'] ?? false;
    if ($req !== false && $req !== []) {
        // true = irgendeine Gruppe; Liste = eine der genannten
        $needed = is_array($req) ? array_intersect($groups, $req) : $groups;
        if ($needed === []) {
            return 'Für diese Kennung ist keine Berechtigung hinterlegt.';
        }
    }
    return null;
}

// -------------------------------------------- Warteschlange zur Freischaltung

function pending_users_file(): string
{
    return data_path() . '/pending-users.json';
}

/** @return array<string,array> Kennung => {display, email, groups, first_seen, last_seen, tries} */
function pending_users(): array
{
    return json_read(pending_users_file());
}

/**
 * Einen abgewiesenen Anmeldeversuch vormerken.
 *
 * Ohne das wäre 'auto_create' => false bei undurchsichtigen Kennungen
 * unbrauchbar: Niemand kann ein Konto vorab anlegen, dessen Kennung er nicht
 * kennt. So sieht die Verwaltung stattdessen Klarname und E-Mail und kann
 * mit einem Klick freischalten.
 */
function pending_user_note(string $username, ?string $display, ?string $email, array $groups, string $reason = 'unbekannt'): void
{
    json_update(pending_users_file(), function (array $q) use ($username, $display, $email, $groups, $reason) {
        $now = date('c');
        $q[$username] = [
            'reason' => $reason,
            'display' => $display !== null && $display !== '' ? mb_substr($display, 0, 80) : ($q[$username]['display'] ?? null),
            'email' => $email !== null && $email !== '' ? strtolower($email) : ($q[$username]['email'] ?? null),
            'groups' => $groups,
            'first_seen' => $q[$username]['first_seen'] ?? $now,
            'last_seen' => $now,
            'tries' => (int)($q[$username]['tries'] ?? 0) + 1,
        ];
        // Die Warteschlange darf nicht unbegrenzt wachsen – ältestes fliegt raus
        if (count($q) > 200) {
            uasort($q, fn($a, $b) => strcmp($a['last_seen'], $b['last_seen']));
            $q = array_slice($q, -200, null, true);
        }
        return $q;
    });
}

function pending_user_drop(string $username): void
{
    json_update(pending_users_file(), function (array $q) use ($username) {
        unset($q[$username]);
        return $q;
    });
}

/**
 * Vorgemerkte Kennung freischalten: Konto anlegen, damit die nächste Anmeldung
 * durchgeht. Der Rest (Gruppen, Mail, Name) kommt beim Login aus dem Verzeichnis.
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function pending_user_approve(string $username, string $source): ?string
{
    $q = pending_users();
    if (!isset($q[$username])) return 'Diese Kennung steht nicht in der Warteschlange.';
    $e = $q[$username];
    // $force: Die Freischaltung ist genau der Moment, in dem ein Administrator
    // eine Verknüpfung bewusst bestätigt – auch die mit einem bestehenden
    // lokalen Konto. Die Rolle wird dabei zurückgesetzt (siehe user_provision).
    $err = user_provision($username, $source, $e['email'] ?? null, (array)($e['groups'] ?? []),
        true, $e['display'] ?? null, true);
    if ($err === null) pending_user_drop($username);
    return $err;
}

/**
 * Konto aus einer externen Quelle anlegen oder aktualisieren.
 *
 * Externe Konten bekommen bewusst KEINEN Passwort-Hash – damit kann sich
 * niemand über das lokale Formular als sie ausgeben, selbst wenn er den
 * Nutzernamen kennt. Die Rolle bleibt bei Aktualisierungen unangetastet:
 * Wer lokal zum Admin gemacht wurde, bleibt es auch nach dem nächsten Login.
 *
 * @param string[] $groups
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
/**
 * Taugt die Kennung als Kontoschlüssel? Keine Steuerzeichen, kein Leerraum,
 * höchstens 190 Zeichen. Wird schon vor dem Vormerken geprüft – sonst lägen
 * in der Warteschlange Einträge, die sich nie freischalten ließen.
 */
function valid_external_id(string $username): bool
{
    return preg_match('/^[^\x00-\x1F\x7F\s]{1,190}$/u', $username) === 1;
}

function user_provision(string $username, string $source, ?string $email, array $groups, bool $autoCreate, ?string $display = null, bool $force = false): ?string
{
    if (!valid_external_id($username)) {
        return 'Ungültige Kennung aus der zentralen Anmeldung.';
    }

    // Ein bestehendes Konto anderer Herkunft darf NICHT stillschweigend
    // übernommen werden. Sonst genügte es, im Verzeichnis eine Mail-Adresse
    // einzutragen, unter der sich hier jemand lokal registriert hat – man
    // erbte dessen Konto samt Rolle und sperrte ihn aus seinem Passwort aus.
    // Selbstregistrierte Konten sind nach E-Mail geschlüsselt und sehen
    // externen Kennungen damit zum Verwechseln ähnlich.
    //
    // Der legitime Umstieg eines lokalen Kontos auf zentrale Anmeldung läuft
    // über die Warteschlange: Ein Administrator bestätigt die Verknüpfung
    // bewusst ($force).
    $current = users_all()[$username] ?? null;
    if (!$force && $current !== null && ($current['auth'] ?? 'local') !== $source) {
        return 'Für diese Kennung gibt es bereits ein Konto mit anderer Anmeldeart.';
    }
    $err = null;
    $display = $display === null ? null : trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $display));
    json_update(users_file(), function (array $users) use ($username, $source, $email, $groups, $autoCreate, $display, &$err) {
        $exists = isset($users[$username]);
        if (!$exists && !$autoCreate) {
            $err = 'Für diese Kennung gibt es hier kein Konto, und die automatische Anlage ist deaktiviert.';
            return null;
        }
        if (!$exists) {
            $users[$username] = [
                'role' => $users === [] ? 'admin' : 'user',
                'created' => date('c'),
            ];
        } elseif (($users[$username]['auth'] ?? 'local') !== $source) {
            // Bewusst freigegebene Verknüpfung eines bestehenden Kontos:
            // die Rolle wird NICHT geerbt. Wer ein Administrator-Konto
            // verknüpft, soll die Rolle danach ausdrücklich neu vergeben,
            // statt sie versehentlich weiterzureichen.
            $users[$username]['role'] = 'user';
        }
        $users[$username]['auth'] = $source;
        // Ein vorhandener lokaler Passwort-Hash wird entfernt: Das Konto wird
        // ab jetzt zentral verwaltet, ein Alt-Passwort darf nicht weitergelten.
        unset($users[$username]['pass']);
        if ($email !== null && $email !== '') $users[$username]['email'] = strtolower($email);
        // Der Klarname aus dem Verzeichnis gewinnt – er ist dort gepflegt.
        // Fehlt er, bleibt ein lokal gesetzter Name bestehen.
        if ($display !== null && $display !== '') {
            $users[$username]['display_name'] = mb_substr($display, 0, 80);
        }
        $users[$username]['groups'] = $groups;
        $users[$username]['last_login'] = date('c');
        return $users;
    });
    return $err;
}

/** Sitzung für ein extern authentifiziertes Konto eröffnen */
function sso_start_session(string $username): void
{
    auth_boot();
    session_regenerate_id(true);
    $_SESSION['user'] = $username;
    $_SESSION['auth_source'] = users_all()[$username]['auth'] ?? 'local';
    unset($_SESSION['csrf']);
}

// ------------------------------------------------- Weg 2: SSO via Webserver

/**
 * Wert einer vom Webserver gesetzten Variablen lesen – mit Spoofing-Schutz.
 *
 * Variablen wie REMOTE_USER setzt der Webserver selbst; sie sind
 * vertrauenswürdig. Variablen, die als HTTP-Header ankommen (HTTP_*), kann
 * dagegen jeder Client frei erfinden. Sie werden nur akzeptiert, wenn die
 * Anfrage von einem in 'trusted_proxies' eingetragenen Reverse Proxy kommt,
 * der solche Header nachweislich überschreibt.
 *
 * Ohne Allowlist wird ein HTTP_-Header verworfen – lieber eine kaputte
 * Anmeldung als eine, bei der sich jeder als beliebiger Nutzer ausgibt.
 */
function sso_server_var(string $key): ?string
{
    if ($key === '') return null;
    if (str_starts_with($key, 'HTTP_')) {
        // Eigene Liste, sonst die der Instanz
        $trusted = (array)sso_cfg()['trusted_proxies'] ?: (array)cfg('trusted_proxies');
        if ($trusted === [] || !in_array(client_ip(), $trusted, true)) return null;
    }
    $v = $_SERVER[$key] ?? null;
    return is_string($v) && $v !== '' ? $v : null;
}

/**
 * Wen hat der Webserver angemeldet?
 * @return ?array{user:string,email:?string,groups:string[]}
 */
function sso_identity(): ?array
{
    if (!sso_enabled()) return null;
    $c = sso_cfg();
    $user = sso_server_var((string)$c['user_var']);
    if ($user === null) return null;

    $groups = [];
    $raw = $c['group_var'] !== '' ? sso_server_var((string)$c['group_var']) : null;
    if ($raw !== null) {
        $sep = (string)$c['group_separator'];
        $groups = $sep === '' ? [$raw] : explode($sep, $raw);
    }

    return [
        'user' => $user,
        'email' => $c['mail_var'] !== '' ? sso_server_var((string)$c['mail_var']) : null,
        'display' => $c['name_var'] !== '' ? sso_server_var((string)$c['name_var']) : null,
        'groups' => sso_map_groups($groups, (array)$c['group_map'], (array)$c['default_groups']),
    ];
}

/**
 * Anmeldung über den Webserver versuchen. Wird auf der Login-Seite und beim
 * Aufruf von login.php?sso=1 ausgewertet.
 * @return ?string Fehlermeldung; null = angemeldet oder nichts zu tun
 */
function sso_attempt(): ?string
{
    $id = sso_identity();
    if ($id === null) return null;
    $c = sso_cfg();

    if (!valid_external_id($id['user'])) return 'Ungültige Kennung aus der zentralen Anmeldung.';

    $deny = access_denied_reason($id['user'], $id['groups'], $c);
    if ($deny !== null) return $deny;

    $existing = users_all()[$id['user']] ?? null;
    $fremd = $existing !== null && ($existing['auth'] ?? 'local') !== 'sso';
    if ($existing === null && !$c['auto_create'] || $fremd) {
        if ($c['approval_queue']) {
            pending_user_note($id['user'], $id['display'] ?? null, $id['email'], $id['groups'],
                $fremd ? 'kollision' : 'unbekannt');
            return $fremd
                ? 'Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart. '
                  . 'Die Verknüpfung muss ein Administrator bestätigen.'
                : 'Dein Zugang ist noch nicht freigeschaltet. Die Anfrage liegt jetzt zur Prüfung vor.';
        }
        return $fremd
            ? 'Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart.'
            : 'Für diese Kennung gibt es hier kein Konto.';
    }

    $err = user_provision($id['user'], 'sso', $id['email'], $id['groups'],
        (bool)$c['auto_create'], $id['display'] ?? null);
    if ($err !== null) return $err;
    sso_start_session($id['user']);
    return null;
}

// -------------------------------------------------------- Weg 1: LDAP-Bind

/**
 * Nutzer gegen LDAP prüfen.
 *
 * Ablauf: mit dem Dienstkonto (oder anonym) binden, den Eintrag zum
 * Nutzernamen suchen, dann mit dem gefundenen DN und dem eingegebenen
 * Passwort erneut binden. Klappt das, ist das Passwort korrekt.
 *
 * @return ?array{user:string,email:?string,groups:string[]} null = nicht authentifiziert
 */
function ldap_authenticate(string $username, string $password): ?array
{
    // Leere Passwörter würden als "unauthenticated bind" durchgehen und
    // fälschlich als Erfolg gelten – der Klassiker unter den LDAP-Lücken.
    if ($password === '' || $username === '') return null;
    if (!ldap_enabled()) return null;

    $c = ldap_cfg();
    $conn = @ldap_connect((string)$c['uri']);
    if ($conn === false) return null;
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)$c['timeout']);

    try {
        if ($c['start_tls'] && !@ldap_start_tls($conn)) return null;

        // Suche mit Dienstkonto (leer = anonym)
        if (!@ldap_bind($conn, $c['bind_dn'] !== '' ? (string)$c['bind_dn'] : null,
                        $c['bind_dn'] !== '' ? (string)$c['bind_pass'] : null)) {
            return null;
        }

        // LDAP-Injection: Nutzereingabe gehört escaped in den Filter
        $safe = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $filter = str_replace('%s', $safe, (string)$c['user_filter']);
        $attrs = array_values(array_filter([(string)$c['mail_attr'], (string)$c['name_attr'], 'memberOf', 'dn']));
        $res = @ldap_search($conn, (string)$c['base_dn'], $filter, $attrs, 0, 2, (int)$c['timeout']);
        if ($res === false) return null;
        $entries = @ldap_get_entries($conn, $res);
        // Genau ein Treffer – mehrdeutige Kennungen werden abgelehnt
        if (!is_array($entries) || ($entries['count'] ?? 0) !== 1) return null;
        $dn = (string)($entries[0]['dn'] ?? '');
        if ($dn === '') return null;

        // Die eigentliche Prüfung: Bind als der gefundene Nutzer
        if (!@ldap_bind($conn, $dn, $password)) return null;

        $mailAttr = strtolower((string)$c['mail_attr']);
        $email = $entries[0][$mailAttr][0] ?? null;
        $nameAttr = strtolower((string)$c['name_attr']);
        $display = $nameAttr !== '' ? ($entries[0][$nameAttr][0] ?? null) : null;

        return [
            'user' => $username,
            'email' => is_string($email) ? $email : null,
            'display' => is_string($display) ? $display : null,
            'groups' => sso_map_groups(
                ldap_group_names($conn, $dn, $entries[0], $c),
                (array)$c['group_map'],
                (array)$c['default_groups']
            ),
        ];
    } finally {
        @ldap_unbind($conn);
    }
}

/**
 * Gruppennamen zu einem Nutzer ermitteln – entweder aus dem memberOf-Attribut
 * am Eintrag selbst oder über eine eigene Suche im Gruppenbaum.
 *
 * @param resource|\LDAP\Connection $conn
 * @return string[]
 */
function ldap_group_names($conn, string $dn, array $entry, array $c): array
{
    $names = [];

    if ($c['group_mode'] === 'search' && $c['group_base_dn'] !== '') {
        $filter = str_replace('%s', ldap_escape($dn, '', LDAP_ESCAPE_FILTER), (string)$c['group_filter']);
        $attr = (string)$c['group_attr'];
        $res = @ldap_search($conn, (string)$c['group_base_dn'], $filter, [$attr], 0, 200, (int)$c['timeout']);
        if ($res !== false) {
            $groups = @ldap_get_entries($conn, $res);
            for ($i = 0; $i < (int)($groups['count'] ?? 0); $i++) {
                $v = $groups[$i][strtolower($attr)][0] ?? null;
                if (is_string($v)) $names[] = $v;
            }
        }
        return $names;
    }

    // memberOf liefert volle DNs – der erste RDN-Wert ist der Gruppenname
    for ($i = 0; $i < (int)($entry['memberof']['count'] ?? 0); $i++) {
        $groupDn = (string)$entry['memberof'][$i];
        $parts = ldap_explode_dn($groupDn, 1);
        if (is_array($parts) && ($parts['count'] ?? 0) > 0) $names[] = (string)$parts[0];
    }
    return $names;
}

/**
 * Login-Versuch über LDAP inklusive Kontoanlage.
 * @return ?string null = erfolgreich angemeldet, sonst Fehlermeldung
 */
function ldap_login(string $username, string $password): ?string
{
    $id = ldap_authenticate($username, $password);
    if ($id === null) return 'Anmeldung fehlgeschlagen.';
    $c = ldap_cfg();

    if (!valid_external_id($id['user'])) return 'Ungültige Kennung aus dem Verzeichnis.';

    $deny = access_denied_reason($id['user'], $id['groups'], $c);
    if ($deny !== null) return $deny;

    $existing = users_all()[$id['user']] ?? null;
    $fremd = $existing !== null && ($existing['auth'] ?? 'local') !== 'ldap';
    if ($existing === null && !$c['auto_create'] || $fremd) {
        if ($c['approval_queue']) {
            pending_user_note($id['user'], $id['display'] ?? null, $id['email'], $id['groups'],
                $fremd ? 'kollision' : 'unbekannt');
            return $fremd
                ? 'Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart. '
                  . 'Die Verknüpfung muss ein Administrator bestätigen.'
                : 'Dein Zugang ist noch nicht freigeschaltet. Die Anfrage liegt jetzt zur Prüfung vor.';
        }
        return $fremd
            ? 'Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart.'
            : 'Für diese Kennung gibt es hier kein Konto.';
    }

    $err = user_provision($id['user'], 'ldap', $id['email'], $id['groups'],
        (bool)$c['auto_create'], $id['display'] ?? null);
    if ($err !== null) return $err;
    sso_start_session($id['user']);
    return null;
}
