<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Browser-Erweiterung und Programmierschnittstelle, gebündelt.
 *
 * Bis 4.4 waren das zwei Abschnitte tief unten im Profil – und damit genau
 * dort, wo niemand ein Werkzeug sucht. Sie gehören zusammen: Ein
 * Verbindungscode ist nichts anderes als ein verpackter Zugangsschlüssel.
 * Erreichbar über die Fußzeile – eingerichtet wird so etwas einmal, die
 * Kopfzeile bleibt den täglichen Wegen vorbehalten.
 */
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/extension.php';
require_once __DIR__ . '/../inc/token.php';
require_once __DIR__ . '/../inc/audit.php';

$user = auth_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'connect_code') {
        if (!user_can($user['name'], 'api_access')) {
            flash(t('Für den Zugriff über die Schnittstelle fehlt deinem Konto die Berechtigung.'), 'err');
        } else {
            // Wie beim frisch angelegten Schlüssel: einmal anzeigen, dann weg
            $_SESSION['connect_code'] = ext_connect_code($user['name']);
            audit(t('Verbindungscode für die Erweiterung erzeugt'));
        }
        redirect_to(base_url() . '/admin/erweiterung.php');
    }

    if ($action === 'token_new') {
        if (!user_can($user['name'], 'api_access')) {
            flash(t('Für die Schnittstelle fehlt deinem Konto die Berechtigung.'), 'err');
        } elseif (count(tokens_of($user['name'])) >= 10) {
            flash(t('Höchstens zehn Zugangsschlüssel pro Konto – zieh zuerst einen zurück.'), 'err');
        } else {
            $neu = token_create($user['name'], (string)($_POST['label'] ?? ''),
                (string)($_POST['scope'] ?? TOKEN_VOLL), ($_POST['own_only'] ?? '') === '1');
            // Der Klartext wird nirgends gespeichert; er muss also jetzt gezeigt
            // werden oder gar nicht. Über die Sitzung, damit die Umleitung
            // hinter dem Formular erhalten bleibt.
            $_SESSION['fresh_token'] = $neu['token'];
            flash(t('Zugangsschlüssel angelegt.'));
        }
        redirect_to(base_url() . '/admin/erweiterung.php#api');
    }

    if ($action === 'token_revoke') {
        $ok = token_revoke($user['name'], (string)($_POST['id'] ?? ''));
        flash($ok ? t('Zugangsschlüssel zurückgezogen.') : t('Diesen Schlüssel gibt es nicht.'), $ok ? 'ok' : 'err');
        redirect_to(base_url() . '/admin/erweiterung.php#api');
    }

}

$laeden = ext_stores();
$ladenNamen = ext_laden_namen();
$darfApi = user_can($user['name'], 'api_access');

page_header(t('Browser-Erweiterung / API'), true);
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
        <p class="small"><?= t('Er enthält ein Zugangsmittel: nicht weitergeben. Zurückziehen lässt er sich unten bei den Zugangsschlüsseln.') ?></p>
    </div>
    <?php endif; ?>

    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="connect_code">
        <p><button class="btn btn-primary" type="submit"><?= t('Verbindungscode erzeugen') ?></button></p>
    </form>
    <p class="muted small"><?= t('Jeder Code wird nur einmal angezeigt. Ein neuer Code legt einen eigenen Zugangsschlüssel an – alte bleiben gültig, bis du sie unten zurückziehst.') ?></p>
    <?php endif; ?>
    <h2 id="api"><?= t('Programmierschnittstelle') ?></h2>
    <p class="muted small"><?= t('Der Verbindungscode oben ist nichts anderes als ein verpackter Zugangsschlüssel – hier liegen alle Schlüssel deines Kontos, auch für eigene Programme.') ?></p>
    <?php if (!user_can($user['name'], 'api_access')): ?>
        <p class="muted small"><?= t('Für den Zugriff über die Schnittstelle fehlt deinem Konto die Berechtigung. Sie hängt an einer Gruppe – ein Administrator kann sie freischalten.') ?></p>
    <?php else: ?>
        <?php $frisch = $_SESSION['fresh_token'] ?? null; unset($_SESSION['fresh_token']); ?>
        <?php if ($frisch !== null): ?>
        <div class="flash flash-ok" style="word-break:break-all">
            <strong><?= t('Dein neuer Schlüssel:') ?></strong><br>
            <code><?= e($frisch) ?></code><br>
            <span class="small"><?= t('Notier ihn jetzt – er wird nicht gespeichert und lässt sich später nicht noch einmal anzeigen.') ?></span>
        </div>
        <?php endif; ?>
        <?php $doku = trim((string)cfg('api_doc_url')); ?>
        <p class="muted small"><?= t('Ein Schlüssel meldet ein Programm unter deinem Konto an. Er kann nie mehr, als du selbst darfst – und auf Wunsch deutlich weniger.') ?><?php if ($doku !== ''): ?>
        <a href="<?= e(str_contains($doku, '://') ? $doku : base_url() . '/' . ltrim($doku, '/')) ?>"><?= t('Zur Anleitung') ?></a>.<?php endif; ?></p>
        <?php $meine = tokens_of($user['name']); ?>
        <?php if ($meine !== []): ?>
        <div class="table-scroll"><table>
            <tr><th><?= t('Bezeichnung') ?></th><th><?= t('Umfang') ?></th><th><?= t('Anfang') ?></th><th><?= t('Angelegt') ?></th><th><?= t('Zuletzt benutzt') ?></th><th></th></tr>
            <?php foreach ($meine as $t): ?>
            <tr>
                <td><?= e((string)($t['label'] ?? '')) ?: '<span class="muted">' . t('ohne') . '</span>' ?></td>
                <td class="small"><?php
                    $umf = token_umfaenge()[(string)($t['scope'] ?? TOKEN_VOLL)] ?? token_umfaenge()[TOKEN_VOLL];
                    echo e($umf);
                    if (!empty($t['own_only'])) {
                        echo '<br><span class="muted">' . t('nur eigene Links') . '</span>';
                    }
                ?></td>
                <td><code><?= e((string)($t['hint'] ?? '')) ?>…</code></td>
                <td class="small"><?= e(date('d.m.Y', strtotime((string)$t['created']))) ?></td>
                <td class="small"><?= ($t['last_used'] ?? null) !== null
                    ? e(date('d.m.Y', strtotime((string)$t['last_used'])))
                    : '<span class="muted">' . t('nie') . '</span>' ?></td>
                <td><form method="post" action="" class="inline" data-confirm="<?= t('Schlüssel zurückziehen? Programme, die ihn nutzen, verlieren sofort den Zugriff.') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="token_revoke">
                    <input type="hidden" name="id" value="<?= e((string)$t['id']) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Zurückziehen') ?></button>
                </form></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="token_new">
            <label for="p-label"><?= t('Neuer Schlüssel') ?> <span class="muted"><?= t('(Bezeichnung, damit du ihn später zuordnen kannst)') ?></span></label>
            <input id="p-label" type="text" name="label" maxlength="60" placeholder="z. B. Kassensystem">

            <label for="p-scope"><?= t('Umfang') ?> <span class="muted"><?= t('(mehr als dein Konto darf er nie – aber weniger)') ?></span></label>
            <select id="p-scope" name="scope" style="max-width:22rem">
                <?php foreach (token_umfaenge() as $wert => $titel): ?>
                <option value="<?= e($wert) ?>"><?= e($titel) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="radio" style="margin-top:.6rem">
                <input type="checkbox" name="own_only" value="1">
                <span><?= t('Nur Links, die mit diesem Schlüssel angelegt wurden') ?><br>
                <span class="muted small"><?= t('Der Schlüssel sieht und ändert nichts vom übrigen Bestand des Kontos – auch nicht das, was du selbst hier anlegst. Passend für ein Kassensystem oder einen Auftrag, der laufend Codes erzeugt.') ?></span></span>
            </label>
            <p><button class="btn" type="submit"><?= t('Anlegen') ?></button></p>
        </form>
    <?php endif; ?>

</div>
<?php page_footer(); ?>
