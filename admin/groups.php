<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';

$user = auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = strtolower(trim((string)($_POST['id'] ?? '')));

    if ($action === 'save') {
        $err = group_save($id, (string)($_POST['name'] ?? ''), (array)($_POST['perms'] ?? []),
            (array)($_POST['limits'] ?? []), (string)($_POST['prefix'] ?? ''),
            ($_POST['art'] ?? '') === 'shared');
        flash($err ?? t('Gruppe „%s“ gespeichert.', $id), $err === null ? 'ok' : 'err');
    } elseif ($action === 'delete') {
        if (group_get($id) === null) {
            flash(t('Diese Gruppe gibt es nicht.'), 'err');
        } else {
            $n = count(group_members($id));
            group_delete($id);
            flash(t('Gruppe „%s“ gelöscht', $id) . ($n > 0 ? ' ' . t('(%d Mitgliedschaften aufgehoben)', $n) : '')
                . '. ' . t('Die Links der Gruppe bleiben bei ihren Besitzern.'));
        }
    }
    redirect_to('groups.php');
}

$groups = groups_all();
ksort($groups);
$perms = perms_all();

// Links je Gruppe zählen, damit sichtbar ist, was an einer Gruppe hängt
$linkCount = [];
if (db() !== null || link_index_ready()) {
    foreach (array_keys($groups) as $gid) {
        $n = count(link_codes_of_group((string)$gid));
        if ($n > 0) $linkCount[(string)$gid] = $n;
    }
} else {
    foreach (links_all() as $l) {
        $g = $l['group'] ?? null;
        if (is_string($g)) $linkCount[$g] = ($linkCount[$g] ?? 0) + 1;
    }
}

$editId = (string)($_GET['edit'] ?? '');
$edit = $editId !== '' ? group_get($editId) : null;

page_header(t('Gruppen'), true);
show_flash();
?>

<div class="card">
    <h2><?= $edit !== null ? t('Gruppe bearbeiten') : t('Neue Gruppe') ?></h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div>
            <label for="g-id"><?= t('Kennung') ?> <span class="muted"><?= t('(kurz, klein, unveränderlich – z.&nbsp;B. marketing)') ?></span></label>
            <input id="g-id" type="text" name="id" required pattern="[a-z0-9._-]{2,32}"
                   value="<?= e($editId) ?>"<?= $edit !== null ? ' readonly' : '' ?>>
        </div>
        <div>
            <label for="g-name"><?= t('Anzeigename') ?></label>
            <input id="g-name" type="text" name="name" required maxlength="64"
                   value="<?= e($edit['name'] ?? '') ?>" placeholder="Marketing">
        </div>
        <div>
            <label><?= t('Art der Gruppe') ?></label>
            <?php
            // Beim Bearbeiten gilt der gespeicherte Wert; Bestandsgruppen ohne
            // Angabe sind Arbeitsgruppen (siehe group_shared). Beim Anlegen ist
            // die engere Variante vorausgewählt.
            $istGeteilt = $edit !== null ? (bool)($edit['shared'] ?? true) : false;
            ?>
            <div class="radio-group">
                <label class="radio">
                    <input type="radio" name="art" value="perms"<?= $istGeteilt ? '' : ' checked' ?>>
                    <span><strong><?= t('Nur Rechte') ?></strong><br>
                    <span class="muted small"><?= t('Die Mitglieder bekommen die unten gewählten Berechtigungen und Limits. Ihre Links bleiben privat. Richtig für Tarife, Rollen und Kontingente.') ?></span></span>
                </label>
                <label class="radio">
                    <input type="radio" name="art" value="shared"<?= $istGeteilt ? ' checked' : '' ?>>
                    <span><strong><?= t('Rechte und gemeinsame Linkverwaltung') ?></strong><br>
                    <span class="muted small"><?= t('Zusätzlich lassen sich Links dieser Gruppe zuordnen; %sjedes Mitglied kann sie dann sehen, ändern und löschen%s. Richtig für Teams, die zusammenarbeiten – nicht für Tarife.', '<strong>', '</strong>') ?></span></span>
                </label>
            </div>
        </div>

        <div>
            <label><?= t('Rechte der Mitglieder') ?></label>
            <?php foreach ($perms as $key => $label): ?>
            <label class="check">
                <input type="checkbox" name="perms[]" value="<?= e($key) ?>"
                    <?= in_array($key, $edit['perms'] ?? [], true) ? ' checked' : '' ?>>
                <?= e($label) ?>
            </label>
            <?php endforeach; ?>
            <p class="muted small"><?= t('Zusätzlich gelten die Standardrechte aus %s für alle angemeldeten Konten. Administratoren dürfen ohnehin alles.', '<code>config.php</code>') ?></p>
        </div>
        <div>
            <label for="g-prefix"><?= t('Namensraum') ?> <span class="muted"><?= t('(optional – z.&nbsp;B. %s)', '<code>bib</code>') ?></span></label>
            <div class="short-row">
                <span class="prefix"><?= e(preg_replace('#^https?://#', '', base_url())) ?>/</span>
                <input id="g-prefix" type="text" name="prefix" pattern="[a-z0-9_-]{1,32}" style="max-width:12rem"
                       value="<?= e($edit['prefix'] ?? '') ?>" placeholder="bib">
                <span class="prefix">/…</span>
            </div>
            <p class="muted small"><?= t('Ist ein Namensraum gesetzt, legen Mitglieder ihre Kurzlinks ausschließlich darunter an. So bekommt jede Abteilung ihren eigenen Bereich, ohne sich mit den anderen um kurze Namen zu streiten. Administratoren bleiben frei.') ?></p>
        </div>
        <div>
            <label><?= t('Eigene Limits') ?> <span class="muted"><?= t('(leer oder 0 = es gilt der Wert aus %s)', '<code>config.php</code>') ?></span></label>
            <div class="check-row">
                <?php foreach (limit_names() as $k => $lbl): ?>
                <span style="display:inline-flex;align-items:center;gap:0.4rem">
                    <span class="muted small"><?= e($lbl) ?></span>
                    <input type="number" name="limits[<?= e($k) ?>]" min="0" step="1" style="width:6.5rem"
                           value="<?= e((string)($edit['limits'][$k] ?? '')) ?>">
                </span>
                <?php endforeach; ?>
            </div>
            <p class="muted small"><?= t('Wer in mehreren Gruppen ist, bekommt jeweils den höchsten Wert.') ?></p>
        </div>
        <button class="btn btn-primary" type="submit"><?= $edit !== null ? t('Speichern') : t('Anlegen') ?></button>
        <?php if ($edit !== null): ?>
        <p><a href="groups.php"><?= t('Abbrechen') ?></a></p>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2><?= t('Gruppen') ?> <span class="muted">(<?= count($groups) ?>)</span></h2>
    <?php if ($groups === []): ?>
        <p class="muted"><?= t('Noch keine Gruppen. Ohne Gruppen sieht jedes Konto nur seine eigenen Links.') ?></p>
    <?php else: ?>
    <div class="user-list">
        <?php foreach ($groups as $id => $g):
            $members = group_members((string)$id);
            $geteilt = group_shared((string)$id);
            $pfx = (string)($g['prefix'] ?? '');
            $lim = array_filter($g['limits'] ?? []);
        ?>
        <details class="user">
            <summary>
                <span class="user-name">
                    <strong><?= e($g['name']) ?></strong>
                    <br><span class="muted small" style="font-family:var(--mono)"><?= e((string)$id) ?></span>
                </span>
                <span class="user-meta">
                    <?= $geteilt
                        ? '<span class="tag tag-on" title="' . t('Mitglieder verwalten die Links dieser Gruppe gemeinsam') . '">' . t('geteilt') . '</span>'
                        : '<span class="tag" title="' . t('Vergibt nur Rechte und Limits – Links bleiben privat') . '">' . t('nur Rechte') . '</span>' ?>
                    <?php if ($pfx !== ''): ?><span class="tag"><?= t('Namensraum') ?> <code><?= e($pfx) ?>/</code></span><?php endif; ?>
                    <span class="small"><?= count($members) ?> <span class="muted"><?= count($members) === 1 ? t('Mitglied') : t('Mitglieder') ?></span></span>
                    <span class="small"><?= (int)($linkCount[(string)$id] ?? 0) ?> <span class="muted"><?= t('Links') ?></span></span>
                </span>
            </summary>

            <div class="user-forms">
                <div>
                    <label><?= t('Rechte der Mitglieder') ?></label>
                    <?php if (($g['perms'] ?? []) === []): ?>
                        <p class="muted small"><?= t('Keine besonderen – es gelten die Grundrechte aus den %sEinstellungen%s.', '<a href="settings.php">', '</a>') ?></p>
                    <?php else: ?>
                        <div class="check-row">
                            <?php foreach ($g['perms'] as $perm): ?>
                            <span class="tag tag-on"><?= e($perms[$perm] ?? $perm) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label><?= t('Eigene Limits') ?></label>
                    <?php if ($lim === []): ?>
                        <p class="muted small"><?= t('Keine – es gelten die Werte aus den %sGrundregeln%s.', '<a href="settings.php">', '</a>') ?></p>
                    <?php else: ?>
                        <p class="small"><?= e(implode(' · ', array_map(
                            fn($k, $v) => $v . ' ' . (limit_names()[$k] ?? $k),
                            array_keys($lim), $lim))) ?></p>
                        <p class="muted small"><?= t('Gilt statt der Grundregel. Wer in mehreren Gruppen ist, bekommt jeweils den höchsten Wert.') ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label><?= t('Mitglieder') ?></label>
                    <?php if ($members === []): ?>
                        <p class="muted small"><?= t('Niemand – zuzuordnen in der %sNutzerverwaltung%s.', '<a href="users.php">', '</a>') ?></p>
                    <?php else: ?>
                        <p class="small"><?= e(implode(', ', array_map('user_display', $members))) ?></p>
                    <?php endif; ?>
                </div>

                <div class="user-danger">
                    <a class="btn btn-small" href="groups.php?edit=<?= e(rawurlencode((string)$id)) ?>"><?= t('Bearbeiten') ?></a>
                    <form method="post" action="" class="inline"
                          data-confirm="<?= e(t('Gruppe „%s“ wirklich löschen? Mitgliedschaften und Link-Zuordnungen werden aufgehoben, die Links selbst bleiben.', (string)$id)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                        <button class="btn btn-small btn-danger" type="submit"><?= t('Gruppe löschen') ?></button>
                    </form>
                </div>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <p class="muted small"><?= t('Wer in welcher Gruppe ist, wird in der %sNutzerverwaltung%s gesetzt. Bei zentraler Anmeldung kann die Zuordnung auch aus dem Verzeichnis kommen – siehe %s in der Konfiguration.', '<a href="users.php">', '</a>', '<code>group_map</code>') ?></p>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
