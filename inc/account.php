<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
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
require_once __DIR__ . '/token.php';

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
    $u = user_get($username) ?? [];
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
    foreach (links_of_owner($username) as $code => $l) {
        $c = clicks_get((string)$code);
        $links[] = [
            'kurzlink' => short_url((string)$code, (string)($l['domain'] ?? '')),
            'code' => (string)$code,
            'name' => $l['title'] ?? null,
            'schlagworte' => array_values((array)($l['tags'] ?? [])),
            'ziel' => $l['url'] ?? null,
            'art' => ($l['type'] ?? '') === 'custom' ? 'Wunsch-Name' : 'Zufallscode',
            'gruppe' => $l['group'] ?? null,
            'angelegt' => $l['created'] ?? null,
            'geaendert' => $l['updated'] ?? null,
            'startet_ab' => $l['starts'] ?? null,
            'laeuft_ab' => $l['expires'] ?? null,
            'passwortgeschuetzt' => isset($l['pass']),
            'gesperrt' => (bool)($l['disabled'] ?? false),
            // Wer das Ziel wann geändert hat – dieselbe Liste, die in der
            // Statistik des Links steht
            'ziel_aenderungen' => array_map(fn($h) => [
                'zeitpunkt' => $h['t'] ?? null,
                'wer' => $h['wer'] ?? null,
                'von' => $h['von'] ?? null,
                'nach' => $h['nach'] ?? null,
            ], (array)($l['history'] ?? [])),
            // Link-in-Bio: die Seite selbst ist öffentlich, gehört aber
            // genauso in die Auskunft wie jeder andere hinterlegte Inhalt
            'bio_seite' => ($l['kind'] ?? '') !== 'bio' ? null : [
                'einleitung' => $l['bio_text'] ?? null,
                'in_suchmaschinen' => (bool)($l['bio_index'] ?? false),
                'logo' => $l['bio_logo'] ?? null,
                'farben' => (array)($l['bio_colors'] ?? []),
                'ziele' => array_map(fn($i) => [
                    'beschriftung' => $i['label'] ?? null,
                    'ziel' => $i['url'] ?? null,
                ], (array)($l['items'] ?? [])),
            ],
            'klicks' => [
                'gesamt' => $c['n'] ?? 0,
                'letzter_aufruf' => $c['last'] ?? null,
                'je_tag' => $c['days'] ?? [],
                // Summen je Merkmal, keine Einzelaufrufe – dieselben Zahlen,
                // die in der Statistik des Links stehen
                'je_herkunft' => $c['refs'] ?? [],
                'je_geraet' => $c['devs'] ?? [],
                'je_sprache' => $c['langs'] ?? [],
            ],
        ];
    }
    usort($links, fn($a, $b) => strcmp((string)$b['angelegt'], (string)$a['angelegt']));

    return [
        'hinweis' => t('Auskunft nach Art. 15 und Datenübertragbarkeit nach Art. 20 DSGVO. Diese Datei enthält alles, was %s zu diesem Konto gespeichert hat – ohne den Passwort-Hash.', cfg('site_name')),
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
        // Angemeldete Geräte: die Liste aus dem Profil. Der Abdruck der
        // Sitzung selbst bleibt draußen – er ist der Schlüssel zur Sitzung,
        // kein Inhalt, und stünde in einer Datei im Download-Ordner falsch.
        'angemeldete_geraete' => array_values(array_map(fn($x) => [
            'geraet' => $x['geraet'] ?? null,
            'seit' => $x['seit'] ?? null,
            'zuletzt_gesehen' => $x['zuletzt'] ?? null,
        ], (array)($u['sessions'] ?? []))),
        // Zwei-Faktor: dass es sie gibt, nicht womit sie rechnet
        'zwei_faktor' => [
            'app_eingerichtet' => (bool)($u['totp']['confirmed'] ?? false),
            'wiederherstellungscodes_offen' => count((array)($u['totp']['recovery'] ?? [])),
            'passkeys' => array_values(array_map(fn($p) => [
                'bezeichnung' => $p['label'] ?? null,
                'angelegt' => $p['created'] ?? null,
                'zuletzt_benutzt' => $p['last_used'] ?? null,
            ], (array)($u['passkeys'] ?? []))),
        ],
        // Zugangsschlüssel der Schnittstelle – ohne den Schlüssel selbst,
        // den es nach der einmaligen Anzeige nirgends mehr gibt
        'zugangsschluessel' => array_values(array_map(fn($t) => [
            'bezeichnung' => $t['label'] ?? null,
            'erkennungszeichen' => $t['hint'] ?? null,
            'angelegt' => $t['created'] ?? null,
            'zuletzt_benutzt' => $t['last_used'] ?? null,
        ], tokens_of($username))),
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
            'Das Geheimnis der Authenticator-App, die Hashes der Wiederherstellungscodes, das Schlüsselmaterial der Passkeys und die Hashes der Zugangsschlüssel – ebenfalls Zugangsmittel.',
            'Der Abdruck laufender Sitzungen – er ist der Schlüssel zur Sitzung.',
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
    foreach (links_of_owner($username) as $l) {
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
        return t('Konten werden auf dieser Instanz zentral verwaltet und können nicht selbst gelöscht werden.');
    }
    // Dieselbe Sperre wie in der Nutzerverwaltung: Eine Instanz ohne
    // Administrator ließe sich nicht mehr bedienen.
    if ((user_get($username)['role'] ?? '') === 'admin' && admin_count() <= 1) {
        return t('Du bist der letzte Administrator. Ernenne erst jemand anderen, dann geht das.');
    }

    foreach (links_of_owner($username) as $code => $l) {
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

    // Zugangsschlüssel gehören zum Konto und dürfen es nicht überleben
    tokens_drop_user($username);

    return user_delete($username);
}
