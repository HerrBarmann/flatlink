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
        'qr_unbranded' => 'QR-Codes ohne Absenderzeile',
        'api_access' => 'Zugriff über die Programmierschnittstelle (API)',
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
/**
 * Gruppe anlegen oder ändern.
 *
 * $shared entscheidet über die Betriebsart und ist die wichtigste Angabe
 * überhaupt – siehe group_shared().
 */
function group_save(string $id, string $name, array $perms, array $limits = [], string $prefix = '', bool $shared = false): ?string
{
    if (!valid_group_id($id)) {
        return 'Gruppen-Kennung: 2–32 Zeichen, nur Kleinbuchstaben, Ziffern, Punkt, Minus, Unterstrich.';
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 64) return 'Anzeigename: 1–64 Zeichen.';
    $perms = array_values(array_intersect($perms, array_keys(perms_all())));

    // Eigene Limits sind optional; 0 oder leer heißt "kein eigener Wert",
    // dann gilt für dieses Konto weiter das globale Limit
    $clean = [];
    foreach (['links', 'stats_days', 'logos'] as $k) {
        $v = (int)($limits[$k] ?? 0);
        if ($v > 0) $clean[$k] = $v;
    }

    $prefix = strtolower(trim($prefix, " /"));
    if ($prefix !== '' && preg_match('/^[a-z0-9_-]{1,32}$/', $prefix) !== 1) {
        return 'Präfix: 1–32 Zeichen, nur Kleinbuchstaben, Ziffern, Punkt, Minus, Unterstrich.';
    }

    json_update(groups_file(), function (array $groups) use ($id, $name, $perms, $clean, $prefix, $shared) {
        $groups[$id] = [
            'name' => $name,
            'perms' => $perms,
            'limits' => $clean,
            'prefix' => $prefix,
            'shared' => $shared,
            'created' => $groups[$id]['created'] ?? date('c'),
        ];
        return $groups;
    });
    return null;
}

// ---- Namensraum-Präfixe ----

/** Präfix einer Gruppe ('' = keins) */
function group_prefix(string $id): string
{
    return (string)(groups_all()[$id]['prefix'] ?? '');
}

/**
 * Präfixe, unter denen ein Konto Kurzlinks anlegen darf.
 *
 * Leeres Ergebnis heißt: keine Beschränkung – der Link landet direkt im
 * Wurzel-Namensraum. Sobald aber auch nur eine Gruppe des Kontos ein Präfix
 * führt, sind ausschließlich diese Präfixe erlaubt. So bekommt die
 * Bibliothek /bib/… und die Studierendenschaft /stud/…, ohne sich
 * gegenseitig den Namensraum wegzunehmen.
 *
 * @return string[]
 */
function user_prefixes(string $username): array
{
    $out = [];
    $groups = groups_all();
    foreach (user_groups($username) as $g) {
        $p = (string)($groups[$g]['prefix'] ?? '');
        if ($p !== '') $out[] = $p;
    }
    return array_values(array_unique($out));
}

/** Alle vergebenen Präfixe – dürfen nicht als gewöhnlicher Code belegt werden */
function all_prefixes(): array
{
    $out = [];
    foreach (groups_all() as $g) {
        $p = (string)($g['prefix'] ?? '');
        if ($p !== '') $out[] = $p;
    }
    return array_values(array_unique($out));
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
    // Über alle Ablagen: die Zuordnung entfernen, die Links selbst behalten
    foreach (link_store_files() as $file) {
        json_update($file, function (array $links) use ($id) {
            $touched = false;
            foreach ($links as $c => $l) {
                if (($l['group'] ?? null) === $id) { unset($links[$c]['group']); $touched = true; }
            }
            return $touched ? $links : null;
        });
    }
}

// ---- Mitgliedschaften ----

/**
 * Gruppen-IDs eines Kontos. Gefiltert wird zweifach: Gruppen, die es nicht
 * mehr gibt, und Mitgliedschaften, deren Befristung abgelaufen ist. Letzteres
 * geschieht rein bei der Auswertung – kein Cronjob, keine Aufräumläufe. Der
 * Eintrag bleibt stehen und lebt wieder auf, wenn die Frist verlängert wird.
 *
 * @return string[]
 */
function user_groups(string $username): array
{
    $u = users_all()[$username] ?? null;
    if ($u === null) return [];
    $known = groups_all();
    $until = (array)($u['groups_until'] ?? []);
    $today = date('Y-m-d');
    return array_values(array_filter(
        $u['groups'] ?? [],
        fn($g) => isset($known[$g]) && (!isset($until[$g]) || $until[$g] >= $today)
    ));
}

/** Ablaufdatum einer Mitgliedschaft ('YYYY-MM-DD') oder null = unbefristet */
function user_group_until(string $username, string $group): ?string
{
    $v = users_all()[$username]['groups_until'][$group] ?? null;
    return is_string($v) ? $v : null;
}

/**
 * Gruppenmitgliedschaften eines Kontos setzen (ersetzt die bisherigen).
 * $until befristet alle hier gesetzten Gruppen auf dieses Datum
 * ('YYYY-MM-DD'); null = unbefristet.
 *
 * @param string[] $groups
 */
function user_set_groups(string $username, array $groups, ?string $until = null): void
{
    $known = array_keys(groups_all());
    $groups = array_values(array_unique(array_intersect($groups, $known)));
    if ($until !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) $until = null;

    json_update(users_file(), function (array $users) use ($username, $groups, $until) {
        if (!isset($users[$username])) return null;
        $users[$username]['groups'] = $groups;
        // Befristungen nur für die jetzt gesetzten Gruppen führen
        $map = [];
        foreach ($groups as $g) {
            $keep = $until ?? ($users[$username]['groups_until'][$g] ?? null);
            if (is_string($keep)) $map[$g] = $keep;
        }
        if ($map === []) {
            unset($users[$username]['groups_until']);
        } else {
            $users[$username]['groups_until'] = $map;
        }
        return $users;
    });
}

/** @return string[] Kontonamen mit gültiger Mitgliedschaft in dieser Gruppe */
function group_members(string $id): array
{
    $out = [];
    foreach (users_all() as $name => $u) {
        if (in_array($id, user_groups((string)$name), true)) $out[] = (string)$name;
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

// ---- Nutzungs-Limits ----

/**
 * Limit eines Kontos ('links' | 'stats_days' | 'logos').
 *
 * Grundlage ist der globale Wert aus der Konfiguration. Gruppen können ihn
 * anheben: Wer in mehreren ist, bekommt den jeweils höchsten Wert. Admins
 * unterliegen keinen Limits, und eine 0 in der Konfiguration bedeutet
 * ebenfalls "unbegrenzt". Rückgabe ist immer eine Zahl, die sich direkt
 * vergleichen lässt.
 */
function user_limit(string $username, string $key): int
{
    $u = users_all()[$username] ?? null;
    if ($u !== null && ($u['role'] ?? '') === 'admin') return PHP_INT_MAX;

    $best = (int)(cfg('limits')[$key] ?? 0);
    if ($best === 0) return PHP_INT_MAX;   // global unbegrenzt schlägt alles

    $groups = groups_all();
    foreach (user_groups($username) as $g) {
        $v = (int)($groups[$g]['limits'][$key] ?? 0);
        if ($v === 0) continue;
        if ($v > $best) $best = $v;
    }
    return $best;
}

/** Limit für die Anzeige aufbereiten: unbegrenzte Werte als "∞" */
function limit_label(int $limit): string
{
    return $limit === PHP_INT_MAX ? '∞' : (string)$limit;
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
    // Rechtegruppen gewähren bewusst keinen Zugriff auf fremde Links
    return is_string($g) && in_array($g, user_shared_groups($user['name']), true);
}

/**
 * Teilt diese Gruppe auch Links, oder vergibt sie nur Rechte?
 *
 * Eine Gruppe kann zweierlei bedeuten, und die beiden haben nichts miteinander
 * zu tun:
 *
 *   - **Arbeitsgruppe** (`shared`): Was ihr zugeordnet wird, verwaltet das
 *     ganze Team gemeinsam. Für die Bibliothek, die Fachschaft, die Redaktion.
 *   - **Rechtegruppe**: Vergibt nur Berechtigungen und Limits an ihre
 *     Mitglieder. Für Tarife („Pro"), Rollen, Kontingente.
 *
 * Werden beide in einen Topf geworfen, entsteht ein handfestes Leck: Hängt ein
 * kostenpflichtiger Tarif an einer Gruppe, taucht diese im Zuordnungsfeld auf –
 * und ein Kunde, der sie versehentlich auswählt, gibt seinen Link für sämtliche
 * anderen zahlenden Kunden zum Bearbeiten und Löschen frei.
 *
 * Bestandsgruppen ohne Angabe gelten als Arbeitsgruppen: Sie wurden unter der
 * alten Bedeutung angelegt, und ihnen nachträglich den Zugriff zu entziehen
 * würde Teams aussperren. Neue Gruppen legt die Oberfläche als Rechtegruppe an,
 * weil der umgekehrte Irrtum teurer ist: Ein Team, das seine Links nicht sieht,
 * meldet sich sofort – ein Leck bemerkt niemand.
 */
function group_shared(string $id): bool
{
    return (bool)(groups_all()[$id]['shared'] ?? true);
}

/** @return string[] Nur die Gruppen eines Kontos, die Links gemeinsam verwalten */
function user_shared_groups(string $username): array
{
    return array_values(array_filter(user_groups($username), 'group_shared'));
}

/**
 * Alle Links, auf die ein Konto Zugriff hat (eigene + die seiner Arbeitsgruppen).
 * @param array{name:string,role:string} $user
 */
function links_visible(array $user): array
{
    if (($user['role'] ?? '') === 'admin') return links_all();
    return array_filter(links_all(), fn($l) => link_access($user, $l));
}

/**
 * Limit einer Gruppe für einen Schlüssel – oder den globalen Wert, wenn die
 * Gruppe keinen eigenen hat.
 *
 * Nützlich überall dort, wo über eine Gruppe geredet wird, ohne dass ein
 * konkretes Mitglied vorliegt: etwa auf einer Tarifseite. Zahlen dort von Hand
 * einzutragen heißt, dass sie irgendwann nicht mehr stimmen.
 */
function group_limit(string $group, string $key): int
{
    $eigen = (int)(groups_all()[$group]['limits'][$key] ?? 0);
    if ($eigen > 0) return $eigen;
    $global = (int)((array)cfg('limits'))[$key] ?? 0;
    return $global > 0 ? $global : PHP_INT_MAX;
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
