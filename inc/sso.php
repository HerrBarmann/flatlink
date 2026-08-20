<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
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
        'group_separator' => ';', 'group_map' => [], 'auto_create' => true, 'group_sync' => 'merge',
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
        'group_map' => [], 'auto_create' => true, 'require_group' => false, 'group_sync' => 'merge',
        'approval_queue' => true, 'default_groups' => [], 'timeout' => 5,
        // Für die Personensuche in der Nutzerverwaltung. Getrennt vom
        // user_filter, weil der eine Kennung exakt trifft, dieser aber
        // Bruchstücke über mehrere Attribute finden soll.
        'search_filter' => '', 'uid_attr' => '',
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
        if (!$ok) return t('Diese Kennung gehört nicht zu einer zugelassenen Einrichtung.');
    }

    $req = $c['require_group'] ?? false;
    if ($req !== false && $req !== []) {
        // true = irgendeine Gruppe; Liste = eine der genannten
        $needed = is_array($req) ? array_intersect($groups, $req) : $groups;
        if ($needed === []) {
            return t('Für diese Kennung ist keine Berechtigung hinterlegt.');
        }
    }
    return null;
}

// -------------------------------------------- Warteschlange zur Freischaltung

/** @return array<string,array> Kennung => {display, email, groups, first_seen, last_seen, tries} */
function pending_users(): array
{
    return db_map_all(db(), 'pending_users', 'name');
}

/**
 * Einen abgewiesenen Anmeldeversuch vormerken.
 *
 * Ohne das wäre 'auto_create' => false bei undurchsichtigen Kennungen
 * unbrauchbar: Niemand kann ein Konto vorab anlegen, dessen Kennung er nicht
 * kennt. So sieht die Verwaltung stattdessen Klarname und E-Mail und kann
 * mit einem Klick freischalten.
 */
function pending_user_note(string $username, ?string $display, ?string $email, array $groups, string $reason = 'unbekannt', string $source = 'sso'): void
{
    db_map_update(db(), 'pending_users', 'name', function (array $q) use ($username, $display, $email, $groups, $reason, $source) {
        $now = date('c');
        $q[$username] = [
            'reason' => $reason,
            // Aus welcher Anmeldeart die Anfrage kam. Ohne diese Angabe legte
            // die Freischaltung immer ein SSO-Konto an – auch bei einer
            // Anmeldung über das Verzeichnis. Wer sich danach per LDAP
            // anmeldete, galt als „andere Anmeldeart" und wurde abgewiesen:
            // freigeschaltet und trotzdem ausgesperrt.
            'source' => in_array($source, ['ldap', 'sso'], true) ? $source : 'sso',
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
    // Nur beim ersten Mal melden: Wer sich täglich erfolglos anmeldet, soll
    // nicht täglich eine Nachricht auslösen.
    if ((int)((pending_users()[$username]['tries'] ?? 0)) === 1) {
        hook_fire('user.pending', [
            'user' => $username,
            'display' => $display,
            'email' => $email,
            'reason' => $reason,
        ]);
    }
}

function pending_user_drop(string $username): void
{
    db_map_update(db(), 'pending_users', 'name', function (array $q) use ($username) {
        unset($q[$username]);
        return $q;
    });
}

/**
 * Vorgemerkte Kennung freischalten: Konto anlegen, damit die nächste Anmeldung
 * durchgeht. Der Rest (Gruppen, Mail, Name) kommt beim Login aus dem Verzeichnis.
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function pending_user_approve(string $username, ?string $source = null): ?string
{
    $q = pending_users();
    if (!isset($q[$username])) return t('Diese Kennung steht nicht in der Warteschlange.');
    $e = $q[$username];
    // Die Anmeldeart steht im Eintrag – sie stammt aus dem Anmeldeversuch, der
    // ihn erzeugt hat. Ein Wert von außen gilt nur, wenn er ausdrücklich
    // übergeben wird; vorgegeben wird hier nichts mehr. Einträge aus der Zeit
    // vor dieser Änderung tragen kein Feld, für sie bleibt es bei 'sso'.
    $source = $source ?? (string)($e['source'] ?? 'sso');
    if (!in_array($source, ['ldap', 'sso'], true)) $source = 'sso';
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
        return t('Ungültige Kennung aus der zentralen Anmeldung.');
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
    $current = user_get($username);
    if (!$force && $current !== null && ($current['auth'] ?? 'local') !== $source) {
        return t('Für diese Kennung gibt es bereits ein Konto mit anderer Anmeldeart.');
    }
    $err = null;
    $display = $display === null ? null : trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $display));
    // Vor dem Schreiben, nicht darin: Ob dies das allererste Konto der
    // Instanz ist, entscheidet die Rolle – der Schreibvorgang selbst fasst
    // dann nur noch dieses eine Konto an und bleibt auch mit Datenbank ein
    // einzelner Datensatz statt aller.
    $ersteAnlage = !users_exist();
    // Der Modus richtet sich nach der Anmeldeart, aus der das Konto stammt.
    $modus = (string)(($source === 'ldap' ? ldap_cfg() : sso_cfg())['group_sync'] ?? 'merge');
    if (!in_array($modus, ['off', 'merge', 'replace'], true)) $modus = 'merge';

    users_update(function (array $users) use ($username, $source, $email, $groups, $autoCreate, $display, $ersteAnlage, $modus, &$err) {
        $exists = isset($users[$username]);
        if (!$exists && !$autoCreate) {
            $err = t('Für diese Kennung gibt es hier kein Konto, und die automatische Anlage ist deaktiviert.');
            return null;
        }
        if (!$exists) {
            $users[$username] = [
                'role' => $ersteAnlage ? 'admin' : 'user',
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
        // Wie sich Gruppen aus dem Verzeichnis zu den hier vergebenen
        // verhalten, entscheidet 'group_sync':
        //
        //   merge   – Verzeichnisgruppen kommen hinzu, hier vergebene bleiben.
        //             Voreinstellung, weil dabei nichts verlorengehen kann.
        //   replace – das Verzeichnis bestimmt allein. Richtig, wenn dort
        //             wirklich alle Zuordnungen gepflegt werden; wer daneben
        //             von Hand etwas zuweist, verliert es beim nächsten Login.
        //   off     – Gruppen kommen nie von außen, sie werden nur hier vergeben.
        //
        // Vorher wurde immer ersetzt. Das kostete stillschweigend jede von Hand
        // gesetzte Zuordnung, sobald das Verzeichnis keine passende Gruppe
        // lieferte – und mit leerer group_map liefert es meistens keine.
        $bisher = (array)($users[$username]['groups'] ?? []);
        if ($modus === 'off') {
            $users[$username]['groups'] = $bisher;
        } elseif ($modus === 'replace') {
            $users[$username]['groups'] = $groups;
        } else {
            $users[$username]['groups'] = array_values(array_unique(array_merge($bisher, $groups)));
        }
        $users[$username]['last_login'] = date('c');
        return $users;
    }, $username);
    return $err;
}

/** Sitzung für ein extern authentifiziertes Konto eröffnen */
function sso_start_session(string $username): void
{
    // Auch wer über LDAP oder SSO kommt, kommt nicht in ein gesperrtes Konto.
    // Ohne diese Prüfung liefe die Sperre genau bei den Konten ins Leere, für
    // die der Abgleich sie überhaupt setzt.
    if (user_locked(user_get($username))) {
        ldap_log('Anmeldung in gesperrtes Konto abgewiesen', $username);
        return;
    }
    auth_boot();
    session_regenerate_id(true);
    $_SESSION['user'] = $username;
    session_register($username);
    $_SESSION['auth_source'] = user_get($username)['auth'] ?? 'local';
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

    if (!valid_external_id($id['user'])) return t('Ungültige Kennung aus der zentralen Anmeldung.');

    $deny = access_denied_reason($id['user'], $id['groups'], $c);
    if ($deny !== null) return $deny;

    // Wie bei ldap_login(): vor user_provision(), damit ein gesperrtes
    // Konto keinen Anmeldezeitpunkt für eine Anmeldung bekommt, die es
    // nicht gibt – und die Person eine Auskunft statt eines leeren
    // Formulars.
    if (user_locked(user_get($id['user']))) {
        ldap_log('Anmeldung in gesperrtes Konto abgewiesen (SSO)', $id['user']);
        return t('Dieses Konto ist gesperrt. Bitte wende dich an die Verwaltung.');
    }

    $existing = user_get($id['user']);
    $fremd = $existing !== null && ($existing['auth'] ?? 'local') !== 'sso';
    if ($existing === null && !$c['auto_create'] || $fremd) {
        if ($c['approval_queue']) {
            pending_user_note($id['user'], $id['display'] ?? null, $id['email'], $id['groups'],
                $fremd ? 'kollision' : 'unbekannt', 'sso');
            return $fremd
                ? t('Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart. Die Verknüpfung muss ein Administrator bestätigen.')
                : t('Dein Zugang ist noch nicht freigeschaltet. Die Anfrage liegt jetzt zur Prüfung vor.');
        }
        return $fremd
            ? t('Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart.')
            : t('Für diese Kennung gibt es hier kein Konto.');
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
/**
 * Warum die Anmeldung scheiterte – für das Fehlerprotokoll, nicht für den Browser.
 *
 * Wer sich anmeldet, bekommt weiterhin nur „Anmeldung fehlgeschlagen": Ob eine
 * Kennung existiert, geht niemanden etwas an, der sie nicht schon kennt. Wer
 * die Instanz betreibt, steht aber vor demselben Satz und weiß nicht, ob das
 * Dienstkonto falsch ist, die Basis-DN, der Filter – oder ob schlicht die
 * PHP-Erweiterung fehlt. Acht Ursachen, ein Satz: Das war nicht zu
 * diagnostizieren.
 *
 * Das Passwort steht hier nie, die Kennung schon: Ohne sie ließe sich eine
 * einzelne fehlgeschlagene Anmeldung nicht wiederfinden.
 */
function ldap_log(string $was, string $kennung = '', $conn = null): void
{
    $detail = '';
    if ($conn !== null && function_exists('ldap_error')) {
        $e = @ldap_error($conn);
        if (is_string($e) && $e !== '' && $e !== 'Success') $detail = ' – LDAP meldet: ' . $e;
    }
    error_log('flatlink LDAP: ' . $was . ($kennung !== '' ? ' (Kennung: ' . $kennung . ')' : '') . $detail);
}

function ldap_authenticate(string $username, string $password): ?array
{
    // Leere Passwörter würden als "unauthenticated bind" durchgehen und
    // fälschlich als Erfolg gelten – der Klassiker unter den LDAP-Lücken.
    if ($password === '' || $username === '') return null;
    if (!ldap_enabled()) {
        // Der häufigste Fall beim Einrichten: Erweiterung fehlt oder der
        // Schalter steht noch auf false.
        if (!extension_loaded('ldap')) {
            ldap_log('Die PHP-Erweiterung ldap fehlt (apt install php-ldap, dann Apache neu laden)');
        }
        return null;
    }

    $c = ldap_cfg();
    $conn = @ldap_connect((string)$c['uri']);
    if ($conn === false) {
        ldap_log('Verbindung nicht möglich – stimmt die Adresse? ' . (string)$c['uri']);
        return null;
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)$c['timeout']);

    try {
        if ($c['start_tls'] && !@ldap_start_tls($conn)) {
            ldap_log('START_TLS abgelehnt – Zertifikat des Servers prüfbar?', '', $conn);
            return null;
        }

        // Suche mit Dienstkonto (leer = anonym)
        if (!@ldap_bind($conn, $c['bind_dn'] !== '' ? (string)$c['bind_dn'] : null,
                        $c['bind_dn'] !== '' ? (string)$c['bind_pass'] : null)) {
            // Die Meldung des Servers steht ohnehin dahinter und ist genauer,
            // als eine Vermutung es wäre: „Can't contact LDAP server" heißt
            // Adresse oder Port, „Invalid credentials" heißt Dienstkonto.
            ldap_log('Bind für die Suche fehlgeschlagen ('
                . ($c['bind_dn'] === '' ? 'anonym' : 'als ' . (string)$c['bind_dn']) . ')', '', $conn);
            return null;
        }

        // LDAP-Injection: Nutzereingabe gehört escaped in den Filter
        $safe = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $filter = str_replace('%s', $safe, (string)$c['user_filter']);
        $attrs = array_values(array_filter([(string)$c['mail_attr'], (string)$c['name_attr'], 'memberOf', 'dn']));
        $res = @ldap_search($conn, (string)$c['base_dn'], $filter, $attrs, 0, 2, (int)$c['timeout']);
        if ($res === false) {
            ldap_log('Suche fehlgeschlagen – stimmt die Basis-DN? ' . (string)$c['base_dn'], $username, $conn);
            return null;
        }
        $entries = @ldap_get_entries($conn, $res);
        // Genau ein Treffer – mehrdeutige Kennungen werden abgelehnt
        if (!is_array($entries) || ($entries['count'] ?? 0) !== 1) {
            $n = is_array($entries) ? (int)($entries['count'] ?? 0) : 0;
            ldap_log($n === 0
                ? 'Kein Treffer für den Filter ' . $filter . ' unterhalb von ' . (string)$c['base_dn']
                : $n . ' Treffer für den Filter ' . $filter . ' – die Kennung ist dort nicht eindeutig',
                $username);
            return null;
        }
        $dn = (string)($entries[0]['dn'] ?? '');
        if ($dn === '') return null;

        // Die eigentliche Prüfung: Bind als der gefundene Nutzer
        if (!@ldap_bind($conn, $dn, $password)) {
            // Der einzige Fall, in dem „Anmeldung fehlgeschlagen" auch stimmt:
            // gefunden, aber das Passwort passt nicht.
            ldap_log('Passwort abgelehnt für ' . $dn, $username, $conn);
            return null;
        }

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
/**
 * Welches Attribut trägt die Kennung?
 *
 * Konfigurierbar über `uid_attr`; ohne Angabe wird es aus dem `user_filter`
 * gelesen – wer dort `(sAMAccountName=%s)` stehen hat, meint dieses Attribut
 * auch beim Suchen. Das erspart einen zweiten Eintrag, der zwangsläufig
 * irgendwann vom ersten abweicht.
 */
function ldap_uid_attr(array $c): string
{
    $gesetzt = trim((string)($c['uid_attr'] ?? ''));
    if ($gesetzt !== '') return $gesetzt;
    if (preg_match('/\(([a-zA-Z][a-zA-Z0-9-]*)=%s\)/', (string)($c['user_filter'] ?? ''), $m) === 1) {
        return $m[1];
    }
    return 'uid';
}

/**
 * Den Suchfilter für die Personensuche bauen.
 *
 * Ohne eigenen `search_filter` entsteht er aus den Attributen, die für diese
 * Instanz ohnehin konfiguriert sind – Kennung, Klarname, E-Mail –, ergänzt um
 * die üblichen Namensfelder. Das ist der Punkt: Ein fest verdrahtetes
 * `(cn=*%s*)` findet an einem Verzeichnis nichts, das seinen Anzeigenamen in
 * einem eigenen Feld führt, und niemand sollte dafür einen LDAP-Filter
 * schreiben müssen. Attribute, die es nicht gibt, liefern einfach nichts.
 *
 * Mehrere Wörter werden UND-verknüpft, jedes für sich über alle Attribute:
 * „Dennis Bormann" findet damit auch einen Eintrag „Bormann, Dennis" – und
 * eine Suche nach zwei Namensteilen wird enger statt breiter.
 */
function ldap_search_filter(array $c, string $suche): string
{
    $eigen = trim((string)($c['search_filter'] ?? ''));
    if ($eigen !== '') {
        return str_replace('%s', ldap_escape($suche, '', LDAP_ESCAPE_FILTER), $eigen);
    }

    $attrs = array_values(array_unique(array_filter([
        ldap_uid_attr($c),
        (string)($c['name_attr'] ?? ''),
        (string)($c['mail_attr'] ?? ''),
        'cn', 'sn', 'givenName', 'mail',
    ])));

    $woerter = preg_split('/\s+/u', trim($suche), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $teile = [];
    foreach ($woerter as $wort) {
        $safe = ldap_escape($wort, '', LDAP_ESCAPE_FILTER);
        $oder = '';
        foreach ($attrs as $a) $oder .= '(' . $a . '=*' . $safe . '*)';
        $teile[] = '(|' . $oder . ')';
    }
    if ($teile === []) return '';
    return count($teile) === 1 ? $teile[0] : '(&' . implode('', $teile) . ')';
}

/**
 * Im Verzeichnis nach Personen suchen – für die Nutzerverwaltung.
 *
 * Bisher entstand ein Konto erst, nachdem sich jemand einmal vergeblich
 * angemeldet hatte: Der Versuch legte einen Eintrag in der Warteschlange an,
 * den ein Administrator freischaltete. Das funktioniert, mutet den Leuten aber
 * einen Fehlschlag zu, den sie nicht einordnen können – und wer ein Konto
 * vorbereiten will, bevor jemand anfängt, kann es gar nicht.
 *
 * Gesucht wird mit dem Dienstkonto, also mit denselben Rechten wie bei der
 * Anmeldung. Angelegt wird hier nichts; das entscheidet der Aufrufer.
 *
 * @return array{0:?string,1:array<int,array{uid:string,name:string,mail:string,dn:string,vorhanden:bool}>}
 */
function ldap_directory_search(string $suche, int $limit = 25): array
{
    $suche = trim($suche);
    if (mb_strlen($suche) < 2) return [t('Bitte mindestens zwei Zeichen eingeben.'), []];
    if (!ldap_enabled()) {
        return [extension_loaded('ldap')
            ? t('Die Anmeldung über LDAP ist nicht eingeschaltet.')
            : t('Die PHP-Erweiterung ldap fehlt.'), []];
    }

    $c = ldap_cfg();
    $conn = @ldap_connect((string)$c['uri']);
    if ($conn === false) return [t('Verbindung nicht möglich – stimmt die Adresse?'), []];
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)$c['timeout']);

    try {
        if ($c['start_tls'] && !@ldap_start_tls($conn)) {
            ldap_log('START_TLS abgelehnt', '', $conn);
            return [t('Verschlüsselung (START_TLS) abgelehnt.'), []];
        }
        if (!@ldap_bind($conn, $c['bind_dn'] !== '' ? (string)$c['bind_dn'] : null,
                        $c['bind_dn'] !== '' ? (string)$c['bind_pass'] : null)) {
            ldap_log('Bind für die Suche fehlgeschlagen', '', $conn);
            return [t('Anmeldung am Verzeichnis fehlgeschlagen – Dienstkonto prüfen.'), []];
        }

        // Dieselbe Vorsicht wie bei der Anmeldung: Die Eingabe gehört escaped
        // in den Filter, sonst schreibt sie ihn um. Das erledigt der Filterbau.
        $filter = ldap_search_filter($c, $suche);
        if ($filter === '') return [t('Bitte mindestens zwei Zeichen eingeben.'), []];
        $uidAttr = ldap_uid_attr($c);
        $attrs = array_values(array_unique(array_filter([
            $uidAttr, (string)$c['mail_attr'], (string)$c['name_attr'],
        ])));
        // Ein Treffer mehr als angefragt: Daran lässt sich erkennen, dass die
        // Liste unvollständig ist, ohne alles zu holen.
        $res = @ldap_search($conn, (string)$c['base_dn'], $filter, $attrs, 0, $limit + 1, (int)$c['timeout']);
        if ($res === false) {
            ldap_log('Suche fehlgeschlagen – Basis-DN? ' . (string)$c['base_dn'], $suche, $conn);
            return [t('Die Suche schlug fehl – stimmt die Basis-DN?'), []];
        }
        $eintraege = @ldap_get_entries($conn, $res);
        $n = is_array($eintraege) ? (int)($eintraege['count'] ?? 0) : 0;

        $treffer = [];
        $vorhanden = users_all();
        for ($i = 0; $i < $n; $i++) {
            $e = $eintraege[$i];
            $hole = function (string $attr) use ($e): string {
                $k = strtolower($attr);
                return $attr !== '' && isset($e[$k][0]) ? (string)$e[$k][0] : '';
            };
            $uid = $hole($uidAttr);
            // Ohne Kennung ließe sich kein Konto anlegen – solche Einträge
            // (Verteiler, Funktionsobjekte) gehören nicht in die Liste.
            if ($uid === '' || !valid_external_id($uid)) continue;
            $treffer[] = [
                'uid' => $uid,
                'name' => $hole((string)$c['name_attr']),
                'mail' => $hole((string)$c['mail_attr']),
                'dn' => (string)($e['dn'] ?? ''),
                'vorhanden' => isset($vorhanden[$uid]),
            ];
        }
        usort($treffer, fn($a, $b) => strnatcasecmp($a['name'] ?: $a['uid'], $b['name'] ?: $b['uid']));
        return [null, $treffer];
    } finally {
        @ldap_unbind($conn);
    }
}

/**
 * Alle Kennungen aus dem Verzeichnis holen.
 *
 * Für den Abgleich, der Konten sperrt, deren Person das Haus verlassen hat.
 * Bewusst EINE Abfrage über den ganzen Baum statt einer je Konto: Bei
 * tausend Konten wären das tausend Anfragen an ein Verzeichnis, das
 * womöglich mit einer Ratenbegrenzung antwortet – und tausend Gelegenheiten,
 * dass eine davon in eine Zeitüberschreitung läuft und ein Konto zu Unrecht
 * als verschwunden gilt.
 *
 * Die Rückgabe unterscheidet ausdrücklich zwischen „das Verzeichnis sagt,
 * hier sind alle" und „ich konnte nicht fragen". Der Unterschied ist der
 * wichtigste in der ganzen Funktion: Wer ihn verwischt, sperrt beim ersten
 * Netzwerkfehler das gesamte Haus aus.
 *
 * @param int $limit Obergrenze; 0 = so viel, wie der Server hergibt
 * @return array{0:?string,1:string[]} [Fehlermeldung oder null, Kennungen kleingeschrieben]
 */
function ldap_alle_kennungen(int $limit = 0): array
{
    if (!ldap_enabled()) {
        return [extension_loaded('ldap')
            ? 'Die Anmeldung über LDAP ist nicht eingeschaltet.'
            : 'Die PHP-Erweiterung ldap fehlt.', []];
    }
    $c = ldap_cfg();
    $conn = @ldap_connect((string)$c['uri']);
    if ($conn === false) return ['Verbindung nicht möglich – stimmt die Adresse?', []];
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)$c['timeout']);

    try {
        if ($c['start_tls'] && !@ldap_start_tls($conn)) {
            ldap_log('START_TLS abgelehnt (Abgleich)', '', $conn);
            return ['Verschlüsselung (START_TLS) abgelehnt.', []];
        }
        if (!@ldap_bind($conn, $c['bind_dn'] !== '' ? (string)$c['bind_dn'] : null,
                        $c['bind_dn'] !== '' ? (string)$c['bind_pass'] : null)) {
            ldap_log('Bind für den Abgleich fehlgeschlagen', '', $conn);
            return ['Anmeldung am Verzeichnis fehlgeschlagen – Dienstkonto prüfen.', []];
        }

        $attr = ldap_uid_attr($c);
        // Jeder Eintrag, der dieses Attribut überhaupt trägt. Enger zu filtern
        // wäre gefährlich: Was der Filter nicht findet, gilt hinterher als
        // ausgeschieden.
        //
        // Geblättert, nicht am Stück: Ein Verzeichnis liefert nicht beliebig
        // viele Einträge auf einmal. Active Directory deckelt über
        // `MaxPageSize` bei 1000, OpenLDAP über `sizelimit` meist bei 500 –
        // und zwar nicht mit einem Fehler, sondern mit einer TEILMENGE plus
        // Hinweis. Wer den Hinweis nicht liest, hält die Teilmenge für das
        // ganze Verzeichnis und sperrt alles, was zufällig hinter der Grenze
        // steht. Bei 1200 Konten und einer Grenze von 1000 wären das 200
        // Beschäftigte – und mit 16,7 % bliebe es sogar unter der
        // Schmerzgrenze des Abgleichs.
        $out = [];
        $cookie = '';
        $seite = 500;
        $runden = 0;
        do {
            $steuerung = [[
                'oid' => LDAP_CONTROL_PAGEDRESULTS,
                'iscritical' => false,
                'value' => ['size' => $seite, 'cookie' => $cookie],
            ]];
            $res = @ldap_search($conn, (string)$c['base_dn'], '(' . $attr . '=*)',
                [$attr], 0, $limit, (int)$c['timeout'], LDAP_DEREF_NEVER, $steuerung);
            if ($res === false) {
                ldap_log('Suche für den Abgleich fehlgeschlagen', '', $conn);
                return ['Die Suche im Verzeichnis schlug fehl.', []];
            }
            // Fehler 4 heißt: Der Server hat gekürzt. Für einen Abgleich, der
            // aus Abwesenheit auf Ausscheiden schließt, ist eine gekürzte
            // Liste genauso unbrauchbar wie gar keine – und deshalb bricht er
            // hier mit derselben Begründung ab.
            $nr = ldap_errno($conn);
            if ($nr === 4) {
                ldap_log('Verzeichnis kürzte die Antwort (sizelimit)', '', $conn);
                return ['Das Verzeichnis lieferte nur einen Teil seiner Einträge '
                      . '(Grenze des Servers erreicht).', []];
            }

            $eintraege = @ldap_get_entries($conn, $res);
            if (!is_array($eintraege)) return ['Das Verzeichnis antwortete unlesbar.', []];
            for ($i = 0; $i < (int)($eintraege['count'] ?? 0); $i++) {
                $wert = $eintraege[$i][strtolower($attr)][0] ?? null;
                if (is_string($wert) && $wert !== '') $out[] = mb_strtolower($wert);
            }

            // Das Cookie sagt, ob noch eine Seite folgt. Fehlt die Unterstützung
            // im Server, kommt keines zurück und die Schleife endet nach dem
            // ersten Durchgang – dann greift oben die Prüfung auf Fehler 4.
            $cookie = '';
            if (@ldap_parse_result($conn, $res, $fehlercode, $matched, $meldung,
                                   $verweise, $antwort)) {
                $cookie = (string)($antwort[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '');
            }
        } while ($cookie !== '' && ++$runden < 200);

        if ($cookie !== '') {
            // 200 Seiten à 500 sind 100.000 Einträge. Wer mehr hat, bekommt
            // lieber einen Abbruch als eine halbe Liste.
            return ['Das Verzeichnis ist größer als erwartet – der Abgleich '
                  . 'brach nach 200 Seiten ab.', []];
        }
        return [null, array_values(array_unique($out))];
    } finally {
        @ldap_unbind($conn);
    }
}

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
    if ($id === null) return t('Anmeldung fehlgeschlagen.');
    $c = ldap_cfg();

    if (!valid_external_id($id['user'])) return t('Ungültige Kennung aus dem Verzeichnis.');

    $deny = access_denied_reason($id['user'], $id['groups'], $c);
    if ($deny !== null) return $deny;

    // Gesperrt: Hier abbiegen, nicht erst in sso_start_session(). Sonst liefe
    // vorher user_provision() durch und schriebe `last_login` für eine
    // Anmeldung, die nicht stattfindet – und die Person bekäme wortlos wieder
    // das Anmeldeformular. Klartext ist hier richtig: Wer bis hierher kommt,
    // hat sich am Verzeichnis bereits ausgewiesen, die Auskunft verrät ihm
    // also nichts Neues und erspart einen Anruf beim Support.
    if (user_locked(user_get($id['user']))) {
        ldap_log('Anmeldung in gesperrtes Konto abgewiesen', $id['user']);
        return t('Dieses Konto ist gesperrt. Bitte wende dich an die Verwaltung.');
    }

    $existing = user_get($id['user']) ?? null;
    $fremd = $existing !== null && ($existing['auth'] ?? 'local') !== 'ldap';
    if ($existing === null && !$c['auto_create'] || $fremd) {
        if ($c['approval_queue']) {
            pending_user_note($id['user'], $id['display'] ?? null, $id['email'], $id['groups'],
                $fremd ? 'kollision' : 'unbekannt', 'ldap');
            return $fremd
                ? t('Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart. Die Verknüpfung muss ein Administrator bestätigen.')
                : t('Dein Zugang ist noch nicht freigeschaltet. Die Anfrage liegt jetzt zur Prüfung vor.');
        }
        return $fremd
            ? t('Unter dieser Kennung gibt es bereits ein Konto mit anderer Anmeldeart.')
            : t('Für diese Kennung gibt es hier kein Konto.');
    }

    $err = user_provision($id['user'], 'ldap', $id['email'], $id['groups'],
        (bool)$c['auto_create'], $id['display'] ?? null);
    if ($err !== null) return $err;
    sso_start_session($id['user']);
    return null;
}
