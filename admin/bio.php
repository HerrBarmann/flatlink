<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Verwaltung der Link-in-Bio-Seiten.
 *
 * Anlegen und Ändern laufen über dieselben Regeln wie bei Kurzlinks
 * (inc/linkrules.php): Limits, Wunsch-Namen, Namensräume und Gruppen gelten
 * unverändert. Hier steht nur, was eine Seite zusätzlich hat – Überschrift,
 * Einleitung, Zielliste und die Sichtbarkeit für Suchmaschinen.
 */
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/linkrules.php';
require_once __DIR__ . '/../inc/bio.php';
require_once __DIR__ . '/../inc/qrpanel.php';   // qr_logo_choices() für die Logo-Auswahl

$user = auth_require();
$isAdmin = $user['role'] === 'admin';

if (!user_can($user['name'], 'bio_page')) {
    page_header('Link-in-Bio', true);
    echo '<div class="card center"><h1>Link-in-Bio</h1>'
        . '<p>' . t('Für Link-in-Bio-Seiten fehlt deinem Konto die Berechtigung.') . '</p>'
        . '<p class="muted small">' . t('Sie hängt an einer Gruppe – ein Administrator kann sie freischalten.') . '</p>'
        . '<p><a class="btn" href="index.php">' . t('Zurück zu den Links') . '</a></p></div>';
    page_footer();
    exit;
}

$assignable = link_rules_assignable($user);
$editCode = (string)($_GET['edit'] ?? '');
$darfGestalten = user_can($user['name'], 'bio_style');
$bioLimit = user_limit($user['name'], 'bio');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $code = (string)($_POST['code'] ?? '');

    [$itemErr, $items] = bio_items_from_fields(
        array_map('strval', (array)($_POST['label'] ?? [])),
        array_map('strval', (array)($_POST['url'] ?? []))
    );
    $titel = trim((string)($_POST['title'] ?? ''));
    $text = mb_substr(trim((string)($_POST['bio_text'] ?? '')), 0, 500);
    $index = ($_POST['bio_index'] ?? '') === '1';
    // Ohne die Berechtigung wird die Gestaltung gar nicht erst gelesen – dann
    // bleibt eine vorhandene unangetastet, statt beim Speichern zu verschwinden.
    // Ein Logo darf nur setzen, wer es auch sehen darf – sonst ließe sich per
    // POST jede fremde Kennung eintragen. Der bereits gespeicherte Wert bleibt
    // davon unberührt: Bearbeitet ein Vertreter die Seite, soll das Logo des
    // Besitzers nicht verschwinden, bloß weil er selbst es nicht sieht.
    $logoWunsch = (string)($_POST['bio_logo'] ?? '');
    $logoAlt = $action === 'update' ? (string)(link_get($code)['bio_logo'] ?? '') : '';
    if ($logoWunsch !== '' && $logoWunsch !== $logoAlt && !isset(qr_logo_choices($user)[$logoWunsch])) {
        $logoWunsch = $logoAlt;
    }
    $stil = $darfGestalten ? [
        'logo' => $logoWunsch,
        'colors' => [
            'bg' => (string)($_POST['c_bg'] ?? ''),
            'ink' => (string)($_POST['c_ink'] ?? ''),
            'btn' => (string)($_POST['c_btn'] ?? ''),
            'btn_ink' => (string)($_POST['c_btn_ink'] ?? ''),
        ],
    ] : null;

    if ($action === 'create') {
        // Das Ziel eines Kurzlinks entfällt hier; die Regeln erwarten aber
        // eines. Der eigene Code ist die ehrlichste Angabe: Die Seite zeigt auf
        // sich selbst, und die Prüfung auf schädliche Ziele läuft ohnehin über
        // die einzelnen Einträge.
        [$err, $full, $opts] = link_rules_create($user, [
            'url' => base_url() . '/',
            'code' => (string)($_POST['wunsch'] ?? ''),
            'prefix' => (string)($_POST['prefix'] ?? ''),
            'group' => (string)($_POST['group'] ?? ''),
            'expires' => (string)($_POST['expires'] ?? ''),
            'title' => $titel,
        ]);
        if ($itemErr !== null) {
            flash($itemErr, 'err');
        } elseif (!$isAdmin && bio_count($user['name']) >= $bioLimit) {
            flash($bioLimit === 1
                ? t('Dein Konto darf eine Link-in-Bio-Seite haben. Bearbeite die vorhandene oder lösche sie.')
                : t('Limit erreicht: %d Link-in-Bio-Seiten.', $bioLimit), 'err');
        } elseif ($err !== null) {
            flash($err, 'err');
        } else {
            [$ok, $ergebnis] = link_create($opts['url'], $full, $user['name'],
                $full === null ? 'random' : 'custom', $opts);
            if (!$ok) {
                flash($ergebnis, 'err');
            } else {
                bio_write($ergebnis, $items, $text, $index, $stil);
                flash(t('Seite %s angelegt.', short_url($ergebnis)));
                redirect_to('bio.php?edit=' . urlencode($ergebnis));
            }
        }
    } elseif ($action === 'update') {
        $l = link_get($code);
        if ($l === null || !bio_is($l) || !link_access($user, $l)) {
            flash(t('Kein Zugriff auf diese Seite.'), 'err');
        } elseif ($itemErr !== null) {
            flash($itemErr, 'err');
        } else {
            [$err, $opts] = link_rules_update($user, $l, [
                'url' => (string)($l['url'] ?? base_url() . '/'),
                'expires' => (string)($_POST['expires'] ?? ''),
                'group' => (string)($_POST['group'] ?? ''),
                'title' => $titel,
            ]);
            if ($err !== null) {
                flash($err, 'err');
            } else {
                link_update($code, $opts['url'], $opts);
                if ($assignable !== [] || ($l['group'] ?? null) !== null) {
                    link_set_group($code, $opts['group']);
                }
                bio_write($code, $items, $text, $index, $stil);
                flash(t('Seite aktualisiert.'));
            }
        }
        redirect_to('bio.php?edit=' . urlencode($code));
    } elseif ($action === 'delete') {
        $l = link_get($code);
        if ($l === null || !bio_is($l) || !link_access($user, $l)) {
            flash(t('Kein Zugriff auf diese Seite.'), 'err');
        } else {
            link_delete($code);
            flash(t('Seite %s gelöscht.', $code));
        }
    }
    redirect_to('bio.php');
}

$seiten = array_filter(links_visible($user), 'bio_is');
uasort($seiten, fn($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));

$edit = $editCode !== '' ? link_get($editCode) : null;
if ($edit !== null && (!bio_is($edit) || !link_access($user, $edit))) $edit = null;

$myPrefixes = $isAdmin ? [] : user_prefixes($user['name']);
$mayCustom = user_can($user['name'], 'custom_code');

page_header('Link-in-Bio', true);
show_flash();
?>
<div class="card">
    <h1>Link-in-Bio</h1>
    <p class="muted"><?= t('Eine Seite mit mehreren Zielen unter einem Kurzcode – für das Profil im sozialen Netz, den Aufkleber am Schaufenster, die Fußzeile der Speisekarte. Gezählt wird wie überall bei uns: ein Zähler je Tag, für die Seite und je Ziel. Kein Datensatz über Besucher.') ?></p>
    <p class="muted small"><?= t('Seiten:') ?> <?= bio_count($user['name']) ?>/<?= e(limit_label($bioLimit)) ?><?php
        if (!$darfGestalten): ?> · <?= t('Eigenes Logo und eigene Farben gibt es mit der Berechtigung zum Gestalten') ?><?php endif; ?></p>
</div>

<?php if ($seiten !== []): ?>
<div class="card">
    <h2><?= t('Deine Seiten') ?></h2>
    <div class="table-scroll"><table>
        <tr><th><?= t('Seite') ?></th><th><?= t('Ziele') ?></th><th><?= t('Aufrufe') ?></th><th><?= t('Gruppe') ?></th><th><?= t('Angelegt') ?></th><th></th></tr>
        <?php foreach ($seiten as $c => $l): $k = clicks_get((string)$c); ?>
        <tr<?= (string)$c === $editCode ? ' class="row-hl"' : '' ?>>
            <td><a href="<?= e(short_url((string)$c)) ?>" target="_blank" rel="noopener"><?= e((string)$c) ?></a>
                <?php if (($l['title'] ?? '') !== ''): ?><br><span class="link-title"><?= e((string)$l['title']) ?></span><?php endif; ?></td>
            <td><?= count((array)($l['items'] ?? [])) ?></td>
            <td><a href="stats.php?c=<?= e(rawurlencode((string)$c)) ?>"><?= (int)($k['n'] ?? 0) ?></a></td>
            <td><?php $g = $l['group'] ?? null;
                echo $g === null ? '<span class="muted">–</span>'
                    : '<span class="tag tag-on">' . e(group_label($g)) . '</span>'; ?></td>
            <td class="small"><?= e(date('d.m.Y', strtotime((string)$l['created']))) ?></td>
            <td class="actions">
                <a class="btn btn-small" href="bio.php?edit=<?= e(rawurlencode((string)$c)) ?>"><?= t('Bearbeiten') ?></a>
                <a class="btn btn-small" href="qr.php?c=<?= e(rawurlencode((string)$c)) ?>&amp;format=svg&amp;download=1">QR</a>
                <form method="post" action="" class="inline" data-confirm="<?= e(t('Seite endgültig löschen?')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="code" value="<?= e((string)$c) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Löschen') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= $edit !== null ? t('Seite bearbeiten:') . ' ' . e($editCode) : t('Neue Seite') ?></h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $edit !== null ? 'update' : 'create' ?>">
        <?php if ($edit !== null): ?><input type="hidden" name="code" value="<?= e($editCode) ?>"><?php endif; ?>

        <label for="b-title"><?= t('Überschrift') ?></label>
        <input id="b-title" type="text" name="title" maxlength="120" required
               value="<?= e((string)($edit['title'] ?? '')) ?>" placeholder="Café Kiwi">

        <label for="b-text"><?= t('Einleitung') ?> <span class="muted"><?= t('(optional, max. 500 Zeichen)') ?></span></label>
        <textarea id="b-text" name="bio_text" rows="2" maxlength="500"
                  placeholder="<?= e(t('Alles Wichtige auf einen Blick.')) ?>"><?= e((string)($edit['bio_text'] ?? '')) ?></textarea>

        <label><?= t('Ziele') ?></label>
        <?php
        $vorhanden = (array)($edit['items'] ?? []);
        // Immer ein paar leere Zeilen mitliefern: Ohne JavaScript ist das der
        // einzige Weg, weitere Ziele einzutragen – der Knopf darunter fügt
        // dann nur noch bequemer nach.
        $zeilen = array_merge($vorhanden, array_fill(0, 3, ['label' => '', 'url' => '']));
        ?>
        <div id="bio-rows" class="pair-rows">
            <?php foreach ($zeilen as $z): ?>
            <div class="pair-row" data-row>
                <input type="text" name="label[]" maxlength="80" placeholder="<?= e(t('Anzeigename')) ?>"
                       value="<?= e((string)($z['label'] ?? '')) ?>">
                <input type="text" name="url[]" inputmode="url" placeholder="https://…"
                       value="<?= e((string)($z['url'] ?? '')) ?>">
                <span class="row-tools">
                    <button type="button" class="btn btn-small" data-move-row="up"
                            title="<?= e(t('Nach oben')) ?>" aria-label="<?= e(t('Nach oben')) ?>">&uarr;</button>
                    <button type="button" class="btn btn-small" data-move-row="down"
                            title="<?= e(t('Nach unten')) ?>" aria-label="<?= e(t('Nach unten')) ?>">&darr;</button>
                    <button type="button" class="btn btn-small btn-danger" data-remove-row
                            title="<?= e(t('Diese Zeile entfernen')) ?>" aria-label="<?= e(t('Diese Zeile entfernen')) ?>">&times;</button>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <p><button type="button" class="btn btn-small" data-add-row="#bio-rows"><?= t('Link hinzufügen') ?></button></p>
        <p class="muted small"><?= t('Ohne Anzeigenamen steht die Adresse selbst auf der Schaltfläche. Leere Zeilen werden übergangen. Die Reihenfolge auf der Seite ist die hier – höchstens %d Ziele.', BIO_MAX_ITEMS) ?></p>

        <?php if ($edit === null): ?>
            <?php if ($myPrefixes !== []): ?>
            <div>
                <label><?= t('Namensraum') ?></label>
                <select name="prefix">
                    <?php foreach ($myPrefixes as $p): ?>
                    <option value="<?= e($p) ?>"><?= e($p) ?>/</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($mayCustom): ?>
            <div>
                <label for="b-wunsch"><?= t('Wunsch-Adresse') ?> <span class="muted"><?= t('(leer = zufällig)') ?></span></label>
                <input id="b-wunsch" type="text" name="wunsch" placeholder="cafe-kiwi"
                       pattern="[A-Za-z0-9_-]{<?= (int)settings()['custom_code_min_len'] ?>,64}">
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($assignable !== [] || ($edit['group'] ?? null) !== null): ?>
        <div>
            <label for="b-group"><?= t('Gruppe') ?> <span class="muted"><?= t('(optional – alle Mitglieder verwalten die Seite)') ?></span></label>
            <select id="b-group" name="group">
                <option value=""><?= t('– keine, nur für dich –') ?></option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"<?= ($edit['group'] ?? null) === $gid ? ' selected' : '' ?>><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div>
            <label for="b-expires"><?= t('Ablaufdatum') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
            <input id="b-expires" type="date" name="expires" min="<?= e(date('Y-m-d')) ?>"
                   value="<?= e((string)($edit['expires'] ?? '')) ?>">
        </div>

        <?php if ($darfGestalten):
            $farben = bio_colors($edit ?? []);
            // Dieselbe Auswahl wie im QR-Designer: eigene Logos und die, die
            // eine Gruppe freigegeben hat.
            $meineLogos = qr_logo_choices($user);
        ?>
        <label><?= t('Gestaltung') ?></label>
        <div>
            <label for="b-logo">Logo <span class="muted"><?= t('(aus deiner %sLogo-Bibliothek%s)', '<a href="qrdesign.php">', '</a>') ?></span></label>
            <select id="b-logo" name="bio_logo">
                <option value=""><?= t('– kein Logo –') ?></option>
                <?php foreach ($meineLogos as $lid => $name): ?>
                <option value="<?= e((string)$lid) ?>"<?= ($edit['bio_logo'] ?? '') === (string)$lid ? ' selected' : '' ?>>
                    <?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($meineLogos === []): ?>
            <p class="muted small"><?= t('Noch kein Logo hochgeladen – das geht im %sQR-Designer%s.', '<a href="qrdesign.php">', '</a>') ?></p>
            <?php endif; ?>
        </div>
        <div class="two-col">
            <div><label for="c-bg"><?= t('Hintergrund') ?></label>
                <input id="c-bg" type="color" name="c_bg" value="<?= e($farben['bg']) ?>"></div>
            <div><label for="c-ink"><?= t('Schrift') ?></label>
                <input id="c-ink" type="color" name="c_ink" value="<?= e($farben['ink']) ?>"></div>
        </div>
        <div class="two-col">
            <div><label for="c-btn"><?= t('Schaltflächen') ?></label>
                <input id="c-btn" type="color" name="c_btn" value="<?= e($farben['btn']) ?>"></div>
            <div><label for="c-btn-ink"><?= t('Schrift auf Schaltflächen') ?></label>
                <input id="c-btn-ink" type="color" name="c_btn_ink" value="<?= e($farben['btn_ink']) ?>"></div>
        </div>
        <p class="muted small"><?= t('Achte auf ausreichenden Kontrast – die Seite wird meist auf einem Handy im Sonnenlicht geöffnet.') ?></p>
        <?php endif; ?>

        <label class="check">
            <input type="checkbox" name="bio_index" value="1"<?= !empty($edit['bio_index']) ? ' checked' : '' ?>>
            <?= t('In Suchmaschinen auffindbar machen') ?>
        </label>
        <p class="muted small"><?= t('Aus, solange nichts anderes gewählt ist: Eine Seite, die als QR-Code an einer Tür klebt, muss nicht zwingend auch gefunden werden. Wer das möchte, sagt es ausdrücklich.') ?></p>

        <button class="btn btn-primary" type="submit"><?= $edit !== null ? t('Speichern') : t('Seite anlegen') ?></button>
        <?php if ($edit !== null): ?>
        <p class="muted small"><a href="bio.php"><?= t('Abbrechen und neue Seite anlegen') ?></a></p>
        <?php endif; ?>
    </form>
</div>
<?php page_footer(); ?>
