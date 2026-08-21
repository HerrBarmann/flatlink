<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Browser-Erweiterung: Läden, Verbindungscode, Einrichtung.
 *
 * Bis 4.4 war das ein Abschnitt tief unten im Profil – und damit genau dort,
 * wo niemand eine Erweiterung sucht. Jetzt ist es ein eigener Punkt in der
 * Kopfzeile: Wer sie noch nicht hat, findet sie; wer sie hat, findet den
 * Verbindungscode.
 */
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/extension.php';
require_once __DIR__ . '/../inc/audit.php';

$user = auth_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'connect_code') {
    csrf_check();
    if (!user_can($user['name'], 'api_access')) {
        flash(t('Für den Zugriff über die Schnittstelle fehlt deinem Konto die Berechtigung.'), 'err');
    } else {
        // Wie beim frisch angelegten Schlüssel: einmal anzeigen, dann weg
        $_SESSION['connect_code'] = ext_connect_code($user['name']);
        audit(t('Verbindungscode für die Erweiterung erzeugt'));
    }
    redirect_to(base_url() . '/admin/erweiterung.php');
}

$laeden = ext_stores();
$ladenNamen = ext_laden_namen();
$darfApi = user_can($user['name'], 'api_access');

page_header(t('Browser-Erweiterung'), true);
show_flash();
?>
<div class="card narrow">
    <h1><?= t('Browser-Erweiterung') ?></h1>
    <p><?= t('Kürzt die Seite, auf der du gerade bist, mit einem Klick – Kurzlink und QR-Code, ohne den Tab zu wechseln.') ?></p>

    <?php if ($laeden !== []): ?>
    <h2><?= t('1. Installieren') ?></h2>
    <p class="short-row">
        <?php foreach ($laeden as $laden => $url): ?>
        <a class="btn" href="<?= e($url) ?>" target="_blank" rel="noopener">
            <?= e($ladenNamen[$laden] ?? $laden) ?></a>
        <?php endforeach; ?>
    </p>
    <?php else: ?>
    <p class="muted small"><?= t('Sobald sie in den Läden von Chrome und Firefox steht, findest du den Link hier. Ist sie schon installiert, richtet ein Verbindungscode sie ein.') ?></p>
    <?php endif; ?>

    <h2><?= $laeden !== [] ? t('2. Verbinden') : t('Verbinden') ?></h2>
    <?php if (!$darfApi): ?>
        <p class="muted small"><?= t('Für den Zugriff über die Schnittstelle fehlt deinem Konto die Berechtigung – die Erweiterung braucht sie. Frag die Verwaltung deiner Instanz.') ?></p>
    <?php else: ?>
    <p class="muted small"><?= t('Ein Verbindungscode trägt Adresse und Zugangsschlüssel in einem: in der Erweiterung unter „Einstellungen" einfügen, fertig.') ?></p>

    <?php $code = $_SESSION['connect_code'] ?? null; unset($_SESSION['connect_code']); ?>
    <?php if ($code !== null): ?>
    <div class="flash flash-ok">
        <strong><?= t('Dein Verbindungscode:') ?></strong>
        <p><input type="text" value="<?= e($code) ?>" readonly onclick="this.select()"
                  style="font-family:var(--mono);font-size:0.85rem" aria-label="<?= t('Verbindungscode') ?>"></p>
        <p class="small"><?= t('Er enthält ein Zugangsmittel: nicht weitergeben. Zurückziehen lässt er sich im Profil unter „Programmierschnittstelle".') ?></p>
    </div>
    <?php endif; ?>

    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="connect_code">
        <p><button class="btn btn-primary" type="submit"><?= t('Verbindungscode erzeugen') ?></button></p>
    </form>
    <p class="muted small"><?= t('Jeder Code wird nur einmal angezeigt. Ein neuer Code legt einen eigenen Zugangsschlüssel an – alte bleiben gültig, bis du sie im Profil zurückziehst.') ?></p>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
