<?php
declare(strict_types=1);
/**
 * Auskunft, Mitnahme und Löschung des eigenen Kontos.
 *
 * Art. 15, 17 und 20 DSGVO gewähren jeder betroffenen Person Auskunft über die
 * gespeicherten Daten, deren Herausgabe in maschinenlesbarer Form und die
 * Löschung. Eine formlose E-Mail an den Betreiber erfüllt das ebenfalls – aber
 * sie kostet beide Seiten Zeit, und sie hängt daran, dass jemand das Postfach
 * liest. Ein Knopf erfüllt es sofort und nachweisbar.
 *
 * Eigene Datei, weil beides gleichzeitig Konten (auth.php), Links (store.php)
 * und Gruppen (groups.php) braucht – in einer der drei Schichten säße es
 * quer.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/groups.php';

/**
 * Alles, was zu einem Konto gespeichert ist, als verschachteltes Array.
 *
 * Bewusst nicht enthalten: der Passwort-Hash. Er ist ein Zugangsmittel, kein
 * Inhalt – ihn in eine Datei zu schreiben, die anschließend im Download-Ordner
 * liegt, schafft ein Risiko, ohne der Auskunft zu dienen. Vom IP-Hash des
 * Double-Opt-In steht nur da, dass er existiert: Der Wert selbst sagt der
 * betroffenen Person nichts, was sie nicht ohnehin weiß.
 */
function account_export(string $username): array
{
    $u = users_all()[$username] ?? [];
    $groups = groups_all();
    $perms = perms_all();

    $mitgliedschaften = [];
    foreach (user_groups($username) as $g) {
        $mitgliedschaften[] = [
            'kennung' => $g,
            'name' => $groups[$g]['name'] ?? $g,
            'befristet_bis' => user_group_until($username, $g),
        ];
    }

    $links = [];
    foreach (links_all() as $code => $l) {
        if (($l['owner'] ?? null) !== $username) continue;
        $c = clicks_get((string)$code);
        $links[] = [
            'kurzlink' => base_url() . '/' . $code,
            'code' => (string)$code,
            'name' => $l['title'] ?? null,
            'ziel' => $l['url'] ?? null,
            'art' => ($l['type'] ?? '') === 'custom' ? 'Wunsch-Name' : 'Zufallscode',
            'gruppe' => $l['group'] ?? null,
            'angelegt' => $l['created'] ?? null,
            'geaendert' => $l['updated'] ?? null,
            'laeuft_ab' => $l['expires'] ?? null,
            'passwortgeschuetzt' => isset($l['pass']),
            'gesperrt' => (bool)($l['disabled'] ?? false),
            'klicks' => [
                'gesamt' => $c['n'] ?? 0,
                'letzter_aufruf' => $c['last'] ?? null,
                'je_tag' => $c['days'] ?? [],
            ],
        ];
    }
    usort($links, fn($a, $b) => strcmp((string)$b['angelegt'], (string)$a['angelegt']));

    return [
        'hinweis' => 'Auskunft nach Art. 15 und Datenübertragbarkeit nach Art. 20 DSGVO. '
            . 'Diese Datei enthält alles, was ' . cfg('site_name') . ' zu diesem Konto '
            . 'gespeichert hat – ohne den Passwort-Hash.',
        'erstellt_am' => date('c'),
        'dienst' => cfg('site_name'),
        'konto' => [
            'kennung' => $username,
            'anzeigename' => user_has_display($username) ? user_display($username) : null,
            'email' => $u['email'] ?? null,
            'rolle' => $u['role'] ?? 'user',
            'anmeldung' => match ($u['auth'] ?? 'local') {
                'ldap' => 'LDAP-Verzeichnis',
                'sso' => 'zentrale Anmeldung (SSO)',
                default => 'lokales Passwort',
            },
            'angelegt' => $u['created'] ?? null,
            'bestaetigt' => $u['verified'] ?? null,
            'bestaetigung_ip_hash_gespeichert' => isset($u['verified_ip']),
            'letzte_anmeldung' => $u['last_login'] ?? null,
        ],
        'gruppen' => $mitgliedschaften,
        'rechte' => array_values(array_map(
            fn($p) => $perms[$p] ?? $p,
            user_perms($username)
        )),
        'grenzen' => [
            'links' => limit_label(user_limit($username, 'links')),
            'logos' => limit_label(user_limit($username, 'logos')),
            'statistik_tage' => limit_label(user_limit($username, 'stats_days')),
        ],
        'links' => $links,
        'nicht_enthalten' => [
            'Passwort-Hash – Zugangsmittel, kein Inhalt.',
            'Server-Protokolle des Hosting-Anbieters – liegen außerhalb dieser Anwendung.',
            'Einzelne Aufrufe von Kurzlinks – werden nie gespeichert, nur Tageszähler.',
        ],
    ];
}

/**
 * Wie viele Links beim Löschen des Kontos verschwinden und wie viele bleiben.
 * @return array{eigene:int,gruppe:int}
 */
function account_delete_scope(string $username): array
{
    $eigene = 0;
    $gruppe = 0;
    foreach (links_all() as $l) {
        if (($l['owner'] ?? null) !== $username) continue;
        if (($l['group'] ?? '') !== '') $gruppe++; else $eigene++;
    }
    return ['eigene' => $eigene, 'gruppe' => $gruppe];
}

/**
 * Konto endgültig löschen, samt der Links, die nur daran hängen.
 *
 * Links mit Gruppenzuordnung bleiben und verlieren nur den Besitzer: Sie
 * gehören der Gruppe, andere Mitglieder arbeiten damit weiter, und gedruckte
 * QR-Codes darauf würden sonst ins Leere zeigen, weil eine einzelne Person
 * geht. Alles ohne Gruppe ist ausschließlich Sache dieses Kontos und wird
 * mitgelöscht – zusammen mit den Klickzählern.
 *
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function account_delete(string $username): ?string
{
    if (!cfg('self_delete')) {
        return 'Konten werden auf dieser Instanz zentral verwaltet und können nicht selbst gelöscht werden.';
    }
    // Dieselbe Sperre wie in der Nutzerverwaltung: Eine Instanz ohne
    // Administrator ließe sich nicht mehr bedienen.
    if ((users_all()[$username]['role'] ?? '') === 'admin' && admin_count() <= 1) {
        return 'Du bist der letzte Administrator. Ernenne erst jemand anderen, dann geht das.';
    }

    foreach (links_all() as $code => $l) {
        if (($l['owner'] ?? null) !== $username) continue;
        if (($l['group'] ?? '') !== '') {
            link_write((string)$code, function (?array $l) {
                if ($l === null) return false;
                unset($l['owner']);
                $l['updated'] = date('c');
                return $l;
            });
        } else {
            link_delete((string)$code);
        }
    }

    // Offene Bestätigungen (E-Mail-Wechsel, Passwort-Reset) mitnehmen – sie
    // tragen die Kennung und wären sonst noch stundenlang einlösbar.
    foreach (glob(data_path('pending') . '/*.json') ?: [] as $f) {
        $d = json_read($f);
        if (($d['user'] ?? null) === $username) @unlink($f);
    }

    return user_delete($username);
}
