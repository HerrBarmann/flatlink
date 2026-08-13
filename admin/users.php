<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';

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
        flash($err ?? 'Nutzer ' . $name . ' angelegt.', $err === null ? 'ok' : 'err');
    } elseif ($action === 'display') {
        $err = user_set_display_name($name, (string)($_POST['display_name'] ?? ''));
        flash($err ?? 'Anzeigename von ' . $name . ': ' . (user_has_display($name) ? user_display($name) : '(keiner)') . '.',
            $err === null ? 'ok' : 'err');
    } elseif ($action === 'password') {
        $err = user_set_password($name, (string)($_POST['password'] ?? ''));
        flash($err ?? 'Passwort von ' . $name . ' geändert.', $err === null ? 'ok' : 'err');
    } elseif ($action === 'groups') {
        if (!isset(users_all()[$name])) {
            flash('Nutzer nicht gefunden.', 'err');
        } else {
            $until = trim((string)($_POST['until'] ?? ''));
            user_set_groups($name, (array)($_POST['groups'] ?? []), $until === '' ? null : $until);
            $now = user_groups($name);
            flash('Gruppen von ' . $name . ': ' . ($now === [] ? 'keine' : implode(', ', array_map('group_label', $now)))
                . ($until !== '' ? ' (befristet bis ' . date('d.m.Y', strtotime($until)) . ')' : '') . '.');
        }
    } elseif ($action === 'role') {
        $role = (string)($_POST['role'] ?? '');
        if ($name === $user['name']) {
            flash('Die eigene Rolle lässt sich hier nicht ändern – sonst sperrst du dich womöglich aus.', 'err');
        } else {
            $err = user_set_role($name, $role);
            flash($err ?? 'Rolle von ' . $name . ': ' . $role . '.', $err === null ? 'ok' : 'err');
        }
    } elseif ($action === 'delete') {
        if ($name === $user['name']) {
            flash('Du kannst dich nicht selbst löschen.', 'err');
        } else {
            $err = user_delete($name);
            flash($err ?? 'Nutzer ' . $name . ' gelöscht. Seine Links bleiben bestehen.', $err === null ? 'ok' : 'err');
        }
    }
    redirect_to('users.php');
}

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

page_header('Nutzer', true);
show_flash();
?>

<div class="card">
    <h2>Neuer Nutzer</h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div>
            <label for="u-name">Nutzername</label>
            <input id="u-name" type="text" name="username" required pattern="[a-zA-Z0-9._-]{2,32}">
        </div>
        <div>
            <label for="u-display">Anzeigename <span class="muted">(optional)</span></label>
            <input id="u-display" type="text" name="display_name" maxlength="80" placeholder="Vorname Nachname">
        </div>
        <div>
            <label for="u-pass">Passwort (mind. 8 Zeichen)</label>
            <input id="u-pass" type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label for="u-role">Rolle</label>
            <select id="u-role" name="role">
                <option value="user">user – verwaltet eigene und Gruppen-Links</option>
                <option value="admin">admin – alles, inkl. Nutzer- und Gruppenverwaltung</option>
            </select>
        </div>
        <?php if ($groups !== []): ?>
        <div>
            <label>Gruppen</label>
            <div class="check-row">
                <?php foreach ($groups as $gid => $g): ?>
                <label class="check"><input type="checkbox" name="groups[]" value="<?= e((string)$gid) ?>"> <?= e($g['name']) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Anlegen</button>
    </form>
    <p class="muted small">Hier angelegte Konten melden sich mit lokalem Passwort an. Konten aus
    LDAP oder zentraler Anmeldung entstehen automatisch beim ersten Login.</p>
</div>

<div class="card">
    <div class="list-head">
        <h2>Nutzer <span class="muted">(<?= count($users) ?><?= $q !== '' ? ' von ' . count(users_all()) : '' ?>)</span></h2>
        <form method="get" action="" class="short-row">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, Kennung oder E-Mail…">
            <button class="btn btn-small" type="submit">Suchen</button>
            <?php if ($q !== ''): ?><a class="btn btn-small" href="users.php">Alle</a><?php endif; ?>
        </form>
    </div>
    <div class="table-scroll"><table>
        <tr><th>Konto</th><th>Rolle</th><th>Anmeldung</th><th>Gruppen</th><th>Links</th><th>Seit</th><th></th></tr>
        <?php foreach ($users as $name => $u):
            $src = $u['auth'] ?? 'local';
            $mine = user_groups((string)$name);
        ?>
        <tr>
            <td>
                <strong><?= e(user_display((string)$name)) ?></strong><?= $name === $user['name'] ? ' <span class="muted">(du)</span>' : '' ?>
                <?php if (user_has_display((string)$name)): ?>
                <br><span class="muted small" style="font-family:var(--mono)" title="<?= e((string)$name) ?>"><?= e(mb_strimwidth((string)$name, 0, 38, '…')) ?></span>
                <?php endif; ?>
            </td>
            <td><?= e($u['role']) ?></td>
            <td><span class="tag"><?= e(match ($src) {
                'ldap' => 'LDAP', 'sso' => 'SSO', default => 'lokal',
            }) ?></span></td>
            <td class="small">
                <?php if ($mine === []): ?><span class="muted">–</span>
                <?php else: foreach ($mine as $g): $bis = user_group_until((string)$name, $g); ?>
                    <span class="tag tag-on" <?= $bis !== null ? 'title="befristet bis ' . e(date('d.m.Y', strtotime($bis))) . '"' : '' ?>>
                        <?= e(group_label($g)) ?><?= $bis !== null ? ' ⏱' : '' ?></span>
                <?php endforeach; endif; ?>
            </td>
            <td><?= link_count((string)$name) ?><span class="muted">/<?= e(limit_label(user_limit((string)$name, 'links'))) ?></span></td>
            <td><?= e(date('d.m.Y', strtotime($u['created']))) ?></td>
            <td class="actions">
                <?php if ($groups !== []): ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="groups">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <?php foreach ($groups as $gid => $g): ?>
                    <label class="check" title="<?= e($g['name']) ?>">
                        <input type="checkbox" name="groups[]" value="<?= e((string)$gid) ?>"
                            <?= in_array((string)$gid, $mine, true) ? ' checked' : '' ?>>
                        <?= e(mb_strimwidth($g['name'], 0, 14, '…')) ?>
                    </label>
                    <?php endforeach; ?>
                    <input type="date" name="until" min="<?= e(date('Y-m-d')) ?>"
                           title="Mitgliedschaft befristen (leer = unbefristet)">
                    <button class="btn btn-small" type="submit">Gruppen setzen</button>
                </form>
                <?php endif; ?>
                <?php if ($name !== $user['name']): ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="role">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                    <button class="btn btn-small" type="submit">→ <?= $u['role'] === 'admin' ? 'user' : 'admin' ?></button>
                </form>
                <?php endif; ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="display">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <input type="text" name="display_name" maxlength="80" placeholder="Anzeigename"
                           value="<?= e(user_has_display((string)$name) ? user_display((string)$name) : '') ?>">
                    <button class="btn btn-small" type="submit">Setzen</button>
                </form>
                <?php if ($src === 'local'): ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <input type="password" name="password" placeholder="Neues Passwort" minlength="8" required autocomplete="new-password">
                    <button class="btn btn-small" type="submit">Setzen</button>
                </form>
                <?php endif; ?>
                <?php if ($name !== $user['name']): ?>
                <form method="post" action="" class="inline" onsubmit="return confirm('Nutzer „<?= e((string)$name) ?>“ wirklich löschen?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="username" value="<?= e((string)$name) ?>">
                    <button class="btn btn-small btn-danger" type="submit">Löschen</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php if ($groups === []): ?>
    <p class="muted small">Es gibt noch keine Gruppen – <a href="groups.php">hier anlegen</a>.
    Ohne Gruppen sieht jedes Konto nur seine eigenen Links.</p>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
