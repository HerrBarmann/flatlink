<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/groups.php';
require_once __DIR__ . '/inc/safety.php';

auth_boot();

$mode = settings()['public_mode'];
if ($mode === 'off') {
    // Öffentliches Kürzen deaktiviert – nur der Login-Bereich bleibt
    // erreichbar. Stehen die statischen QR-Werkzeuge trotzdem offen
    // (Grundregel qr_public = 'on'), verweist die Karte darauf: Das ist der
    // Fall einer Instanz, die Kurzlinks den Angemeldeten vorbehält, aber
    // allen im Haus QR-Codes anbieten will – ohne den Hinweis fände die
    // niemand.
    require_once __DIR__ . '/inc/qrpanel.php';
    page_header(t('Nicht verfügbar'));
    echo '<div class="card center"><h1>' . e(cfg('site_name')) . '</h1>'
        . '<p>' . t('Die öffentliche Link-Erstellung ist deaktiviert.') . '</p>'
        . '<p><a class="btn" href="admin/">' . t('Zum Login') . '</a></p></div>';
    if (qr_static_offen()) {
        echo '<div class="card center"><h2>' . t('QR-Codes ohne Kurzlink') . '</h2>'
            . '<p class="muted">' . t('Diese Werkzeuge stehen allen offen. Der fertige Code enthält die Daten selbst – gespeichert wird nichts.') . '</p>'
            . '<p class="qr-links">'
            . '<a class="btn" href="qr-designer.php?m=statisch">' . t('Link/Text') . '</a> '
            . '<a class="btn" href="wlan-qr.php">' . t('WLAN') . '</a> '
            . '<a class="btn" href="vcard-qr.php">' . t('Kontakt') . '</a> '
            . '<a class="btn" href="termin-qr.php">' . t('Termin') . '</a> '
            . '<a class="btn" href="gs1-qr.php">GS1</a>'
            . '</p></div>';
    }
    page_footer();
    exit;
}

$created = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Honeypot: echte Browser lassen das Feld leer
    if (($_POST['website'] ?? '') !== '') {
        $error = t('Das hat nicht geklappt.');
    } else {
        $url = trim((string)($_POST['url'] ?? ''));
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && $url !== '') {
            $url = 'https://' . $url;
        }
        $user = auth_user();
        if (!valid_url($url)) {
            $error = t('Das sieht nicht nach einer gültigen Adresse aus (http/https).');
        } elseif ($user === null && !rate_limit_ok(client_ip())) {
            $error = t('Rate-Limit erreicht – bitte später erneut versuchen.');
        } elseif ($user !== null && link_count($user['name']) >= user_limit($user['name'], 'links')) {
            $error = t('Limit erreicht: %d aktive Links. Lösche zuerst alte Links im Login-Bereich.',
                user_limit($user['name'], 'links'));
        } elseif (url_flagged($url)) {
            $error = t('Diese Ziel-URL ist als schädlich gemeldet und kann nicht verkürzt werden.');
        } else {
            $prefix = $mode === 'prefix' ? settings()['public_prefix'] : '';
            [$ok, $result] = link_create($url, null, $user['name'] ?? null, 'random', ['prefix' => $prefix]);
            if ($ok) $created = $result; else $error = $result;
        }
    }
}

page_header(t('Kurzlinks & QR-Codes'));
?>
<div class="hero">
    <h1><?= e(cfg('site_name')) ?></h1>
    <p class="sub"><?= t('Lange URL kürzen – der passende QR-Code kommt mit.') ?></p>
</div>

<?php if ($error !== null): ?>
    <div class="flash flash-err"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($created !== null): $short = short_url($created); ?>
    <div class="card result">
        <h2><?= t('Fertig.') ?></h2>
        <div class="short-row">
            <input id="short" type="text" readonly value="<?= e($short) ?>">
            <button class="btn" type="button" data-copy="#short" data-copied="<?= t('Kopiert') ?> ✓"><?= t('Kopieren') ?></button>
        </div>
        <div class="qr-preview">
            <img src="qr.php?c=<?= e($created) ?>&amp;size=240" width="180" height="180" alt="<?= t('QR-Code für %s', e($short)) ?>">
            <div class="qr-links">
                <a class="btn btn-small" href="qr.php?c=<?= e($created) ?>&amp;format=svg&amp;download=1"><?= t('QR als SVG') ?></a>
                <a class="btn btn-small" href="qr.php?c=<?= e($created) ?>&amp;format=png&amp;size=1024&amp;download=1"><?= t('QR als PNG') ?></a>
            </div>
        </div>
        <?php if (auth_user() === null): ?>
        <p class="muted small"><?= t('Das Ziel dieses Links ist fest. Links mit änderbarem Ziel und Klick-Statistik gibt es %snach dem Anmelden%s.', '<a href="admin/">', '</a>') ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form class="card create-form" method="post" action="">
    <?= csrf_field() ?>
    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
    <label for="url"><?= t('Lange URL') ?></label>
    <div class="short-row">
        <input id="url" type="text" name="url" placeholder="<?= t('https://example.com/sehr/lange/adresse') ?>" required autofocus>
        <button class="btn btn-primary" type="submit"><?= t('Kürzen') ?></button>
    </div>
</form>
<?php page_footer(); ?>
