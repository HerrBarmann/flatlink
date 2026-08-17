<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/groups.php';
require_once __DIR__ . '/inc/qrpanel.php';

auth_boot();
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
        <h3>Dein WLAN</h3>
        <label for="w-ssid">Netzwerkname (SSID)</label>
        <input id="w-ssid" type="text" maxlength="32" placeholder="MeinWLAN" autofocus>
        <label for="w-enc">Verschlüsselung</label>
        <select id="w-enc">
            <option value="WPA" selected>WPA / WPA2 / WPA3 (Standard)</option>
            <option value="WEP">WEP (veraltet)</option>
            <option value="nopass">Offenes Netz (kein Passwort)</option>
        </select>
        <div id="w-pw-row">
            <label for="w-pw">Passwort</label>
            <input id="w-pw" type="text" maxlength="63" autocomplete="off">
        </div>
        <label class="radio" style="margin-top:0.6rem">
            <input id="w-hidden" type="checkbox">
            <span>Verstecktes Netzwerk (SSID wird nicht ausgestrahlt)</span>
        </label>

        <?= qr_design_panel([
            'frame' => true,
            'logos' => qr_logo_choices(auth_user()),
        ]) ?>

        <p class="muted small" style="margin-top:1rem">Netzwerkname und Passwort werden nur zur Erzeugung der Grafik an den Server geschickt (per POST, nicht in der Adresszeile) und <strong>nicht gespeichert</strong>. Der fertige Code funktioniert für immer – er enthält die Daten selbst.</p>
    </div>

    <div class="card preview">
        <h3>Vorschau</h3>
        <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
            <img id="w-preview" alt="WLAN-QR-Code-Vorschau" width="300">
        </div>
        <div id="w-lesbarkeit" class="lesbarkeit" aria-live="polite"></div>
        <div class="qr-links">
            <button id="w-svg" class="btn" type="button">SVG</button>
            <button id="w-png" class="btn" type="button">PNG (1024 px)</button>
            <button id="w-pdf" class="btn" type="button">PDF (Druck)</button>
            <button id="w-eps" class="btn" type="button" title="EPS für Satz und Belichtung">EPS</button>
        </div>
        <p class="muted small">Tipp: Vor dem Aufhängen einmal mit dem eigenen Handy testen.</p>
    </div>
</div>
</div>

<script src="assets/qroptions.js" defer></script>
<script src="assets/wlan-qr.js" defer></script>

<?php page_footer(); ?>
