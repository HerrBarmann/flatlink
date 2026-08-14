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
            flash('Konto bestätigt – willkommen!');
            redirect_to('admin/');
        }
        $error = $err;
    }
}

if (!$closed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Honeypot: echte Browser lassen das Feld leer
    if (($_POST['website'] ?? '') !== '') {
        $error = 'Das hat nicht geklappt.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        $repeat = (string)($_POST['repeat'] ?? '');

        if (($err = register_validate($email, $pass)) !== null) {
            $error = $err;
        } elseif ($pass !== $repeat) {
            $error = 'Die Wiederholung stimmt nicht mit dem Passwort überein.';
        } elseif (!bucket_rate_ok('reg', 5)) {
            $error = 'Zu viele Versuche von dieser Adresse – bitte in einer Stunde erneut.';
        } else {
            if (user_email_taken($email)) {
                // Keine Konto-Enumeration: nach außen dieselbe Meldung, per Mail aufklären
                mail_send($email, 'Dein Konto bei ' . cfg('site_name'),
                    "Hallo,\n\n"
                    . "jemand (vermutlich du) wollte sich mit dieser Adresse bei " . cfg('site_name') . "\n"
                    . "registrieren – aber dazu gibt es schon ein Konto.\n\n"
                    . "Passwort vergessen? " . (mail_link('reset.php') ?? '') . "\n\n"
                    . "Falls das nicht du warst, kannst du diese Mail ignorieren.\n\n"
                    . "– " . cfg('site_name'));
            } else {
                $token = pending_create('reg', [
                    'email' => $email,
                    'pass' => password_hash($pass, PASSWORD_DEFAULT),
                ]);
                $ok = mail_send($email, 'Bestätige deine Registrierung bei ' . cfg('site_name'),
                    "Hallo,\n\n"
                    . "einmal klicken, fertig:\n\n"
                    . mail_link('register.php') . "?token=" . $token . "\n\n"
                    . "Der Link ist 24 Stunden gültig. Falls du dich nicht bei " . cfg('site_name') . "\n"
                    . "registriert hast, ignoriere diese Mail einfach – es passiert nichts.\n\n"
                    . "– " . cfg('site_name'));
                if (!$ok) {
                    $error = 'Die Bestätigungsmail konnte gerade nicht verschickt werden – bitte später erneut versuchen.';
                }
            }
            if ($error === null) $sent = true;
        }
    }
}

page_header('Registrieren', false,
    'Kostenloses Konto für ' . cfg('site_name') . ': QR-Codes mit eigenem Logo, Klick-Statistik und bearbeitbare Kurzlinks.',
    base_url() . '/register.php');
?>

<?php if ($closed): ?>
    <div class="card center">
        <h1>Registrierung geschlossen</h1>
        <p>Aktuell nehmen wir keine neuen Konten an. Kurzlinks kannst du trotzdem <a href="./">ohne Konto erstellen</a>.</p>
    </div>
<?php elseif ($sent): ?>
    <div class="card center">
        <h1>Fast geschafft.</h1>
        <p>Wir haben dir eine E-Mail geschickt – ein Klick auf den Link darin, und dein Konto ist aktiv.</p>
        <p class="muted small">Nichts angekommen? Schau im Spam-Ordner nach. Der Link ist 24 Stunden gültig.</p>
    </div>
<?php else: ?>
    <div class="card narrow">
        <h1>Konto anlegen</h1>
        <p class="muted">Kostenlos. Damit gibt's den vollen QR-Designer mit eigenem Logo, Klick-Statistik, Bearbeiten und Ablaufdaten.</p>

        <?php if ($tokenBad): ?>
            <div class="flash flash-err">Dieser Bestätigungslink ist ungültig oder abgelaufen. Registriere dich einfach erneut.</div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="flash flash-err"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php">
            <?= csrf_field() ?>
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
            <label for="r-email">E-Mail-Adresse</label>
            <input id="r-email" type="text" name="email" required autofocus autocomplete="email" inputmode="email">
            <label for="r-pass">Passwort (mind. 8 Zeichen)</label>
            <input id="r-pass" type="password" name="password" required minlength="8" autocomplete="new-password">
            <label for="r-repeat">Passwort wiederholen</label>
            <input id="r-repeat" type="password" name="repeat" required minlength="8" autocomplete="new-password">
            <p><button class="btn btn-primary" type="submit">Registrieren</button></p>
        </form>
        <p class="muted small">Schon ein Konto? <a href="admin/">Anmelden</a> · <a href="reset.php">Passwort vergessen?</a></p>
    </div>
<?php endif; ?>
<?php page_footer(); ?>
