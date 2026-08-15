<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/totp.php';
require_once __DIR__ . '/../inc/webauthn.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/sso.php';

$user = auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $name = trim((string)($_POST['username'] ?? ''));

    if ($action === 'add') {
        $err = user_add($name, (string)($_POST['password'] ?? ''), (string)($_POST['role'] ?? 'user'));
        if ($err === null) {
            user_set_groups($name, (array)($_POST['groups'] ?? []));
            user_set_display_name($name, (string)($_POST['display_name'] ?? ''));
        }
        flash($err ?? t('Nutzer %s angelegt.', $name), $err === null ? 'ok' : 'err');
    } elseif ($action === 'display') {
        $err = user_set_display_name($name, (string)($_POST['display_name'] ?? ''));
        flash($err ?? t('Anzeigename von %s: %s.', $name, user_has_display($name) ? user_display($name) : t('(keiner)')),
            $err === null ? 'ok' : 'err');
    } elseif ($action === 'password') {
        $err = user_set_password($name, (string)($_POST['password'] ?? ''));
        flash($err ?? t('Passwort von %s geändert.', $name), $err === null ? 'ok' : 'err');
    } elseif ($action === 'groups') {
        if (!isset(users_all()[$name])) {
            flash(t('Nutzer nicht gefunden.'), 'err');
        } else {
            $until = trim((string)($_POST['until'] ?? ''));
            user_set_groups($name, (array)($_POST['groups'] ?? []), $until === '' ? null : $until);
            $now = user_groups($name);
            flash(t('Gruppen von %s: %s', $name, $now === [] ? t('keine') : implode(', ', array_map('group_label', $now)))
                . ($until !== '' ? ' ' . t('(befristet bis %s)', date('d.m.Y', strtotime($until))) : '') . '.');
        }
    } elseif ($action === 'role') {
        $role = (string)($_POST['role'] ?? '');
        if ($name === $user['name']) {
            flash(t('Die eigene Rolle lässt sich hier nicht ändern – sonst sperrst du dich womöglich aus.'), 'err');
        } else {
            $err = user_set_role($name, $role);
            flash($err ?? t('Rolle von %s: %s.', $name, $role), $err === null ? 'ok' : 'err');
        }
    } elseif ($action === 'reset2fa') {
        // Der Weg zurück, wenn jemand sein Gerät verloren hat. Ein Passkey
        // lässt sich nicht abschreiben und in den Safe legen – anders als die
        // Wiederherstellungscodes der App gibt es hier nichts nachzuschlagen.
        // Bleibt: jemand, der die Person kennt, schaltet den Schutz ab.
        if ($name === $user['name']) {
            flash(t('Den eigenen Schutz bitte im Profil verwalten – dort ist ein Nachweis fällig.'), 'err');
        } elseif (!isset(users_all()[$name])) {
            flash(t('Nutzer nicht gefunden.'), 'err');
        } else {
            totp_disable($name);
            passkeys_drop_user($name);
            flash(t('Zweite Stufe für %s zurückgesetzt. Vergewissere dich, dass die Anfrage wirklich von dieser Person kam – danach schützt nur noch das Passwort.', $name));
        }
    } elseif ($action === 'approve') {
        $err = pending_user_approve($name, (string)($_POST['source'] ?? 'sso'));
        flash($err ?? t('Zugang für %s freigeschaltet – die nächste Anmeldung geht durch.', $name),
            $err === null ? 'ok' : 'err');
    } elseif ($action === 'reject') {
        pending_user_drop($name);
        flash(t('Anfrage verworfen. Bei einem erneuten Anmeldeversuch taucht sie wieder auf.'));
    } elseif ($action === 'delete') {
        if ($name === $user['name']) {
            flash(t('Du kannst dich nicht selbst löschen.'), 'err');
        } else {
            $err = user_delete($name);
            flash($err ?? t('Nutzer %s gelöscht. Seine Links bleiben bestehen.', $name), $err === null ? 'ok' : 'err');
        }
    }
    redirect_to('users.php');
}

$queue = pending_users();
uasort($queue, fn($a, $b) => strcmp($b['last_seen'], $a['last_seen']));

$q = trim((string)($_GET['q'] ?? ''));
$users = users_all();
if ($q !== '') {
    // Undurchsichtige Kennungen aus Föderationen sind ohne Suche unauffindbar
    $users = array_filter($users, fn($u, $k) =>
        stripos((string)$k, $q) !== false
        || stripos((string)($u['display_name'] ?? ''), $q) !== false
        || stripos((string)($u['email'] ?? ''), $q) !== false,
        ARRAY_FILTER_USE_BOTH);
}
// Nach Anzeigename sortieren – danach sucht man, nicht nach der Kennung
uksort($users, fn($a, $b) => strcasecmp(user_display((string)$a), user_display((string)$b)));
$groups = groups_all();
ksort($groups);
$perms = perms_all();

page_header(t('Nutzer'), true);
show_flash();
?>

<?php if ($queue !== []): ?>
<div class="card highlight">
    <h2><?= t('Wartet auf Freischaltung') ?> <span class="muted">(<?= count($queue) ?>)</span></h2>
    <p class="muted small"><?= t('Diese Kennungen haben sich erfolgreich über die zentrale Anmeldung ausgewiesen, haben hier aber noch kein Konto. Freischalten legt eines an – Name, E-Mail und Gruppen kommen bei der nächsten Anmeldung aus dem Verzeichnis.') ?></p>
    <div class="table-scroll"><table>
        <tr><th><?= t('Person') ?></th><th><?= t('Kennung') ?></th><th><?= t('Gruppen laut Verzeichnis') ?></th><th><?= t('Versuche') ?></th><th></th></tr>
        <?php foreach ($queue as $key => $e): ?>
        <tr>
            <td>
                <strong><?= e($e['display'] ?? t('(kein Name übermittelt)')) ?></strong>
                <?php if (!empty($e['email'])): ?><br><span class="muted small"><?= e($e['email']) ?></span><?php endif; ?>
                <?php if (($e['reason'] ?? '') === 'kollision'):
                    $vorhanden = users_all()[$key] ?? null; ?>
                <div class="flash flash-err small" style="margin:0.4rem 0 0">
                    <strong><?= t('Achtung:') ?></strong> <?= t('Unter dieser Kennung gibt es hier bereits ein Konto (Anmeldung: %s, Rolle: %s). Freischalten verknüpft beide: Das bisherige Passwort verfällt, und die Rolle wird auf %s zurückgesetzt. Nur bestätigen, wenn es dieselbe Person ist.',
                        e(match ($vorhanden['auth'] ?? 'local') { 'ldap' => 'LDAP', 'sso' => 'SSO', default => t('lokales Passwort') }),
                        e($vorhanden['role'] ?? '?'), '<em>user</em>') ?>
                </div>
                <?php endif; ?>
            </td>
            <td class="small" style="font-family:var(--mono)" title="<?= e((string)$key) ?>">
                <?= e(mb_strimwidth((string)$key, 0, 34, '…')) ?></td>
            <td class="small"><?php
                $gs = (array)($e['groups'] ?? []);
                echo $gs === [] ? '<span class="muted">' . t('keine') . '</span>'
                    : implode(' ', array_map(fn($g) => '<span class="tag tag-on">' . e(group_label($g)) . '</span>', $gs));
            ?></td>
            <td><?= (int)($e['tries'] ?? 1) ?>×<br><span class="muted small"><?= e(date('d.m.Y H:i', strtotime($e['last_seen']))) ?></span></td>
            <td class="actions">
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="username" value="<?= e((string)$key) ?>">
                    <button class="btn btn-small btn-primary" type="submit"><?= t('Freischalten') ?></button>
                </form>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="username" value="<?= e((string)$key) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Verwerfen') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= t('Neuer Nutzer') ?></h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div>
            <label for="u-name"><?= t('Nutzername') ?></label>
            <input id="u-name" type="text" name="username" required pattern="[a-zA-Z0-9._-]{2,32}">
        </div>
        <div>
            <label for="u-display"><?= t('Anzeigename') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
            <input id="u-display" type="text" name="display_name" maxlength="80" placeholder="<?= t('Vorname Nachname') ?>">
        </div>
        <div>
            <label for="u-pass"><?= t('Passwort (mind. 8 Zeichen)') ?></label>
            <input id="u-pass" type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label for="u-role"><?= t('Rolle') ?></label>
            <select id="u-role" name="role">
                <option value="user">user – <?= t('verwaltet eigene und Gruppen-Links') ?></option>
                <option value="admin">admin – <?= t('alles, inkl. Nutzer- und Gruppenverwaltung') ?></option>
            </select>
        </div>
        <?php if ($groups !== []): ?>
        <div>
            <label><?= t('Gruppen') ?></label>
            <div class="check-row">
                <?php foreach ($groups as $gid => $g): ?>
                <label class="check"><input type="checkbox" name="groups[]" value="<?= e((string)$gid) ?>"> <?= e($g['name']) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= t('Anlegen') ?></button>
    </form>
    <p class="muted small"><?= t('Hier angelegte Konten melden sich mit lokalem Passwort an. Konten aus LDAP oder zentraler Anmeldung entstehen automatisch beim ersten Login.') ?></p>
</div>

<div class="card">
    <div class="list-head">
        <h2><?= t('Nutzer') ?> <span class="muted">(<?= count($users) ?><?= $q !== '' ? ' ' . t('von') . ' ' . count(users_all()) : '' ?>)</span></h2>
        <form method="get" action="" class="short-row">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('Name, Kennung oder E-Mail…') ?>">
            <button class="btn btn-small" type="submit"><?= t('Suchen') ?></button>
            <?php if ($q !== ''): ?><a class="btn btn-small" href="users.php"><?= t('Alle') ?></a><?php endif; ?>
        </form>
    </div>
    <div class="user-list">
        <?php foreach ($users as $name => $u):
            $src = $u['auth'] ?? 'local';
            $mine = user_groups((string)$name);
            $selbst = $name === $user['name'];
        ?>
        <details class="user">
            <summary>
                <span class="user-name">
                    <strong><?= e(user_display((string)$name)) ?></strong><?= $selbst ? ' <span class="muted">(' . t('du') . ')</span>' : '' ?>
                    <?php if (user_has_display((string)$name)): ?>
                    <br><span class="muted small" style="font-family:var(--mono)" title="<?= e((string)$name) ?>"><?= e(mb_strimwidth((string)$name, 0, 44, '…')) ?></span>
                    <?php endif; ?>
                </span>
                <span class="user-meta">
                    <span class="tag"><?= e(match ($src) { 'ldap' => 'LDAP', 'sso' => 'SSO', default => t('lokal') }) ?></span>
                    <?php if ($u['role'] === 'admin'): ?><span class="tag tag-on">Admin</span><?php endif; ?>
                    <?php
                    $zf = [];
                    if (passkeys_active((string)$name)) $zf[] = count(passkeys_of((string)$name)) . '× Passkey';
                    if (totp_active((string)$name)) $zf[] = 'App';
                    ?>
                    <?php if ($zf !== []): ?><span class="tag tag-on" title="<?= t('Zweite Stufe beim Anmelden') ?>">🔒 <?= e(implode(' + ', $zf)) ?></span><?php endif; ?>
                    <?php foreach ($mine as $g): $bis = user_group_until((string)$name, $g); ?>
                        <span class="tag tag-on" <?= $bis !== null ? 'title="' . t('befristet bis %s', e(date('d.m.Y', strtotime($bis)))) . '"' : '' ?>>
                            <?= e(group_label($g)) ?><?= $bis !== null ? ' ⏱' : '' ?></span>
                    <?php endforeach; ?>
                    <span class="small"><?= link_count((string)$name) ?><span class="muted">/<?= e(limit_label(user_limit((string)$name, 'links'))) ?> <?= t('Links') ?></span></span>
                    <span class="small muted"><?= t('seit') ?> <?= e(date('d.m.Y', strtotime($u['created']))) ?></span>
                </span>
            </summary>

            <div class="user-forms">
                <?php if ($groups !== []): ?>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="groups">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <label><?= t('Gruppen') ?></label>
                    <div class="check-row">
                        <?php foreach ($groups as $gid => $g): ?>
                        <label class="check" title="<?= e($g['name']) ?>">
                            <input type="checkbox" name="groups[]" value="<?= e((string)$gid) ?>"
                                <?= in_array((string)$gid, $mine, true) ? ' checked' : '' ?>>
                            <?= e($g['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="short-row">
                        <input type="date" name="until" min="<?= e(date('Y-m-d')) ?>"
                               title="<?= t('Mitgliedschaft befristen (leer = unbefristet)') ?>">
                        <button class="btn btn-small" type="submit"><?= t('Gruppen setzen') ?></button>
                    </div>
                    <p class="muted small"><?= t('Datum leer lassen = unbefristet.') ?></p>
                </form>
                <?php endif; ?>

                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="display">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <label for="dn-<?= e((string)$name) ?>"><?= t('Anzeigename') ?></label>
                    <div class="short-row">
                        <input id="dn-<?= e((string)$name) ?>" type="text" name="display_name" maxlength="80"
                               placeholder="<?= t('leer = Kennung') ?>"
                               value="<?= e(user_has_display((string)$name) ? user_display((string)$name) : '') ?>">
                        <button class="btn btn-small" type="submit"><?= t('Setzen') ?></button>
                    </div>
                </form>

                <?php if ($src === 'local'): ?>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <label for="pw-<?= e((string)$name) ?>"><?= t('Neues Passwort setzen') ?></label>
                    <div class="short-row">
                        <input id="pw-<?= e((string)$name) ?>" type="password" name="password"
                               placeholder="<?= t('mind. 8 Zeichen') ?>" minlength="8" required autocomplete="new-password">
                        <button class="btn btn-small" type="submit"><?= t('Setzen') ?></button>
                    </div>
                </form>
                <?php endif; ?>

                <?php if (!$selbst): ?>
                <div class="user-danger">
                    <form method="post" action="" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="role">
                        <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                        <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                        <button class="btn btn-small" type="submit"><?= t('Rolle') ?> → <?= $u['role'] === 'admin' ? 'user' : 'admin' ?></button>
                    </form>
                    <?php if ($zf !== []): ?>
                    <form method="post" action="" class="inline" data-confirm="<?= e(t('Zweite Stufe für „%s“ wirklich zurücksetzen? Danach schützt nur noch das Passwort.', (string)$name)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset2fa">
                        <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                        <button class="btn btn-small" type="submit"><?= t('Zweite Stufe zurücksetzen') ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="" class="inline" data-confirm="<?= e(t('Nutzer „%s“ wirklich löschen?', (string)$name)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                        <button class="btn btn-small btn-danger" type="submit"><?= t('Konto löschen') ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <?php if ($groups === []): ?>
    <p class="muted small"><?= t('Es gibt noch keine Gruppen –') ?> <a href="groups.php"><?= t('hier anlegen') ?></a>.
    <?= t('Ohne Gruppen sieht jedes Konto nur seine eigenen Links.') ?></p>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
