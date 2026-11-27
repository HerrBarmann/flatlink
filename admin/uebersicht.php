<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
//
// Die Übersicht nach der Anmeldung ('overview'): „Hallo, Vorname", was
// ansteht, Schnellzugriff, zuletzt angelegt. Die Logik steht in
// inc/uebersicht.php; diese Seite zeichnet nur.
require_once __DIR__ . '/../inc/uebersicht.php';
$user = auth_require();
if (!cfg('overview')) redirect_to(base_url() . '/admin/index.php');

$adm = base_url() . '/admin/';
$aufgaben = uebersicht_aufgaben($user, $adm);
$zuletzt = uebersicht_zuletzt($user);

// Schnellzugriff: die häufigsten Handgriffe, höchstens sechs – aus der
// Menütabelle, damit nichts darin steht, was das Konto nicht darf.
$schnell = [['schluessel' => 'links', 'text' => t('Kurzlink anlegen'), 'href' => $adm . 'index.php#neu']];
foreach (nav_menu($user, $adm, base_url() . '/') as $b) {
    foreach ($b['punkte'] as $p) {
        if (in_array($p['schluessel'], ['uebersicht', 'links', 'settings', 'reports', 'groups'], true)) continue;
        $schnell[] = $p;
    }
}
$schnell = array_slice($schnell, 0, 6);

page_header(t('Übersicht'), true);
?>
<div class="card uebersicht">
    <h1><?= t('Hallo,') ?> <span class="accent"><?= e(uebersicht_vorname((string)$user['name'])) ?></span></h1>
    <p class="muted small"><?= t('Angemeldet als') ?> <strong><?= e((string)$user['name']) ?></strong>.</p>

    <section class="ueb-teil" aria-labelledby="t-ansteht">
        <h2 id="t-ansteht"><?= t('Was ansteht') ?></h2>
        <?php if ($aufgaben === []): ?>
        <p class="todo-none"><?= t('Nichts zu tun.') ?></p>
        <?php else: ?>
        <ul class="todos">
            <?php foreach ($aufgaben as $a): ?>
            <li class="todo todo-<?= e($a['stufe']) ?>"><a href="<?= e($a['href']) ?>"><span><?= e($a['text']) ?></span><span class="todo-pfeil" aria-hidden="true">→</span></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="ueb-teil" aria-labelledby="t-schnell">
        <h2 id="t-schnell"><?= t('Schnellzugriff') ?></h2>
        <nav class="quick" aria-labelledby="t-schnell">
            <?php foreach ($schnell as $p): ?>
            <a href="<?= e($p['href']) ?>" data-key="<?= e($p['schluessel']) ?>"><span><?= e($p['text']) ?></span></a>
            <?php endforeach; ?>
        </nav>
    </section>

    <section class="ueb-teil" aria-labelledby="t-zuletzt">
        <h2 id="t-zuletzt"><?= t('Zuletzt angelegt') ?></h2>
        <?php if ($zuletzt === []): ?>
        <p class="muted"><?= t('Noch keine eigenen Kurzlinks.') ?> <a href="<?= e($adm) ?>index.php#neu"><?= t('Den ersten anlegen') ?></a></p>
        <?php else: ?>
        <ul class="recent">
            <?php foreach ($zuletzt as $z): ?>
            <li><span class="when"><?= $z['angelegt'] > 0 ? e(date('d.m. H:i', $z['angelegt'])) : '' ?></span>
                <a href="<?= e($adm . 'index.php?hl=' . rawurlencode($z['code']) . dom_param($z['domain'], 'hld')) ?>"><?= e(short_url($z['code'], $z['domain'])) ?></a>
                <span class="muted"><?= e($z['titel'] !== '' ? $z['titel'] : $z['ziel']) ?></span>
                <span class="recent-n"><?= $z['klicks'] === 1 ? t('1 Aufruf') : t('%d Aufrufe', $z['klicks']) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <p><a href="<?= e($adm) ?>index.php"><?= t('Alle Links') ?></a></p>
        <?php endif; ?>
    </section>
</div>
<?php page_footer(); ?>
