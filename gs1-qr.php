<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/groups.php';
require_once __DIR__ . '/inc/qrpanel.php';

auth_boot();
page_header(t('GS1 Digital Link erstellen'), false,
    t('GTIN mit Charge, Seriennummer oder Haltbarkeitsdatum als GS1 Digital Link – der Nachfolger des EAN-Strichcodes.'),
    base_url() . '/gs1-qr.php');
?>

<div class="hero">
    <h1><?= t('GS1 Digital Link') ?></h1>
    <p class="sub"><?= t('Ein Code für Kasse und Kundschaft: maschinenlesbar und zugleich eine Adresse, die im Browser aufgeht.') ?></p>
</div>

<div>
<?= qr_type_nav('gs1') ?>

<div class="designer">
    <div class="card controls">
        <h3><?= t('Dein Produkt') ?></h3>

        <label for="g-gtin">GTIN / EAN <span class="muted"><?= t('(8, 12, 13 oder 14 Ziffern)') ?></span></label>
        <input id="g-gtin" type="text" inputmode="numeric" maxlength="20" autofocus
               placeholder="4006381333931">

        <div class="two-col">
            <div>
                <label for="g-lot"><?= t('Charge') ?> <span class="muted"><?= t('(optional)') ?></span></label>
                <input id="g-lot" type="text" maxlength="20" placeholder="LOT-42">
            </div>
            <div>
                <label for="g-serial"><?= t('Seriennummer') ?> <span class="muted"><?= t('(optional)') ?></span></label>
                <input id="g-serial" type="text" maxlength="20" placeholder="SN-0001">
            </div>
        </div>

        <label for="g-mhd"><?= t('Mindestens haltbar bis') ?> <span class="muted"><?= t('(optional)') ?></span></label>
        <input id="g-mhd" type="date">

        <label for="g-resolver"><?= t('Auflösungsdienst') ?> <span class="muted"><?= t('(optional – leer = id.gs1.org)') ?></span></label>
        <input id="g-resolver" type="text" placeholder="https://id.gs1.org">
        <p class="muted small"><?= t('Hier steht, unter welcher Adresse der Code aufgelöst wird. Wer eine eigene Domain einträgt, muss dort selbst dafür sorgen, dass beim Scannen etwas Sinnvolles erscheint.') ?></p>

        <?= qr_design_panel([
            'frame' => true,
            'logos' => qr_logo_choices(auth_user()),
        ]) ?>
    </div>

    <div class="card preview">
        <h3><?= t('Vorschau') ?></h3>
        <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
            <img id="g-preview" alt="<?= e(t('GS1-QR-Code-Vorschau')) ?>" width="300">
        </div>
        <div id="g-lesbarkeit" class="lesbarkeit" aria-live="polite"></div>
        <div id="g-status" class="flash" style="display:none"></div>
        <p class="muted small" style="word-break:break-all"><code id="g-url"></code></p>
        <div class="qr-links">
            <button class="btn btn-small" type="button" id="g-svg">SVG</button>
            <button class="btn btn-small" type="button" id="g-png"><?= t('PNG (1024 px)') ?></button>
            <button class="btn btn-small" type="button" id="g-pdf"><?= t('PDF (Druck)') ?></button>
            <button class="btn btn-small" type="button" id="g-eps" title="<?= e(t('EPS für Satz und Belichtung')) ?>">EPS</button>
        </div>
        <p class="muted small"><?= t('Die Angaben werden nur zur Erzeugung der Grafik verwendet und %snicht gespeichert%s. Der Code enthält die Adresse selbst – er funktioniert für immer, ganz ohne uns.', '<strong>', '</strong>') ?></p>
    </div>
</div>
</div>

<script src="assets/qroptions.js" defer></script>
<script src="assets/gs1-qr.js" defer></script>

<?php page_footer(); ?>
