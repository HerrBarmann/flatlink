<?php
declare(strict_types=1);

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
            $error = 'Das hat nicht geklappt.';
        } elseif (!bucket_rate_ok('pwreset', 5)) {
            $error = 'Zu viele Versuche von dieser Adresse – bitte in einer Stunde erneut.';
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
                mail_send($email, 'Passwort zurücksetzen bei ' . cfg('site_name'),
                    "Hallo,\n\n"
                    . "hier kannst du ein neues Passwort setzen:\n\n"
                    . $link . "?token=" . $t . "\n\n"
                    . "Der Link ist eine Stunde gültig. Falls du das nicht angefordert hast,\n"
                    . "ignoriere diese Mail – dein Passwort bleibt unverändert.\n\n"
                    . "– " . cfg('site_name'));
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
            $error = 'Dieser Link ist ungültig oder abgelaufen – fordere einfach einen neuen an.';
        } elseif (strlen($new) < 8) {
            $error = 'Passwort: mindestens 8 Zeichen.';
        } elseif ($new !== $repeat) {
            $error = 'Die Wiederholung stimmt nicht mit dem neuen Passwort überein.';
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

page_header('Passwort zurücksetzen');
?>

<?php if ($stage === 'done'): ?>
    <div class="card center">
        <h1>Erledigt.</h1>
        <p>Dein Passwort ist geändert – du kannst dich jetzt anmelden.</p>
        <p><a class="btn btn-primary" href="admin/">Zum Login</a></p>
    </div>
<?php elseif ($stage === 'bad'): ?>
    <div class="card center">
        <h1>Link abgelaufen</h1>
        <p>Dieser Link ist ungültig oder abgelaufen – fordere einfach einen neuen an.</p>
        <p><a class="btn" href="reset.php">Neuen Link anfordern</a></p>
    </div>
<?php elseif ($stage === 'set'): ?>
    <div class="card narrow">
        <h1>Neues Passwort</h1>
        <?php if ($error !== null): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="reset.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label for="rs-new">Neues Passwort (mind. 8 Zeichen)</label>
            <input id="rs-new" type="password" name="new" required minlength="8" autofocus autocomplete="new-password">
            <label for="rs-repeat">Wiederholen</label>
            <input id="rs-repeat" type="password" name="repeat" required minlength="8" autocomplete="new-password">
            <p><button class="btn btn-primary" type="submit">Passwort setzen</button></p>
        </form>
    </div>
<?php elseif ($sent): ?>
    <div class="card center">
        <h1>Schau in dein Postfach.</h1>
        <p>Falls zu dieser Adresse ein Konto existiert, ist ein Reset-Link unterwegs (eine Stunde gültig).</p>
    </div>
<?php else: ?>
    <div class="card narrow">
        <h1>Passwort vergessen?</h1>
        <p class="muted">Kein Drama. Wir schicken dir einen Link zum Zurücksetzen.</p>
        <?php if ($error !== null): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="reset.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
            <label for="rs-email">E-Mail-Adresse</label>
            <input id="rs-email" type="text" name="email" required autofocus autocomplete="email" inputmode="email">
            <p><button class="btn btn-primary" type="submit">Link anfordern</button></p>
        </form>
    </div>
<?php endif; ?>
<?php page_footer(); ?>
