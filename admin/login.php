<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/sso.php';
require_once __DIR__ . '/../inc/totp.php';
require_once __DIR__ . '/../inc/webauthn.php';

require_once __DIR__ . '/../inc/domains.php';
domain_force_main();
auth_boot();
if (auth_user() !== null) redirect_to('index.php');

// ---- Zweite Stufe -------------------------------------------------------
// Passwort stimmt, jetzt fehlt noch das Einmalkennwort. Der Zustand steht in
// der Sitzung; ohne ihn ist diese Maske nicht erreichbar.
$wartet = auth_pending();
if ($wartet !== null) {
    $fehler = null;
    $keys = passkeys_of($wartet);
    $mitApp = totp_active($wartet);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (($_POST['abbruch'] ?? '') === '1') {
            unset($_SESSION['pending_user'], $_SESSION['pending_since']);
            redirect_to('login.php');
        }
        // Passkey-Weg: antwortet mit JSON, wird vom Skript im Browser gerufen
        if (($_POST['action'] ?? '') === 'pk_challenge') {
            if ($keys === []) wa_json(['error' => 'Für dieses Konto ist kein Passkey hinterlegt.'], 400);
            wa_json(passkey_request_options($wartet));
        }
        if (($_POST['action'] ?? '') === 'pk_verify') {
            // Auch dieser Weg wird gebremst. Zwar ist eine Unterschrift nicht
            // zu erraten, aber ein Fehlversuch kostet uns Rechenzeit.
            if (!bucket_rate_ok('totp', 20, $wartet)) {
                wa_json(['error' => 'Zu viele Versuche – bitte später erneut.'], 429);
            }
            $daten = json_decode((string)($_POST['daten'] ?? ''), true);
            if (!is_array($daten)) wa_json(['error' => 'Antwort unlesbar.'], 400);
            $err = passkey_verify($wartet, $daten);
            if ($err !== null) { sleep(1); wa_json(['error' => $err], 403); }
            auth_pending_complete();
            wa_json(['ok' => true, 'redirect' => 'index.php']);
        }
        // Auch die zweite Stufe wird gebremst – sechs Stellen sind sonst in
        // überschaubarer Zeit durchprobiert.
        if (!bucket_rate_ok('totp', 20, $wartet)) {
            $fehler = 'Zu viele Versuche – bitte später erneut.';
        } elseif ($mitApp && totp_check($wartet, (string)($_POST['code'] ?? ''))) {
            auth_pending_complete();
            redirect_to('index.php');
        } else {
            sleep(1);
            $fehler = 'Der Code stimmt nicht.';
        }
    }
    page_header('Bestätigung', true);
    ?>
    <div class="card narrow">
        <h1>Noch ein Schritt</h1>
        <?php if ($fehler !== null): ?><div class="flash flash-err"><?= e($fehler) ?></div><?php endif; ?>

        <?php if ($keys !== []): ?>
        <p class="muted">Bestätige mit deinem Passkey – Fingerabdruck, Gesicht oder Geräte-PIN.</p>
        <p><button class="btn btn-primary" type="button" style="width:100%"
                   data-passkey="login" data-url="login.php" data-csrf="<?= e(csrf_token()) ?>"
                   data-status="pk-status">Mit Passkey bestätigen</button></p>
        <div id="pk-status" class="flash" style="display:none"></div>
        <?php endif; ?>

        <?php if ($mitApp): ?>
            <?php if ($keys !== []): ?>
            <p class="muted small" style="text-align:center">oder mit einem Code aus der App:</p>
            <?php else: ?>
            <p class="muted">Gib den sechsstelligen Code aus deiner Authenticator-App ein.
            Ein Wiederherstellungscode geht auch.</p>
            <?php endif; ?>
        <form method="post" action="" data-enter-submit>
            <?= csrf_field() ?>
            <label for="code">Code</label>
            <input id="code" type="text" name="code" required<?= $keys === [] ? ' autofocus' : '' ?>
                   autocomplete="one-time-code" inputmode="numeric" placeholder="123456">
            <p><button class="btn<?= $keys === [] ? ' btn-primary' : '' ?>" type="submit">Bestätigen</button></p>
        </form>
        <?php endif; ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="abbruch" value="1">
            <p class="muted small"><button class="btn btn-small" type="submit">Abbrechen</button></p>
        </form>
    </div>
    <?php
    if ($keys !== []) page_script('assets/passkey.js');
    page_footer();
    exit;
}

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
    } elseif (auth_login($username, $password, $braucht2fa)) {
        redirect_to($braucht2fa ? 'login.php' : 'index.php');
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
