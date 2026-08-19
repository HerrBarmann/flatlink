<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/extension.php';
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

    // Zurück zu dem Abschnitt, aus dem das Formular kam. Ohne das landet man
    // nach jedem Klick wieder ganz oben und scrollt sich durch die halbe
    // Seite zurück – bei den Zugangsschlüsseln und dem Verbindungscode, wo
    // man mehrere Schritte hintereinander macht, ist das die reinste Plage.
    // Beide zeigen ihr Ergebnis obendrein nur ein einziges Mal, und zwar in
    // ihrem Abschnitt: Dort muss der Blick nach dem Absenden hin.
    $anker = match (true) {
        str_starts_with($action, 'token_')                    => '#api',
        str_starts_with($action, 'totp_'),
        str_starts_with($action, 'pk_')                       => '#zwei-faktor',
        $action === 'connect_code'                            => '#erweiterung',
        $action === 'session_revoke'                          => '#sitzungen',
        $action === 'display'                                 => '#anzeigename',
        $action === 'email'                                   => '#email',
        $action === 'password'                                => '#passwort',
        $action === 'export'                                  => '#daten',
        $action === 'delete'                                  => '#loeschen',
        default                                               => '',
    };

    // ---- Passkeys ----
    // Diese drei Fälle antworten mit JSON statt mit einer Seite: Sie werden
    // vom Skript im Browser aufgerufen, nicht von einem Formular.
    if ($action === 'pk_challenge') {
        wa_json(passkey_create_options($user));
    }

    if ($action === 'pk_register') {
        $daten = json_decode((string)($_POST['daten'] ?? ''), true);
        if (!is_array($daten)) wa_json(['error' => t('Antwort unlesbar.')], 400);
        $err = passkey_register($user['name'], $daten, (string)($_POST['label'] ?? ''));
        if ($err !== null) wa_json(['error' => $err], 422);
        flash(t('Passkey hinterlegt.'));
        wa_json(['ok' => true, 'redirect' => 'profile.php' . $anker]);
    }

    if ($action === 'pk_remove') {
        // Den letzten Passkey nur entfernen, wenn danach noch etwas übrig ist –
        // sonst stünde das Konto ohne zweite Stufe da, obwohl sie verlangt wird.
        $rest = count(passkeys_of($user['name'])) - 1;
        if (totp_required($user['role']) && $rest < 1 && !totp_active($user['name'])) {
            flash(t('Diese Instanz verlangt eine zweite Stufe – richte zuerst eine andere ein.'), 'err');
        } elseif (passkey_remove($user['name'], (string)($_POST['id'] ?? ''))) {
            flash(t('Passkey entfernt.'));
        } else {
            flash(t('Dieser Passkey war nicht (mehr) hinterlegt.'), 'err');
        }
        redirect_to('profile.php' . $anker);
    }

    if ($action === 'totp_start') {
        // Ein neues Geheimnis überschreibt ein noch unbestätigtes – wer die
        // Einrichtung abgebrochen hat, fängt sauber von vorn an.
        if (totp_active($user['name'])) {
            flash(t('Die zweite Stufe ist bereits eingerichtet.'), 'err');
        } else {
            totp_begin($user['name']);
        }
        redirect_to('profile.php' . $anker);
    }

    if ($action === 'totp_confirm') {
        // Sechs Stellen sind ohne Bremse in überschaubarer Zeit durchprobiert.
        // Gezählt statt gewartet – Warten belegt einen PHP-Prozess und wäre
        // auf kleinen Instanzen der wirksamere Angriff (siehe inc/auth.php).
        if (!bucket_rate_ok('nachweis', 20, $user['name'])) {
            http_response_code(429);
            flash(t('Zu viele Versuche – bitte später erneut.'), 'err');
            redirect_to('profile.php' . $anker);
        }
        $codes = totp_confirm($user['name'], (string)($_POST['code'] ?? ''));
        if ($codes === null) {
            flash(t('Der Code stimmt nicht – prüfe die Uhrzeit deines Geräts.'), 'err');
        } else {
            // Nur einmal zu sehen, wie bei den API-Schlüsseln
            $_SESSION['fresh_recovery'] = $codes;
            flash(t('Zwei-Faktor-Anmeldung ist aktiv.'));
        }
        redirect_to('profile.php' . $anker);
    }

    if ($action === 'totp_off') {
        // Abschalten verlangt denselben Nachweis wie das Löschen des Kontos:
        // Wer kurz an einem offenen Rechner sitzt, soll den Schutz nicht mit
        // einem Klick entfernen können.
        if (!bucket_rate_ok('nachweis', 20, $user['name'])) {
            http_response_code(429);
            flash(t('Zu viele Versuche – bitte später erneut.'), 'err');
            redirect_to('profile.php' . $anker);
        }
        $nachweis = $extern
            ? trim((string)($_POST['confirm'] ?? '')) === $user['name']
            : password_verify((string)($_POST['current'] ?? ''), user_get($user['name'])['pass'] ?? '');
        if (!$nachweis) {
            flash(t('Nachweis fehlt – die zweite Stufe bleibt aktiv.'), 'err');
        } elseif (totp_required($user['role']) && !passkeys_active($user['name'])) {
            flash(t('Diese Instanz verlangt eine zweite Stufe – richte zuerst einen Passkey ein.'), 'err');
        } else {
            totp_disable($user['name']);
            flash(t('Zwei-Faktor-Anmeldung abgeschaltet.'));
        }
        redirect_to('profile.php' . $anker);
    }

    if ($action === 'token_new') {
        if (!user_can($user['name'], 'api_access')) {
            flash(t('Für die Schnittstelle fehlt deinem Konto die Berechtigung.'), 'err');
        } elseif (count(tokens_of($user['name'])) >= 10) {
            flash(t('Höchstens zehn Zugangsschlüssel pro Konto – zieh zuerst einen zurück.'), 'err');
        } else {
            $neu = token_create($user['name'], (string)($_POST['label'] ?? ''));
            // Der Klartext wird nirgends gespeichert; er muss also jetzt gezeigt
            // werden oder gar nicht. Über die Sitzung, damit die Umleitung
            // hinter dem Formular erhalten bleibt.
            $_SESSION['fresh_token'] = $neu['token'];
            flash(t('Zugangsschlüssel angelegt.'));
        }
        redirect_to('profile.php' . $anker);
    }

    if ($action === 'token_revoke') {
        $ok = token_revoke($user['name'], (string)($_POST['id'] ?? ''));
        flash($ok ? t('Zugangsschlüssel zurückgezogen.') : t('Diesen Schlüssel gibt es nicht.'), $ok ? 'ok' : 'err');
        redirect_to('profile.php' . $anker);
    }

    if ($action === 'connect_code') {
        if (!user_can($user['name'], 'api_access')) {
            flash(t('Für den Zugriff über die Schnittstelle fehlt deinem Konto die Berechtigung.'), 'err');
            redirect_to('profile.php' . $anker);
        }
        // Wie beim frisch angelegten Schlüssel: einmal anzeigen, dann weg
        $_SESSION['connect_code'] = ext_connect_code($user['name']);
        audit(t('Verbindungscode für die Erweiterung erzeugt'));
        redirect_to('profile.php' . $anker);
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
        if (!bucket_rate_ok('nachweis', 20, $user['name'])) {
            http_response_code(429);
            flash(t('Zu viele Versuche – bitte später erneut.'), 'err');
            redirect_to('profile.php' . $anker);
        }
        $nachweis = $extern
            ? trim((string)($_POST['confirm'] ?? '')) === $user['name']
            : password_verify((string)($_POST['current'] ?? ''), user_get($user['name'])['pass'] ?? '');
        if (!$nachweis) {
            flash($extern
                ? t('Zum Löschen bitte die Kennung genau so eintippen, wie sie oben steht.')
                : t('Das Passwort stimmt nicht – es wurde nichts gelöscht.'), 'err');
            redirect_to('profile.php' . $anker);
        }
        $umfang = account_delete_scope($user['name']);
        $err = account_delete($user['name']);
        if ($err !== null) {
            flash($err, 'err');
            redirect_to('profile.php' . $anker);
        }
        auth_logout();
        // Keine Flash-Nachricht: Die Sitzung ist gerade beendet worden, sie
        // käme nirgends mehr an. Eine gelöschte Existenz verdient ohnehin
        // mehr als eine Zeile, die beim nächsten Klick verschwindet.
        page_header(t('Konto gelöscht'), true);
        echo '<div class="card narrow"><h1>' . t('Konto gelöscht') . '</h1>'
            . '<p>' . ($umfang['eigene'] === 1
                ? t('Dein Konto ist entfernt, zusammen mit einem Kurzlink und den zugehörigen Zählern.')
                : t('Dein Konto ist entfernt, zusammen mit %d Kurzlinks und den zugehörigen Zählern.', (int)$umfang['eigene'])) . '</p>';
        if ($umfang['gruppe'] > 0) {
            echo '<p class="muted small">' . ($umfang['gruppe'] === 1
                ? t('Ein Link war einer Gruppe zugeordnet und bleibt bestehen – dort arbeiten andere damit weiter.')
                : t('%d Links waren Gruppen zugeordnet und bleiben bestehen – dort arbeiten andere damit weiter.', (int)$umfang['gruppe'])) . '</p>';
        }
        echo '<p><a class="btn btn-primary" href="' . e(base_url()) . '/">' . t('Zur Startseite') . '</a></p></div>';
        page_footer();
        exit;
    }

    if ($action === 'email') {
        $new = strtolower(trim((string)($_POST['email'] ?? '')));
        if (filter_var($new, FILTER_VALIDATE_EMAIL) === false) {
            flash(t('Das sieht nicht nach einer gültigen E-Mail-Adresse aus.'), 'err');
        } elseif (user_email_taken($new) && user_resolve($new) !== $user['name']) {
            flash(t('Diese Adresse ist bereits mit einem Konto verknüpft.'), 'err');
        } elseif (!bucket_rate_ok('chmail', 5)) {
            flash(t('Zu viele Versuche – bitte in einer Stunde erneut.'), 'err');
        } else {
            $token = pending_create('chmail', ['user' => $user['name'], 'email' => $new]);
            $ok = mail_send($new, t('Neue E-Mail-Adresse bei %s bestätigen', cfg('site_name')),
                t("Hallo,\n\nfür das Konto „%s“ bei %s soll diese Adresse\nals E-Mail-Adresse hinterlegt werden. Zum Bestätigen (angemeldet bleiben!):\n\n%s\n\nDer Link ist 24 Stunden gültig. Falls du das nicht warst, ignoriere diese Mail.\n\n– %s",
                    $user['name'], cfg('site_name'), mail_link('admin/profile.php') . '?token=' . $token, cfg('site_name')));
            // Sicherheits-Info an die bisherige Adresse, falls vorhanden
            $old = user_get($user['name'])['email'] ?? null;
            if ($ok && $old !== null && strtolower($old) !== $new) {
                mail_send($old, t('E-Mail-Änderung für dein Konto bei %s', cfg('site_name')),
                    t("Hallo,\n\nfür dein Konto wurde eine Änderung der E-Mail-Adresse angefordert\n(neue Adresse: %s). Wenn das nicht du warst, ändere bitte\numgehend dein Passwort und melde dich bei uns.\n\n– %s",
                        $new, cfg('site_name')));
            }
            flash($ok
                ? t('Bestätigungslink an %s geschickt – die Adresse ist erst nach dem Klick aktiv.', $new)
                : t('Die Mail konnte gerade nicht verschickt werden – bitte später erneut versuchen.'),
                $ok ? 'ok' : 'err');
        }
    } elseif ($action === 'display') {
        $err = user_set_display_name($user['name'], (string)($_POST['display_name'] ?? ''));
        flash($err ?? t('Anzeigename gespeichert.'), $err === null ? 'ok' : 'err');
    } elseif ($extern) {
        // Zentral verwaltete Konten haben hier keinen Passwort-Hash. Ein lokal
        // gesetztes Passwort brächte nichts: Die Anmeldung weist solche Konten
        // ab, und die nächste Anmeldung über das Verzeichnis löscht den Hash
        // ohnehin wieder.
        flash(t('Dein Passwort verwaltet die zentrale Anmeldung – hier lässt es sich nicht ändern.'), 'err');
    } else {
        $current = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $repeat = (string)($_POST['repeat'] ?? '');

        $stored = user_get($user['name'])['pass'] ?? '';
        if (!bucket_rate_ok('nachweis', 20, $user['name'])) {
            http_response_code(429);
            flash(t('Zu viele Versuche – bitte später erneut.'), 'err');
        } elseif (!password_verify($current, $stored)) {
            flash(t('Das aktuelle Passwort stimmt nicht.'), 'err');
        } elseif ($new !== $repeat) {
            flash(t('Die Wiederholung stimmt nicht mit dem neuen Passwort überein.'), 'err');
        } else {
            $err = user_set_password($user['name'], $new);
            if ($err === null) {
                // Ein neues Passwort meldet alle anderen Geräte ab – wer es
                // ändert, weil das alte in falsche Hände geriet, will genau das
                sessions_revoke($user['name'], session_fingerprint());
            }
            flash($err ?? t('Passwort geändert. Alle anderen Sitzungen sind abgemeldet.'), $err === null ? 'ok' : 'err');
        }
    }
    if ($action === 'session_revoke') {
        // Einzeln oder alle anderen – die eigene Sitzung bleibt immer
        $fp = (string)($_POST['sitzung'] ?? '');
        if ($fp === 'andere') {
            sessions_revoke($user['name'], session_fingerprint());
            flash(t('Alle anderen Sitzungen sind abgemeldet.'));
        } elseif (preg_match('/^[a-f0-9]{64}$/', $fp) === 1 && $fp !== session_fingerprint()) {
            sessions_revoke($user['name'], null, $fp);
            flash(t('Sitzung abgemeldet.'));
        }
    }

    redirect_to('profile.php' . $anker);
}

// Bestätigungslink aus der Mail: neue Adresse aktivieren
if (isset($_GET['token'])) {
    $d = pending_get('chmail', (string)$_GET['token']);
    if ($d === null) {
        flash(t('Dieser Bestätigungslink ist ungültig oder abgelaufen.'), 'err');
    } elseif (($d['user'] ?? '') !== $user['name']) {
        flash(t('Dieser Link gehört zu einem anderen Konto – bitte dort anmelden und erneut klicken.'), 'err');
    } else {
        pending_take('chmail', (string)$_GET['token']);
        $err = user_set_email($user['name'], (string)$d['email']);
        flash($err ?? t('E-Mail-Adresse aktualisiert: %s', $d['email']), $err === null ? 'ok' : 'err');
    }
    redirect_to('profile.php' . $anker);
}

page_header(t('Profil'), true);
show_flash();
?>
<div class="card narrow">
    <h1><?= t('Profil') ?></h1>
    <p class="muted"><?= t('Angemeldet als') ?> <strong><?= e(user_display($user['name'])) ?></strong>
        <?php if (user_has_display($user['name'])): ?><br><span class="small" style="font-family:var(--mono)"><?= e($user['name']) ?></span><?php endif; ?>
        <br><?= t('Rolle:') ?> <?= e($user['role']) ?></p>
    <?php $codeQuota = (int)settings()['custom_code_quota']; ?>
    <p><span class="muted small"><?= t('Links:') ?> <?= link_count($user['name']) ?>/<?= e(limit_label(user_limit($user['name'], 'links'))) ?> ·
        <?= t('Wunsch-Codes:') ?> <?= custom_code_count($user['name']) ?><?= $codeQuota > 0 ? '/' . $codeQuota : '' ?>
        (<?= t('mind. %d Zeichen', (int)settings()['custom_code_min_len']) ?>) ·
        <?= t('Logos:') ?> <?= e(limit_label(user_limit($user['name'], 'logos'))) ?> ·
        <?= t('Statistik:') ?> <?= (int)user_limit($user['name'], 'stats_days') === PHP_INT_MAX ? t('unbegrenzt') : t('%d Tage', (int)user_limit($user['name'], 'stats_days')) ?> ·
        <a href="import.php"><?= t('CSV-Import') ?></a></span></p>

    <h2 id="anzeigename"><?= t('Anzeigename') ?></h2>
    <?php if (($user['auth'] ?? 'local') !== 'local'): ?>
        <p class="muted small"><?= t('Dein Anzeigename kommt aus der zentralen Anmeldung (%s) und wird bei jeder Anmeldung von dort aktualisiert.', '<strong>' . e(user_display($user['name'])) . '</strong>') ?></p>
    <?php else: ?>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="display">
        <label for="p-display"><?= t('Wie sollen andere dich sehen?') ?> <span class="muted">(<?= t('leer = deine Kennung') ?>)</span></label>
        <div class="short-row">
            <input id="p-display" type="text" name="display_name" maxlength="80"
                   value="<?= e(user_has_display($user['name']) ? user_display($user['name']) : '') ?>"
                   placeholder="<?= t('Vorname Nachname') ?>">
            <button class="btn" type="submit"><?= t('Speichern') ?></button>
        </div>
    </form>
    <?php endif; ?>

    <h2 id="email"><?= t('E-Mail-Adresse') ?></h2>
    <?php $email = user_get($user['name'])['email'] ?? null; ?>
    <?php if ($email !== null): ?>
        <p class="muted small"><?= t('Hinterlegt: %s – wird für Login und Passwort-Reset verwendet.', '<strong>' . e($email) . '</strong>') ?></p>
    <?php elseif (!$extern): ?>
        <p class="muted small"><strong><?= t('Keine E-Mail hinterlegt.') ?></strong> <?= t('Ohne bestätigte Adresse funktioniert der Passwort-Reset für dieses Konto nicht.') ?></p>
    <?php else: ?>
        <p class="muted small"><strong><?= t('Keine E-Mail hinterlegt.') ?></strong> <?= t('Ohne Adresse erreichen dich keine Hinweise des Dienstes – etwa die Vorwarnung, bevor ein lange ungenutzter Link aufgeräumt wird.') ?></p>
    <?php endif; ?>
    <?php if ($extern): ?>
        <p class="muted small"><?= t('Liefert die zentrale Anmeldung eine Adresse mit, überschreibt sie eine hier eingetragene bei der nächsten Anmeldung. Tut sie das nicht, bleibt deine Eintragung stehen.') ?></p>
    <?php endif; ?>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="email">
        <label for="p-email"><?= $email !== null ? t('Neue E-Mail-Adresse') : t('E-Mail-Adresse hinterlegen') ?></label>
        <div class="short-row">
            <input id="p-email" type="text" name="email" required inputmode="email" autocomplete="email">
            <button class="btn" type="submit"><?= t('Bestätigungslink senden') ?></button>
        </div>
        <p class="muted small"><?= t('Wir schicken einen Link an die neue Adresse – erst nach dem Klick ist sie aktiv.') ?></p>
    </form>

    <h2 id="passwort"><?= t('Passwort') ?></h2>
    <?php if ($extern): ?>
        <p class="muted small"><?= t('Dein Passwort verwaltet die zentrale Anmeldung – hier gibt es keins, das sich ändern ließe. Wende dich dafür an die Stelle, über die du dich anmeldest.') ?></p>
    <?php else: ?>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <?= username_hint($user['name']) ?>
        <label for="p-current"><?= t('Aktuelles Passwort') ?></label>
        <input id="p-current" type="password" name="current" required autocomplete="current-password">
        <label for="p-new"><?= t('Neues Passwort (mind. 8 Zeichen)') ?></label>
        <input id="p-new" type="password" name="new" required minlength="8" autocomplete="new-password">
        <label for="p-repeat"><?= t('Neues Passwort wiederholen') ?></label>
        <input id="p-repeat" type="password" name="repeat" required minlength="8" autocomplete="new-password">
        <p><button class="btn btn-primary" type="submit"><?= t('Passwort ändern') ?></button></p>
    </form>
    <?php endif; ?>

    <h2 id="sitzungen"><?= t('Sitzungen') ?></h2>
    <p class="muted small"><?= t('Wo dieses Konto gerade angemeldet ist. Abgemeldete Sitzungen enden mit ihrem nächsten Seitenaufruf; ein Passwortwechsel meldet alle anderen von selbst ab.') ?></p>
    <?php
    $sitzungen = (array)(user_get($user['name'])['sessions'] ?? []);
    // Neueste zuerst, die eigene erkennbar
    uasort($sitzungen, fn($a, $b) => strcmp((string)($b['zuletzt'] ?? ''), (string)($a['zuletzt'] ?? '')));
    $eigeneSitzung = session_fingerprint();
    ?>
    <ul class="key-list">
        <?php foreach ($sitzungen as $fp => $sz): ?>
        <li>
            <div>
                <strong><?= e((string)($sz['geraet'] ?? '') !== '' ? (string)$sz['geraet'] : t('Unbekanntes Gerät')) ?></strong>
                <?php if ($fp === $eigeneSitzung): ?><span class="tag tag-on"><?= t('diese Sitzung') ?></span><?php endif; ?><br>
                <span class="muted small"><?= t('angemeldet seit') ?> <?= e(date('d.m.Y H:i', strtotime((string)($sz['seit'] ?? '')))) ?>
                    · <?= t('zuletzt aktiv') ?> <?= e(date('d.m.Y H:i', strtotime((string)($sz['zuletzt'] ?? '')))) ?></span>
            </div>
            <?php if ($fp !== $eigeneSitzung): ?>
            <form method="post" action="" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="session_revoke">
                <input type="hidden" name="sitzung" value="<?= e((string)$fp) ?>">
                <button class="btn btn-small btn-danger" type="submit"><?= t('Abmelden') ?></button>
            </form>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($sitzungen) > 1): ?>
    <form method="post" action="" data-confirm="<?= t('Alle anderen Sitzungen abmelden? Diese hier bleibt bestehen.') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="session_revoke">
        <input type="hidden" name="sitzung" value="andere">
        <button class="btn btn-small" type="submit"><?= t('Alle anderen Sitzungen abmelden') ?></button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2 id="zwei-faktor"><?= t('Zwei-Faktor-Anmeldung') ?></h2>
    <?php
    $t = totp_get($user['name']);
    $aktiv = totp_active($user['name']);
    $keys = passkeys_of($user['name']);
    $pflicht = totp_required($user['role']);
    $frischeCodes = $_SESSION['fresh_recovery'] ?? null;
    unset($_SESSION['fresh_recovery']);
    ?>
    <p class="muted small"><?= t('Ein zweiter Nachweis beim Anmelden. Wer dein Passwort kennt, kommt damit trotzdem nicht an deine Links – und an die Ziele der Codes, die längst irgendwo gedruckt hängen.') ?><?php if ($pflicht): ?> <strong><?= t('Diese Instanz verlangt ihn.') ?></strong><?php endif; ?></p>

    <h3>Passkey <span class="muted small">(<?= t('empfohlen') ?>)</span></h3>
    <?php if (!webauthn_possible()): ?>
        <p class="muted small"><?= t('Passkeys brauchen eine gesicherte Verbindung (HTTPS). Auf dieser Instanz ist das gerade nicht der Fall – nimm so lange die App unten.') ?></p>
    <?php else: ?>
        <p class="muted small"><?= t('Fingerabdruck, Gesicht oder Geräte-PIN – hinterlegt in deinem Telefon, deinem Rechner oder auf einem Sicherheitsschlüssel. Anders als ein Code aus einer App ist ein Passkey %san diese Adresse gebunden%s: Auf einer nachgebauten Anmeldeseite gibt ihn dein Gerät gar nicht erst heraus. Genau davor schützt ein abtippbarer Code nicht.', '<strong>', '</strong>') ?></p>

        <?php if ($keys !== []): ?>
        <ul class="key-list">
            <?php foreach ($keys as $k): ?>
            <li>
                <div>
                    <strong><?= e((string)$k['label']) ?></strong><br>
                    <span class="muted small"><?= t('eingerichtet') ?> <?= e(date('d.m.Y', strtotime((string)$k['created']))) ?>
                    <?php if (!empty($k['last_used'])): ?> · <?= t('zuletzt benutzt') ?> <?= e(date('d.m.Y', strtotime((string)$k['last_used']))) ?>
                    <?php else: ?> · <?= t('noch nicht benutzt') ?><?php endif; ?></span>
                </div>
                <form method="post" action="" data-confirm="Diesen Passkey wirklich entfernen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pk_remove">
                    <input type="hidden" name="id" value="<?= e((string)$k['id']) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Entfernen') ?></button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (count($keys) < 10): ?>
        <label for="pk-label"><?= t('Name für das Gerät') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
        <div class="short-row">
            <input id="pk-label" type="text" maxlength="60" placeholder="<?= $keys === [] ? t('Mein Telefon') : t('Zweites Gerät') ?>">
            <button class="btn<?= ($pflicht && !$aktiv && $keys === []) ? ' btn-primary' : '' ?>" type="button"
                    data-passkey="register" data-url="profile.php" data-csrf="<?= e(csrf_token()) ?>"
                    data-label="pk-label" data-status="pk-status"><?= $keys === [] ? t('Passkey einrichten') : t('Weiteren einrichten') ?></button>
        </div>
        <div id="pk-status" class="flash" style="display:none"></div>
        <?php if ($keys !== []): ?>
        <p class="muted small"><?= t('Ein zweites Gerät ist keine Umständlichkeit, sondern der Ersatzschlüssel: Passkeys lassen sich nicht abschreiben und aufheben.') ?></p>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <h3><?= t('Einmalkennwort aus einer App') ?></h3>
    <?php if ($frischeCodes !== null): ?>
    <div class="flash flash-ok">
        <strong><?= t('Wiederherstellungscodes') ?></strong> – <?= t('jeder gilt einmal, falls dein Gerät weg ist. Sie werden nicht noch einmal angezeigt.') ?>
        <div class="term" style="margin-top:0.6rem"><?= e(implode("\n", $frischeCodes)) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($aktiv): ?>
        <p class="muted small"><?= t('Aktiv. Beim Anmelden fragt %s nach einem Code aus deiner App. Noch %d Wiederherstellungscodes übrig.', e(cfg('site_name')), totp_recovery_left($user['name'])) ?></p>
        <?php if ($pflicht && $keys === []): ?>
            <p class="muted small"><?= t('Diese Instanz verlangt eine zweite Stufe – abschalten geht erst, wenn ein Passkey eingerichtet ist.') ?></p>
        <?php else: ?>
        <details>
            <summary class="muted small"><?= t('Abschalten') ?></summary>
            <form method="post" action="" data-confirm="<?= t('Einmalkennwort wirklich abschalten?') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="totp_off">
                <?php if ($extern): ?>
                    <label for="t-confirm"><?= t('Zur Sicherheit deine Kennung eintippen:') ?>
                        <span style="font-family:var(--mono)"><?= e($user['name']) ?></span></label>
                    <input id="t-confirm" type="text" name="confirm" required autocomplete="off">
                <?php else: ?>
                    <?= username_hint($user['name']) ?>
                    <label for="t-pass"><?= t('Zur Sicherheit dein Passwort:') ?></label>
                    <input id="t-pass" type="password" name="current" required autocomplete="current-password">
                <?php endif; ?>
                <p><button class="btn btn-danger" type="submit"><?= t('Abschalten') ?></button></p>
            </form>
        </details>
        <?php endif; ?>

    <?php elseif ($t !== null): ?>
        <p class="muted small"><?= t('Scanne den Code mit einer Authenticator-App und gib dann die sechs Ziffern ein, die sie anzeigt.') ?></p>
        <div style="max-width:220px;margin:0 0 0.8rem"><?= totp_qr_svg($user['name'], (string)$t['secret']) ?></div>
        <p class="muted small"><?= t('Geht das Scannen nicht, trag den Schlüssel von Hand ein:') ?><br>
        <code style="word-break:break-all"><?= e(chunk_split((string)$t['secret'], 4, ' ')) ?></code></p>
        <form method="post" action="" data-enter-submit>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="totp_confirm">
            <label for="t-code"><?= t('Code aus der App') ?></label>
            <div class="short-row">
                <input id="t-code" type="text" name="code" required inputmode="numeric"
                       autocomplete="one-time-code" placeholder="123456">
                <button class="btn btn-primary" type="submit"><?= t('Aktivieren') ?></button>
            </div>
        </form>

    <?php else: ?>
        <p class="muted small"><?= t('Sechs Ziffern, die alle 30 Sekunden wechseln. Funktioniert auf jedem Gerät und in jedem Browser – aber es lässt sich abtippen, und damit auch auf einer nachgebauten Seite eingeben. Der richtige Weg, wenn Passkeys nicht in Frage kommen.') ?></p>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="totp_start">
            <p><button class="btn<?= ($pflicht && $keys === []) ? ' btn-primary' : '' ?>" type="submit"><?= t('Einrichten') ?></button></p>
        </form>
    <?php endif; ?>

    <h2 id="api"><?= t('Programmierschnittstelle') ?></h2>
    <?php if (!user_can($user['name'], 'api_access')): ?>
        <p class="muted small"><?= t('Für den Zugriff über die Schnittstelle fehlt deinem Konto die Berechtigung. Sie hängt an einer Gruppe – ein Administrator kann sie freischalten.') ?></p>
    <?php else: ?>
        <?php $frisch = $_SESSION['fresh_token'] ?? null; unset($_SESSION['fresh_token']); ?>
        <?php if ($frisch !== null): ?>
        <div class="flash flash-ok" style="word-break:break-all">
            <strong><?= t('Dein neuer Schlüssel:') ?></strong><br>
            <code><?= e($frisch) ?></code><br>
            <span class="small"><?= t('Notier ihn jetzt – er wird nicht gespeichert und lässt sich später nicht noch einmal anzeigen.') ?></span>
        </div>
        <?php endif; ?>
        <?php $doku = trim((string)cfg('api_doc_url')); ?>
        <p class="muted small"><?= t('Ein Schlüssel meldet ein Programm unter deinem Konto an. Er kann nie mehr, als du selbst darfst.') ?><?php if ($doku !== ''): ?>
        <a href="<?= e(str_contains($doku, '://') ? $doku : base_url() . '/' . ltrim($doku, '/')) ?>"><?= t('Zur Anleitung') ?></a>.<?php endif; ?></p>
        <?php $meine = tokens_of($user['name']); ?>
        <?php if ($meine !== []): ?>
        <div class="table-scroll"><table>
            <tr><th><?= t('Bezeichnung') ?></th><th><?= t('Anfang') ?></th><th><?= t('Angelegt') ?></th><th><?= t('Zuletzt benutzt') ?></th><th></th></tr>
            <?php foreach ($meine as $t): ?>
            <tr>
                <td><?= e((string)($t['label'] ?? '')) ?: '<span class="muted">' . t('ohne') . '</span>' ?></td>
                <td><code><?= e((string)($t['hint'] ?? '')) ?>…</code></td>
                <td class="small"><?= e(date('d.m.Y', strtotime((string)$t['created']))) ?></td>
                <td class="small"><?= ($t['last_used'] ?? null) !== null
                    ? e(date('d.m.Y', strtotime((string)$t['last_used'])))
                    : '<span class="muted">' . t('nie') . '</span>' ?></td>
                <td><form method="post" action="" class="inline" data-confirm="<?= t('Schlüssel zurückziehen? Programme, die ihn nutzen, verlieren sofort den Zugriff.') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="token_revoke">
                    <input type="hidden" name="id" value="<?= e((string)$t['id']) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Zurückziehen') ?></button>
                </form></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="token_new">
            <label for="p-label"><?= t('Neuer Schlüssel') ?> <span class="muted"><?= t('(Bezeichnung, damit du ihn später zuordnen kannst)') ?></span></label>
            <div class="short-row">
                <input id="p-label" type="text" name="label" maxlength="60" placeholder="z. B. Kassensystem">
                <button class="btn" type="submit"><?= t('Anlegen') ?></button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (user_can($user['name'], 'api_access')):
        $laeden = ext_stores();
        $ladenNamen = ext_laden_namen();
    ?>
    <h2 id="erweiterung"><?= t('Browser-Erweiterung') ?></h2>
    <p class="muted small"><?= $laeden !== []
        ? t('Kürzt die geöffnete Seite mit einem Klick. Aus dem Laden installieren, dann unten einen Verbindungscode erzeugen und ihn in den Einstellungen der Erweiterung einfügen – Adresse und Zugangsschlüssel stehen darin.')
        : t('Kürzt die geöffnete Seite mit einem Klick. Sobald sie in den Läden von Chrome und Firefox steht, findest du den Link hier. Ist sie schon installiert, richtet ein Verbindungscode sie ein.') ?></p>

    <?php if ($laeden !== []): ?>
    <p class="short-row">
        <?php foreach ($laeden as $laden => $url): ?>
        <a class="btn" href="<?= e($url) ?>" target="_blank" rel="noopener">
            <?= e($ladenNamen[$laden] ?? $laden) ?></a>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>
    <?php $code = $_SESSION['connect_code'] ?? null; unset($_SESSION['connect_code']); ?>
    <?php if ($code !== null): ?>
    <div class="flash flash-ok">
        <strong><?= t('Dein Verbindungscode:') ?></strong>
        <p><input type="text" value="<?= e($code) ?>" readonly onclick="this.select()"
                  style="font-family:var(--mono);font-size:0.85rem" aria-label="<?= t('Verbindungscode') ?>"></p>
        <p class="small"><?= t('In der Erweiterung unter „Einstellungen“ einfügen – Adresse und Schlüssel stehen darin. Er enthält ein Zugangsmittel: nicht weitergeben. Zurückziehen lässt er sich oben unter „Zugangsschlüssel“.') ?></p>
    </div>
    <?php endif; ?>

    <p class="short-row">
        <form method="post" action="" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="connect_code">
            <button class="btn" type="submit"><?= t('Verbindungscode erzeugen') ?></button>
        </form>
    </p>
    <p class="muted small"><?= t('Für eine Erweiterung, die schon installiert ist – etwa aus dem Chrome Web Store oder von addons.mozilla.org. Einmal kopieren, einmal einfügen, fertig.') ?></p>

    <?php endif; ?>

    <h2 id="daten"><?= t('Deine Daten') ?></h2>
    <p class="muted small"><?= t('Alles, was über dieses Konto gespeichert ist, als JSON-Datei: Kontodaten, Gruppen, Rechte sowie jeder Kurzlink mit Ziel, Datum und Klickzahlen. Ohne Passwort-Hash – der ist ein Zugangsmittel, kein Inhalt.') ?></p>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="export">
        <p><button class="btn" type="submit"><?= t('Daten herunterladen') ?></button></p>
    </form>

    <?php if (cfg('self_delete')): $umfang = account_delete_scope($user['name']); ?>
    <h2 id="loeschen"><?= t('Konto löschen') ?></h2>
    <p class="muted small"><?= $umfang['eigene'] === 1
        ? t('Das Konto verschwindet mitsamt einem Kurzlink und den zugehörigen Klickzählern. Gedruckte QR-Codes darauf zeigen danach ins Leere.')
        : t('Das Konto verschwindet mitsamt %d Kurzlinks und den zugehörigen Klickzählern. Gedruckte QR-Codes darauf zeigen danach ins Leere.', (int)$umfang['eigene']) ?>
    <?php if ($umfang['gruppe'] > 0): ?>
        <?= $umfang['gruppe'] === 1
            ? t('Ein weiterer Link ist einer Gruppe zugeordnet und bleibt bestehen.')
            : t('%d weitere Links sind Gruppen zugeordnet und bleiben bestehen.', (int)$umfang['gruppe']) ?>
    <?php endif; ?>
    <?= t('Rückgängig machen lässt sich das nicht.') ?></p>
    <?php if ($extern): ?>
        <p class="muted small"><?= t('Dein Konto kommt aus der zentralen Anmeldung. Meldest du dich danach erneut an, kann es je nach Einstellung neu angelegt werden – die Links sind trotzdem weg.') ?></p>
    <?php endif; ?>
    <form method="post" action="" data-confirm="<?= t('Konto und Links endgültig löschen?') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <?php if ($extern): ?>
            <label for="p-confirm"><?= t('Zur Sicherheit deine Kennung eintippen:') ?>
                <span style="font-family:var(--mono)"><?= e($user['name']) ?></span></label>
            <input id="p-confirm" type="text" name="confirm" required autocomplete="off">
        <?php else: ?>
            <?= username_hint($user['name']) ?>
            <label for="p-del-pass"><?= t('Zur Sicherheit dein Passwort:') ?></label>
            <input id="p-del-pass" type="password" name="current" required autocomplete="current-password">
        <?php endif; ?>
        <p><button class="btn btn-danger" type="submit"><?= t('Konto endgültig löschen') ?></button></p>
    </form>
    <?php endif; ?>
</div>
<?php page_script('assets/passkey.js');
page_footer(); ?>
