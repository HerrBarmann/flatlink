<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/sso.php';

auth_boot();
if (auth_user() !== null) redirect_to('index.php');

$firstRun = !users_exist();
$error = null;

// Hat der Webserver schon jemanden angemeldet (Shibboleth, SAML, OIDC)?
// Der Versuch läuft bei jedem Aufruf, damit der Weg über einen Link direkt
// zu /admin/ ebenso funktioniert wie der Knopf unten.
if (!$firstRun && sso_enabled()) {
    $ssoErr = sso_attempt();
    if ($ssoErr !== null) {
        $error = $ssoErr;
    } elseif (auth_user() !== null) {
        redirect_to('index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($firstRun) {
        // Ersteinrichtung: ersten Admin anlegen (nur solange keine Nutzer existieren)
        $error = user_add($username, $password, 'admin');
        if ($error === null) {
            auth_login($username, $password);
            flash('Willkommen! Admin-Konto angelegt.');
            redirect_to('index.php');
        }
    } elseif (auth_login($username, $password)) {
        redirect_to('index.php');
    } elseif (ldap_enabled() && ldap_login($username, $password) === null) {
        // Lokales Passwort hat nicht gepasst – jetzt das Verzeichnis fragen
        redirect_to('index.php');
    } else {
        $error = 'Login fehlgeschlagen.';
    }
}

$sso = sso_cfg();
page_header($firstRun ? 'Ersteinrichtung' : 'Login', true);
?>
<div class="card narrow">
    <?php if ($firstRun): ?>
        <h1>Ersteinrichtung</h1>
        <p>Noch keine Nutzer vorhanden – leg dein Admin-Konto an.</p>
    <?php else: ?>
        <h1>Login</h1>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="flash flash-err"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$firstRun && sso_enabled() && $sso['login_url'] !== ''): ?>
        <p><a class="btn btn-primary" style="display:block;text-align:center"
              href="<?= e((string)$sso['login_url']) ?>"><?= e((string)$sso['button_label']) ?></a></p>
        <p class="muted small" style="text-align:center">oder mit lokalem Konto:</p>
    <?php endif; ?>

    <form method="post" action="" data-enter-submit>
        <?= csrf_field() ?>
        <label for="username"><?= $firstRun ? 'Nutzername' : 'E-Mail oder Nutzername' ?></label>
        <input id="username" type="text" name="username" required autofocus autocomplete="username">
        <label for="password">Passwort<?= $firstRun ? ' (mind. 8 Zeichen)' : '' ?></label>
        <input id="password" type="password" name="password" required autocomplete="<?= $firstRun ? 'new-password' : 'current-password' ?>">
        <p><button class="btn btn-primary" type="submit"><?= $firstRun ? 'Admin anlegen' : 'Anmelden' ?></button></p>
    </form>
    <?php if (!$firstRun): ?>
        <p class="muted small"><a href="../reset.php">Passwort vergessen?</a><?php if (settings()['registration'] === 'on'): ?> · Noch kein Konto? <a href="../register.php">Registrieren</a><?php endif; ?></p>
        <?php if (ldap_enabled()): ?>
        <p class="muted small">Konten aus dem Verzeichnis melden sich hier mit ihrer gewohnten Kennung an.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
