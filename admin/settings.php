<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';

$user = auth_require_admin();
$s = settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = (string)($_POST['public_mode'] ?? 'on');
    $prefix = trim((string)($_POST['public_prefix'] ?? 'p'), '/ ');
    $rate = (int)($_POST['public_rate_limit'] ?? 15);
    $registration = ($_POST['registration'] ?? '') === 'on' ? 'on' : 'off';

    if (!in_array($mode, ['on', 'prefix', 'off'], true)) {
        flash('Ungültiger Modus.', 'err');
    } elseif ($mode === 'prefix' && !valid_code($prefix)) {
        flash('Ungültiger Prefix: 1–64 Zeichen (a-z, A-Z, 0-9, _ und -), nicht reserviert.', 'err');
    } elseif ($rate < 1 || $rate > 1000) {
        flash('Rate-Limit: 1–1000 pro Stunde.', 'err');
    } else {
        settings_save([
            'public_mode' => $mode,
            'public_prefix' => $prefix === '' ? 'p' : $prefix,
            'public_rate_limit' => $rate,
            'registration' => $registration,
        ]);
        flash('Einstellungen gespeichert.');
    }
    redirect_to('settings.php');
}

page_header('Einstellungen', true);
show_flash();
$host = preg_replace('#^https?://#', '', base_url());
?>

<div class="card">
    <h2>Ablage</h2>
    <?php
    $dataPath = data_path();
    $imWebroot = data_dir_in_webroot();
    $links = count(link_store_files());
    ?>
    <p class="muted small">Laufzeitdaten liegen in<br>
    <code style="word-break:break-all"><?= e($dataPath) ?></code></p>
    <?php if ($imWebroot): ?>
    <div class="flash flash-err" style="margin-top:0.8rem">
        <strong>Das Verzeichnis liegt im Webroot.</strong> Geschützt ist es dort nur durch die
        <code>.htaccess</code> – die nginx, Caddy und LiteSpeed ignorieren. Darin stehen
        Passwort-Hashes und gültige Reset-Token. Wenn dein Hosting einen Pfad außerhalb zulässt,
        trag ihn als <code>data_dir</code> in <code>inc/config.php</code> ein – den Inhalt vorher
        kopieren, erst danach die Konfiguration umstellen.
    </div>
    <?php else: ?>
    <p class="muted small">Außerhalb des Webroots – so soll es sein.</p>
    <?php endif; ?>
    <p class="muted small">Kurzlinks: <?= $links ?> <?= $links === 1 ? 'Datei' : 'Ablagen' ?><?php
        if (!links_sharded()) echo ' – noch die alte Sammeldatei, <code>migrate-links.php</code> teilt sie auf';
    ?></p>
</div>

<div class="card">
    <h2>Öffentliche Oberfläche</h2>
    <form method="post" action="">
        <?= csrf_field() ?>
        <div class="radio-group">
            <label class="radio">
                <input type="radio" name="public_mode" value="on"<?= $s['public_mode'] === 'on' ? ' checked' : '' ?>>
                <span><strong>Aktiv</strong><br>
                <span class="muted">Jeder kann zufällige Kurzlinks direkt unter <?= e($host) ?>/… erstellen.</span></span>
            </label>
            <label class="radio">
                <input type="radio" name="public_mode" value="prefix"<?= $s['public_mode'] === 'prefix' ? ' checked' : '' ?>>
                <span><strong>Aktiv, aber nur unter Prefix</strong><br>
                <span class="muted">Öffentlich erstellte Links landen unter <?= e($host) ?>/<span id="pfx-preview"><?= e($s['public_prefix']) ?></span>/… –
                der restliche Namensraum bleibt für eingeloggte Nutzer reserviert.</span></span>
            </label>
            <label class="radio">
                <input type="radio" name="public_mode" value="off"<?= $s['public_mode'] === 'off' ? ' checked' : '' ?>>
                <span><strong>Deaktiviert</strong><br>
                <span class="muted">Die Startseite zeigt nur einen Hinweis, Links entstehen ausschließlich im Login-Bereich. Bestehende Kurzlinks leiten weiterhin um.</span></span>
            </label>
        </div>

        <label for="s-prefix">Prefix für öffentliche Links</label>
        <input id="s-prefix" type="text" name="public_prefix" value="<?= e($s['public_prefix']) ?>" pattern="[A-Za-z0-9_-]{1,64}"
               oninput="document.getElementById('pfx-preview').textContent = this.value || 'p'">
        <p class="muted small">Gilt nur im Prefix-Modus und nur für neue Links – bestehende Kurzlinks behalten ihre Adresse.</p>

        <label for="s-rate">Rate-Limit der öffentlichen Erstellung (Links pro IP und Stunde)</label>
        <input id="s-rate" type="number" name="public_rate_limit" value="<?= (int)$s['public_rate_limit'] ?>" min="1" max="1000">

        <h2 style="margin-top:1.5rem">Registrierung</h2>
        <div class="radio-group">
            <label class="radio">
                <input type="radio" name="registration" value="on"<?= $s['registration'] === 'on' ? ' checked' : '' ?>>
                <span><strong>Offen</strong><br>
                <span class="muted">Jeder kann sich mit E-Mail-Bestätigung (Double-Opt-In) ein Konto anlegen.</span></span>
            </label>
            <label class="radio">
                <input type="radio" name="registration" value="off"<?= $s['registration'] !== 'on' ? ' checked' : '' ?>>
                <span><strong>Geschlossen</strong><br>
                <span class="muted">Neue Konten legt nur der Admin an. Bestehende Konten funktionieren weiter.</span></span>
            </label>
        </div>

        <button class="btn btn-primary" type="submit" style="margin-top:1rem">Speichern</button>
    </form>
</div>
<?php page_footer(); ?>
