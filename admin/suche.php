<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
//
// Suche überall ('nav_search'): ein Feld im Kopf jeder Seite, das Kurzlinks
// und Seiten der Verwaltung findet.
//
// Ohne JavaScript schickt das Feld ab, und diese Seite zeigt die Treffer. Mit
// JavaScript (assets/app.js) öffnet sich ein Dialog, der beim Tippen dieselbe
// Seite holt und ihre .suche-ergebnisse einsetzt – es gibt also genau eine
// Stelle, die entscheidet, was gefunden wird und was das Konto sehen darf.
//
// POST mit CSRF-Feld: Suchbegriffe gehören nicht in Adressen und Logs.
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/domains.php';
$user = auth_require();

$q = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $q = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 200);
}

const SUCHE_JE_GRUPPE = 8;
// Über diesem Bestand durchsucht die Seite nicht alles selbst, sondern
// verweist auf die Linkliste, die dafür eingerichtet ist (Blättern in SQL).
const SUCHE_BESTAND = 2000;

$gruppen = [];
if (mb_strlen($q) >= 2) {
    $klein = mb_strtolower($q);

    // Seiten: was im Menü steht und das Konto benutzen darf
    $adm = base_url() . '/admin/';
    $seiten = [];
    foreach (nav_menu($user, $adm, base_url() . '/') as $b) {
        foreach ($b['punkte'] as $p) {
            if (mb_stripos($p['text'], $q) !== false) {
                $seiten[] = ['href' => $p['href'], 'text' => $p['text'], 'neben' => $b['titel'], 'art' => t('Seite')];
            }
        }
    }
    if (mb_stripos(t('Profil'), $q) !== false) {
        $seiten[] = ['href' => $adm . 'profile.php', 'text' => t('Profil'), 'neben' => '', 'art' => t('Seite')];
    }

    // Kurzlinks: dieselben Felder wie die Suche der Linkliste
    $treffer = [];
    $gesamt = 0;
    $vollzugriff = $user['role'] === 'admin' || user_can($user['name'], 'links_all');
    $zuGross = $vollzugriff && links_count() > SUCHE_BESTAND;
    if (!$zuGross) {
        foreach (links_visible($user) as $l) {
            $code = (string)($l['_code'] ?? '');
            if (!(mb_stripos($code, $q) !== false
                || mb_stripos((string)($l['url'] ?? ''), $q) !== false
                || mb_stripos((string)($l['title'] ?? ''), $q) !== false
                || in_array($klein, (array)($l['tags'] ?? []), true))) continue;
            $gesamt++;
            if (count($treffer) >= SUCHE_JE_GRUPPE) continue;
            $dom = (string)($l['domain'] ?? '');
            $treffer[] = [
                'href' => $adm . 'index.php?hl=' . rawurlencode($code) . dom_param($dom, 'hld'),
                'text' => short_url($code, $dom),
                'neben' => (string)($l['title'] ?? '') !== '' ? (string)$l['title'] : (string)($l['url'] ?? ''),
                'art' => t('Kurzlink'),
            ];
        }
    }
    $alle = $adm . 'index.php?q=' . rawurlencode($q);
    if ($treffer !== [] || $zuGross) {
        $gruppen[] = ['titel' => t('Kurzlinks'), 'treffer' => $treffer,
            'mehr' => $zuGross ? [$alle, t('In der Linkliste suchen')]
                : ($gesamt > count($treffer) ? [$alle, t('Alle %d Treffer in der Linkliste', $gesamt)] : null)];
    }
    if ($seiten !== []) $gruppen[] = ['titel' => t('Seiten'), 'treffer' => $seiten, 'mehr' => null];
}

page_header(t('Suche'), true);
?>
<div class="card">
    <h1><?= t('Suche') ?></h1>
    <form method="post" action="suche.php" role="search" class="suche-feld">
        <?= csrf_field() ?>
        <label for="suche-seite-q"><?= t('Kurzlinks und Seiten durchsuchen') ?></label>
        <div class="inline-form">
            <input id="suche-seite-q" type="search" name="q" value="<?= e($q) ?>" autocomplete="off" required minlength="2">
            <button class="btn" type="submit"><?= t('Suchen') ?></button>
        </div>
    </form>
    <?php /* Diesen Block liest auch der Dialog der Suche (assets/app.js). */ ?>
    <div class="suche-ergebnisse">
    <?php if ($q !== '' && $gruppen === []): ?>
        <p class="suche-nichts"><?= mb_strlen($q) < 2 ? t('Bitte mindestens zwei Zeichen eingeben.') : t('Nichts gefunden für „%s“.', $q) ?></p>
    <?php endif; ?>
    <?php foreach ($gruppen as $g): ?>
        <section class="suche-gruppe">
            <h2><?= e($g['titel']) ?></h2>
            <?php if ($g['treffer'] !== []): ?>
            <ul>
                <?php foreach ($g['treffer'] as $t): ?>
                <li><a class="suche-treffer" href="<?= e($t['href']) ?>">
                    <span class="suche-text"><strong><?= e($t['text']) ?></strong><?php if ($t['neben'] !== ''): ?><span><?= e($t['neben']) ?></span><?php endif; ?></span>
                    <span class="suche-art"><?= e($t['art']) ?></span>
                </a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($g['mehr'] !== null): ?>
            <p class="suche-mehr"><a href="<?= e($g['mehr'][0]) ?>"><?= e($g['mehr'][1]) ?></a></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
</div>
<?php page_footer(); ?>
