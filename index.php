<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/safety.php';

auth_boot();

$mode = settings()['public_mode'];
if ($mode === 'off') {
    // Öffentliches Kürzen deaktiviert – nur der Login-Bereich bleibt erreichbar
    page_header('Nicht verfügbar');
    echo '<div class="card center"><h1>' . e(cfg('site_name')) . '</h1>'
        . '<p>Die öffentliche Link-Erstellung ist deaktiviert.</p>'
        . '<p><a class="btn" href="admin/">Zum Login</a></p></div>';
    page_footer();
    exit;
}

$created = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Honeypot: echte Browser lassen das Feld leer
    if (($_POST['website'] ?? '') !== '') {
        $error = 'Das hat nicht geklappt.';
    } else {
        $url = trim((string)($_POST['url'] ?? ''));
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && $url !== '') {
            $url = 'https://' . $url;
        }
        $user = auth_user();
        if (!valid_url($url)) {
            $error = 'Das sieht nicht nach einer gültigen Adresse aus (http/https).';
        } elseif ($user === null && !rate_limit_ok(client_ip())) {
            $error = 'Rate-Limit erreicht – bitte später erneut versuchen.';
        } elseif ($user !== null && link_count($user['name']) >= user_limit($user['name'], 'links')) {
            $error = 'Limit erreicht: ' . user_limit($user['name'], 'links')
                . ' aktive Links. Lösche zuerst alte Links im Login-Bereich.';
        } elseif (url_flagged($url)) {
            $error = 'Diese Ziel-URL ist als schädlich gemeldet und kann nicht verkürzt werden.';
        } else {
            $prefix = $mode === 'prefix' ? settings()['public_prefix'] : '';
            [$ok, $result] = link_create($url, null, $user['name'] ?? null, 'random', $prefix);
            if ($ok) $created = $result; else $error = $result;
        }
    }
}

page_header('Kurzlinks & QR-Codes');
?>
<div class="hero">
    <h1><?= e(cfg('site_name')) ?></h1>
    <p class="sub">Lange URL kürzen – der passende QR-Code kommt mit.</p>
</div>

<?php if ($error !== null): ?>
    <div class="flash flash-err"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($created !== null): $short = short_url($created); ?>
    <div class="card result">
        <h2>Fertig.</h2>
        <div class="short-row">
            <input id="short" type="text" readonly value="<?= e($short) ?>">
            <button class="btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('short').value);this.textContent='Kopiert ✓'">Kopieren</button>
        </div>
        <div class="qr-preview">
            <img src="qr.php?c=<?= e($created) ?>&amp;size=240" width="180" height="180" alt="QR-Code für <?= e($short) ?>">
            <div class="qr-links">
                <a class="btn btn-small" href="qr.php?c=<?= e($created) ?>&amp;format=svg&amp;download=1">QR als SVG</a>
                <a class="btn btn-small" href="qr.php?c=<?= e($created) ?>&amp;format=png&amp;size=1024&amp;download=1">QR als PNG</a>
            </div>
        </div>
        <?php if (auth_user() === null): ?>
        <p class="muted small">Das Ziel dieses Links ist fest. Links mit änderbarem Ziel und
        Klick-Statistik gibt es <a href="admin/">nach dem Anmelden</a>.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form class="card create-form" method="post" action="">
    <?= csrf_field() ?>
    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
    <label for="url">Lange URL</label>
    <div class="short-row">
        <input id="url" type="text" name="url" placeholder="https://example.com/sehr/lange/adresse" required autofocus>
        <button class="btn btn-primary" type="submit">Kürzen</button>
    </div>
</form>
<?php page_footer(); ?>
