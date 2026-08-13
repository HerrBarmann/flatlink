<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/safety.php';
require_once __DIR__ . '/../inc/groups.php';

$user = auth_require();
$isAdmin = $user['role'] === 'admin';
// Kontingent für Wunsch-Codes; 0 in der Konfiguration = unbegrenzt
$codeQuota = (int)cfg('custom_code_quota');
$myGroups = user_groups($user['name']);
// Namensraum-Präfixe: leer = frei, sonst darf nur darunter angelegt werden
$myPrefixes = $isAdmin ? [] : user_prefixes($user['name']);
// Zuweisbar sind die eigenen Gruppen; Admins dürfen jeder Gruppe zuordnen
$assignable = $isAdmin ? array_keys(groups_all()) : $myGroups;
$mayCustom = user_can($user['name'], 'custom_code');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $url = trim((string)($_POST['url'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        // Gewählter Namensraum; ohne Beschränkung bleibt er leer
        $prefix = trim((string)($_POST['prefix'] ?? ''));
        if ($myPrefixes === []) {
            $prefix = '';
        } elseif (!in_array($prefix, $myPrefixes, true)) {
            $prefix = $myPrefixes[0];
        }
        $group = trim((string)($_POST['group'] ?? ''));
        $group = $group === '' ? null : $group;
        [$expOk, $expires] = parse_expiry((string)($_POST['expires'] ?? ''));
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && $url !== '') {
            $url = 'https://' . $url;
        }
        if (!valid_url($url)) {
            flash('Ungültige Ziel-URL (nur http/https).', 'err');
        } elseif (!$isAdmin && link_count($user['name']) >= user_limit($user['name'], 'links')) {
            flash('Limit erreicht: ' . user_limit($user['name'], 'links') . ' aktive Links. Lösche zuerst alte Links.', 'err');
        } elseif ($code !== '' && !$mayCustom) {
            flash('Für Wunsch-Namen fehlt deinem Konto die Berechtigung – frag einen Administrator.', 'err');
        } elseif ($group !== null && !in_array($group, $assignable, true)) {
            flash('Diese Gruppe steht dir nicht zur Verfügung.', 'err');
        } elseif ($code !== '' && !$isAdmin && mb_strlen($code) < (int)cfg('custom_code_min_len')) {
            flash('Wunsch-Codes brauchen mindestens ' . (int)cfg('custom_code_min_len') . ' Zeichen – die kürzeren sind reserviert.', 'err');
        } elseif ($code !== '' && !$isAdmin && $codeQuota > 0 && custom_code_count($user['name']) >= $codeQuota) {
            flash('Kontingent erreicht: maximal ' . $codeQuota . ' aktive Wunsch-Codes pro Konto. Lösche zuerst einen bestehenden.', 'err');
        } elseif ($code !== '' && !valid_code($code)) {
            flash('Ungültiger Wunsch-Name: 1–64 Zeichen (a-z, A-Z, 0-9, _ und -), nicht reserviert.', 'err');
        } elseif ($code !== '' && $prefix === '' && in_array(strtolower($code), all_prefixes(), true)) {
            flash('Dieser Name ist als Namensraum vergeben.', 'err');
        } elseif (!$expOk) {
            flash('Ungültiges Ablaufdatum (frühestens heute).', 'err');
        } elseif (url_flagged($url)) {
            flash('Diese Ziel-URL ist als schädlich gemeldet und kann nicht verkürzt werden.', 'err');
        } else {
            // Wunsch-Name unter das Präfix hängen; Zufallscodes erledigt link_create
            $full = $code === '' ? null : ($prefix === '' ? $code : $prefix . '/' . $code);
            [$ok, $result] = link_create($url, $full, $user['name'], $code === '' ? 'random' : 'custom', $prefix, $expires, $group);
            if ($ok) {
                $linkpass = (string)($_POST['linkpass'] ?? '');
                if ($linkpass !== '') {
                    link_set_password($result, password_hash($linkpass, PASSWORD_DEFAULT));
                }
                flash('Kurzlink ' . short_url($result) . ' angelegt'
                    . ($group !== null ? ' für Gruppe „' . group_label($group) . '“' : '')
                    . ($linkpass !== '' ? ' (passwortgeschützt)' : '') . '.');
                redirect_to('index.php?hl=' . urlencode($result));
            }
            flash($result, 'err');
        }
    } elseif ($action === 'update') {
        $code = (string)($_POST['code'] ?? '');
        $url = trim((string)($_POST['url'] ?? ''));
        $link = link_get($code);
        $rawExp = trim((string)($_POST['expires'] ?? ''));
        if ($link !== null && $rawExp === (string)($link['expires'] ?? '')) {
            // Unverändertes Datum durchlassen (sonst wäre ein abgelaufener Link nicht mehr editierbar)
            [$expOk, $expires] = [true, $link['expires'] ?? null];
        } else {
            [$expOk, $expires] = parse_expiry($rawExp);
        }
        $group = trim((string)($_POST['group'] ?? ''));
        $group = $group === '' ? null : $group;
        if ($link === null || !link_access($user, $link)) {
            flash('Kein Zugriff auf diesen Link.', 'err');
        } elseif ($group !== null && !in_array($group, $assignable, true)) {
            flash('Diese Gruppe steht dir nicht zur Verfügung.', 'err');
        } elseif (!valid_url($url)) {
            flash('Ungültige Ziel-URL (nur http/https).', 'err');
        } elseif (!$expOk) {
            flash('Ungültiges Ablaufdatum (frühestens heute, leer = kein Ablauf).', 'err');
        } else {
            link_update($code, $url, $expires);
            // Gruppe nur anfassen, wenn das Formular sie überhaupt anbieten konnte
            if ($assignable !== [] || ($link['group'] ?? null) !== null) {
                link_set_group($code, $group);
            }
            if (($_POST['linkpass_remove'] ?? '') === '1') {
                link_set_password($code, null);
            } elseif (($linkpass = (string)($_POST['linkpass'] ?? '')) !== '') {
                link_set_password($code, password_hash($linkpass, PASSWORD_DEFAULT));
            }
            flash('Kurzlink ' . $code . ' aktualisiert.');
        }
    } elseif ($action === 'delete') {
        $code = (string)($_POST['code'] ?? '');
        $link = link_get($code);
        if ($link === null || !link_access($user, $link)) {
            flash('Kein Zugriff auf diesen Link.', 'err');
        } else {
            link_delete($code);
            flash('Kurzlink ' . $code . ' gelöscht.');
        }
    }
    redirect_to('index.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$highlight = (string)($_GET['hl'] ?? '');
$gFilter = (string)($_GET['g'] ?? '');
$links = links_visible($user);
if ($gFilter === '-') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === null);
} elseif ($gFilter !== '') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === $gFilter);
}
if ($q !== '') {
    $links = array_filter($links, fn($l, $c) => stripos($c, $q) !== false || stripos($l['url'], $q) !== false, ARRAY_FILTER_USE_BOTH);
}
uasort($links, fn($a, $b) => strcmp($b['created'], $a['created']));

$editCode = (string)($_GET['edit'] ?? '');
$editLink = $editCode !== '' ? link_get($editCode) : null;
if ($editLink !== null && !link_access($user, $editLink)) $editLink = null;

page_header('Links', true);
show_flash();
?>

<div class="card">
    <div class="list-head">
        <h2>Neuer Kurzlink</h2>
        <?php if (user_can($user['name'], 'csv_import')): ?>
        <a class="btn btn-small" href="import.php">CSV-Import</a>
        <?php endif; ?>
    </div>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div>
            <label for="c-url">Ziel-URL</label>
            <input id="c-url" type="text" name="url" placeholder="https://example.com/…" required>
        </div>
        <?php if ($isAdmin): ?>
        <div>
            <label for="c-code">Wunsch-Name <span class="muted">(leer = zufällig, Admin: ohne Limits)</span></label>
            <div class="short-row">
                <span class="prefix"><?= e(preg_replace('#^https?://#', '', base_url())) ?>/</span>
                <input id="c-code" type="text" name="code" placeholder="wunschname" pattern="[A-Za-z0-9_-]{1,64}">
            </div>
        </div>
        <?php elseif ($mayCustom): ?>
        <div>
            <?php $used = custom_code_count($user['name']); ?>
            <label for="c-code">Wunsch-Name <span class="muted">(leer = zufällig · mind. <?= (int)cfg('custom_code_min_len') ?> Zeichen<?= $codeQuota > 0 ? ' · ' . $used . '/' . $codeQuota . ' belegt' : '' ?>)</span></label>
            <div class="short-row">
                <span class="prefix"><?= e(preg_replace('#^https?://#', '', base_url())) ?>/</span>
                <?php if (count($myPrefixes) === 1): ?>
                    <span class="prefix"><?= e($myPrefixes[0]) ?>/</span>
                    <input type="hidden" name="prefix" value="<?= e($myPrefixes[0]) ?>">
                <?php elseif (count($myPrefixes) > 1): ?>
                    <select name="prefix" style="flex:0 0 auto;width:auto">
                        <?php foreach ($myPrefixes as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?>/</option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <input id="c-code" type="text" name="code" placeholder="wunschname" pattern="[A-Za-z0-9_-]{<?= (int)cfg('custom_code_min_len') ?>,64}">
            </div>
            <?php if ($myPrefixes !== []): ?>
            <p class="muted small">Deine Links liegen im Namensraum
                <?= e(implode(' bzw. ', array_map(fn($p) => $p . '/', $myPrefixes))) ?> –
                so kommen sich die Bereiche nicht in die Quere.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!$mayCustom && !$isAdmin && $myPrefixes !== []): ?>
        <div>
            <label>Namensraum</label>
            <?php if (count($myPrefixes) === 1): ?>
                <p class="muted small" style="margin:0">Deine Links entstehen unter
                <code><?= e(preg_replace('#^https?://#', '', base_url())) ?>/<?= e($myPrefixes[0]) ?>/…</code></p>
                <input type="hidden" name="prefix" value="<?= e($myPrefixes[0]) ?>">
            <?php else: ?>
                <select name="prefix">
                    <?php foreach ($myPrefixes as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?>/…</option><?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($assignable !== []): ?>
        <div>
            <label for="c-group">Gruppe <span class="muted">(optional – alle Mitglieder der Gruppe können den Link verwalten)</span></label>
            <select id="c-group" name="group">
                <option value="">– keine, nur für dich –</option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="c-expires">Ablaufdatum <span class="muted">(optional, gültig bis einschließlich)</span></label>
            <input id="c-expires" type="date" name="expires" min="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
            <label for="c-linkpass">Passwortschutz <span class="muted">(optional – Besucher müssen es vor der Weiterleitung eingeben)</span></label>
            <input id="c-linkpass" type="text" name="linkpass" autocomplete="off" placeholder="leer = kein Schutz">
        </div>
        <button class="btn btn-primary" type="submit">Anlegen</button>
    </form>
</div>

<?php if ($editLink !== null): ?>
<div class="card highlight">
    <h2>„<?= e($editCode) ?>“ bearbeiten</h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="code" value="<?= e($editCode) ?>">
        <div>
            <label for="e-url">Ziel-URL</label>
            <input id="e-url" type="text" name="url" value="<?= e($editLink['url']) ?>" required>
        </div>
        <div>
            <label for="e-expires">Ablaufdatum <span class="muted">(leer = kein Ablauf)</span></label>
            <input id="e-expires" type="date" name="expires" value="<?= e($editLink['expires'] ?? '') ?>">
        </div>
        <?php if ($assignable !== [] || ($editLink['group'] ?? null) !== null): ?>
        <div>
            <label for="e-group">Gruppe</label>
            <select id="e-group" name="group">
                <option value="">– keine, nur für dich –</option>
                <?php
                $opts = $assignable;
                // Eine bestehende Zuordnung bleibt wählbar, auch wenn die Gruppe
                // nicht mehr zu den eigenen gehört – sonst ginge sie beim
                // Speichern still verloren
                $cur = $editLink['group'] ?? null;
                if ($cur !== null && !in_array($cur, $opts, true)) $opts[] = $cur;
                foreach ($opts as $gid): ?>
                <option value="<?= e($gid) ?>"<?= $cur === $gid ? ' selected' : '' ?>><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="e-linkpass">Passwortschutz
                <span class="muted"><?= !empty($editLink['pass']) ? '(aktiv – neues Passwort setzen oder entfernen)' : '(optional)' ?></span></label>
            <input id="e-linkpass" type="text" name="linkpass" autocomplete="off" placeholder="leer = unverändert">
            <?php if (!empty($editLink['pass'])): ?>
            <label class="radio" style="margin-top:0.4rem">
                <input type="checkbox" name="linkpass_remove" value="1">
                <span>Passwortschutz entfernen</span>
            </label>
            <?php endif; ?>
        </div>
        <div class="short-row">
            <button class="btn btn-primary" type="submit">Speichern</button>
            <a class="btn" href="index.php">Abbrechen</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="list-head">
        <h2><?= $isAdmin ? 'Alle Links' : ($myGroups === [] ? 'Deine Links' : 'Deine und Gruppen-Links') ?> <span class="muted">(<?= count($links) ?>)</span></h2>
        <form method="get" action="" class="short-row">
            <?php if ($assignable !== []): ?>
            <select name="g" onchange="this.form.submit()">
                <option value="">alle Gruppen</option>
                <option value="-"<?= $gFilter === '-' ? ' selected' : '' ?>>ohne Gruppe</option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"<?= $gFilter === $gid ? ' selected' : '' ?>><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Suchen…">
            <button class="btn btn-small" type="submit">Suchen</button>
        </form>
    </div>
    <?php if ($links === []): ?>
        <p class="muted">Noch keine Links<?= $q !== '' ? ' für diese Suche' : '' ?>.</p>
    <?php else: ?>
    <div class="table-scroll"><table>
        <tr><th>Code</th><th>Ziel</th><th>Klicks</th><th>Gruppe</th><?php if ($isAdmin): ?><th>Besitzer</th><?php endif; ?><th>Läuft ab</th><th>Erstellt</th><th></th></tr>
        <?php foreach ($links as $code => $link): $clicks = clicks_get((string)$code); ?>
        <tr<?= $code === $highlight ? ' class="row-hl"' : '' ?>>
            <td><a href="<?= e(short_url((string)$code)) ?>" target="_blank" rel="noopener"><?= e((string)$code) ?></a><?=
                !empty($link['pass']) ? ' <span class="badge badge-quiet" title="passwortgeschützt">PW</span>' : '' ?></td>
            <td class="url-cell" title="<?= e($link['url']) ?>"><?= e(mb_strimwidth($link['url'], 0, 60, '…')) ?></td>
            <td><a href="stats.php?c=<?= e(rawurlencode((string)$code)) ?>" title="Statistik"><?= (int)$clicks['n'] ?></a></td>
            <td><?php
                $g = $link['group'] ?? null;
                echo $g === null
                    ? '<span class="muted">–</span>'
                    : '<span class="tag tag-on">' . e(group_label($g)) . '</span>';
            ?></td>
            <?php if ($isAdmin): ?><td><?php
                $o = $link['owner'] ?? null;
                echo $o === null
                    ? '<span class="muted">–</span>'
                    : '<span title="' . e($o) . '">' . e(user_display($o)) . '</span>';
            ?></td><?php endif; ?>
            <td><?php
                if (($link['expires'] ?? null) === null) {
                    echo '<span class="muted">–</span>';
                } elseif (link_expired($link)) {
                    echo '<span class="badge badge-expired" title="seit ' . e(date('d.m.Y', strtotime($link['expires']))) . '">abgelaufen</span>';
                } else {
                    echo e(date('d.m.Y', strtotime($link['expires'])));
                }
            ?></td>
            <td><?= e(date('d.m.Y', strtotime($link['created']))) ?></td>
            <td class="actions">
                <a class="btn btn-small" href="qrdesign.php?c=<?= e(rawurlencode((string)$code)) ?>">QR</a>
                <a class="btn btn-small" href="index.php?edit=<?= e(rawurlencode((string)$code)) ?>">Bearbeiten</a>
                <form method="post" action="" class="inline" onsubmit="return confirm('Kurzlink „<?= e((string)$code) ?>“ wirklich löschen?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="code" value="<?= e((string)$code) ?>">
                    <button class="btn btn-small btn-danger" type="submit">Löschen</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
