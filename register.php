<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/mail.php';

auth_boot();
if (auth_user() !== null) redirect_to('admin/');

$closed = settings()['registration'] !== 'on';
$error = null;
$sent = false;
$tokenBad = false;

// Bestätigungslink aus der Mail: Token einlösen, Konto anlegen, direkt einloggen
if (!$closed && isset($_GET['token'])) {
    $d = pending_take('reg', (string)$_GET['token']);
    if ($d === null) {
        $tokenBad = true;
    } else {
        $err = user_activate($d['email'], $d['pass']);
        if ($err === null) {
            session_regenerate_id(true);
            $_SESSION['user'] = $d['email'];
            unset($_SESSION['csrf']);
            flash(t('Konto bestätigt – willkommen!'));
            redirect_to('admin/');
        }
        $error = $err;
    }
}

if (!$closed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Honeypot: echte Browser lassen das Feld leer
    if (($_POST['website'] ?? '') !== '') {
        $error = t('Das hat nicht geklappt.');
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        $repeat = (string)($_POST['repeat'] ?? '');

        if (($err = register_validate($email, $pass)) !== null) {
            $error = $err;
        } elseif ($pass !== $repeat) {
            $error = t('Die Wiederholung stimmt nicht mit dem Passwort überein.');
        } elseif (!bucket_rate_ok('reg', 5)) {
            $error = t('Zu viele Versuche von dieser Adresse – bitte in einer Stunde erneut.');
        } else {
            if (user_email_taken($email)) {
                // Keine Konto-Enumeration: nach außen dieselbe Meldung, per Mail aufklären
                mail_send($email, t('Dein Konto bei %s', cfg('site_name')),
                    t("Hallo,\n\njemand (vermutlich du) wollte sich mit dieser Adresse bei %s\nregistrieren – aber dazu gibt es schon ein Konto.\n\nPasswort vergessen? %s\n\nFalls das nicht du warst, kannst du diese Mail ignorieren.\n\n– %s",
                        cfg('site_name'), (mail_link('reset.php') ?? ''), cfg('site_name')));
            } else {
                $token = pending_create('reg', [
                    'email' => $email,
                    'pass' => password_hash($pass, PASSWORD_DEFAULT),
                ]);
                $ok = mail_send($email, t('Bestätige deine Registrierung bei %s', cfg('site_name')),
                    t("Hallo,\n\neinmal klicken, fertig:\n\n%s\n\nDer Link ist 24 Stunden gültig. Falls du dich nicht bei %s\nregistriert hast, ignoriere diese Mail einfach – es passiert nichts.\n\n– %s",
                        mail_link('register.php') . '?token=' . $token, cfg('site_name'), cfg('site_name')));
                if (!$ok) {
                    $error = t('Die Bestätigungsmail konnte gerade nicht verschickt werden – bitte später erneut versuchen.');
                }
            }
            if ($error === null) $sent = true;
        }
    }
}

page_header(t('Registrieren'), false,
    t('Kostenloses Konto für %s: QR-Codes mit eigenem Logo, Klick-Statistik und bearbeitbare Kurzlinks.', cfg('site_name')),
    base_url() . '/register.php');
?>

<?php if ($closed): ?>
    <div class="card center">
        <h1><?= t('Registrierung geschlossen') ?></h1>
        <p><?= t('Aktuell nehmen wir keine neuen Konten an. Kurzlinks kannst du trotzdem %sohne Konto erstellen%s.', '<a href="./">', '</a>') ?></p>
    </div>
<?php elseif ($sent): ?>
    <div class="card center">
        <h1><?= t('Fast geschafft.') ?></h1>
        <p><?= t('Wir haben dir eine E-Mail geschickt – ein Klick auf den Link darin, und dein Konto ist aktiv.') ?></p>
        <p class="muted small"><?= t('Nichts angekommen? Schau im Spam-Ordner nach. Der Link ist 24 Stunden gültig.') ?></p>
    </div>
<?php else: ?>
    <div class="card narrow">
        <h1><?= t('Konto anlegen') ?></h1>
        <p class="muted"><?= t("Kostenlos. Damit gibt's den vollen QR-Designer mit eigenem Logo, Klick-Statistik, Bearbeiten und Ablaufdaten.") ?></p>

        <?php if ($tokenBad): ?>
            <div class="flash flash-err"><?= t('Dieser Bestätigungslink ist ungültig oder abgelaufen. Registriere dich einfach erneut.') ?></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="flash flash-err"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php">
            <?= csrf_field() ?>
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
            <label for="r-email"><?= t('E-Mail-Adresse') ?></label>
            <input id="r-email" type="text" name="email" required autofocus autocomplete="email" inputmode="email">
            <label for="r-pass"><?= t('Passwort (mind. 8 Zeichen)') ?></label>
            <input id="r-pass" type="password" name="password" required minlength="8" autocomplete="new-password">
            <label for="r-repeat"><?= t('Passwort wiederholen') ?></label>
            <input id="r-repeat" type="password" name="repeat" required minlength="8" autocomplete="new-password">
            <p><button class="btn btn-primary" type="submit"><?= t('Registrieren') ?></button></p>
        </form>
        <p class="muted small"><?= t('Schon ein Konto?') ?> <a href="admin/"><?= t('Anmelden') ?></a> · <a href="reset.php"><?= t('Passwort vergessen?') ?></a></p>
    </div>
<?php endif; ?>
<?php page_footer(); ?>
