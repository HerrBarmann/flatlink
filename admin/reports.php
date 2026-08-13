<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';

$user = auth_require_admin();
$reportsDir = data_path('reports');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $file = basename((string)($_POST['file'] ?? ''));
    $code = (string)($_POST['code'] ?? '');

    if ($action === 'block' && lookup_code_ok($code)) {
        link_set_disabled($code, true);
        flash('Kurzlink „' . $code . '“ gesperrt.');
    } elseif ($action === 'unblock' && lookup_code_ok($code)) {
        link_set_disabled($code, false);
        flash('Kurzlink „' . $code . '“ entsperrt.');
    } elseif ($action === 'dismiss' && preg_match('/^[0-9-]+-[a-f0-9]{8}\.json$/', $file) && is_file($reportsDir . '/' . $file)) {
        unlink($reportsDir . '/' . $file);
        flash('Meldung erledigt.');
    }
    redirect_to('reports.php');
}

$reports = [];
foreach (glob($reportsDir . '/*.json') ?: [] as $f) {
    $reports[basename($f)] = json_read($f);
}
krsort($reports);

page_header('Meldungen', true);
show_flash();
?>

<div class="card">
    <h2>Missbrauchs-Meldungen <span class="muted">(<?= count($reports) ?>)</span></h2>
    <?php if ($reports === []): ?>
        <p class="muted">Keine offenen Meldungen.</p>
    <?php else: ?>
    <div class="table-scroll"><table>
        <tr><th>Eingang</th><th>Code</th><th>Grund</th><th>Beschreibung</th><th>Ziel-URL</th><th>Status</th><th></th></tr>
        <?php foreach ($reports as $file => $r): $link = link_get($r['code'] ?? ''); ?>
        <tr>
            <td><?= e(date('d.m.Y H:i', strtotime($r['created'] ?? 'now'))) ?></td>
            <td><a href="<?= e(short_url((string)($r['code'] ?? ''))) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($r['code'] ?? '?')) ?></a></td>
            <td><?= e((string)($r['reason'] ?? '–')) ?></td>
            <td class="url-cell" title="<?= e((string)($r['text'] ?? '')) ?>"><?= e(mb_strimwidth((string)($r['text'] ?? '–'), 0, 40, '…')) ?></td>
            <td class="url-cell" title="<?= e($link['url'] ?? '') ?>"><?= $link === null ? '<span class="muted">gelöscht</span>' : e(mb_strimwidth($link['url'], 0, 40, '…')) ?></td>
            <td><?= $link === null ? '–' : (!empty($link['disabled']) ? '<span class="badge badge-expired">gesperrt</span>' : '<span class="muted">aktiv</span>') ?></td>
            <td class="actions">
                <?php if ($link !== null && empty($link['disabled'])): ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="block">
                    <input type="hidden" name="code" value="<?= e((string)$r['code']) ?>">
                    <button class="btn btn-small" type="submit">Sperren</button>
                </form>
                <?php elseif ($link !== null): ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unblock">
                    <input type="hidden" name="code" value="<?= e((string)$r['code']) ?>">
                    <button class="btn btn-small" type="submit">Entsperren</button>
                </form>
                <?php endif; ?>
                <form method="post" action="" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="dismiss">
                    <input type="hidden" name="file" value="<?= e((string)$file) ?>">
                    <button class="btn btn-small btn-danger" type="submit">Erledigt</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <p class="muted small">Sperren beantwortet den Kurzlink mit 410 („wegen Missbrauchs gesperrt"), löscht ihn aber nicht –
    so bleibt der Vorgang nachvollziehbar und der Code wird nicht neu vergeben.</p>
</div>
<?php page_footer(); ?>
