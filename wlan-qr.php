<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/groups.php';
require_once __DIR__ . '/inc/qrpanel.php';

auth_boot();

// Ob dieses Werkzeug ohne Anmeldung offensteht, entscheidet die Grundregel
// qr_public (siehe qr_static_offen). Gespeichert wird hier nie etwas – aber
// wer seine Instanz zumacht, meint damit auch die Werkzeuge.
if (auth_user() === null && !qr_static_offen()) {
    http_response_code(403);
    page_header(t('Nur nach Anmeldung'));
    echo '<div class="card center"><h1>' . e(cfg('site_name')) . '</h1>'
        . '<p>' . t('Die QR-Generatoren stehen auf dieser Instanz nur angemeldeten Konten offen.') . '</p>'
        . '<p><a class="btn" href="admin/">' . t('Zum Login') . '</a></p></div>';
    page_footer();
    exit;
}

page_header(t('WLAN-QR-Code erstellen'), false,
    t('Netzwerkname und Passwort eingeben – wer den Code scannt, ist verbunden. Der Code enthält die Daten selbst und braucht keinen Dienst.'),
    base_url() . '/wlan-qr.php');
?>

<div class="hero">
    <h1><?= t('WLAN-QR-Code') ?></h1>
    <p class="sub"><?= t('Für Gäste, Sitzungsräume oder das Sekretariat: Code aufhängen statt Passwort buchstabieren.') ?></p>
</div>

<div>
<?= qr_type_nav('wifi') ?>

<div class="designer">
    <div class="card controls">
        <h3><?= t('Dein WLAN') ?></h3>
        <label for="w-ssid"><?= t('Netzwerkname (SSID)') ?></label>
        <input id="w-ssid" type="text" maxlength="32" placeholder="<?= e(t('MeinWLAN')) ?>" autofocus>
        <label for="w-enc"><?= t('Verschlüsselung') ?></label>
        <select id="w-enc">
            <option value="WPA" selected>WPA / WPA2 / WPA3 <?= t('(Standard)') ?></option>
            <option value="WEP">WEP <?= t('(veraltet)') ?></option>
            <option value="nopass"><?= t('Offenes Netz (kein Passwort)') ?></option>
        </select>
        <div id="w-pw-row">
            <label for="w-pw"><?= t('Passwort') ?></label>
            <input id="w-pw" type="text" maxlength="63" autocomplete="off">
        </div>
        <label class="radio" style="margin-top:0.6rem">
            <input id="w-hidden" type="checkbox">
            <span><?= t('Verstecktes Netzwerk (SSID wird nicht ausgestrahlt)') ?></span>
        </label>

        <?= qr_design_panel([
            'frame' => true,
            'logos' => qr_logo_choices(auth_user()),
        ]) ?>

        <p class="muted small" style="margin-top:1rem"><?= t('Netzwerkname und Passwort werden nur zur Erzeugung der Grafik an den Server geschickt (per POST, nicht in der Adresszeile) und %snicht gespeichert%s. Der fertige Code funktioniert für immer – er enthält die Daten selbst.', '<strong>', '</strong>') ?></p>
    </div>

    <div class="card preview">
        <h3><?= t('Vorschau') ?></h3>
        <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
            <img id="w-preview" alt="<?= e(t('WLAN-QR-Code-Vorschau')) ?>" width="300">
        </div>
        <div id="w-lesbarkeit" class="lesbarkeit" aria-live="polite"></div>
        <div class="qr-links">
            <button id="w-svg" class="btn" type="button">SVG</button>
            <button id="w-png" class="btn" type="button"><?= t('PNG (1024 px)') ?></button>
            <button id="w-pdf" class="btn" type="button"><?= t('PDF (Druck)') ?></button>
            <button id="w-eps" class="btn" type="button" title="<?= e(t('EPS für Satz und Belichtung')) ?>">EPS</button>
        </div>
        <p class="muted small"><?= t('Tipp: Vor dem Aufhängen einmal mit dem eigenen Handy testen.') ?></p>
    </div>
</div>
</div>

<script src="assets/qroptions.js" defer></script>
<script src="assets/wlan-qr.js" defer></script>

<?php page_footer(); ?>
