<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/store.php';

/**
 * Gruppen und Berechtigungen.
 *
 * Eine Gruppe bündelt zweierlei: geteilten Zugriff auf Kurzlinks (jedes
 * Mitglied darf die Links der Gruppe verwalten) und Rechte (was Mitglieder
 * überhaupt dürfen). Ein Konto kann in beliebig vielen Gruppen sein; seine
 * Rechte sind die Vereinigung aller Gruppenrechte.
 *
 * Ablage: data/groups.json
 *   { "marketing": { "name": "Marketing", "perms": ["custom_code"], "created": "..." } }
 * Mitgliedschaften stehen am Konto in users.json unter "groups".
 */

/** Alle vergebbaren Rechte mit Beschriftung für die Oberfläche */
function perms_all(): array
{
    return [
        'custom_code' => 'Wunsch-Namen vergeben',
        'csv_import'  => 'Links per CSV importieren',
        'logo_upload' => 'Eigene Logos hochladen',
    ];
}

function groups_file(): string
{
    return data_path() . '/groups.json';
}

/** @return array<string,array{name:string,perms:string[],created:string}> */
function groups_all(): array
{
    return json_read(groups_file());
}

function group_get(string $id): ?array
{
    return groups_all()[$id] ?? null;
}

/** Anzeigename einer Gruppe (fällt auf die ID zurück, falls gelöscht) */
function group_label(string $id): string
{
    return groups_all()[$id]['name'] ?? $id;
}

function valid_group_id(string $id): bool
{
    return preg_match('/^[a-z0-9._-]{2,32}$/', $id) === 1;
}

/**
 * Gruppe anlegen oder umbenennen/umrechten.
 * @param string[] $perms
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function group_save(string $id, string $name, array $perms): ?string
{
    if (!valid_group_id($id)) {
        return 'Gruppen-Kennung: 2–32 Zeichen, nur Kleinbuchstaben, Ziffern, Punkt, Minus, Unterstrich.';
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 64) return 'Anzeigename: 1–64 Zeichen.';
    $perms = array_values(array_intersect($perms, array_keys(perms_all())));

    json_update(groups_file(), function (array $groups) use ($id, $name, $perms) {
        $groups[$id] = [
            'name' => $name,
            'perms' => $perms,
            'created' => $groups[$id]['created'] ?? date('c'),
        ];
        return $groups;
    });
    return null;
}

/**
 * Gruppe löschen. Mitgliedschaften und die Zuordnung an Kurzlinks werden
 * mit entfernt, damit keine verwaisten Verweise zurückbleiben – die Links
 * selbst bleiben bestehen und gehören danach nur noch ihren Besitzern.
 */
function group_delete(string $id): void
{
    json_update(groups_file(), function (array $groups) use ($id) {
        unset($groups[$id]);
        return $groups;
    });
    json_update(users_file(), function (array $users) use ($id) {
        foreach ($users as $k => $u) {
            if (in_array($id, $u['groups'] ?? [], true)) {
                $users[$k]['groups'] = array_values(array_diff($u['groups'], [$id]));
            }
        }
        return $users;
    });
    json_update(links_file(), function (array $links) use ($id) {
        foreach ($links as $c => $l) {
            if (($l['group'] ?? null) === $id) unset($links[$c]['group']);
        }
        return $links;
    });
}

// ---- Mitgliedschaften ----

/** @return string[] Gruppen-IDs eines Kontos (nur solche, die es noch gibt) */
function user_groups(string $username): array
{
    $u = users_all()[$username] ?? null;
    if ($u === null) return [];
    $known = groups_all();
    return array_values(array_filter($u['groups'] ?? [], fn($g) => isset($known[$g])));
}

/**
 * Gruppenmitgliedschaften eines Kontos setzen (ersetzt die bisherigen).
 * @param string[] $groups
 */
function user_set_groups(string $username, array $groups): void
{
    $known = array_keys(groups_all());
    $groups = array_values(array_unique(array_intersect($groups, $known)));
    json_update(users_file(), function (array $users) use ($username, $groups) {
        if (!isset($users[$username])) return null;
        $users[$username]['groups'] = $groups;
        return $users;
    });
}

/** @return string[] Kontonamen, die in dieser Gruppe sind */
function group_members(string $id): array
{
    $out = [];
    foreach (users_all() as $name => $u) {
        if (in_array($id, $u['groups'] ?? [], true)) $out[] = (string)$name;
    }
    return $out;
}

// ---- Rechte ----

/**
 * Darf dieses Konto das? Admins dürfen alles. Sonst gilt die Vereinigung der
 * Rechte aller Gruppen, in denen das Konto ist – plus der Standardrechte aus
 * der Konfiguration, die für alle angemeldeten Konten gelten.
 */
function user_can(string $username, string $perm): bool
{
    $u = users_all()[$username] ?? null;
    if ($u === null) return false;
    if (($u['role'] ?? '') === 'admin') return true;
    return in_array($perm, user_perms($username), true);
}

// ---- Zugriff auf Kurzlinks ----

/**
 * Darf dieses Konto den Link sehen und verwalten?
 * Zugriff hat, wem der Link gehört, wer in seiner Gruppe ist – und jeder Admin.
 * Gruppenlinks sind bewusst voll verwaltbar: Genau dafür gibt es Gruppen.
 *
 * @param array{name:string,role:string} $user
 */
function link_access(array $user, array $link): bool
{
    if (($user['role'] ?? '') === 'admin') return true;
    if (($link['owner'] ?? null) === $user['name']) return true;
    $g = $link['group'] ?? null;
    return is_string($g) && in_array($g, user_groups($user['name']), true);
}

/**
 * Alle Links, auf die ein Konto Zugriff hat (eigene + die seiner Gruppen).
 * @param array{name:string,role:string} $user
 */
function links_visible(array $user): array
{
    if (($user['role'] ?? '') === 'admin') return links_all();
    return array_filter(links_all(), fn($l) => link_access($user, $l));
}

/** @return string[] Alle Rechte eines Kontos */
function user_perms(string $username): array
{
    $u = users_all()[$username] ?? null;
    if ($u === null) return [];
    if (($u['role'] ?? '') === 'admin') return array_keys(perms_all());

    $perms = (array)(cfg('default_perms') ?? []);
    $groups = groups_all();
    foreach (user_groups($username) as $g) {
        $perms = array_merge($perms, $groups[$g]['perms'] ?? []);
    }
    return array_values(array_unique(array_intersect($perms, array_keys(perms_all()))));
}
