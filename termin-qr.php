<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/groups.php';
require_once __DIR__ . '/inc/qrpanel.php';

auth_boot();
page_header(t('Termin-QR-Code erstellen'), false,
    t('Titel, Ort und Zeit in einen QR-Code – gescannt landet der Termin im Kalender.'),
    base_url() . '/termin-qr.php');
?>

<div class="hero">
    <h1><?= t('Termin-QR-Code') ?></h1>
    <p class="sub"><?= t('Für Plakate, Programmhefte und Aushänge: scannen, im Kalender.') ?></p>
</div>

<div>
<?= qr_type_nav('event') ?>

<div class="designer">
    <div class="card controls">
        <h3>Dein Termin</h3>
        <label for="t-titel">Titel</label>
        <input id="t-titel" type="text" maxlength="64" placeholder="Sommerfest 2026" autofocus>
        <label for="t-ort">Ort <span class="muted">(optional)</span></label>
        <input id="t-ort" type="text" maxlength="64" placeholder="Vereinsheim, Musterweg 1">
        <div class="two-col">
            <div><label for="t-start">Beginn</label><input id="t-start" type="datetime-local"></div>
            <div><label for="t-ende">Ende <span class="muted">(optional)</span></label><input id="t-ende" type="datetime-local"></div>
        </div>

        <?= qr_design_panel([
            'frame' => true,
            'logos' => qr_logo_choices(auth_user()),
        ]) ?>

        <p class="muted small" style="margin-top:1rem">Die Angaben werden nur zur Erzeugung
        der Grafik verwendet und <strong>nicht gespeichert</strong>. Der Code enthält den
        Termin selbst – er funktioniert für immer, ganz ohne uns.</p>
    </div>

    <div class="card preview">
        <h3>Vorschau</h3>
        <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
            <img id="t-preview" alt="Termin-QR-Code-Vorschau" width="300">
        </div>
        <div id="t-lesbarkeit" class="lesbarkeit" aria-live="polite"></div>
        <div class="qr-links">
            <button id="t-svg" class="btn" type="button">SVG</button>
            <button id="t-png" class="btn" type="button">PNG (1024 px)</button>
            <button id="t-pdf" class="btn" type="button">PDF (Druck)</button>
            <button id="t-eps" class="btn" type="button" title="EPS für Satz und Belichtung">EPS</button>
        </div>
        <p class="muted small">Beim Scannen bietet das Handy an, den Termin in den Kalender zu übernehmen.</p>
    </div>
</div>
</div>

<script src="assets/qroptions.js" defer></script>
<script src="assets/termin-qr.js" defer></script>

<?php page_footer(); ?>
