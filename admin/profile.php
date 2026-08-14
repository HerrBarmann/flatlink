<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/account.php';
require_once __DIR__ . '/../inc/token.php';
require_once __DIR__ . '/../inc/totp.php';
require_once __DIR__ . '/../inc/webauthn.php';
require_once __DIR__ . '/../inc/mail.php';

// Ausgenommen vom Zwang zur zweiten Stufe – hier wird sie ja eingerichtet
$user = auth_require(true);
$extern = ($user['auth'] ?? 'local') !== 'local';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'password');

    // ---- Passkeys ----
    // Diese drei Fälle antworten mit JSON statt mit einer Seite: Sie werden
    // vom Skript im Browser aufgerufen, nicht von einem Formular.
    if ($action === 'pk_challenge') {
        wa_json(passkey_create_options($user));
    }

    if ($action === 'pk_register') {
        $daten = json_decode((string)($_POST['daten'] ?? ''), true);
        if (!is_array($daten)) wa_json(['error' => 'Antwort unlesbar.'], 400);
        $err = passkey_register($user['name'], $daten, (string)($_POST['label'] ?? ''));
        if ($err !== null) wa_json(['error' => $err], 422);
        flash('Passkey hinterlegt.');
        wa_json(['ok' => true, 'redirect' => 'profile.php']);
    }

    if ($action === 'pk_remove') {
        // Den letzten Passkey nur entfernen, wenn danach noch etwas übrig ist –
        // sonst stünde das Konto ohne zweite Stufe da, obwohl sie verlangt wird.
        $rest = count(passkeys_of($user['name'])) - 1;
        if (totp_required($user['role']) && $rest < 1 && !totp_active($user['name'])) {
            flash('Diese Instanz verlangt eine zweite Stufe – richte zuerst eine andere ein.', 'err');
        } elseif (passkey_remove($user['name'], (string)($_POST['id'] ?? ''))) {
            flash('Passkey entfernt.');
        } else {
            flash('Dieser Passkey war nicht (mehr) hinterlegt.', 'err');
        }
        redirect_to('profile.php');
    }

    if ($action === 'totp_start') {
        // Ein neues Geheimnis überschreibt ein noch unbestätigtes – wer die
        // Einrichtung abgebrochen hat, fängt sauber von vorn an.
        if (totp_active($user['name'])) {
            flash('Die zweite Stufe ist bereits eingerichtet.', 'err');
        } else {
            totp_begin($user['name']);
        }
        redirect_to('profile.php');
    }

    if ($action === 'totp_confirm') {
        $codes = totp_confirm($user['name'], (string)($_POST['code'] ?? ''));
        if ($codes === null) {
            sleep(1);
            flash('Der Code stimmt nicht – prüfe die Uhrzeit deines Geräts.', 'err');
        } else {
            // Nur einmal zu sehen, wie bei den API-Schlüsseln
            $_SESSION['fresh_recovery'] = $codes;
            flash('Zwei-Faktor-Anmeldung ist aktiv.');
        }
        redirect_to('profile.php');
    }

    if ($action === 'totp_off') {
        // Abschalten verlangt denselben Nachweis wie das Löschen des Kontos:
        // Wer kurz an einem offenen Rechner sitzt, soll den Schutz nicht mit
        // einem Klick entfernen können.
        $nachweis = $extern
            ? trim((string)($_POST['confirm'] ?? '')) === $user['name']
            : password_verify((string)($_POST['current'] ?? ''), users_all()[$user['name']]['pass'] ?? '');
        if (!$nachweis) {
            sleep(1);
            flash('Nachweis fehlt – die zweite Stufe bleibt aktiv.', 'err');
        } elseif (totp_required($user['role']) && !passkeys_active($user['name'])) {
            flash('Diese Instanz verlangt eine zweite Stufe – richte zuerst einen Passkey ein.', 'err');
        } else {
            totp_disable($user['name']);
            flash('Zwei-Faktor-Anmeldung abgeschaltet.');
        }
        redirect_to('profile.php');
    }

    if ($action === 'token_new') {
        if (!user_can($user['name'], 'api_access')) {
            flash('Für die Schnittstelle fehlt deinem Konto die Berechtigung.', 'err');
        } elseif (count(tokens_of($user['name'])) >= 10) {
            flash('Höchstens zehn Zugangsschlüssel pro Konto – zieh zuerst einen zurück.', 'err');
        } else {
            $neu = token_create($user['name'], (string)($_POST['label'] ?? ''));
            // Der Klartext wird nirgends gespeichert; er muss also jetzt gezeigt
            // werden oder gar nicht. Über die Sitzung, damit die Umleitung
            // hinter dem Formular erhalten bleibt.
            $_SESSION['fresh_token'] = $neu['token'];
            flash('Zugangsschlüssel angelegt.');
        }
        redirect_to('profile.php');
    }

    if ($action === 'token_revoke') {
        $ok = token_revoke($user['name'], (string)($_POST['id'] ?? ''));
        flash($ok ? 'Zugangsschlüssel zurückgezogen.' : 'Diesen Schlüssel gibt es nicht.', $ok ? 'ok' : 'err');
        redirect_to('profile.php');
    }

    if ($action === 'export') {
        // Bewusst POST: Ein GET-Download ließe sich über ein eingebettetes Bild
        // auf einer fremden Seite auslösen – ohne Nutzen für den Angreifer,
        // aber es gehört sich nicht.
        $daten = account_export($user['name']);
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'
            . preg_replace('/[^A-Za-z0-9._-]/', '-', $user['name']) . '-daten-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        nosniff_header();
        echo json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete') {
        // Nachweis, dass wirklich der Kontoinhaber vor dem Knopf sitzt. Wer
        // sich über LDAP oder SSO anmeldet, hat hier kein Passwort – dort
        // tritt das Abtippen der eigenen Kennung an dessen Stelle.
        $nachweis = $extern
            ? trim((string)($_POST['confirm'] ?? '')) === $user['name']
            : password_verify((string)($_POST['current'] ?? ''), users_all()[$user['name']]['pass'] ?? '');
        if (!$nachweis) {
            sleep(1);
            flash($extern
                ? 'Zum Löschen bitte die Kennung genau so eintippen, wie sie oben steht.'
                : 'Das Passwort stimmt nicht – es wurde nichts gelöscht.', 'err');
            redirect_to('profile.php');
        }
        $umfang = account_delete_scope($user['name']);
        $err = account_delete($user['name']);
        if ($err !== null) {
            flash($err, 'err');
            redirect_to('profile.php');
        }
        auth_logout();
        // Keine Flash-Nachricht: Die Sitzung ist gerade beendet worden, sie
        // käme nirgends mehr an. Eine gelöschte Existenz verdient ohnehin
        // mehr als eine Zeile, die beim nächsten Klick verschwindet.
        page_header('Konto gelöscht', true);
        echo '<div class="card narrow"><h1>Konto gelöscht</h1>'
            . '<p>Dein Konto ist entfernt, zusammen mit ' . (int)$umfang['eigene']
            . ' Kurzlink' . ($umfang['eigene'] === 1 ? '' : 's') . ' und den zugehörigen Zählern.</p>';
        if ($umfang['gruppe'] > 0) {
            echo '<p class="muted small">' . (int)$umfang['gruppe'] . ' Link'
                . ($umfang['gruppe'] === 1 ? ' war einer Gruppe zugeordnet und bleibt' : 'e waren Gruppen zugeordnet und bleiben')
                . ' bestehen – dort arbeiten andere damit weiter.</p>';
        }
        echo '<p><a class="btn btn-primary" href="' . e(base_url()) . '/">Zur Startseite</a></p></div>';
        page_footer();
        exit;
    }

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
                . mail_link('admin/profile.php') . "?token=" . $token . "\n\n"
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
    } elseif ($action === 'display') {
        $err = user_set_display_name($user['name'], (string)($_POST['display_name'] ?? ''));
        flash($err ?? 'Anzeigename gespeichert.', $err === null ? 'ok' : 'err');
    } elseif ($extern) {
        // Zentral verwaltete Konten haben hier keinen Passwort-Hash. Ein lokal
        // gesetztes Passwort brächte nichts: Die Anmeldung weist solche Konten
        // ab, und die nächste Anmeldung über das Verzeichnis löscht den Hash
        // ohnehin wieder.
        flash('Dein Passwort verwaltet die zentrale Anmeldung – hier lässt es sich nicht ändern.', 'err');
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
    <p class="muted">Angemeldet als <strong><?= e(user_display($user['name'])) ?></strong>
        <?php if (user_has_display($user['name'])): ?><br><span class="small" style="font-family:var(--mono)"><?= e($user['name']) ?></span><?php endif; ?>
        <br>Rolle: <?= e($user['role']) ?></p>
    <?php $codeQuota = (int)settings()['custom_code_quota']; ?>
    <p><span class="muted small">Links: <?= link_count($user['name']) ?>/<?= e(limit_label(user_limit($user['name'], 'links'))) ?> ·
        Wunsch-Codes: <?= custom_code_count($user['name']) ?><?= $codeQuota > 0 ? '/' . $codeQuota : '' ?>
        (mind. <?= (int)settings()['custom_code_min_len'] ?> Zeichen) ·
        Logos: <?= e(limit_label(user_limit($user['name'], 'logos'))) ?> ·
        Statistik: <?= (int)user_limit($user['name'], 'stats_days') === PHP_INT_MAX ? 'unbegrenzt' : (int)user_limit($user['name'], 'stats_days') . ' Tage' ?> ·
        <a href="import.php">CSV-Import</a></span></p>

    <h2>Anzeigename</h2>
    <?php if (($user['auth'] ?? 'local') !== 'local'): ?>
        <p class="muted small">Dein Anzeigename kommt aus der zentralen Anmeldung
        (<strong><?= e(user_display($user['name'])) ?></strong>) und wird bei jeder Anmeldung
        von dort aktualisiert.</p>
    <?php else: ?>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="display">
        <label for="p-display">Wie sollen andere dich sehen? <span class="muted">(leer = deine Kennung)</span></label>
        <div class="short-row">
            <input id="p-display" type="text" name="display_name" maxlength="80"
                   value="<?= e(user_has_display($user['name']) ? user_display($user['name']) : '') ?>"
                   placeholder="Vorname Nachname">
            <button class="btn" type="submit">Speichern</button>
        </div>
    </form>
    <?php endif; ?>

    <h2>E-Mail-Adresse</h2>
    <?php $email = users_all()[$user['name']]['email'] ?? null; ?>
    <?php if ($email !== null): ?>
        <p class="muted small">Hinterlegt: <strong><?= e($email) ?></strong> – wird für Login und Passwort-Reset verwendet.</p>
    <?php elseif (!$extern): ?>
        <p class="muted small"><strong>Keine E-Mail hinterlegt.</strong> Ohne bestätigte Adresse funktioniert
        der Passwort-Reset für dieses Konto nicht.</p>
    <?php else: ?>
        <p class="muted small"><strong>Keine E-Mail hinterlegt.</strong> Ohne Adresse erreichen dich
        keine Hinweise des Dienstes – etwa die Vorwarnung, bevor ein lange ungenutzter Link
        aufgeräumt wird.</p>
    <?php endif; ?>
    <?php if ($extern): ?>
        <p class="muted small">Liefert die zentrale Anmeldung eine Adresse mit, überschreibt sie eine
        hier eingetragene bei der nächsten Anmeldung. Tut sie das nicht, bleibt deine Eintragung stehen.</p>
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

    <h2>Passwort</h2>
    <?php if ($extern): ?>
        <p class="muted small">Dein Passwort verwaltet die zentrale Anmeldung – hier gibt es keins,
        das sich ändern ließe. Wende dich dafür an die Stelle, über die du dich anmeldest.</p>
    <?php else: ?>
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
    <?php endif; ?>

    <h2>Zwei-Faktor-Anmeldung</h2>
    <?php
    $t = totp_get($user['name']);
    $aktiv = totp_active($user['name']);
    $keys = passkeys_of($user['name']);
    $pflicht = totp_required($user['role']);
    $frischeCodes = $_SESSION['fresh_recovery'] ?? null;
    unset($_SESSION['fresh_recovery']);
    ?>
    <p class="muted small">Ein zweiter Nachweis beim Anmelden. Wer dein Passwort kennt, kommt
    damit trotzdem nicht an deine Links – und an die Ziele der Codes, die längst irgendwo
    gedruckt hängen.<?php if ($pflicht): ?> <strong>Diese Instanz verlangt ihn.</strong><?php endif; ?></p>

    <h3>Passkey <span class="muted small">(empfohlen)</span></h3>
    <?php if (!webauthn_possible()): ?>
        <p class="muted small">Passkeys brauchen eine gesicherte Verbindung (HTTPS). Auf dieser
        Instanz ist das gerade nicht der Fall – nimm so lange die App unten.</p>
    <?php else: ?>
        <p class="muted small">Fingerabdruck, Gesicht oder Geräte-PIN – hinterlegt in deinem
        Telefon, deinem Rechner oder auf einem Sicherheitsschlüssel. Anders als ein Code aus einer
        App ist ein Passkey <strong>an diese Adresse gebunden</strong>: Auf einer nachgebauten
        Anmeldeseite gibt ihn dein Gerät gar nicht erst heraus. Genau davor schützt ein
        abtippbarer Code nicht.</p>

        <?php if ($keys !== []): ?>
        <ul class="key-list">
            <?php foreach ($keys as $k): ?>
            <li>
                <div>
                    <strong><?= e((string)$k['label']) ?></strong><br>
                    <span class="muted small">eingerichtet <?= e(date('d.m.Y', strtotime((string)$k['created']))) ?>
                    <?php if (!empty($k['last_used'])): ?> · zuletzt benutzt <?= e(date('d.m.Y', strtotime((string)$k['last_used']))) ?>
                    <?php else: ?> · noch nicht benutzt<?php endif; ?></span>
                </div>
                <form method="post" action="" data-confirm="Diesen Passkey wirklich entfernen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pk_remove">
                    <input type="hidden" name="id" value="<?= e((string)$k['id']) ?>">
                    <button class="btn btn-small btn-danger" type="submit">Entfernen</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (count($keys) < 10): ?>
        <label for="pk-label">Name für das Gerät <span class="muted">(optional)</span></label>
        <div class="short-row">
            <input id="pk-label" type="text" maxlength="60" placeholder="<?= $keys === [] ? 'Mein Telefon' : 'Zweites Gerät' ?>">
            <button class="btn<?= ($pflicht && !$aktiv && $keys === []) ? ' btn-primary' : '' ?>" type="button"
                    data-passkey="register" data-url="profile.php" data-csrf="<?= e(csrf_token()) ?>"
                    data-label="pk-label" data-status="pk-status"><?= $keys === [] ? 'Passkey einrichten' : 'Weiteren einrichten' ?></button>
        </div>
        <div id="pk-status" class="flash" style="display:none"></div>
        <?php if ($keys !== []): ?>
        <p class="muted small">Ein zweites Gerät ist keine Umständlichkeit, sondern der
        Ersatzschlüssel: Passkeys lassen sich nicht abschreiben und aufheben.</p>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <h3>Einmalkennwort aus einer App</h3>
    <?php if ($frischeCodes !== null): ?>
    <div class="flash flash-ok">
        <strong>Wiederherstellungscodes</strong> – jeder gilt einmal, falls dein Gerät weg ist.
        Sie werden nicht noch einmal angezeigt.
        <div class="term" style="margin-top:0.6rem"><?= e(implode("\n", $frischeCodes)) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($aktiv): ?>
        <p class="muted small">Aktiv. Beim Anmelden fragt <?= e(cfg('site_name')) ?> nach einem
        Code aus deiner App. Noch <?= totp_recovery_left($user['name']) ?> Wiederherstellungscodes übrig.</p>
        <?php if ($pflicht && $keys === []): ?>
            <p class="muted small">Diese Instanz verlangt eine zweite Stufe – abschalten geht erst,
            wenn ein Passkey eingerichtet ist.</p>
        <?php else: ?>
        <details>
            <summary class="muted small">Abschalten</summary>
            <form method="post" action="" data-confirm="Einmalkennwort wirklich abschalten?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="totp_off">
                <?php if ($extern): ?>
                    <label for="t-confirm">Zur Sicherheit deine Kennung eintippen:
                        <span style="font-family:var(--mono)"><?= e($user['name']) ?></span></label>
                    <input id="t-confirm" type="text" name="confirm" required autocomplete="off">
                <?php else: ?>
                    <label for="t-pass">Zur Sicherheit dein Passwort:</label>
                    <input id="t-pass" type="password" name="current" required autocomplete="current-password">
                <?php endif; ?>
                <p><button class="btn btn-danger" type="submit">Abschalten</button></p>
            </form>
        </details>
        <?php endif; ?>

    <?php elseif ($t !== null): ?>
        <p class="muted small">Scanne den Code mit einer Authenticator-App und gib dann die
        sechs Ziffern ein, die sie anzeigt.</p>
        <div style="max-width:220px;margin:0 0 0.8rem"><?= totp_qr_svg($user['name'], (string)$t['secret']) ?></div>
        <p class="muted small">Geht das Scannen nicht, trag den Schlüssel von Hand ein:<br>
        <code style="word-break:break-all"><?= e(chunk_split((string)$t['secret'], 4, ' ')) ?></code></p>
        <form method="post" action="" data-enter-submit>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="totp_confirm">
            <label for="t-code">Code aus der App</label>
            <div class="short-row">
                <input id="t-code" type="text" name="code" required inputmode="numeric"
                       autocomplete="one-time-code" placeholder="123456">
                <button class="btn btn-primary" type="submit">Aktivieren</button>
            </div>
        </form>

    <?php else: ?>
        <p class="muted small">Sechs Ziffern, die alle 30 Sekunden wechseln. Funktioniert auf
        jedem Gerät und in jedem Browser – aber es lässt sich abtippen, und damit auch auf einer
        nachgebauten Seite eingeben. Der richtige Weg, wenn Passkeys nicht in Frage kommen.</p>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="totp_start">
            <p><button class="btn<?= ($pflicht && $keys === []) ? ' btn-primary' : '' ?>" type="submit">Einrichten</button></p>
        </form>
    <?php endif; ?>

    <h2>Programmierschnittstelle</h2>
    <?php if (!user_can($user['name'], 'api_access')): ?>
        <p class="muted small">Für den Zugriff über die Schnittstelle fehlt deinem Konto die
        Berechtigung. Sie hängt an einer Gruppe – ein Administrator kann sie freischalten.</p>
    <?php else: ?>
        <?php $frisch = $_SESSION['fresh_token'] ?? null; unset($_SESSION['fresh_token']); ?>
        <?php if ($frisch !== null): ?>
        <div class="flash flash-ok" style="word-break:break-all">
            <strong>Dein neuer Schlüssel:</strong><br>
            <code><?= e($frisch) ?></code><br>
            <span class="small">Notier ihn jetzt – er wird nicht gespeichert und lässt sich
            später nicht noch einmal anzeigen.</span>
        </div>
        <?php endif; ?>
        <?php $doku = trim((string)cfg('api_doc_url')); ?>
        <p class="muted small">Ein Schlüssel meldet ein Programm unter deinem Konto an. Er kann
        nie mehr, als du selbst darfst.<?php if ($doku !== ''): ?>
        <a href="<?= e(str_contains($doku, '://') ? $doku : base_url() . '/' . ltrim($doku, '/')) ?>">Zur Anleitung</a>.<?php endif; ?></p>
        <?php $meine = tokens_of($user['name']); ?>
        <?php if ($meine !== []): ?>
        <div class="table-scroll"><table>
            <tr><th>Bezeichnung</th><th>Anfang</th><th>Angelegt</th><th>Zuletzt benutzt</th><th></th></tr>
            <?php foreach ($meine as $t): ?>
            <tr>
                <td><?= e((string)($t['label'] ?? '')) ?: '<span class="muted">ohne</span>' ?></td>
                <td><code><?= e((string)($t['hint'] ?? '')) ?>…</code></td>
                <td class="small"><?= e(date('d.m.Y', strtotime((string)$t['created']))) ?></td>
                <td class="small"><?= ($t['last_used'] ?? null) !== null
                    ? e(date('d.m.Y', strtotime((string)$t['last_used'])))
                    : '<span class="muted">nie</span>' ?></td>
                <td><form method="post" action="" class="inline" data-confirm="Schlüssel zurückziehen? Programme, die ihn nutzen, verlieren sofort den Zugriff.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="token_revoke">
                    <input type="hidden" name="id" value="<?= e((string)$t['id']) ?>">
                    <button class="btn btn-small btn-danger" type="submit">Zurückziehen</button>
                </form></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="token_new">
            <label for="p-label">Neuer Schlüssel <span class="muted">(Bezeichnung, damit du ihn später zuordnen kannst)</span></label>
            <div class="short-row">
                <input id="p-label" type="text" name="label" maxlength="60" placeholder="z. B. Kassensystem">
                <button class="btn" type="submit">Anlegen</button>
            </div>
        </form>
    <?php endif; ?>

    <h2>Deine Daten</h2>
    <p class="muted small">Alles, was über dieses Konto gespeichert ist, als JSON-Datei:
    Kontodaten, Gruppen, Rechte sowie jeder Kurzlink mit Ziel, Datum und Klickzahlen.
    Ohne Passwort-Hash – der ist ein Zugangsmittel, kein Inhalt.</p>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="export">
        <p><button class="btn" type="submit">Daten herunterladen</button></p>
    </form>

    <?php if (cfg('self_delete')): $umfang = account_delete_scope($user['name']); ?>
    <h2>Konto löschen</h2>
    <p class="muted small">Das Konto verschwindet mitsamt
    <strong><?= (int)$umfang['eigene'] ?> Kurzlink<?= $umfang['eigene'] === 1 ? '' : 's' ?></strong>
    und den zugehörigen Klickzählern. Gedruckte QR-Codes darauf zeigen danach ins Leere.
    <?php if ($umfang['gruppe'] > 0): ?>
        <?= (int)$umfang['gruppe'] ?> weitere<?= $umfang['gruppe'] === 1 ? 'r Link ist' : ' Links sind' ?>
        einer Gruppe zugeordnet und bleib<?= $umfang['gruppe'] === 1 ? 't' : 'en' ?> bestehen.
    <?php endif; ?>
    Rückgängig machen lässt sich das nicht.</p>
    <?php if ($extern): ?>
        <p class="muted small">Dein Konto kommt aus der zentralen Anmeldung. Meldest du dich
        danach erneut an, kann es je nach Einstellung neu angelegt werden – die Links sind
        trotzdem weg.</p>
    <?php endif; ?>
    <form method="post" action="" data-confirm="Konto und Links endgültig löschen?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <?php if ($extern): ?>
            <label for="p-confirm">Zur Sicherheit deine Kennung eintippen:
                <span style="font-family:var(--mono)"><?= e($user['name']) ?></span></label>
            <input id="p-confirm" type="text" name="confirm" required autocomplete="off">
        <?php else: ?>
            <label for="p-del-pass">Zur Sicherheit dein Passwort:</label>
            <input id="p-del-pass" type="password" name="current" required autocomplete="current-password">
        <?php endif; ?>
        <p><button class="btn btn-danger" type="submit">Konto endgültig löschen</button></p>
    </form>
    <?php endif; ?>
</div>
<?php page_script('assets/passkey.js');
page_footer(); ?>
