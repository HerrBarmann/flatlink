<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/account.php';
require_once __DIR__ . '/../inc/token.php';
require_once __DIR__ . '/../inc/mail.php';

$user = auth_require();
$extern = ($user['auth'] ?? 'local') !== 'local';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'password');

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
<?php page_footer(); ?>
