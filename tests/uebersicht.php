<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Übersicht nach der Anmeldung (inc/uebersicht.php).
 *
 * Geprüft wird, was die Seite verspricht: Was ansteht, steht in fester
 * Reihenfolge da (zu erledigen, prüfen, Tipp), zählt Arbeit statt Bestand und
 * führt dorthin, wo man es erledigt. Dieselben Zahlen wie im Menü kommen aus
 * einer Stelle. Dazu „Zuletzt angelegt", der Vorname und die Rangfolge der
 * Tabs.
 *
 * Aufruf: php tests/uebersicht.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }
require_once __DIR__ . '/../inc/uebersicht.php';
require_once __DIR__ . '/../inc/sso.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}

const U_KONTO = 'test-uebersicht';
const U_ADMIN = 'test-uebersicht-admin';
const U_WARTET = 'test-uebersicht-wartet';
foreach ([U_KONTO, U_ADMIN] as $k) if (user_get($k) !== null) user_delete($k);
user_add(U_KONTO, 'Pruef-Passwort-123!', 'user');
user_add(U_ADMIN, 'Pruef-Passwort-123!', 'admin');
user_set_display_name(U_KONTO, 'Christel Jurkeit');

// Drei eigene Links: läuft in 5 Tagen ab, in 30 Tagen, gesperrt
$jetzt = time();
$codes = [];
foreach (['bald' => 5, 'spaet' => 30, 'gesperrt' => 0] as $was => $tage) {
    [$ok, $code] = link_create('https://example.org/' . $was, null, U_KONTO, 'random', []);
    if (!$ok) exit("Link ließ sich nicht anlegen: $code\n");
    $codes[$was] = $code;
    link_write($code, function (array $l) use ($was, $tage, $jetzt) {
        if ($tage > 0) $l['expires'] = date('Y-m-d', $jetzt + $tage * 86400);
        if ($was === 'gesperrt') $l['disabled'] = true;
        // Für „Zuletzt": gestaffelt angelegt, „bald" zuletzt
        $l['created'] = date('c', $jetzt - ['bald' => 60, 'spaet' => 3600, 'gesperrt' => 7200][$was]);
        return $l;
    });
}

echo "Was ansteht (eigene Links)\n";
$konto = ['name' => U_KONTO] + (array)user_get(U_KONTO);
$a = uebersicht_aufgaben($konto, '/admin/', $jetzt);
$stufen = array_column($a, 'stufe');
pruefe('Reihenfolge: zu erledigen, prüfen, Tipp', $stufen === array_values(array_filter(
    array_merge(array_filter($stufen, fn($s) => $s === 'todo'), array_filter($stufen, fn($s) => $s === 'warn'),
                array_filter($stufen, fn($s) => $s === 'hint')))), implode(',', $stufen));
pruefe('ein Konto ohne Verwaltung hat nichts „zu erledigen"', !in_array('todo', $stufen, true));
$texte = array_column($a, 'text');
pruefe('der gesperrte Link steht da und führt direkt zu ihm',
    in_array(t('1 Kurzlink ist gesperrt'), $texte, true)
    && in_array('/admin/index.php?hl=' . rawurlencode($codes['gesperrt']), array_column($a, 'href'), true));
pruefe('nur der Link, der in 14 Tagen abläuft, zählt (nicht der in 30)',
    in_array(t('1 Kurzlink läuft in den nächsten %d Tagen ab', UEBERSICHT_ABLAUF_TAGE), $texte, true)
    && in_array('/admin/index.php?hl=' . rawurlencode($codes['bald']), array_column($a, 'href'), true));

echo "\nWas ansteht (Verwaltung) und dieselben Zahlen im Menü\n";
pending_user_note(U_WARTET, 'Wartende Person', 'wartet@example.org', [], 'unbekannt', 'sso');
$meldung = data_path('reports') . '/' . date('Ymd-His') . '-' . substr(md5('uebersicht'), 0, 8) . '.json';
if (!is_dir(dirname($meldung))) mkdir(dirname($meldung), 0700, true);
file_put_contents($meldung, json_encode(['code' => 'x', 'grund' => 'Test']));
$admin = ['name' => U_ADMIN] + (array)user_get(U_ADMIN);
$z = uebersicht_zaehler($admin);
$a = uebersicht_aufgaben($admin, '/admin/', $jetzt);
pruefe('Freischaltung und Meldung stehen „zu erledigen" ganz oben',
    ($a[0]['stufe'] ?? '') === 'todo' && ($a[1]['stufe'] ?? '') === 'todo'
    && str_contains($a[0]['href'], 'users.php#freischalten') && str_contains($a[1]['href'], 'reports.php'));
pruefe('die Zahl im Text ist die Zahl im Menü',
    ($z['users'] ?? 0) >= 1 && str_starts_with($a[0]['text'], (string)$z['users'])
    && ($z['reports'] ?? 0) >= 1 && str_starts_with($a[1]['text'], (string)$z['reports']),
    json_encode($z));
$html = nav_side_list([['schluessel' => 'v', 'titel' => '', 'punkte' => [
    ['schluessel' => 'reports', 'text' => 'Meldungen', 'href' => 'reports.php', 'aktiv' => false, 'zaehler' => 3]]]]);
pruefe('der Menüpunkt trägt den Zähler', str_contains($html, '<span class="menu-count" title="' . e(t('%d offen', 3)) . '">3</span>'));
pruefe('ein Konto ohne Verwaltung sieht keine Zähler', uebersicht_zaehler($konto) === []);
pending_user_drop(U_WARTET);
@unlink($meldung);

echo "\nZuletzt angelegt, Vorname\n";
$zl = uebersicht_zuletzt($konto);
pruefe('neueste zuerst', array_column($zl, 'code') === [$codes['bald'], $codes['spaet'], $codes['gesperrt']]);
pruefe('höchstens so viele wie verlangt', count(uebersicht_zuletzt($konto, 2)) === 2);
pruefe('„Hallo, Christel": das erste Wort des Anzeigenamens', uebersicht_vorname(U_KONTO) === 'Christel');
pruefe('ohne Anzeigenamen die Kennung', uebersicht_vorname(U_ADMIN) === U_ADMIN);

echo "\nTab-Leiste nach Rang\n";
$p = fn(string $k, int $rang) => ['schluessel' => $k, 'text' => $k, 'href' => $k, 'aktiv' => false, 'tab' => $rang];
$menu = [['schluessel' => 'a', 'titel' => '', 'punkte' => [
    $p('uebersicht', 1), $p('links', 2), $p('bio', 0), $p('qr', 3), $p('qrzip', 5), $p('users', 0)]]];
preg_match_all('/data-key="([^"]+)"/', nav_tab_bar($menu, '/admin/'), $m);
pruefe('Übersicht, Links, QR-Designer, QR-Serie und „Mehr"', $m[1] === ['uebersicht', 'links', 'qr', 'qrzip', 'mehr'], implode(',', $m[1]));

// ---- Aufräumen ----
foreach ($codes as $c) link_delete($c);
foreach ([U_KONTO, U_ADMIN] as $k) user_delete($k);
pruefe('Testkonten, -links und -meldung wieder entfernt',
    user_get(U_KONTO) === null && user_get(U_ADMIN) === null && !is_file($meldung)
    && !array_key_exists(U_WARTET, pending_users()));

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
