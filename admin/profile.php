<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/mail.php';

$user = auth_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'password');

    if ($action === 'email') {
        $new = strtolower(trim((string)($_POST['email'] ?? '')));
        if (filter_var($new, FILTER_VALIDATE_EMAIL) === false) {
            flash('Das sieht nicht nach einer gültigen E-Mail-Adresse aus.', 'err');
        } elseif (user_email_taken($new) && user_resolve($new) !== $user['name']) {
            flash('Diese Adresse ist bereits mit einem Konto verknüpft.', 'err');
        } elseif (!bucket_rate_ok('chmail', 5)) {
            flash('Zu viele Versuche – bitte in einer Stunde erneut.', 'err');
        } else {
            $token = pending_create('chmail', ['user' => $user['name'], 'email' => $new]);
            $ok = mail_send($new, 'Neue E-Mail-Adresse bei ' . cfg('site_name') . ' bestätigen',
                "Hallo,\n\n"
                . "für das Konto „" . $user['name'] . "“ bei " . cfg('site_name') . " soll diese Adresse\n"
                . "als E-Mail-Adresse hinterlegt werden. Zum Bestätigen (angemeldet bleiben!):\n\n"
                . base_url() . "/admin/profile.php?token=" . $token . "\n\n"
                . "Der Link ist 24 Stunden gültig. Falls du das nicht warst, ignoriere diese Mail.\n\n"
                . "– " . cfg('site_name'));
            // Sicherheits-Info an die bisherige Adresse, falls vorhanden
            $old = users_all()[$user['name']]['email'] ?? null;
            if ($ok && $old !== null && strtolower($old) !== $new) {
                mail_send($old, 'E-Mail-Änderung für dein Konto bei ' . cfg('site_name'),
                    "Hallo,\n\n"
                    . "für dein Konto wurde eine Änderung der E-Mail-Adresse angefordert\n"
                    . "(neue Adresse: " . $new . "). Wenn das nicht du warst, ändere bitte\n"
                    . "umgehend dein Passwort und melde dich bei uns.\n\n"
                    . "– " . cfg('site_name'));
            }
            flash($ok
                ? 'Bestätigungslink an ' . $new . ' geschickt – die Adresse ist erst nach dem Klick aktiv.'
                : 'Die Mail konnte gerade nicht verschickt werden – bitte später erneut versuchen.',
                $ok ? 'ok' : 'err');
        }
    } else {
        $current = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $repeat = (string)($_POST['repeat'] ?? '');

        $stored = users_all()[$user['name']]['pass'] ?? '';
        if (!password_verify($current, $stored)) {
            sleep(1);
            flash('Das aktuelle Passwort stimmt nicht.', 'err');
        } elseif ($new !== $repeat) {
            flash('Die Wiederholung stimmt nicht mit dem neuen Passwort überein.', 'err');
        } else {
            $err = user_set_password($user['name'], $new);
            flash($err ?? 'Passwort geändert.', $err === null ? 'ok' : 'err');
        }
    }
    redirect_to('profile.php');
}

// Bestätigungslink aus der Mail: neue Adresse aktivieren
if (isset($_GET['token'])) {
    $d = pending_get('chmail', (string)$_GET['token']);
    if ($d === null) {
        flash('Dieser Bestätigungslink ist ungültig oder abgelaufen.', 'err');
    } elseif (($d['user'] ?? '') !== $user['name']) {
        flash('Dieser Link gehört zu einem anderen Konto – bitte dort anmelden und erneut klicken.', 'err');
    } else {
        pending_take('chmail', (string)$_GET['token']);
        $err = user_set_email($user['name'], (string)$d['email']);
        flash($err ?? 'E-Mail-Adresse aktualisiert: ' . $d['email'], $err === null ? 'ok' : 'err');
    }
    redirect_to('profile.php');
}

page_header('Profil', true);
show_flash();
?>
<div class="card narrow">
    <h1>Profil</h1>
    <p class="muted">Angemeldet als <strong><?= e($user['name']) ?></strong> (Rolle: <?= e($user['role']) ?>)</p>
    <?php $codeQuota = (int)cfg('custom_code_quota'); ?>
    <p><span class="muted small">Links: <?= link_count($user['name']) ?>/<?= e(limit_label(user_limit($user['name'], 'links'))) ?> ·
        Wunsch-Codes: <?= custom_code_count($user['name']) ?><?= $codeQuota > 0 ? '/' . $codeQuota : '' ?>
        (mind. <?= (int)cfg('custom_code_min_len') ?> Zeichen) ·
        Logos: <?= e(limit_label(user_limit($user['name'], 'logos'))) ?> ·
        Statistik: <?= (int)user_limit($user['name'], 'stats_days') === PHP_INT_MAX ? 'unbegrenzt' : (int)user_limit($user['name'], 'stats_days') . ' Tage' ?> ·
        <a href="import.php">CSV-Import</a></span></p>

    <h2>E-Mail-Adresse</h2>
    <?php $email = users_all()[$user['name']]['email'] ?? null; ?>
    <?php if ($email !== null): ?>
        <p class="muted small">Hinterlegt: <strong><?= e($email) ?></strong> – wird für Login und Passwort-Reset verwendet.</p>
    <?php else: ?>
        <p class="muted small"><strong>Keine E-Mail hinterlegt.</strong> Ohne bestätigte Adresse funktioniert
        der Passwort-Reset für dieses Konto nicht.</p>
    <?php endif; ?>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email">
        <label for="p-email"><?= $email !== null ? 'Neue E-Mail-Adresse' : 'E-Mail-Adresse hinterlegen' ?></label>
        <div class="short-row">
            <input id="p-email" type="text" name="email" required inputmode="email" autocomplete="email">
            <button class="btn" type="submit">Bestätigungslink senden</button>
        </div>
        <p class="muted small">Wir schicken einen Link an die neue Adresse – erst nach dem Klick ist sie aktiv.</p>
    </form>

    <h2>Passwort ändern</h2>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <label for="p-current">Aktuelles Passwort</label>
        <input id="p-current" type="password" name="current" required autocomplete="current-password">
        <label for="p-new">Neues Passwort (mind. 8 Zeichen)</label>
        <input id="p-new" type="password" name="new" required minlength="8" autocomplete="new-password">
        <label for="p-repeat">Neues Passwort wiederholen</label>
        <input id="p-repeat" type="password" name="repeat" required minlength="8" autocomplete="new-password">
        <p><button class="btn btn-primary" type="submit">Passwort ändern</button></p>
    </form>
</div>
<?php page_footer(); ?>
