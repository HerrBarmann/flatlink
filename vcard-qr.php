<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/groups.php';
require_once __DIR__ . '/inc/qrpanel.php';

auth_boot();
page_header(t('Kontakt-QR-Code erstellen'), false,
    t('Name, Telefon, E-Mail und Web in einen QR-Code – gescannt landet der Kontakt direkt im Adressbuch.'),
    base_url() . '/vcard-qr.php');
?>

<div class="hero">
    <h1><?= t('Kontakt-QR-Code') ?></h1>
    <p class="sub"><?= t('Für Visitenkarte, Türschild oder Signatur: einmal scannen, im Adressbuch.') ?></p>
</div>

<div>
<?= qr_type_nav('vcard') ?>

<div class="designer">
    <div class="card controls">
        <h3><?= t('Dein Kontakt') ?></h3>
        <div class="two-col">
            <div><label for="v-vorname"><?= t('Vorname') ?></label><input id="v-vorname" type="text" maxlength="48" autofocus></div>
            <div><label for="v-nachname"><?= t('Nachname') ?></label><input id="v-nachname" type="text" maxlength="48"></div>
        </div>
        <label for="v-firma"><?= t('Firma') ?> <span class="muted"><?= t('(optional)') ?></span></label>
        <input id="v-firma" type="text" maxlength="48">
        <label for="v-tel"><?= t('Telefon') ?> <span class="muted"><?= t('(optional)') ?></span></label>
        <input id="v-tel" type="text" maxlength="32" inputmode="tel" placeholder="<?= e(t('+49 40 123456')) ?>">
        <label for="v-email"><?= t('E-Mail') ?> <span class="muted"><?= t('(optional)') ?></span></label>
        <input id="v-email" type="text" maxlength="64" inputmode="email">
        <label for="v-url"><?= t('Website') ?> <span class="muted"><?= t('(optional)') ?></span></label>
        <input id="v-url" type="text" maxlength="96" placeholder="https://…">

        <?= qr_design_panel([
            'frame' => true,
            'logos' => qr_logo_choices(auth_user()),
        ]) ?>

        <p class="muted small" style="margin-top:1rem"><?= t('Die Angaben werden nur zur Erzeugung der Grafik übertragen und %snicht gespeichert%s. Der Code enthält die Kontaktdaten selbst (vCard) und funktioniert ohne jeden Dienst dahinter.', '<strong>', '</strong>') ?></p>
    </div>

    <div class="card preview">
        <h3><?= t('Vorschau') ?></h3>
        <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
            <img id="v-preview" alt="<?= e(t('Kontakt-QR-Code-Vorschau')) ?>" width="300">
        </div>
        <div id="v-lesbarkeit" class="lesbarkeit" aria-live="polite"></div>
        <div class="qr-links">
            <button id="v-svg" class="btn" type="button">SVG</button>
            <button id="v-png" class="btn" type="button"><?= t('PNG (1024 px)') ?></button>
            <button id="v-pdf" class="btn" type="button"><?= t('PDF (Druck)') ?></button>
            <button id="v-eps" class="btn" type="button" title="<?= e(t('EPS für Satz und Belichtung')) ?>">EPS</button>
        </div>
        <p class="muted small" id="v-hint"><?= t('Tipp: Weniger Felder = gröberes, leichter scannbares Raster.') ?></p>
    </div>
</div>
</div>

<script src="assets/qroptions.js" defer></script>
<script src="assets/vcard-qr.js" defer></script>

<?php page_footer(); ?>
