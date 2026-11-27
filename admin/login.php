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
            wa_json(['ok' => true, 'redirect' => login_ziel($wartet)]);
        }
        // Auch die zweite Stufe wird gebremst – sechs Stellen sind sonst in
        // überschaubarer Zeit durchprobiert.
        if (!bucket_rate_ok('totp', 20, $wartet)) {
            $fehler = t('Zu viele Versuche – bitte später erneut.');
        } elseif ($mitApp && totp_check($wartet, (string)($_POST['code'] ?? ''))) {
            auth_pending_complete();
            redirect_to(login_ziel($wartet));
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
        <div id="pk-status" class="flash" hidden></div>
        <p><button class="btn btn-primary btn-block" type="button"
                   data-passkey="login" data-url="login.php" data-csrf="<?= e(csrf_token()) ?>"
                   data-status="pk-status"><?= t('Mit Passkey bestätigen') ?></button></p>
        <?php endif; ?>

        <?php if ($mitApp): ?>
            <?php if ($keys !== []): ?>
            <p class="oder"><?= t('oder') ?></p>
            <?php else: ?>
            <p class="muted"><?= t('Gib den sechsstelligen Code aus deiner Authenticator-App ein. Ein Wiederherstellungscode geht auch.') ?></p>
            <?php endif; ?>
        <form method="post" action="" data-enter-submit>
            <?= csrf_field() ?>
            <?= username_hint($wartet) ?>
            <label for="code"><?= t('Code') ?></label>
            <input id="code" type="text" name="code" required<?= $keys === [] ? ' autofocus' : '' ?>
                   autocomplete="one-time-code" inputmode="numeric" placeholder="123456">
            <p><button class="btn<?= $keys === [] ? ' btn-primary' : '' ?> btn-block" type="submit"><?= t('Bestätigen') ?></button></p>
        </form>
        <?php endif; ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="abbruch" value="1">
            <p class="muted small form-foot"><button class="btn-link small" type="submit"><?= t('Abbrechen') ?></button></p>
        </form>
    </div>
    <?php
    if ($keys !== []) page_script('assets/passkey.js');
    page_footer();
    exit;
}


$firstRun = !users_exist();
$error = null;        // Ersteinrichtung und SSO haben eigene Meldungen …
$fehlschlag = false;  // … jede andere Anmeldung genau eine: login_fehlertext()
$selbst = base_url() . '/admin/login.php';

// Beim Verlassen nichts mitgeben, in keinem Zwischenspeicher liegen bleiben.
$GLOBALS['_referrer_policy'] = 'same-origin';
header('Cache-Control: no-store');

// Rückweg (next) und Hinweis (abgelaufen) kommen über die Adresse, die
// auth_require() gebaut hat – siehe login_rueckweg().
if (isset($_GET['next']) || isset($_POST['next'])) {
    $next = login_next_pruefen((string)($_POST['next'] ?? $_GET['next']));
    if ($next !== null) $_SESSION['login_next'] = $next;
    else unset($_SESSION['login_next']);
}
$abgelaufen = ($_GET['abgelaufen'] ?? '') === '1';

// „Anderer Benutzername" als Link, wie in der Vorlage des HFMT-Design-Systems.
// Er setzt nur die eigene, noch nicht angemeldete Eingabe zurück.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['andere'] ?? '') === '1') {
    unset($_SESSION['login_roh']);
    redirect_to($selbst);
}

// Hat der Webserver schon jemanden angemeldet (Shibboleth, SAML, OIDC)?
// Der Versuch läuft bei jedem Aufruf, damit der Weg über einen Link direkt
// zu /admin/ ebenso funktioniert wie der Knopf unten.
if (!$firstRun && sso_enabled()) {
    $ssoErr = sso_attempt();
    if ($ssoErr !== null) {
        $error = $ssoErr;
    } elseif (($ssoKonto = auth_user()) !== null) {
        redirect_to(login_ziel((string)$ssoKonto['name']));
    }
}

// ---- Zweistufige Anmeldung ----------------------------------------------
//
// Erst die Kennung, dann der Nachweis. Der Grund ist der Passkey: Er ist kein
// zweiter Faktor, sondern ein Ersatz fürs Passwort — Besitz des Geräts UND
// dessen Entsperrung per Fingerabdruck, Gesicht oder PIN. Ihn hinter ein
// Passwort zu hängen, verschenkt genau das.
//
// Schritt 1 fragt nichts ab: weder Konten noch Verzeichnis noch Passkeys. Er
// merkt sich die Eingabe und zeigt Schritt 2.
//
// Schritt 2 sieht für jede Eingabe gleich aus – ob es das Konto gibt oder
// nicht, ob es einen Passkey hat oder nicht. „Mit Passkey" fragt den Browser
// ohne Liste von Schlüsseln; er bietet an, was er für diese Adresse kennt, und
// sagt hinterher über das Handle, wen er meint. Bis 5.5 bot die Seite den
// Passkey nur Konten an, die einen hatten, und startete ihn dort von selbst –
// wer einen hinterlegt hatte, war daran zu erkennen. Von selbst startet die
// Abfrage jetzt nur, wenn sich in diesem Browser zuletzt jemand mit Passkey
// angemeldet hat: ein Merker im Browser (assets/passkey.js), nichts auf dem
// Server. Bequem bleibt es so für genau die, die es nutzen.
//
// Angezeigt wird immer die ROHEINGABE. Aufgelöst – Schreibweise normalisiert,
// E-Mail-Adresse auf den Kontonamen – wird erst beim Prüfen des Passworts;
// zurückgespiegelt wäre beides ein Orakel („dieses Konto gibt es", „diese
// Adresse gehört zu Kennung X", Review 4.2.0, F2).
$kennung = trim((string)($_SESSION['login_roh'] ?? ''));

// Zwei Arten Formular sprechen mit dieser Seite: die des Kerns (schritt,
// username, action) und die einer Anmeldevorlage nach dem HFMT-Design-System
// (uid, method, assertion; siehe 'login_template').
$vorgang = '';
$aktion = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['action'] ?? '');
    $methode = (string)($_POST['method'] ?? '');
    $schritt = (string)($_POST['schritt'] ?? '');
    $vorgang = match (true) {
        in_array($aktion, ['pk_any_challenge', 'pk_any_verify'], true) => 'passkey-json',
        $methode === 'passkey' => 'passkey',
        $methode === 'password' => 'passwort',
        $schritt === 'kennung', $methode === '' && isset($_POST['uid']) => 'kennung',
        $schritt === 'andere' => 'andere',
        default => 'passwort',   // Ersteinrichtung und Schritt 2 des Kerns
    };
    csrf_check();
}

if (!$firstRun && $vorgang === 'kennung') {
    $eingabe = trim((string)($_POST['uid'] ?? $_POST['username'] ?? ''));
    if ($eingabe !== '') $_SESSION['login_roh'] = mb_substr($eingabe, 0, 190);
    redirect_to($selbst, 303);
}

if (!$firstRun && $vorgang === 'andere') {
    unset($_SESSION['login_roh']);
    redirect_to($selbst, 303);
}

// Passkey, vom Skript des Kerns gerufen (JSON) – in beiden Schritten derselbe
// Weg: im Namensfeld (Schritt 1) und über den Knopf (Schritt 2).
if (!$firstRun && $vorgang === 'passkey-json') {
    if ($aktion === 'pk_any_challenge') wa_json(passkey_any_request_options());
    $daten = json_decode((string)($_POST['daten'] ?? ''), true);
    $wer = login_mit_passkey(is_array($daten) ? $daten : null);
    if ($wer === null) wa_json(['error' => login_passkey_fehlertext()], 403);
    wa_json(['ok' => true, 'redirect' => login_ziel($wer)]);
}

// Passkey als gewöhnliches Formular (Anmeldevorlage): Die Antwort des Geräts
// steht im Feld assertion, die Aufgabe dazu stand schon in der Seite.
if (!$firstRun && $vorgang === 'passkey') {
    $daten = json_decode((string)($_POST['assertion'] ?? ''), true);
    $wer = login_mit_passkey(is_array($daten) ? $daten : null);
    if ($wer !== null) redirect_to(login_ziel($wer), 303);
    $fehlschlag = true;
}

if ($vorgang === 'passwort') {
    $password = (string)($_POST['password'] ?? '');
    if ($firstRun) {
        // Ersteinrichtung: ersten Admin anlegen (nur solange keine Nutzer existieren)
        $username = trim((string)($_POST['username'] ?? ''));
        $error = user_add($username, $password, 'admin');
        if ($error === null) {
            auth_login($username, $password);
            flash(t('Willkommen! Admin-Konto angelegt.'));
            redirect_to(login_ziel($username));
        }
    } else {
        // Die Kennung steht seit Schritt 1 in der Sitzung; das Formular einer
        // Vorlage trägt sie zusätzlich verdeckt mit (für Passwortmanager).
        $roh = $kennung !== '' ? $kennung : trim((string)($_POST['uid'] ?? $_POST['username'] ?? ''));
        $username = $roh === '' ? '' : (user_resolve($roh) ?? mb_substr($roh, 0, 190));
        // Gebremst wird wie bisher je Adresse und Kennung – nur sagt die Seite
        // es nicht mehr eigens. Eine eigene Meldung, ein eigener Statuscode
        // (früher 429) verrieten, dass es diese Kennung gibt und jemand an ihr
        // probiert. Jetzt: dieselbe Seite wie bei jedem Fehlschlag.
        if ($username === '' || login_throttle_left($username) > 0 || !login_source_ok()) {
            $fehlschlag = true;
        } elseif (auth_login($username, $password, $braucht2fa)) {
            unset($_SESSION['login_roh']);
            redirect_to($braucht2fa ? $selbst : login_ziel($username), 303);
        } elseif (ldap_login_enabled() && ldap_login($username, $password) === null) {
            // Lokales Passwort hat nicht gepasst – jetzt das Verzeichnis fragen
            unset($_SESSION['login_roh']);
            redirect_to(login_ziel($username), 303);
        } else {
            $fehlschlag = true;
        }
    }
}

// ---- Eigene Anmeldevorlage ------------------------------------------------
//
// Ist 'login_template' gesetzt, zeichnet diese Datei die Schritte 1 und 2 als
// ganze Seite – etwa die feste Anmeldeseite einer Hochschule. Sie bekommt nur
// Zustand, keine Entscheidung: welcher Schritt, ob die eine Fehlermeldung zu
// zeigen ist, die Aufgabe für den Passkey. Ersteinrichtung und zweite Stufe
// bleiben Seiten von flatlink.
$vorlage = login_vorlage();
if (!$firstRun && $vorlage !== null) {
    security_headers();
    $anmeldung = [
        'schritt'    => $kennung === '' ? 1 : 2,
        'adresse'    => $selbst,                  // Ziel beider Formulare
        'andere'     => $selbst . '?andere=1',    // „Anderer Benutzername"
        'kennung'    => $kennung,                 // Roheingabe aus Schritt 1
        'fehler'     => $fehlschlag || $error !== null,
        'abgelaufen' => $abgelaufen && !$fehlschlag && $error === null,
        'csrf_feld'  => '_csrf',
        'csrf'       => csrf_token(),
        // Ohne allowCredentials, mit Nutzerprüfung; als JSON für data-options
        'passkey'    => json_encode(passkey_any_request_options(), JSON_UNESCAPED_SLASHES),
        'next'       => (string)($_SESSION['login_next'] ?? ''),
        'basis'      => base_url(),
    ];
    (static function (string $datei, array $anmeldung): void { require $datei; })($vorlage, $anmeldung);
    exit;
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
        <div class="flash flash-err" role="alert"><?= e($error) ?></div>
    <?php elseif ($fehlschlag): ?>
        <div class="flash flash-err" role="alert"><?= e(login_fehlertext()) ?></div>
    <?php elseif ($abgelaufen && !$firstRun): ?>
        <div class="flash"><?= t('Du warst länger nicht aktiv. Melde dich bitte wieder an.') ?></div>
    <?php endif; ?>

    <?php if (!$firstRun && sso_enabled() && $sso['login_url'] !== ''): ?>
        <p><a class="btn btn-primary btn-block"
              href="<?= e((string)$sso['login_url']) ?>"><?= e(t((string)$sso['button_label'])) ?></a></p>
        <p class="muted small text-center"><?= t('oder mit lokalem Konto:') ?></p>
    <?php endif; ?>

    <?php if ($firstRun): ?>
    <form method="post" action="" data-enter-submit>
        <?= csrf_field() ?>
        <label for="username"><?= t('Nutzername') ?></label>
        <input id="username" type="text" name="username" required autofocus autocomplete="username">
        <label for="password"><?= t('Passwort') ?> <?= t('(mind. 8 Zeichen)') ?></label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
        <p><button class="btn btn-primary btn-block" type="submit"><?= t('Admin anlegen') ?></button></p>
    </form>

    <?php elseif ($kennung === ''): ?>
    <?php /* Schritt 1: Wer bist du?
             Das Feld trägt „webauthn" im autocomplete. Zusammen mit dem Skript
             bietet der Browser einen auffindbaren Passkey schon hier in der
             Vorschlagsliste an — getippt werden muss dann gar nichts mehr.
             Der Server verrät dabei nicht, wer überhaupt einen hat: Die Suche
             findet ausschließlich auf dem Gerät statt. */ ?>
    <div id="pk-status" class="flash" hidden></div>
    <form method="post" action="" data-enter-submit
          data-passkey-cond="login.php" data-csrf="<?= e(csrf_token()) ?>" data-status="pk-status">
        <?= csrf_field() ?>
        <input type="hidden" name="schritt" value="kennung">
        <label for="username"><?= t('E-Mail oder Nutzername') ?></label>
        <input id="username" type="text" name="username" required autofocus
               autocomplete="username webauthn">
        <p><button class="btn btn-primary btn-block" type="submit"><?= t('Weiter') ?></button></p>
    </form>

    <?php else: ?>
    <div class="konto-zeile">
        <strong><?= e($kennung) ?></strong>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="schritt" value="andere">
            <button class="btn-link small" type="submit"><?= t('Nicht du?') ?></button>
        </form>
    </div>

    <?php /* Schritt 2: für jede Kennung dieselbe Seite. Der Passkey-Knopf
             fragt ohne Schlüsselliste; data-sofort="merker" startet ihn nur
             von selbst, wenn in diesem Browser zuletzt ein Passkey zum Ziel
             führte. Bewusst kein Umschalter: Beide Wege sind sichtbar, und
             damit funktioniert die Maske auch ohne JavaScript unverändert. */ ?>
    <div id="pk-status" class="flash" hidden></div>
    <p><button class="btn btn-block" type="button"
               data-passkey="any" data-url="login.php" data-csrf="<?= e(csrf_token()) ?>"
               data-status="pk-status" data-sofort="merker"><?= t('Mit Passkey anmelden') ?></button></p>
    <p class="oder"><?= t('oder') ?></p>

    <form method="post" action="" data-enter-submit data-passkey-forget>
        <?= csrf_field() ?>
        <?= username_hint($kennung) ?>
        <label for="password"><?= t('Passwort') ?></label>
        <input id="password" type="password" name="password" required autofocus
               autocomplete="current-password">
        <p><button class="btn btn-primary btn-block" type="submit"><?= t('Anmelden') ?></button></p>
    </form>

    <?php endif; ?>
    <?php if (!$firstRun): ?>
        <?php page_script('assets/passkey.js'); ?>
        <p class="muted small form-foot"><a href="../reset.php"><?= t('Passwort vergessen?') ?></a><?php if (settings()['registration'] === 'on'): ?> · <?= t('Noch kein Konto?') ?> <a href="../register.php"><?= t('Registrieren') ?></a><?php endif; ?></p>
        <?php if (ldap_login_enabled()): ?>
        <p class="muted small form-foot"><?= t('Konten aus dem Verzeichnis melden sich hier mit ihrer gewohnten Kennung an.') ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
