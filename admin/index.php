<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/safety.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/linkrules.php';
require_once __DIR__ . '/../inc/domains.php';
require_once __DIR__ . '/../inc/utm.php';

$user = auth_require();
$isAdmin = $user['role'] === 'admin';
// Kontingent für Wunsch-Codes; 0 in der Konfiguration = unbegrenzt
$codeQuota = (int)settings()['custom_code_quota'];
$myGroups = user_groups($user['name']);
// Namensraum-Präfixe: leer = frei, sonst darf nur darunter angelegt werden
$myPrefixes = $isAdmin ? [] : user_prefixes($user['name']);
// Zuweisbar sind die eigenen Gruppen; Admins dürfen jeder Gruppe zuordnen
// Zuordnen lassen sich nur Arbeitsgruppen – eine Rechtegruppe wie ein Tarif
// hat mit der Verwaltung eines einzelnen Links nichts zu tun.
$assignable = $isAdmin ? array_values(array_filter(array_keys(groups_all()), 'group_shared')) : user_shared_groups($user['name']);
$mayCustom = user_can($user['name'], 'custom_code');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        // Sämtliche Regeln stehen in inc/linkrules.php – dieselbe Fassung, die
        // auch die API benutzt.
        [$err, $full, $opts] = link_rules_create($user, [
            'url' => (string)($_POST['url'] ?? ''),
            'code' => (string)($_POST['code'] ?? ''),
            'prefix' => (string)($_POST['prefix'] ?? ''),
            'group' => (string)($_POST['group'] ?? ''),
            'expires' => (string)($_POST['expires'] ?? ''),
            'title' => (string)($_POST['title'] ?? ''),
            'tags' => (string)($_POST['tags'] ?? ''),
            'domain' => (string)($_POST['domain'] ?? ''),
            'utm' => (array)($_POST['utm'] ?? []),
        ]);
        if ($err !== null) {
            flash($err, 'err');
        } else {
            $group = $opts['group'];
            [$ok, $result] = link_create($opts['url'], $full, $user['name'],
                $full === null ? 'random' : 'custom', $opts);
            if ($ok) {
                $linkpass = (string)($_POST['linkpass'] ?? '');
                if ($linkpass !== '') {
                    link_set_password($result, password_hash($linkpass, PASSWORD_DEFAULT));
                }
                flash(t('Kurzlink %s angelegt', short_url($result, (string)($opts['domain'] ?? '')))
                    . ($group !== null ? ' ' . t('für Gruppe „%s“', group_label($group)) : '')
                    . ($linkpass !== '' ? ' ' . t('(passwortgeschützt)') : '') . '.');
                redirect_to('index.php?hl=' . urlencode($result));
            }
            flash($result, 'err');
        }
    } elseif ($action === 'update') {
        $code = (string)($_POST['code'] ?? '');
        $link = link_get($code);
        $err = ($link === null || !link_access($user, $link)) ? t('Kein Zugriff auf diesen Link.') : null;
        $opts = [];
        if ($err === null) {
            [$err, $opts] = link_rules_update($user, $link, [
                'url' => (string)($_POST['url'] ?? ''),
                'expires' => (string)($_POST['expires'] ?? ''),
                'group' => (string)($_POST['group'] ?? ''),
                'title' => (string)($_POST['title'] ?? ''),
                'tags' => (string)($_POST['tags'] ?? ''),
                'domain' => (string)($_POST['domain'] ?? ''),
                'utm' => (array)($_POST['utm'] ?? []),
            ]);
        }
        if ($err !== null) {
            flash($err, 'err');
        } else {
            link_update($code, $opts['url'], $opts);
            // Gruppe nur anfassen, wenn das Formular sie überhaupt anbieten konnte
            if ($assignable !== [] || ($link['group'] ?? null) !== null) {
                link_set_group($code, $opts['group']);
            }
            if (($_POST['linkpass_remove'] ?? '') === '1') {
                link_set_password($code, null);
            } elseif (($linkpass = (string)($_POST['linkpass'] ?? '')) !== '') {
                link_set_password($code, password_hash($linkpass, PASSWORD_DEFAULT));
            }
            flash(t('Kurzlink %s aktualisiert.', $code));
        }
    } elseif ($action === 'delete') {
        $code = (string)($_POST['code'] ?? '');
        $link = link_get($code);
        if ($link === null || !link_access($user, $link)) {
            flash(t('Kein Zugriff auf diesen Link.'), 'err');
        } else {
            link_delete($code);
            flash(t('Kurzlink %s gelöscht.', $code));
        }
    }
    redirect_to('index.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$highlight = (string)($_GET['hl'] ?? '');
$gFilter = (string)($_GET['g'] ?? '');
$links = links_visible($user);
// Vorschläge für den Kampagnen-Baukasten aus dem Gesamtbestand, nicht aus dem
// gefilterten Ausschnitt: Sie sollen beim Anlegen vollständig sein, egal
// welcher Filter gerade in der Liste steht.
$utmVorschlaege = utm_suggestions($links);
if ($gFilter === '-') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === null);
} elseif ($gFilter !== '') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === $gFilter);
}
// Schlagwort-Filter vor der Suche: Die Wolke unter der Liste soll die Zahlen
// des gerade gewählten Ausschnitts zeigen, nicht die des Gesamtbestands.
$alleTags = tags_counts($links);
$tagFilter = mb_strtolower(trim((string)($_GET['tag'] ?? '')));
if ($tagFilter !== '') {
    $links = array_filter($links, fn($l) => in_array($tagFilter, (array)($l['tags'] ?? []), true));
}
if ($q !== '') {
    $links = array_filter($links, fn($l, $c) => stripos($c, $q) !== false
        || stripos($l['url'], $q) !== false
        || stripos((string)($l['title'] ?? ''), $q) !== false
        || in_array(mb_strtolower($q), (array)($l['tags'] ?? []), true), ARRAY_FILTER_USE_BOTH);
}
uasort($links, fn($a, $b) => strcmp($b['created'], $a['created']));

$editCode = (string)($_GET['edit'] ?? '');
$editLink = $editCode !== '' ? link_get($editCode) : null;
if ($editLink !== null && !link_access($user, $editLink)) $editLink = null;

page_header(t('Links'), true);
show_flash();
?>

<div class="card">
    <div class="list-head">
        <h2><?= t('Neuer Kurzlink') ?></h2>
        <a class="btn btn-small" href="import.php"><?= t('CSV-Import') ?></a>
    </div>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div>
            <label for="c-url"><?= t('Ziel-URL') ?></label>
            <input id="c-url" type="text" name="url" placeholder="https://example.com/…" required>
            <label for="c-title"><?= t('Name') ?> <span class="muted">(<?= t('optional – nur für dich, damit du den Link in der Liste wiederfindest') ?>)</span></label>
            <input id="c-title" type="text" name="title" maxlength="120" placeholder="<?= t('z. B. Speisekarte Sommer') ?>">
            <label for="c-tags"><?= t('Schlagworte') ?> <span class="muted">(<?= t('optional, mit Komma trennen – zum Filtern der Liste') ?>)</span></label>
            <input id="c-tags" type="text" name="tags" maxlength="220" placeholder="<?= t('kampagne, sommer, plakat') ?>">
        </div>
        <?= utm_form('c', [], $utmVorschlaege) ?>
        <?php $meineDomains = domains_for($user['name']); if (count($meineDomains) > 1): ?>
        <div>
            <label for="c-domain"><?= t('Domain') ?> <span class="muted">(<?= t('unter welcher Adresse der Link steht') ?>)</span></label>
            <select id="c-domain" name="domain">
                <?php foreach ($meineDomains as $d): ?>
                <option value="<?= e($d) ?>"><?= e($d) ?><?= $d === domain_main() ? ' ' . t('(Standard)') : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <div>
            <label for="c-code"><?= t('Wunsch-Name') ?> <span class="muted">(<?= t('leer = zufällig, Admin: ohne Limits') ?>)</span></label>
            <div class="short-row">
                <span class="prefix"><?= e(preg_replace('#^https?://#', '', base_url())) ?>/</span>
                <input id="c-code" type="text" name="code" placeholder="<?= t('wunschname') ?>" pattern="[A-Za-z0-9_-]{1,64}">
            </div>
        </div>
        <?php elseif ($mayCustom): ?>
        <div>
            <?php $used = custom_code_count($user['name']); ?>
            <label for="c-code"><?= t('Wunsch-Name') ?> <span class="muted">(<?= t('leer = zufällig · mind. %d Zeichen', (int)settings()['custom_code_min_len']) ?><?= $codeQuota > 0 ? ' · ' . t('%1$d/%2$d belegt', $used, $codeQuota) : '' ?>)</span></label>
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
                <input id="c-code" type="text" name="code" placeholder="<?= t('wunschname') ?>" pattern="[A-Za-z0-9_-]{<?= (int)settings()['custom_code_min_len'] ?>,64}">
            </div>
            <?php if ($myPrefixes !== []): ?>
            <p class="muted small"><?= t('Deine Links liegen im Namensraum %s – so kommen sich die Bereiche nicht in die Quere.',
                e(implode(t(' bzw. '), array_map(fn($p) => $p . '/', $myPrefixes)))) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!$mayCustom && !$isAdmin && $myPrefixes !== []): ?>
        <div>
            <label><?= t('Namensraum') ?></label>
            <?php if (count($myPrefixes) === 1): ?>
                <p class="muted small" style="margin:0"><?= t('Deine Links entstehen unter %s',
                '<code>' . e(preg_replace('#^https?://#', '', base_url())) . '/' . e($myPrefixes[0]) . '/…</code>') ?></p>
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
            <label for="c-group"><?= t('Gruppe') ?> <span class="muted">(<?= t('optional – alle Mitglieder der Gruppe können den Link verwalten') ?>)</span></label>
            <select id="c-group" name="group">
                <option value="">– <?= t('keine, nur für dich') ?> –</option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="c-expires"><?= t('Ablaufdatum') ?> <span class="muted">(<?= t('optional, gültig bis einschließlich') ?>)</span></label>
            <input id="c-expires" type="date" name="expires" min="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
            <label for="c-linkpass"><?= t('Passwortschutz') ?> <span class="muted">(<?= t('optional – Besucher müssen es vor der Weiterleitung eingeben') ?>)</span></label>
            <input id="c-linkpass" type="text" name="linkpass" autocomplete="off" placeholder="<?= t('leer = kein Schutz') ?>">
        </div>
        <button class="btn btn-primary" type="submit"><?= t('Anlegen') ?></button>
    </form>
</div>

<?php if ($editLink !== null): ?>
<div class="card highlight">
    <h2><?= t('„%s“ bearbeiten', e($editCode)) ?></h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="code" value="<?= e($editCode) ?>">
        <div>
            <label for="e-url"><?= t('Ziel-URL') ?></label>
            <input id="e-url" type="text" name="url" value="<?= e($editLink['url']) ?>" required>
            <label for="e-title"><?= t('Name') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
            <input id="e-title" type="text" name="title" maxlength="120" value="<?= e((string)($editLink['title'] ?? '')) ?>">
            <label for="e-tags"><?= t('Schlagworte') ?> <span class="muted">(<?= t('mit Komma trennen') ?>)</span></label>
            <input id="e-tags" type="text" name="tags" maxlength="220" value="<?= e(tags_text($editLink)) ?>">
            <?= utm_form('e', utm_extract((string)($editLink['url'] ?? '')), $utmVorschlaege) ?>
            <?php
            $meineDomains = domains_for($user['name']);
            $istDomain = (string)($editLink['domain'] ?? '');
            // Eine bereits gesetzte Domain gehört in die Liste, auch wenn das
            // Konto sie heute nicht mehr wählen dürfte – sonst verschwände sie
            // beim ersten Speichern, und der gedruckte Code zeigte ins Leere.
            if ($istDomain !== '' && !in_array($istDomain, $meineDomains, true)) $meineDomains[] = $istDomain;
            ?>
            <?php if (count($meineDomains) > 1): ?>
            <label for="e-domain"><?= t('Domain') ?></label>
            <select id="e-domain" name="domain">
                <?php foreach ($meineDomains as $d): ?>
                <option value="<?= e($d) ?>"<?= $d === ($istDomain === '' ? domain_main() : $istDomain) ? ' selected' : '' ?>><?= e($d) ?><?= $d === domain_main() ? ' ' . t('(Standard)') : '' ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted small"><?= t('Achtung: Ein bereits gedruckter QR-Code zeigt weiterhin auf die alte Adresse. Ändere sie nur, solange der Link noch nicht im Umlauf ist.') ?></p>
            <?php endif; ?>
        </div>
        <div>
            <label for="e-expires"><?= t('Ablaufdatum') ?> <span class="muted">(<?= t('leer = kein Ablauf') ?>)</span></label>
            <input id="e-expires" type="date" name="expires" value="<?= e($editLink['expires'] ?? '') ?>">
        </div>
        <?php if ($assignable !== [] || ($editLink['group'] ?? null) !== null): ?>
        <div>
            <label for="e-group"><?= t('Gruppe') ?></label>
            <select id="e-group" name="group">
                <option value="">– <?= t('keine, nur für dich') ?> –</option>
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
            <label for="e-linkpass"><?= t('Passwortschutz') ?>
                <span class="muted"><?= !empty($editLink['pass']) ? t('(aktiv – neues Passwort setzen oder entfernen)') : '(' . t('optional') . ')' ?></span></label>
            <input id="e-linkpass" type="text" name="linkpass" autocomplete="off" placeholder="<?= t('leer = unverändert') ?>">
            <?php if (!empty($editLink['pass'])): ?>
            <label class="radio" style="margin-top:0.4rem">
                <input type="checkbox" name="linkpass_remove" value="1">
                <span><?= t('Passwortschutz entfernen') ?></span>
            </label>
            <?php endif; ?>
        </div>
        <div class="short-row">
            <button class="btn btn-primary" type="submit"><?= t('Speichern') ?></button>
            <a class="btn" href="index.php"><?= t('Abbrechen') ?></a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="list-head">
        <h2><?= $isAdmin ? t('Alle Links') : ($myGroups === [] ? t('Deine Links') : t('Deine und Gruppen-Links')) ?> <span class="muted">(<?= count($links) ?>)</span></h2>
        <form method="get" action="" class="short-row">
            <?php if ($assignable !== []): ?>
            <select name="g" data-autosubmit>
                <option value=""><?= t('alle Gruppen') ?></option>
                <option value="-"<?= $gFilter === '-' ? ' selected' : '' ?>><?= t('ohne Gruppe') ?></option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"<?= $gFilter === $gid ? ' selected' : '' ?>><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('Suchen…') ?>">
            <?php if ($tagFilter !== ''): ?><input type="hidden" name="tag" value="<?= e($tagFilter) ?>"><?php endif; ?>
            <button class="btn btn-small" type="submit"><?= t('Suchen') ?></button>
        </form>
    </div>
    <?php if ($alleTags !== []): ?>
    <p class="tag-row" style="margin:0 0 0.9rem">
        <?php if ($tagFilter !== ''): ?>
            <a class="tag" href="index.php<?= $q !== '' ? '?q=' . e(rawurlencode($q)) : '' ?>"><?= t('alle anzeigen') ?></a>
        <?php endif; ?>
        <?php foreach ($alleTags as $t => $n): ?>
        <a class="tag<?= $t === $tagFilter ? ' tag-on' : '' ?>"
           href="index.php?tag=<?= e(rawurlencode((string)$t)) ?><?= $q !== '' ? '&amp;q=' . e(rawurlencode($q)) : '' ?>"><?= e((string)$t) ?>
           <span class="muted"><?= (int)$n ?></span></a>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <?php if ($links === []): ?>
        <p class="muted"><?= t('Noch keine Links') ?><?= $q !== '' ? ' ' . t('für diese Suche') : '' ?><?= $tagFilter !== '' ? ' ' . t('mit diesem Schlagwort') : '' ?>.</p>
    <?php else: ?>
    <?php
    // Der Filter wird mitgenommen: Wer nach einem Schlagwort gefiltert hat und
    // dann eine Serie zieht, meint genau diese Links.
    $serieFilter = array_filter(['g' => $gFilter, 'tag' => $tagFilter, 'q' => $q], fn($v) => $v !== '');
    ?>
    <p class="muted small" style="margin:0 0 0.5rem">
        <a class="btn btn-small" href="qrzip.php<?= $serieFilter !== [] ? '?' . e(http_build_query($serieFilter)) : '' ?>"><?= t('QR-Codes dieser %d Links als ZIP', count($links)) ?></a>
    </p>
    <div class="table-scroll"><table>
        <tr><th><?= t('Link') ?></th><th><?= t('Ziel') ?></th><th><?= t('Klicks') ?></th><th><?= t('Gruppe') ?></th><?php if ($isAdmin): ?><th><?= t('Besitzer') ?></th><?php endif; ?><th><?= t('Läuft ab') ?></th><th><?= t('Erstellt') ?></th><th></th></tr>
        <?php foreach ($links as $code => $link): $clicks = clicks_get((string)$code); ?>
        <tr<?= $code === $highlight ? ' class="row-hl"' : '' ?>>
            <td><a href="<?= e(short_url((string)$code, (string)($link['domain'] ?? ''))) ?>" target="_blank" rel="noopener"><?= e((string)$code) ?></a><?=
                ($link['domain'] ?? '') !== '' ? ' <span class="badge badge-quiet" title="' . e((string)$link['domain']) . '">' . e((string)$link['domain']) . '</span>' : '' ?><?=
                !empty($link['pass']) ? ' <span class="badge badge-quiet" title="' . t('passwortgeschützt') . '">PW</span>' : '' ?>
                <?php if (($link['title'] ?? '') !== ''): ?><br><span class="link-title"><?= e((string)$link['title']) ?></span><?php endif; ?>
                <?php $utm = utm_extract((string)($link['url'] ?? '')); if ($utm !== []): ?>
                <br><span class="badge badge-quiet" title="<?= t('Kampagne:') ?> <?= e(implode(' · ', $utm)) ?>">UTM<?=
                    isset($utm['utm_campaign']) ? ' ' . e($utm['utm_campaign']) : '' ?></span>
                <?php endif; ?>
                <?php if (($link['tags'] ?? []) !== []): ?>
                <br><span class="tag-row">
                    <?php foreach ((array)$link['tags'] as $t): ?>
                    <a class="tag<?= $t === $tagFilter ? ' tag-on' : '' ?>" href="index.php?tag=<?= e(rawurlencode($t)) ?>"><?= e($t) ?></a>
                    <?php endforeach; ?>
                </span>
                <?php endif; ?></td>
            <td class="url-cell" title="<?= e($link['url']) ?>"><?= e(mb_strimwidth($link['url'], 0, 60, '…')) ?></td>
            <td><a href="stats.php?c=<?= e(rawurlencode((string)$code)) ?>" title="<?= t('Statistik') ?>"><?= (int)$clicks['n'] ?></a></td>
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
                    echo '<span class="badge badge-expired" title="' . t('seit %s', e(date('d.m.Y', strtotime($link['expires'])))) . '">' . t('abgelaufen') . '</span>';
                } else {
                    echo e(date('d.m.Y', strtotime($link['expires'])));
                }
            ?></td>
            <td><?= e(date('d.m.Y', strtotime($link['created']))) ?></td>
            <td class="actions">
                <a class="btn btn-small" href="qrdesign.php?c=<?= e(rawurlencode((string)$code)) ?>">QR</a>
                <a class="btn btn-small" href="index.php?edit=<?= e(rawurlencode((string)$code)) ?>"><?= t('Bearbeiten') ?></a>
                <form method="post" action="" class="inline" data-confirm="<?= t('Kurzlink „%s“ wirklich löschen?', e((string)$code)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="code" value="<?= e((string)$code) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Löschen') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
