<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Logo-Bibliothek.
 *
 * Bisher steckte sie im QR-Designer: Auswahlfeld, Hochladen, Freigeben und
 * Löschen in einem Kasten, zwischen Modulformen und Farbverläufen. Das ist
 * zweierlei – die Auswahl gehört zum Gestalten eines einzelnen Codes, die
 * Bibliothek ist ein Bestand, den man unabhängig davon pflegt. Wer ein Logo
 * hochlädt, will meist nicht in diesem Moment einen QR-Code bauen.
 *
 * Hier steht deshalb der Bestand: jedes Logo mit Vorschau, Namen, Freigabe und
 * Löschknopf. Im Designer bleibt das Auswahlfeld und ein Verweis hierher.
 */
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/qrpanel.php';

$user = auth_require();
$isAdmin = $user['role'] === 'admin';
$darfLaden = user_can($user['name'], 'logo_upload');
$logosDir = data_path('logos');

/** Gehört das Logo diesem Konto? Administratoren dürfen an alle. */
function logo_gehoert(string $id, array $user, bool $isAdmin): bool
{
    return $isAdmin || ((logos_meta()[$id]['by'] ?? null) === $user['name']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (string)($_POST['logo'] ?? '');
    $gueltig = preg_match('/^[a-f0-9]{16}\.(png|jpe?g|webp|svg)$/', $id) === 1;

    if ($action === 'upload') {
        if (!$darfLaden) {
            flash(t('Für eigene Logos fehlt deinem Konto die Berechtigung.'), 'err');
        } elseif (!isset($_FILES['datei']) || $_FILES['datei']['error'] !== UPLOAD_ERR_OK) {
            flash(t('Es kam keine Datei an.'), 'err');
        } else {
            $eigene = count(array_filter(logos_meta(), fn($m) => ($m['by'] ?? null) === $user['name']));
            $limit = user_limit($user['name'], 'logos');
            if (!$isAdmin && $eigene >= $limit) {
                flash(t('Logo-Kontingent erreicht (%d).', $limit), 'err');
            } else {
                [$err, $neu] = logo_store($_FILES['datei'], (string)($_POST['name'] ?? ''), $user['name']);
                flash($err ?? t('Logo „%s“ aufgenommen.', $neu), $err === null ? 'ok' : 'err');
            }
        }
    } elseif ($action === 'rename' && $gueltig && logo_gehoert($id, $user, $isAdmin)) {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash(t('Der Name darf nicht leer sein.'), 'err');
        } else {
            $meta = logos_meta()[$id] ?? [];
            logo_meta_set($id, mb_substr($name, 0, 40), (string)($meta['by'] ?? $user['name']));
            flash(t('Umbenannt in „%s“.', mb_substr($name, 0, 40)));
        }
    } elseif ($action === 'share' && $gueltig && logo_gehoert($id, $user, $isAdmin)) {
        logo_share_set($id, (array)($_POST['shared'] ?? []));
        $sh = (array)(logos_meta()[$id]['shared'] ?? []);
        flash($sh === []
            ? t('Freigabe aufgehoben – das Logo ist wieder nur für dich.')
            : t('Freigegeben für %s.', in_array('*', $sh, true)
                ? t('alle angemeldeten Konten')
                : implode(', ', array_map('group_label', $sh))));
        audit(t('Freigabe des Logos „%s“ geändert', (string)(logos_meta()[$id]['name'] ?? $id)));
    } elseif ($action === 'delete' && $gueltig && logo_gehoert($id, $user, $isAdmin)) {
        if (is_file($logosDir . '/' . $id)) unlink($logosDir . '/' . $id);
        logo_meta_delete($id);
        flash(t('Logo gelöscht.'));
    } elseif ($action !== '') {
        flash(t('Das geht nicht – gehört dir dieses Logo?'), 'err');
    }
    redirect_to('logos.php');
}

// Sichtbar ist, was einem gehört oder was eine Gruppe freigegeben hat;
// verwalten lässt sich nur das Eigene.
$sichtbar = qr_logo_choices($user);
$meta = logos_meta();
$gruppen = user_groups($user['name']);
$teilbar = $isAdmin
    ? array_values(array_filter(array_keys(groups_all()), 'group_shared'))
    : user_shared_groups($user['name']);
$eigene = count(array_filter($meta, fn($m) => ($m['by'] ?? null) === $user['name']));
$limit = user_limit($user['name'], 'logos');

page_header(t('Logo-Bibliothek'), true);
?>
<div class="card">
    <h2><?= t('Logo-Bibliothek') ?></h2>
    <p class="muted">
        <?= t('Bilder für die Mitte eines QR-Codes und für Link-in-Bio-Seiten. Was hier liegt, steht im %sQR-Designer%s und bei den anderen Generatoren zur Auswahl.',
              '<a href="../qr-designer.php">', '</a>') ?>
    </p>

    <?php if ($darfLaden): ?>
    <form method="post" action="" enctype="multipart/form-data" class="logo-upload">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <div class="two-col">
            <div>
                <label for="l-name"><?= t('Anzeigename') ?> <span class="muted">(<?= t('leer = Dateiname') ?>)</span></label>
                <input id="l-name" type="text" name="name" maxlength="40" placeholder="<?= t('z. B. Firmenlogo weiß') ?>">
            </div>
            <div>
                <label for="l-datei"><?= t('Bilddatei') ?> <span class="muted">(PNG, JPEG, WebP, SVG · max. 512 KB)</span></label>
                <input id="l-datei" type="file" name="datei" accept=".png,.jpg,.jpeg,.webp,.svg" required>
            </div>
        </div>
        <p>
            <button class="btn" type="submit"><?= t('Hochladen') ?></button>
            <span class="muted small">
                <?= $isAdmin ? t('%d Logos abgelegt.', $eigene) : t('%d von %d Logos belegt.', $eigene, $limit) ?>
            </span>
        </p>
    </form>
    <?php else: ?>
    <p class="hinweis-kasten">
        <?= t('Eigene Logos hochladen darf dieses Konto nicht. Was eine Gruppe freigegeben hat, steht unten trotzdem zur Verfügung.') ?>
    </p>
    <?php endif; ?>
</div>

<?php if ($sichtbar === []): ?>
<div class="card">
    <p class="muted"><?= $darfLaden
        ? t('Noch kein Logo in der Bibliothek. Das erste kommt oben hinein.')
        : t('Für dieses Konto ist kein Logo freigegeben.') ?></p>
</div>
<?php else: ?>

<div class="logo-liste">
    <?php foreach ($sichtbar as $id => $anzeige):
        $m = (array)($meta[$id] ?? []);
        $mein = logo_gehoert((string)$id, $user, $isAdmin);
        $by = (string)($m['by'] ?? '');
        $shared = (array)($m['shared'] ?? []);
    ?>
    <div class="card logo-karte">
        <div class="logo-bild">
            <img src="../logo.php?id=<?= e(rawurlencode((string)$id)) ?>" alt="" loading="lazy">
        </div>
        <div class="logo-text">
            <h3><?= e((string)($m['name'] ?? $id)) ?></h3>
            <p class="muted small">
                <?= e(strtoupper(pathinfo((string)$id, PATHINFO_EXTENSION))) ?>
                <?php if ($by !== '' && $by !== $user['name']): ?>
                    · <?= e(t('von %s', $by)) ?>
                <?php endif; ?>
                <?php if ($shared !== []): ?>
                    · <?= e(in_array('*', $shared, true)
                            ? t('für alle Konten freigegeben')
                            : t('freigegeben für %s', implode(', ', array_map('group_label', $shared)))) ?>
                <?php endif; ?>
            </p>

            <?php if (!$mein): ?>
            <p class="muted small"><?= t('Nutzbar, aber nicht von dir verwaltbar – das Logo gehört jemand anderem.') ?></p>
            <?php else: ?>

            <details>
                <summary><?= t('Umbenennen') ?></summary>
                <form method="post" action="" class="short-row">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="logo" value="<?= e((string)$id) ?>">
                    <input type="text" name="name" maxlength="40" value="<?= e((string)($m['name'] ?? '')) ?>" required>
                    <button class="btn btn-small" type="submit"><?= t('Speichern') ?></button>
                </form>
            </details>

            <?php if ($teilbar !== []): ?>
            <details<?= $shared !== [] ? ' open' : '' ?>>
                <summary><?= t('Freigeben') ?></summary>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="share">
                    <input type="hidden" name="logo" value="<?= e((string)$id) ?>">
                    <div class="check-row">
                        <?php foreach ($teilbar as $gid): ?>
                        <label class="check">
                            <input type="checkbox" name="shared[]" value="<?= e((string)$gid) ?>"
                                   <?= in_array((string)$gid, $shared, true) ? 'checked' : '' ?>>
                            <?= e(group_label((string)$gid)) ?>
                        </label>
                        <?php endforeach; ?>
                        <label class="check">
                            <input type="checkbox" name="shared[]" value="*" <?= in_array('*', $shared, true) ? 'checked' : '' ?>>
                            <?= t('alle angemeldeten Konten') ?>
                        </label>
                    </div>
                    <p class="muted small"><?= t('Freigeben heißt verwenden dürfen. Umbenennen und Löschen bleiben bei dir, und das Logo zählt weiter auf dein Kontingent.') ?></p>
                    <p><button class="btn btn-small" type="submit"><?= t('Freigabe speichern') ?></button></p>
                </form>
            </details>
            <?php endif; ?>

            <form method="post" action="" data-confirm="<?= t('Logo „%s“ löschen?', (string)($m['name'] ?? $id)) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="logo" value="<?= e((string)$id) ?>">
                <button class="btn btn-small btn-danger" type="submit"><?= t('Löschen') ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
