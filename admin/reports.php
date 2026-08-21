<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/safety.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/domains.php';

// Meldungen darf bearbeiten, wer das Recht dazu hat – Administratoren
// ohnehin (user_can sagt für sie zu allem ja).
$user = auth_require();
if (!user_can($user['name'], 'reports_manage')) {
    http_response_code(403);
    nosniff_header();
    exit(t('Für die Bearbeitung von Meldungen fehlt deinem Konto die Berechtigung.'));
}
$reportsDir = data_path('reports');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $file = basename((string)($_POST['file'] ?? ''));
    $code = (string)($_POST['code'] ?? '');
    $dom = dom_param_lesen($_POST['dom'] ?? '');

    if ($action === 'block' && lookup_code_ok($code)) {
        link_set_disabled($code, true, $dom);
        flash(t('Kurzlink „%s“ gesperrt.', $code));
        audit(t('Kurzlink „%s“ gesperrt.', $code), $code);
    } elseif ($action === 'unblock' && lookup_code_ok($code)) {
        link_set_disabled($code, false, $dom);
        flash(t('Kurzlink „%s“ entsperrt.', $code));
        audit(t('Kurzlink „%s“ entsperrt.', $code), $code);
    } elseif ($action === 'dismiss' && preg_match('/^[0-9-]+-[a-f0-9]{8}\.json$/', $file) && is_file($reportsDir . '/' . $file)) {
        unlink($reportsDir . '/' . $file);
        flash(t('Meldung erledigt.'));
        audit(t('Meldung erledigt.'), $code ?? '');
    } elseif ($action === 'recheck') {
        @set_time_limit(0);
        $z = safety_recheck(true);
        if ($z === null) {
            flash(t('Dafür fehlt der Safe-Browsing-Schlüssel in der Konfiguration.'), 'err');
        } else {
            flash(t('%d Ziele geprüft, %d gesperrt.', $z['geprueft'], count($z['gesperrt'])));
            audit(t('%d Ziele geprüft, %d gesperrt.', $z['geprueft'], count($z['gesperrt'])));
        }
    }
    redirect_to('reports.php');
}

$reports = [];
foreach (glob($reportsDir . '/*.json') ?: [] as $f) {
    $reports[basename($f)] = json_read($f);
}
krsort($reports);

page_header(t('Meldungen'), true);
show_flash();
?>

<div class="card">
    <h2><?= t('Missbrauchs-Meldungen') ?> <span class="muted">(<?= count($reports) ?>)</span></h2>
    <?php if ((string)cfg('safe_browsing_key') !== ''):
        $letzte = @filemtime(data_path() . '/safety-recheck.json');
        $tage = (int)cfg('safety_recheck_days');
    ?>
    <p class="muted small"><?= t('Der Bestand wird alle %d Tage erneut gegen Safe Browsing geprüft – gegen Ziele, die erst nach dem Anlegen bösartig werden. Treffer werden gesperrt, nicht gelöscht: Ein Fehlalarm lässt sich unten mit einem Klick zurücknehmen.', max(1, $tage)) ?>
        <?php if ($letzte): ?><br><?= t('Zuletzt: %s', e(date('d.m.Y H:i', $letzte))) ?><?php endif; ?></p>
    <?php if (($ausfall = safety_fail_state()) !== null): ?>
    <div class="flash flash-err">
        <strong><?= t('Die Prüfung läuft ins Leere.') ?></strong>
        <?= t('Seit %1$s sind %2$d Anfragen an Safe Browsing fehlgeschlagen (zuletzt %3$s). Solange das so ist, wird beim Anlegen NICHT geprüft – die Prüfung lässt bewusst durch, statt den Dienst anzuhalten. Häufigste Ursachen: abgelaufener oder falscher %4$s, erschöpftes Kontingent, oder der Server darf nicht nach außen.',
            e(date('d.m.Y H:i', strtotime((string)$ausfall['seit']))),
            (int)$ausfall['n'],
            e(date('d.m.Y H:i', strtotime((string)$ausfall['zuletzt']))),
            '<code>safe_browsing_key</code>') ?>
    </div>
    <?php endif; ?>
    <form method="post" action="" class="inline" data-confirm="<?= t('Alle aktiven Ziele jetzt gegen Safe Browsing prüfen? Das kann bei großen Beständen dauern.') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="recheck">
        <button class="btn btn-small" type="submit"><?= t('Jetzt prüfen') ?></button>
    </form>
    <?php endif; ?>
    <?php if ($reports === []): ?>
        <p class="muted"><?= t('Keine offenen Meldungen.') ?></p>
    <?php else: ?>
    <div class="table-scroll"><table>
        <tr><th><?= t('Eingang') ?></th><th><?= t('Code') ?></th><th><?= t('Grund') ?></th><th><?= t('Beschreibung') ?></th><th><?= t('Ziel-URL') ?></th><th><?= t('Status') ?></th><th></th></tr>
        <?php foreach ($reports as $file => $r):
            $rDom = dom_param_lesen($r['domain'] ?? '');
            $link = link_get((string)($r['code'] ?? ''), $rDom); ?>
        <tr>
            <td><?= e(date('d.m.Y H:i', strtotime($r['created'] ?? 'now'))) ?></td>
            <td><a href="<?= e(short_url((string)($r['code'] ?? ''), $rDom)) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($r['code'] ?? '?')) ?></a></td>
            <td><?= e((string)($r['reason'] ?? '–')) ?></td>
            <td class="url-cell" title="<?= e((string)($r['text'] ?? '')) ?>"><?= e(mb_strimwidth((string)($r['text'] ?? '–'), 0, 40, '…')) ?></td>
            <td class="url-cell" title="<?= e($link['url'] ?? '') ?>"><?= $link === null ? '<span class="muted">' . t('gelöscht') . '</span>' : e(mb_strimwidth($link['url'], 0, 40, '…')) ?></td>
            <td><?= $link === null ? '–' : (!empty($link['disabled']) ? '<span class="badge badge-expired">' . t('gesperrt') . '</span>' : '<span class="muted">' . t('aktiv') . '</span>') ?></td>
            <td class="actions">
                <?php if ($link !== null && empty($link['disabled'])): ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="block">
                    <input type="hidden" name="code" value="<?= e((string)$r['code']) ?>">
                    <input type="hidden" name="dom" value="<?= e($rDom) ?>">
                    <button class="btn btn-small" type="submit"><?= t('Sperren') ?></button>
                </form>
                <?php elseif ($link !== null): ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unblock">
                    <input type="hidden" name="code" value="<?= e((string)$r['code']) ?>">
                    <input type="hidden" name="dom" value="<?= e($rDom) ?>">
                    <button class="btn btn-small" type="submit"><?= t('Entsperren') ?></button>
                </form>
                <?php endif; ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="dismiss">
                    <input type="hidden" name="file" value="<?= e((string)$file) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Erledigt') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <p class="muted small"><?= t('Sperren beantwortet den Kurzlink mit 410 („wegen Missbrauchs gesperrt"), löscht ihn aber nicht – so bleibt der Vorgang nachvollziehbar und der Code wird nicht neu vergeben.') ?></p>
</div>
<?php page_footer(); ?>
