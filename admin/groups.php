<?php
declare(strict_types=1);

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
        flash($err ?? 'Gruppe „' . $id . '“ gespeichert.', $err === null ? 'ok' : 'err');
    } elseif ($action === 'delete') {
        if (group_get($id) === null) {
            flash('Diese Gruppe gibt es nicht.', 'err');
        } else {
            $n = count(group_members($id));
            group_delete($id);
            flash('Gruppe „' . $id . '“ gelöscht' . ($n > 0 ? ' (' . $n . ' Mitgliedschaften aufgehoben)' : '')
                . '. Die Links der Gruppe bleiben bei ihren Besitzern.');
        }
    }
    redirect_to('groups.php');
}

$groups = groups_all();
ksort($groups);
$perms = perms_all();

// Links je Gruppe zählen, damit sichtbar ist, was an einer Gruppe hängt
$linkCount = [];
foreach (links_all() as $l) {
    $g = $l['group'] ?? null;
    if (is_string($g)) $linkCount[$g] = ($linkCount[$g] ?? 0) + 1;
}

$editId = (string)($_GET['edit'] ?? '');
$edit = $editId !== '' ? group_get($editId) : null;

page_header('Gruppen', true);
show_flash();
?>

<div class="card">
    <h2><?= $edit !== null ? 'Gruppe bearbeiten' : 'Neue Gruppe' ?></h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div>
            <label for="g-id">Kennung <span class="muted">(kurz, klein, unveränderlich – z.&nbsp;B. marketing)</span></label>
            <input id="g-id" type="text" name="id" required pattern="[a-z0-9._-]{2,32}"
                   value="<?= e($editId) ?>"<?= $edit !== null ? ' readonly' : '' ?>>
        </div>
        <div>
            <label for="g-name">Anzeigename</label>
            <input id="g-name" type="text" name="name" required maxlength="64"
                   value="<?= e($edit['name'] ?? '') ?>" placeholder="Marketing">
        </div>
        <div>
            <label>Art der Gruppe</label>
            <?php
            // Beim Bearbeiten gilt der gespeicherte Wert; Bestandsgruppen ohne
            // Angabe sind Arbeitsgruppen (siehe group_shared). Beim Anlegen ist
            // die engere Variante vorausgewählt.
            $istGeteilt = $edit !== null ? (bool)($edit['shared'] ?? true) : false;
            ?>
            <div class="radio-group">
                <label class="radio">
                    <input type="radio" name="art" value="perms"<?= $istGeteilt ? '' : ' checked' ?>>
                    <span><strong>Nur Rechte</strong><br>
                    <span class="muted small">Die Mitglieder bekommen die unten gewählten
                    Berechtigungen und Limits. Ihre Links bleiben privat. Richtig für Tarife,
                    Rollen und Kontingente.</span></span>
                </label>
                <label class="radio">
                    <input type="radio" name="art" value="shared"<?= $istGeteilt ? ' checked' : '' ?>>
                    <span><strong>Rechte und gemeinsame Linkverwaltung</strong><br>
                    <span class="muted small">Zusätzlich lassen sich Links dieser Gruppe zuordnen;
                    <strong>jedes Mitglied kann sie dann sehen, ändern und löschen</strong>.
                    Richtig für Teams, die zusammenarbeiten – nicht für Tarife.</span></span>
                </label>
            </div>
        </div>

        <div>
            <label>Rechte der Mitglieder</label>
            <?php foreach ($perms as $key => $label): ?>
            <label class="check">
                <input type="checkbox" name="perms[]" value="<?= e($key) ?>"
                    <?= in_array($key, $edit['perms'] ?? [], true) ? ' checked' : '' ?>>
                <?= e($label) ?>
            </label>
            <?php endforeach; ?>
            <p class="muted small">Zusätzlich gelten die Standardrechte aus <code>config.php</code>
            für alle angemeldeten Konten. Administratoren dürfen ohnehin alles.</p>
        </div>
        <div>
            <label for="g-prefix">Namensraum <span class="muted">(optional – z.&nbsp;B. <code>bib</code>)</span></label>
            <div class="short-row">
                <span class="prefix"><?= e(preg_replace('#^https?://#', '', base_url())) ?>/</span>
                <input id="g-prefix" type="text" name="prefix" pattern="[a-z0-9_-]{1,32}" style="max-width:12rem"
                       value="<?= e($edit['prefix'] ?? '') ?>" placeholder="bib">
                <span class="prefix">/…</span>
            </div>
            <p class="muted small">Ist ein Namensraum gesetzt, legen Mitglieder ihre Kurzlinks
            ausschließlich darunter an. So bekommt jede Abteilung ihren eigenen Bereich, ohne
            sich mit den anderen um kurze Namen zu streiten. Administratoren bleiben frei.</p>
        </div>
        <div>
            <label>Eigene Limits <span class="muted">(leer oder 0 = es gilt der Wert aus <code>config.php</code>)</span></label>
            <div class="check-row">
                <?php foreach (limit_names() as $k => $lbl): ?>
                <span style="display:inline-flex;align-items:center;gap:0.4rem">
                    <span class="muted small"><?= e($lbl) ?></span>
                    <input type="number" name="limits[<?= e($k) ?>]" min="0" step="1" style="width:6.5rem"
                           value="<?= e((string)($edit['limits'][$k] ?? '')) ?>">
                </span>
                <?php endforeach; ?>
            </div>
            <p class="muted small">Wer in mehreren Gruppen ist, bekommt jeweils den höchsten Wert.</p>
        </div>
        <button class="btn btn-primary" type="submit"><?= $edit !== null ? 'Speichern' : 'Anlegen' ?></button>
        <?php if ($edit !== null): ?>
        <p><a href="groups.php">Abbrechen</a></p>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2>Gruppen <span class="muted">(<?= count($groups) ?>)</span></h2>
    <?php if ($groups === []): ?>
        <p class="muted">Noch keine Gruppen. Ohne Gruppen sieht jedes Konto nur seine eigenen Links.</p>
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
                        ? '<span class="tag tag-on" title="Mitglieder verwalten die Links dieser Gruppe gemeinsam">geteilt</span>'
                        : '<span class="tag" title="Vergibt nur Rechte und Limits – Links bleiben privat">nur Rechte</span>' ?>
                    <?php if ($pfx !== ''): ?><span class="tag">Namensraum <code><?= e($pfx) ?>/</code></span><?php endif; ?>
                    <span class="small"><?= count($members) ?> <span class="muted"><?= count($members) === 1 ? 'Mitglied' : 'Mitglieder' ?></span></span>
                    <span class="small"><?= (int)($linkCount[(string)$id] ?? 0) ?> <span class="muted">Links</span></span>
                </span>
            </summary>

            <div class="user-forms">
                <div>
                    <label>Rechte der Mitglieder</label>
                    <?php if (($g['perms'] ?? []) === []): ?>
                        <p class="muted small">Keine besonderen – es gelten die Grundrechte aus den
                        <a href="settings.php">Einstellungen</a>.</p>
                    <?php else: ?>
                        <div class="check-row">
                            <?php foreach ($g['perms'] as $perm): ?>
                            <span class="tag tag-on"><?= e($perms[$perm] ?? $perm) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label>Eigene Limits</label>
                    <?php if ($lim === []): ?>
                        <p class="muted small">Keine – es gelten die Werte aus den
                        <a href="settings.php">Grundregeln</a>.</p>
                    <?php else: ?>
                        <p class="small"><?= e(implode(' · ', array_map(
                            fn($k, $v) => $v . ' ' . (limit_names()[$k] ?? $k),
                            array_keys($lim), $lim))) ?></p>
                        <p class="muted small">Gilt statt der Grundregel. Wer in mehreren Gruppen
                        ist, bekommt jeweils den höchsten Wert.</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label>Mitglieder</label>
                    <?php if ($members === []): ?>
                        <p class="muted small">Niemand – zuzuordnen in der
                        <a href="users.php">Nutzerverwaltung</a>.</p>
                    <?php else: ?>
                        <p class="small"><?= e(implode(', ', array_map('user_display', $members))) ?></p>
                    <?php endif; ?>
                </div>

                <div class="user-danger">
                    <a class="btn btn-small" href="groups.php?edit=<?= e(rawurlencode((string)$id)) ?>">Bearbeiten</a>
                    <form method="post" action="" class="inline"
                          data-confirm="Gruppe „<?= e((string)$id) ?>“ wirklich löschen? Mitgliedschaften und Link-Zuordnungen werden aufgehoben, die Links selbst bleiben.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                        <button class="btn btn-small btn-danger" type="submit">Gruppe löschen</button>
                    </form>
                </div>
            </div>
        </details>
        <?php endforeach; ?>
    </div>
    <p class="muted small">Wer in welcher Gruppe ist, wird in der
    <a href="users.php">Nutzerverwaltung</a> gesetzt. Bei zentraler Anmeldung
    kann die Zuordnung auch aus dem Verzeichnis kommen – siehe
    <code>group_map</code> in der Konfiguration.</p>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
