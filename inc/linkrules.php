<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Regeln, unter denen ein Konto einen Kurzlink anlegen oder ändern darf.
 *
 * Sie standen bisher ausgeschrieben in der Verwaltungsoberfläche. Sobald ein
 * zweiter Weg dazukommt – die API –, wäre das eine Einladung zum
 * Auseinanderlaufen: Eine später ergänzte Regel landet in einem der beiden
 * Zweige und fehlt im anderen. Solche Lücken merkt man erst, wenn jemand sie
 * benutzt. Deshalb steht hier die einzige Fassung, und beide Wege rufen sie.
 *
 * Bewusst nicht enthalten sind Dinge, die nur eine Oberfläche betreffen:
 * CSRF-Prüfung, Formularaufbau, Meldungen. Hier stehen nur Regeln, die
 * unabhängig davon gelten, wer fragt.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/safety.php';
require_once __DIR__ . '/domains.php';
require_once __DIR__ . '/utm.php';

/**
 * Eingaben für einen neuen Kurzlink prüfen und in Anlege-Argumente übersetzen.
 *
 * @param array{name:string,role:string} $user
 * @param array{url?:string,code?:string,prefix?:string,group?:?string,expires?:string,title?:string} $in
 * @return array{0:?string,1:?string,2:array} [Fehlermeldung|null, voller Code|null, Optionen]
 */
function link_rules_create(array $user, array $in): array
{
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $url = trim((string)($in['url'] ?? ''));
    $code = trim((string)($in['code'] ?? ''));
    $group = trim((string)($in['group'] ?? ''));
    $group = $group === '' ? null : $group;

    // Adressen ohne Schema sind ein häufiger Tippfehler und keine Absicht
    if ($url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        $url = 'https://' . $url;
    }
    // Kampagnen-Parameter noch vor der Prüfung anhängen: Geprüft und gemeldet
    // wird die Adresse, die am Ende auch aufgerufen wird.
    if (isset($in['utm']) && is_array($in['utm'])) $url = utm_apply($url, $in['utm']);

    // Namensraum: Wer auf Präfixe festgelegt ist, landet immer unter einem –
    // notfalls unter dem ersten, statt frei anlegen zu dürfen.
    $myPrefixes = $isAdmin ? [] : user_prefixes($user['name']);
    $prefix = trim((string)($in['prefix'] ?? ''));
    if ($myPrefixes === []) {
        $prefix = '';
    } elseif (!in_array($prefix, $myPrefixes, true)) {
        $prefix = $myPrefixes[0];
    }

    // Domain: leer heißt Hauptdomain. Eine, die dem Konto nicht offensteht,
    // wird nicht abgelehnt, sondern fällt auf die Hauptdomain zurück – sie ist
    // eine Adresse, keine Berechtigung, und ein harter Fehler brächte hier
    // niemandem etwas.
    $domain = domain_clean((string)($in['domain'] ?? ''));
    if ($domain === domain_main() || !domain_allowed($domain, $user['name'])) $domain = '';

    $assignable = link_rules_assignable($user);
    $codeQuota = (int)settings()['custom_code_quota'];
    $minLen = (int)settings()['custom_code_min_len'];

    [$expOk, $expires] = parse_expiry((string)($in['expires'] ?? ''));
    [$startOk, $starts] = parse_start((string)($in['starts'] ?? ''));

    $err = null;
    if (!valid_url($url)) {
        $err = t('Ungültige Ziel-URL (nur http/https).');
    } elseif (!$isAdmin && link_count($user['name']) >= user_limit($user['name'], 'links')) {
        $err = t('Limit erreicht: %d aktive Links.', user_limit($user['name'], 'links'));
    } elseif ($code !== '' && !user_can($user['name'], 'custom_code')) {
        $err = t('Für Wunsch-Namen fehlt diesem Konto die Berechtigung.');
    } elseif ($group !== null && !in_array($group, $assignable, true)) {
        $err = t('Diese Gruppe steht diesem Konto nicht zur Verfügung.');
    } elseif ($code !== '' && !$isAdmin && mb_strlen($code) < $minLen) {
        $err = t('Wunsch-Codes brauchen mindestens %d Zeichen.', $minLen);
    } elseif ($code !== '' && !$isAdmin && $codeQuota > 0 && custom_code_count($user['name']) >= $codeQuota) {
        $err = t('Kontingent erreicht: maximal %d aktive Wunsch-Codes pro Konto.', $codeQuota);
    } elseif ($code !== '' && !valid_code($code)) {
        $err = t('Ungültiger oder reservierter Wunsch-Name.');
    } elseif ($code !== '' && $prefix === '' && in_array(strtolower($code), all_prefixes(), true)) {
        $err = t('Dieser Name ist als Namensraum vergeben.');
    } elseif (!$expOk) {
        $err = t('Ungültiges Ablaufdatum (frühestens heute).');
    } elseif (!$startOk) {
        $err = t('Ungültiges Startdatum (JJJJ-MM-TT).');
    } elseif ($starts !== null && $expires !== null && $starts > $expires) {
        $err = t('Der Link kann nicht ablaufen, bevor er beginnt.');
    } elseif (url_flagged($url)) {
        $err = t('Diese Ziel-URL ist als schädlich gemeldet und kann nicht verkürzt werden.');
    }
    if ($err !== null) return [$err, null, []];

    // Wunsch-Name unter das Präfix hängen; Zufallscodes erledigt link_create
    $full = $code === '' ? null : ($prefix === '' ? $code : $prefix . '/' . $code);

    $opts = [
        'prefix' => $prefix,
        'expires' => $expires,
        'starts' => $starts,
        'group' => $group,
        'title' => (string)($in['title'] ?? ''),
        'url' => $url,
        'domain' => $domain,
    ];
    if (array_key_exists('tags', $in)) $opts['tags'] = $in['tags'];
    return [null, $full, $opts];
}

/**
 * Eingaben für die Änderung eines bestehenden Links prüfen.
 *
 * @return array{0:?string,1:array} [Fehlermeldung|null, Optionen]
 */
function link_rules_update(array $user, array $link, array $in): array
{
    $url = trim((string)($in['url'] ?? ($link['url'] ?? '')));
    if ($url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        $url = 'https://' . $url;
    }
    // Nur anfassen, wenn der Aufrufer die Parameter überhaupt mitschickt –
    // sonst verlöre ein Aufruf, der bloß den Titel ändert, die Kampagne.
    if (isset($in['utm']) && is_array($in['utm'])) $url = utm_apply($url, $in['utm']);

    // Ein unverändertes Datum muss durchgehen, sonst ließe sich ein bereits
    // abgelaufener Link nie wieder bearbeiten.
    $rawExp = array_key_exists('expires', $in) ? trim((string)$in['expires']) : (string)($link['expires'] ?? '');
    if ($rawExp === (string)($link['expires'] ?? '')) {
        [$expOk, $expires] = [true, $link['expires'] ?? null];
    } else {
        [$expOk, $expires] = parse_expiry($rawExp);
    }

    // Dasselbe fürs Startdatum
    $rawStart = array_key_exists('starts', $in) ? trim((string)$in['starts']) : (string)($link['starts'] ?? '');
    if ($rawStart === (string)($link['starts'] ?? '')) {
        [$startOk, $starts] = [true, $link['starts'] ?? null];
    } else {
        [$startOk, $starts] = parse_start($rawStart);
    }

    $group = array_key_exists('group', $in) ? trim((string)$in['group']) : (string)($link['group'] ?? '');
    $group = $group === '' ? null : $group;
    $assignable = link_rules_assignable($user);

    if (!valid_url($url)) {
        return [t('Ungültige Ziel-URL (nur http/https).'), []];
    }
    // Eine bestehende Zuordnung darf bleiben, auch wenn sie nicht neu vergeben
    // werden könnte – sonst wäre ein geerbter Gruppenlink nicht mehr änderbar.
    if ($group !== null && $group !== ($link['group'] ?? null) && !in_array($group, $assignable, true)) {
        return [t('Diese Gruppe steht diesem Konto nicht zur Verfügung.'), []];
    }
    if (!$expOk) {
        return [t('Ungültiges Ablaufdatum (frühestens heute, leer = kein Ablauf).'), []];
    }
    if (!$startOk) {
        return [t('Ungültiges Startdatum (JJJJ-MM-TT).'), []];
    }
    if ($starts !== null && $expires !== null && $starts > $expires) {
        return [t('Der Link kann nicht ablaufen, bevor er beginnt.'), []];
    }

    $opts = ['expires' => $expires, 'starts' => $starts, 'group' => $group, 'url' => $url];
    if (array_key_exists('title', $in)) $opts['title'] = (string)$in['title'];
    if (array_key_exists('tags', $in)) $opts['tags'] = $in['tags'];
    if (array_key_exists('domain', $in)) {
        // Wie bei den Gruppen: Eine bereits gesetzte Domain darf bleiben, auch
        // wenn das Konto sie heute nicht mehr wählen könnte. Sonst wäre ein
        // Link nach einem Gruppenwechsel nicht mehr zu bearbeiten – und die
        // Adresse steht womöglich längst gedruckt auf einem Aufkleber.
        $d = domain_clean((string)$in['domain']);
        if ($d === domain_main()) $d = '';
        if ($d !== (string)($link['domain'] ?? '') && !domain_allowed($d, $user['name'])) {
            return [t('Diese Domain steht diesem Konto nicht zur Verfügung.'), []];
        }
        $opts['domain'] = $d;
    }
    return [null, $opts];
}

/**
 * Gruppen, denen dieses Konto einen Link zuordnen darf.
 *
 * Nur Arbeitsgruppen – eine Rechtegruppe wie ein Tarif hat mit der Verwaltung
 * eines einzelnen Links nichts zu tun (siehe group_shared).
 *
 * @return string[]
 */
function link_rules_assignable(array $user): array
{
    return ($user['role'] ?? '') === 'admin'
        ? array_values(array_filter(array_keys(groups_all()), 'group_shared'))
        : user_shared_groups($user['name']);
}
