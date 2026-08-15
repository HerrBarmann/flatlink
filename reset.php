<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mail.php';

auth_boot();

$error = null;
$sent = false;
$done = false;
$token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request') {
        if (($_POST['website'] ?? '') !== '') {
            $error = t('Das hat nicht geklappt.');
        } elseif (!bucket_rate_ok('pwreset', 5)) {
            $error = t('Zu viele Versuche von dieser Adresse – bitte in einer Stunde erneut.');
        } else {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $key = user_resolve($email);
            // Zentral verwaltete Konten (LDAP/SSO) haben hier kein Passwort,
            // das sich zurücksetzen ließe – eine Mail zu schicken würde nur in
            // die Irre führen. Nach außen bleibt die Antwort trotzdem gleich.
            if ($key !== null && (users_all()[$key]['auth'] ?? 'local') !== 'local') {
                $key = null;
            }
            $link = mail_link('reset.php');
            if ($key !== null && $link === null) {
                // Ohne konfigurierte base_url ließe sich der Link fälschen –
                // dann lieber gar nicht verschicken
                error_log('flatlink: Passwort-Reset nicht verschickt – base_url ist nicht konfiguriert.');
                $key = null;
            }
            if ($key !== null) {
                $t = pending_create('pwreset', ['user' => $key], 3600);
                mail_send($email, t('Passwort zurücksetzen bei %s', cfg('site_name')),
                    t("Hallo,\n\nhier kannst du ein neues Passwort setzen:\n\n%s\n\nDer Link ist eine Stunde gültig. Falls du das nicht angefordert hast,\nignoriere diese Mail – dein Passwort bleibt unverändert.\n\n– %s",
                        $link . '?token=' . $t, cfg('site_name')));
            }
            // Immer dieselbe Meldung – keine Auskunft, ob die Adresse existiert
            $sent = true;
        }
    } elseif ($action === 'set') {
        $d = pending_get('pwreset', $token);
        $new = (string)($_POST['new'] ?? '');
        $repeat = (string)($_POST['repeat'] ?? '');
        if ($d === null) {
            $token = '';
            $error = t('Dieser Link ist ungültig oder abgelaufen – fordere einfach einen neuen an.');
        } elseif (strlen($new) < 8) {
            $error = t('Passwort: mindestens 8 Zeichen.');
        } elseif ($new !== $repeat) {
            $error = t('Die Wiederholung stimmt nicht mit dem neuen Passwort überein.');
        } else {
            pending_take('pwreset', $token);
            user_set_password($d['user'], $new);
            $done = true;
        }
    }
}

$stage = 'request';
if ($done) {
    $stage = 'done';
} elseif ($token !== '') {
    $stage = pending_get('pwreset', $token) !== null ? 'set' : ($error !== null ? 'request' : 'bad');
}

page_header(t('Passwort zurücksetzen'));
?>

<?php if ($stage === 'done'): ?>
    <div class="card center">
        <h1><?= t('Erledigt.') ?></h1>
        <p><?= t('Dein Passwort ist geändert – du kannst dich jetzt anmelden.') ?></p>
        <p><a class="btn btn-primary" href="admin/"><?= t('Zum Login') ?></a></p>
    </div>
<?php elseif ($stage === 'bad'): ?>
    <div class="card center">
        <h1><?= t('Link abgelaufen') ?></h1>
        <p><?= t('Dieser Link ist ungültig oder abgelaufen – fordere einfach einen neuen an.') ?></p>
        <p><a class="btn" href="reset.php"><?= t('Neuen Link anfordern') ?></a></p>
    </div>
<?php elseif ($stage === 'set'): ?>
    <div class="card narrow">
        <h1><?= t('Neues Passwort') ?></h1>
        <?php if ($error !== null): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="reset.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label for="rs-new"><?= t('Neues Passwort (mind. 8 Zeichen)') ?></label>
            <input id="rs-new" type="password" name="new" required minlength="8" autofocus autocomplete="new-password">
            <label for="rs-repeat"><?= t('Wiederholen') ?></label>
            <input id="rs-repeat" type="password" name="repeat" required minlength="8" autocomplete="new-password">
            <p><button class="btn btn-primary" type="submit"><?= t('Passwort setzen') ?></button></p>
        </form>
    </div>
<?php elseif ($sent): ?>
    <div class="card center">
        <h1><?= t('Schau in dein Postfach.') ?></h1>
        <p><?= t('Falls zu dieser Adresse ein Konto existiert, ist ein Reset-Link unterwegs (eine Stunde gültig).') ?></p>
    </div>
<?php else: ?>
    <div class="card narrow">
        <h1><?= t('Passwort vergessen?') ?></h1>
        <p class="muted"><?= t('Kein Drama. Wir schicken dir einen Link zum Zurücksetzen.') ?></p>
        <?php if ($error !== null): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="reset.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
            <label for="rs-email"><?= t('E-Mail-Adresse') ?></label>
            <input id="rs-email" type="text" name="email" required autofocus autocomplete="email" inputmode="email">
            <p><button class="btn btn-primary" type="submit"><?= t('Link anfordern') ?></button></p>
        </form>
    </div>
<?php endif; ?>
<?php page_footer(); ?>
