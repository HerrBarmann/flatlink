<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
//
// Die Übersicht nach der Anmeldung ('overview'): was ansteht, die häufigsten
// Handgriffe, was zuletzt angelegt wurde. Aufgebaut wie die Übersicht im
// HFMT-Design-System (Titel „Hallo, Vorname" · Was ansteht · Schnellzugriff ·
// Zuletzt), aber mit flatlinks eigenem Markup.
//
// Die Zahlen, die auch im Menü stehen (Freischaltungen, Meldungen), rechnet
// genau eine Stelle aus: uebersicht_zaehler(). So können Menü und Übersicht
// nie Verschiedenes sagen.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/domains.php';

/** Wie weit „läuft bald ab" vorausschaut */
const UEBERSICHT_ABLAUF_TAGE = 14;

/**
 * Arbeit, die auf dieses Konto wartet, als Zahl je Menüpunkt.
 * Nur, was das Konto erledigen darf; nichts zu tun heißt: kein Eintrag.
 *
 * @return array<string,int> Schlüssel aus nav_menu() ('users', 'reports')
 */
function uebersicht_zaehler(array $user): array
{
    static $cache = [];
    $name = (string)$user['name'];
    if (isset($cache[$name])) return $cache[$name];
    $z = [];
    if (($user['role'] ?? '') === 'admin') {
        require_once __DIR__ . '/sso.php';
        $n = count(pending_users());
        if ($n > 0) $z['users'] = $n;
    }
    if (($user['role'] ?? '') === 'admin' || user_can($name, 'reports_manage')) {
        $n = count(glob(data_path('reports') . '/*.json') ?: []);
        if ($n > 0) $z['reports'] = $n;
    }
    return $cache[$name] = $z;
}

/**
 * Was ansteht, in fester Reihenfolge: zu erledigen, prüfen, Tipp.
 *
 * Jede Zeile führt an die Stelle, an der man es erledigt. Gezählt wird
 * Arbeit, nicht Bestand: „2 Kurzlinks laufen bald ab", nicht „83 Kurzlinks".
 *
 * @return list<array{stufe:string,text:string,href:string}>
 */
function uebersicht_aufgaben(array $user, string $adm, ?int $jetzt = null): array
{
    $jetzt ??= time();
    $name = (string)$user['name'];
    $zaehler = uebersicht_zaehler($user);
    $todo = $warn = $tipp = [];

    // ---- zu erledigen: ohne dich passiert nichts ----
    if (isset($zaehler['users'])) {
        $n = $zaehler['users'];
        $todo[] = ['text' => $n === 1 ? t('1 Konto wartet auf Freischaltung') : t('%d Konten warten auf Freischaltung', $n),
                   'href' => $adm . 'users.php#freischalten'];
    }
    if (isset($zaehler['reports'])) {
        $n = $zaehler['reports'];
        $todo[] = ['text' => $n === 1 ? t('1 Meldung bearbeiten') : t('%d Meldungen bearbeiten', $n),
                   'href' => $adm . 'reports.php'];
    }

    // ---- prüfen: wird bald zur Arbeit (nur die eigenen Links) ----
    $bald = $gesperrt = [];
    foreach (links_of_owner($name) as $l) {
        $code = (string)($l['_code'] ?? '');
        $dom = (string)($l['domain'] ?? '');
        if (!empty($l['disabled'])) { $gesperrt[] = [$code, $dom]; continue; }
        $ende = strtotime((string)($l['expires'] ?? ''));
        if ($ende !== false && $ende >= $jetzt && $ende <= $jetzt + UEBERSICHT_ABLAUF_TAGE * 86400) {
            $bald[] = [$code, $dom];
        }
    }
    // Ein einzelner Link führt direkt zu ihm, mehrere in die Liste.
    $ziel = fn(array $treffer): string => count($treffer) === 1
        ? $adm . 'index.php?hl=' . rawurlencode($treffer[0][0]) . dom_param($treffer[0][1], 'hld')
        : $adm . 'index.php';
    if ($gesperrt !== []) {
        $n = count($gesperrt);
        $warn[] = ['text' => $n === 1 ? t('1 Kurzlink ist gesperrt') : t('%d Kurzlinks sind gesperrt', $n),
                   'href' => $ziel($gesperrt)];
    }
    if ($bald !== []) {
        $n = count($bald);
        $warn[] = ['text' => $n === 1
                       ? t('1 Kurzlink läuft in den nächsten %d Tagen ab', UEBERSICHT_ABLAUF_TAGE)
                       : t('%d Kurzlinks laufen in den nächsten %d Tagen ab', $n, UEBERSICHT_ABLAUF_TAGE),
                   'href' => $ziel($bald)];
    }

    // ---- Tipp: freiwillig ----
    // Ein Passkey hilft nur, wer sich hier mit Passwort anmeldet. Konten aus
    // dem Verzeichnis oder der zentralen Anmeldung melden sich woanders an.
    require_once __DIR__ . '/webauthn.php';
    $u = user_get($name);
    $modus = (string)(settings()['passkey_hint'] ?? '') ?: 'on';
    if ($u !== null && ($u['auth'] ?? 'local') === 'local' && $modus !== 'off'
        && webauthn_possible() && (array)($u['passkeys'] ?? []) === []) {
        $tipp[] = ['text' => t('Richte einen Passkey ein: anmelden per Fingerabdruck, ohne Passwort.'),
                   'href' => $adm . 'profile.php?zeige=zwei-faktor#zwei-faktor'];
    }

    $alle = [];
    foreach ([['todo', $todo], ['warn', $warn], ['hint', $tipp]] as [$stufe, $zeilen]) {
        foreach ($zeilen as $z) $alle[] = ['stufe' => $stufe] + $z;
    }
    return $alle;
}

/**
 * Die zuletzt angelegten eigenen Kurzlinks, neueste zuerst.
 *
 * @return list<array{code:string,domain:string,angelegt:int,titel:string,ziel:string,klicks:int}>
 */
function uebersicht_zuletzt(array $user, int $anzahl = 5): array
{
    $liste = [];
    foreach (links_of_owner((string)$user['name']) as $l) {
        $liste[] = [
            'code' => (string)($l['_code'] ?? ''),
            'domain' => (string)($l['domain'] ?? ''),
            'angelegt' => (int)(strtotime((string)($l['created'] ?? '')) ?: 0),
            'titel' => (string)($l['title'] ?? ''),
            'ziel' => (string)($l['url'] ?? ''),
        ];
    }
    usort($liste, fn($a, $b) => $b['angelegt'] <=> $a['angelegt']);
    $liste = array_slice($liste, 0, $anzahl);
    foreach ($liste as &$e) $e['klicks'] = (int)(clicks_get($e['code'], $e['domain'])['n'] ?? 0);
    return $liste;
}

/** Der Vorname für „Hallo, …": das erste Wort des Anzeigenamens, sonst die Kennung */
function uebersicht_vorname(string $name): string
{
    $anzeige = user_display($name);
    if ($anzeige === $name) return $name;
    return explode(' ', $anzeige)[0];
}
