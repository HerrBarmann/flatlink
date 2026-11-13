<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft das Menü des Login-Bereichs als Tabelle (nav_menu) und die Leiste,
 * die daraus entsteht (nav_side_list).
 *
 * Das Menü kommt seit 5.5 aus EINER Tabelle, gleich ob es oben als Zeile oder
 * links als Leiste gezeichnet wird ('nav_layout'). Die Regel, die das trägt:
 *
 *   Kein Punkt ohne Recht. Was das Konto nicht benutzen darf, fehlt – nicht
 *   ausgegraut, nicht mit Absage dahinter. Leere Bereiche fallen weg, und ein
 *   Bereich mit nur einem Punkt hat keine Überschrift.
 *
 * Dazu: Unterseiten lassen den Punkt ihrer Liste aktiv (Bearbeiten → Links,
 * WLAN-Generator → QR-Designer), und es ist nie mehr als ein Punkt aktiv.
 *
 * Aufruf: php tests/menue.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}
/** Menü für ein Konto auf einer Seite – wie page_header() es aus admin/ heraus baut. */
function menue_auf(string $konto, string $seite): array
{
    $_SERVER['SCRIPT_NAME'] = '/' . $seite;
    $admin = str_starts_with($seite, 'admin/');
    // user_get() liefert den Datensatz ohne eigenes 'name'-Feld; page_header()
    // bekommt ihn aus auth_user(), das es setzt.
    return nav_menu(['name' => $konto] + user_get($konto), $admin ? '' : 'admin/', $admin ? '../' : '');
}
function schluessel(array $menu): array
{
    $k = [];
    foreach ($menu as $b) foreach ($b['punkte'] as $p) $k[] = $p['schluessel'];
    return $k;
}
function aktive(array $menu): array
{
    $k = [];
    foreach ($menu as $b) foreach ($b['punkte'] as $p) if ($p['aktiv']) $k[] = $p['schluessel'];
    return $k;
}
function bereich(array $menu, string $k): ?array
{
    foreach ($menu as $b) if ($b['schluessel'] === $k) return $b;
    return null;
}

const M_ADMIN = 'menue-admin';
const M_KONTO = 'menue-konto';
const M_REDAKTION = 'menue-redaktion';
const M_GRUPPE = 'menue-redaktion';

foreach ([M_ADMIN, M_KONTO, M_REDAKTION] as $k) user_delete($k);
group_delete(M_GRUPPE);
user_add(M_ADMIN, 'Pruef-Passwort-123!', 'admin');
user_add(M_KONTO, 'Pruef-Passwort-123!', 'user');
user_add(M_REDAKTION, 'Pruef-Passwort-123!', 'user');
group_save(M_GRUPPE, 'Redaktion', ['reports_manage']);
user_set_groups(M_REDAKTION, [M_GRUPPE]);

// ---- 1. Administrator ------------------------------------------------------
echo "Administrator\n";
$m = menue_auf(M_ADMIN, 'admin/index.php');
pruefe('drei Bereiche: Kurzlinks, QR-Codes, Verwaltung',
    array_column($m, 'schluessel') === ['links', 'qr', 'verwaltung'], implode(', ', array_column($m, 'schluessel')));
pruefe('die Verwaltung hat alle fünf Punkte',
    array_column(bereich($m, 'verwaltung')['punkte'], 'schluessel') === ['users', 'groups', 'reports', 'audit', 'settings']);
pruefe('jeder Bereich mit mehreren Punkten trägt eine Überschrift',
    !in_array('', array_column($m, 'titel'), true));

// ---- 2. Kein Punkt ohne Recht ----------------------------------------------
echo "\nKein Punkt ohne Recht\n";
foreach ([M_KONTO, M_REDAKTION] as $konto) {
    $k = schluessel(menue_auf($konto, 'admin/index.php'));
    foreach (['bio' => 'bio_page', 'logos' => 'logo_upload', 'reports' => 'reports_manage'] as $punkt => $recht) {
        $darf = user_can($konto, $recht);
        pruefe(sprintf('%-15s „%s" %s – das Recht %s', $konto, $punkt, $darf ? 'steht da' : 'fehlt', $darf ? 'liegt vor' : 'fehlt auch'),
            in_array($punkt, $k, true) === $darf);
    }
    pruefe(sprintf('%-15s keine Benutzer-, Gruppen-, Protokoll- oder Einstellungsseite', $konto),
        array_intersect(['users', 'groups', 'audit', 'settings'], $k) === []);
}
pruefe('ein gewöhnliches Konto sieht keinen Bereich Verwaltung',
    bereich(menue_auf(M_KONTO, 'admin/index.php'), 'verwaltung') === null);

// Die Redaktion darf nur Meldungen bearbeiten – ein Bereich mit einem Punkt ist
// kein Bereich, also ohne Überschrift.
$v = bereich(menue_auf(M_REDAKTION, 'admin/index.php'), 'verwaltung');
pruefe('die Redaktion sieht genau „Meldungen"',
    $v !== null && array_column($v['punkte'], 'schluessel') === ['reports']);
pruefe('… und der Bereich hat dann keine Überschrift', $v !== null && $v['titel'] === '');

// ---- 3. Welcher Punkt aktiv ist --------------------------------------------
echo "\nDer aktive Punkt\n";
foreach ([
    'admin/index.php' => 'links', 'admin/edit.php' => 'links', 'admin/stats.php' => 'links',
    'qr-designer.php' => 'qr', 'wlan-qr.php' => 'qr', 'vcard-qr.php' => 'qr', 'termin-qr.php' => 'qr', 'gs1-qr.php' => 'qr',
    'admin/qrzip.php' => 'qrzip', 'admin/logos.php' => 'logos', 'admin/settings.php' => 'settings',
] as $seite => $soll) {
    $a = aktive(menue_auf(M_ADMIN, $seite));
    pruefe(sprintf('%-20s → %s', $seite, $soll), $a === [$soll], implode(', ', $a) ?: 'keiner');
}
$a = aktive(menue_auf(M_ADMIN, 'admin/erweiterung.php'));
pruefe('admin/erweiterung.php → keiner (steht im Fuß, nicht im Menü)', $a === [], implode(', ', $a));

// Pfade: Aus admin/ heraus relativ, aus dem Webroot mit admin/ davor.
$m = menue_auf(M_ADMIN, 'admin/index.php');
pruefe('aus admin/ heraus: QR-Designer liegt eine Ebene höher',
    bereich($m, 'qr')['punkte'][0]['href'] === '../qr-designer.php');
$m = menue_auf(M_ADMIN, 'wlan-qr.php');
pruefe('aus dem Webroot heraus: Links liegt unter admin/',
    bereich($m, 'links')['punkte'][0]['href'] === 'admin/index.php');

// ---- 4. Die Leiste ---------------------------------------------------------
echo "\nDie Leiste\n";
$html = nav_side_list(menue_auf(M_ADMIN, 'admin/stats.php'));
pruefe('genau ein aria-current', substr_count($html, 'aria-current="page"') === 1);
pruefe('… und zwar an „Links"', (bool)preg_match('#data-key="links" aria-current="page"#', $html));
pruefe('jeder Punkt trägt seinen Schlüssel (für Icons im eigenen CSS)',
    substr_count($html, 'data-key="') === count(schluessel(menue_auf(M_ADMIN, 'admin/stats.php'))));
pruefe('drei Bereichsüberschriften', substr_count($html, 'class="side-nav-title"') === 3);
$html = nav_side_list(menue_auf(M_REDAKTION, 'admin/index.php'));
pruefe('bei der Redaktion keine Überschrift über dem einzelnen Punkt „Meldungen"',
    !str_contains($html, '>' . t('Verwaltung') . '</p>'));
pruefe('Text wird maskiert', !str_contains(nav_side_list([['schluessel' => 'x', 'titel' => '<b>', 'punkte' => [
    ['schluessel' => 'x', 'text' => '<i>', 'href' => '"><x', 'aktiv' => false]]]]), '<i>'));

// ---- Aufräumen -------------------------------------------------------------
foreach ([M_ADMIN, M_KONTO, M_REDAKTION] as $k) user_delete($k);
group_delete(M_GRUPPE);
pruefe('Prüfkonten und -gruppe wieder entfernt',
    user_get(M_ADMIN) === null && user_get(M_KONTO) === null && user_get(M_REDAKTION) === null && group_get(M_GRUPPE) === null);

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
