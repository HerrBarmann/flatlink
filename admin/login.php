<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
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
            if ($keys === []) wa_json(['error' => t('Für dieses Konto ist kein Passkey hinterlegt.')], 400);
            wa_json(passkey_request_options($wartet));
        }
        if (($_POST['action'] ?? '') === 'pk_verify') {
            // Auch dieser Weg wird gebremst. Zwar ist eine Unterschrift nicht
            // zu erraten, aber ein Fehlversuch kostet uns Rechenzeit.
            if (!bucket_rate_ok('totp', 20, $wartet)) {
                wa_json(['error' => t('Zu viele Versuche – bitte später erneut.')], 429);
            }
            $daten = json_decode((string)($_POST['daten'] ?? ''), true);
            if (!is_array($daten)) wa_json(['error' => t('Antwort unlesbar.')], 400);
            $err = passkey_verify($wartet, $daten);
            if ($err !== null) wa_json(['error' => $err], 403);
            auth_pending_complete();
            wa_json(['ok' => true, 'redirect' => 'index.php']);
        }
        // Auch die zweite Stufe wird gebremst – sechs Stellen sind sonst in
        // überschaubarer Zeit durchprobiert.
        if (!bucket_rate_ok('totp', 20, $wartet)) {
            $fehler = t('Zu viele Versuche – bitte später erneut.');
        } elseif ($mitApp && totp_check($wartet, (string)($_POST['code'] ?? ''))) {
            auth_pending_complete();
            redirect_to('index.php');
        } else {
            $fehler = t('Der Code stimmt nicht.');
        }
    }
    page_header(t('Bestätigung'), true);
    ?>
    <div class="card narrow">
        <h1><?= t('Noch ein Schritt') ?></h1>
        <?php if ($fehler !== null): ?><div class="flash flash-err"><?= e($fehler) ?></div><?php endif; ?>

        <?php if ($keys !== []): ?>
        <p class="muted"><?= t('Bestätige mit deinem Passkey – Fingerabdruck, Gesicht oder Geräte-PIN.') ?></p>
        <p><button class="btn btn-primary" type="button" style="width:100%"
                   data-passkey="login" data-url="login.php" data-csrf="<?= e(csrf_token()) ?>"
                   data-status="pk-status"><?= t('Mit Passkey bestätigen') ?></button></p>
        <div id="pk-status" class="flash" style="display:none"></div>
        <?php endif; ?>

        <?php if ($mitApp): ?>
            <?php if ($keys !== []): ?>
            <p class="muted small" style="text-align:center"><?= t('oder mit einem Code aus der App:') ?></p>
            <?php else: ?>
            <p class="muted"><?= t('Gib den sechsstelligen Code aus deiner Authenticator-App ein. Ein Wiederherstellungscode geht auch.') ?></p>
            <?php endif; ?>
        <form method="post" action="" data-enter-submit>
            <?= csrf_field() ?>
            <?= username_hint($wartet) ?>
            <label for="code"><?= t('Code') ?></label>
            <input id="code" type="text" name="code" required<?= $keys === [] ? ' autofocus' : '' ?>
                   autocomplete="one-time-code" inputmode="numeric" placeholder="123456">
            <p><button class="btn<?= $keys === [] ? ' btn-primary' : '' ?>" type="submit"><?= t('Bestätigen') ?></button></p>
        </form>
        <?php endif; ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="abbruch" value="1">
            <p class="muted small"><button class="btn btn-small" type="submit"><?= t('Abbrechen') ?></button></p>
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

// ---- Zweistufige Anmeldung ----------------------------------------------
//
// Erst die Kennung, dann der Nachweis. Der Grund ist der Passkey: Er ist kein
// zweiter Faktor, sondern ein Ersatz fürs Passwort — Besitz des Geräts UND
// dessen Entsperrung per Fingerabdruck, Gesicht oder PIN. Ihn hinter ein
// Passwort zu hängen, verschenkt genau das.
//
// Erst wenn die Kennung bekannt ist, weiß die Seite, ob dieses Konto Passkeys
// hat — vorher gibt es nichts anzubieten.
//
// Zur Preisgabe von Kontonamen, ehrlich: Ein unbekanntes Konto sieht genauso
// aus wie eines ohne Passkey — Passwortfeld, Fehler erst nach dem Absenden.
// Wer aber einen Passkey hinterlegt hat, ist an der Abfrage zu erkennen. Das
// lässt sich nicht vermeiden, ohne das Angebot selbst aufzugeben, und es ist
// derselbe Handel, den die großen Anbieter eingehen. Der Weg über das
// Namensfeld (unten, „conditional mediation") verrät dagegen gar nichts: Dort
// sucht das Gerät, nicht der Server.
$kennung = trim((string)($_SESSION['login_name'] ?? ''));
$kennungKeys = $kennung !== '' ? passkeys_of($kennung) : [];

if (!$firstRun && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['schritt'] ?? '') === 'kennung') {
    csrf_check();
    $eingabe = trim((string)($_POST['username'] ?? ''));
    if ($eingabe === '') {
        $error = t('Bitte gib deine Kennung ein.');
    } else {
        // Auf die tatsächliche Schreibweise bringen, wenn es das Konto gibt –
        // sonst unverändert übernehmen, damit die Maske für Unbekannte gleich
        // aussieht.
        $_SESSION['login_name'] = user_resolve($eingabe) ?? mb_substr($eingabe, 0, 190);
        redirect_to('login.php');
    }
}

// Schritt 1, Passkey aus dem Namensfeld: Das Gerät sucht selbst nach einem
// passenden Konto, wir erfahren es erst aus seiner Antwort.
if (!$firstRun && $kennung === '' && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['pk_any_challenge', 'pk_any_verify'], true)) {
    csrf_check();
    if (!login_source_ok()) {
        wa_json(['error' => t('Zu viele Fehlversuche. Bitte später erneut.')], 429);
    }
    if (($_POST['action'] ?? '') === 'pk_any_challenge') {
        wa_json(passkey_any_request_options());
    }
    $daten = json_decode((string)($_POST['daten'] ?? ''), true);
    if (!is_array($daten)) wa_json(['error' => t('Antwort unlesbar.')], 400);
    // Das Handle sagt uns nur, WEN das Gerät meint. Ob es darf, entscheidet
    // gleich darauf die Unterschrift.
    $wer = passkey_user_by_handle((string)($daten['userHandle'] ?? ''));
    if ($wer === null) {
        webauthn_take_challenge('login');
        wa_json(['error' => t('Zu diesem Passkey gibt es hier kein Konto.')], 403);
    }
    if (login_throttle_left($wer) > 0) {
        webauthn_take_challenge('login');
        wa_json(['error' => t('Zu viele Fehlversuche. Bitte später erneut.')], 429);
    }
    $err = passkey_verify($wer, $daten, true);
    if ($err !== null) {
        login_failure_note();
        wa_json(['error' => $err], 403);
    }
    if (!auth_login_passkey($wer)) {
        wa_json(['error' => t('Dieses Konto steht nicht zur Verfügung.')], 403);
    }
    wa_json(['ok' => true, 'redirect' => 'index.php']);
}

if (!$firstRun && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['schritt'] ?? '') === 'andere') {
    csrf_check();
    unset($_SESSION['login_name']);
    redirect_to('login.php');
}

// Passkey als Anmeldung, nicht als zweite Stufe. Beide Wege antworten mit
// JSON; gerufen werden sie vom Skript im Browser.
if ($kennung !== '' && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['pk_challenge', 'pk_verify'], true)) {
    csrf_check();
    if (!login_source_ok() || login_throttle_left($kennung) > 0) {
        wa_json(['error' => t('Zu viele Fehlversuche. Bitte später erneut.')], 429);
    }
    if ($kennungKeys === []) {
        wa_json(['error' => t('Für dieses Konto ist kein Passkey hinterlegt.')], 400);
    }
    if (($_POST['action'] ?? '') === 'pk_challenge') {
        wa_json(passkey_request_options($kennung, true));
    }
    $daten = json_decode((string)($_POST['daten'] ?? ''), true);
    if (!is_array($daten)) wa_json(['error' => t('Antwort unlesbar.')], 400);
    $err = passkey_verify($kennung, $daten, true);
    if ($err !== null) {
        login_failure_note();
        wa_json(['error' => $err], 403);
    }
    if (!auth_login_passkey($kennung)) {
        wa_json(['error' => t('Dieses Konto steht nicht zur Verfügung.')], 403);
    }
    wa_json(['ok' => true, 'redirect' => 'index.php']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['schritt'] ?? '') === '') {
    csrf_check();
    // Im zweiten Schritt steht die Kennung in der Sitzung, nicht im Formular.
    $username = $firstRun
        ? trim((string)($_POST['username'] ?? ''))
        : ($kennung !== '' ? $kennung : trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    // Gesperrt? Dann gar nicht erst prüfen, sondern sofort mit 429 antworten.
    // Das ist die ehrliche Auskunft („komm in einer Minute wieder") und
    // kostet den Server nichts – im Gegensatz zum früheren Warten, das
    // einen ganzen PHP-Prozess für die Dauer belegte.
    $wartezeit = $firstRun ? 0 : login_throttle_left($username);
    if ($wartezeit > 0 || (!$firstRun && !login_source_ok())) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $wartezeit ?: 60));
        $error = $wartezeit > 0
            ? t('Zu viele Fehlversuche. Bitte in %d Sekunden erneut.', $wartezeit)
            : t('Zu viele Fehlversuche von dieser Adresse. Bitte später erneut.');
    } elseif ($firstRun) {
        // Ersteinrichtung: ersten Admin anlegen (nur solange keine Nutzer existieren)
        $error = user_add($username, $password, 'admin');
        if ($error === null) {
            auth_login($username, $password);
            flash(t('Willkommen! Admin-Konto angelegt.'));
            redirect_to('index.php');
        }
    } elseif (auth_login($username, $password, $braucht2fa)) {
        unset($_SESSION['login_name']);
        redirect_to($braucht2fa ? 'login.php' : 'index.php');
    } elseif (ldap_enabled() && ldap_login($username, $password) === null) {
        // Lokales Passwort hat nicht gepasst – jetzt das Verzeichnis fragen
        unset($_SESSION['login_name']);
        redirect_to('index.php');
    } else {
        $error = t('Login fehlgeschlagen.');
    }
}

$sso = sso_cfg();
page_header($firstRun ? t('Ersteinrichtung') : t('Login'), true);
?>
<div class="card narrow">
    <?php if ($firstRun): ?>
        <h1><?= t('Ersteinrichtung') ?></h1>
        <p><?= t('Noch keine Nutzer vorhanden – leg dein Admin-Konto an.') ?></p>
    <?php else: ?>
        <h1><?= t('Login') ?></h1>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="flash flash-err"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$firstRun && sso_enabled() && $sso['login_url'] !== ''): ?>
        <p><a class="btn btn-primary" style="display:block;text-align:center"
              href="<?= e((string)$sso['login_url']) ?>"><?= e(t((string)$sso['button_label'])) ?></a></p>
        <p class="muted small" style="text-align:center"><?= t('oder mit lokalem Konto:') ?></p>
    <?php endif; ?>

    <?php if ($firstRun): ?>
    <form method="post" action="" data-enter-submit>
        <?= csrf_field() ?>
        <label for="username"><?= t('Nutzername') ?></label>
        <input id="username" type="text" name="username" required autofocus autocomplete="username">
        <label for="password"><?= t('Passwort') ?> <?= t('(mind. 8 Zeichen)') ?></label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
        <p><button class="btn btn-primary" type="submit"><?= t('Admin anlegen') ?></button></p>
    </form>

    <?php elseif ($kennung === ''): ?>
    <?php /* Schritt 1: Wer bist du?
             Das Feld trägt „webauthn" im autocomplete. Zusammen mit dem Skript
             bietet der Browser einen auffindbaren Passkey schon hier in der
             Vorschlagsliste an — getippt werden muss dann gar nichts mehr.
             Der Server verrät dabei nicht, wer überhaupt einen hat: Die Suche
             findet ausschließlich auf dem Gerät statt. */ ?>
    <div id="pk-status" class="flash" style="display:none"></div>
    <form method="post" action="" data-enter-submit
          data-passkey-cond="login.php" data-csrf="<?= e(csrf_token()) ?>" data-status="pk-status">
        <?= csrf_field() ?>
        <input type="hidden" name="schritt" value="kennung">
        <label for="username"><?= t('E-Mail oder Nutzername') ?></label>
        <input id="username" type="text" name="username" required autofocus
               autocomplete="username webauthn">
        <p><button class="btn btn-primary" type="submit"><?= t('Weiter') ?></button></p>
    </form>

    <?php else: ?>
    <div class="konto-zeile">
        <strong><?= e($kennung) ?></strong>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="schritt" value="andere">
            <button class="btn btn-small" type="submit"><?= t('Anderes Konto') ?></button>
        </form>
    </div>

    <?php if ($kennungKeys !== []): ?>
    <?php /* Schritt 2 mit Passkey: Das Skript startet die Abfrage von selbst,
             sobald die Seite steht — genau so, wie es die großen Anbieter tun.
             Bricht jemand ab oder klappt es nicht, blendet es das Passwortfeld
             wieder ein.

             Warum das Passwortfeld hier trotzdem im Markup steht und nicht
             erst vom Skript erzeugt wird: Ohne JavaScript gäbe es sonst
             überhaupt keinen Weg mehr hinein. Verborgen wird es deshalb vom
             Skript, nicht vom Server. */ ?>
    <div id="pk-status" class="flash" style="display:none"></div>
    <p><button class="btn btn-primary" type="button" style="width:100%"
               data-passkey="login" data-url="login.php" data-csrf="<?= e(csrf_token()) ?>"
               data-status="pk-status" data-sofort="1" data-verbirgt="pw-form"><?= t('Mit Passkey anmelden') ?></button></p>
    <p class="muted small" style="text-align:center">
        <button class="btn btn-small" type="button" data-zeigt="pw-form"><?= t('Stattdessen Passwort') ?></button>
    </p>
    <?php endif; ?>

    <form method="post" action="" data-enter-submit id="pw-form">
        <?= csrf_field() ?>
        <?= username_hint($kennung) ?>
        <label for="password"><?= t('Passwort') ?></label>
        <input id="password" type="password" name="password" required
               <?= $kennungKeys === [] ? 'autofocus ' : '' ?>autocomplete="current-password">
        <p><button class="btn btn-primary" type="submit"><?= t('Anmelden') ?></button></p>
    </form>

    <?php endif; ?>
    <?php if (!$firstRun): ?>
        <?php if ($kennung === '' || $kennungKeys !== []) page_script('assets/passkey.js'); ?>
        <p class="muted small"><a href="../reset.php"><?= t('Passwort vergessen?') ?></a><?php if (settings()['registration'] === 'on'): ?> · <?= t('Noch kein Konto?') ?> <a href="../register.php"><?= t('Registrieren') ?></a><?php endif; ?></p>
        <?php if (ldap_enabled()): ?>
        <p class="muted small"><?= t('Konten aus dem Verzeichnis melden sich hier mit ihrer gewohnten Kennung an.') ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
