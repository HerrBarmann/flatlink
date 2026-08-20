<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Das Protokoll der Verwaltungshandlungen – die Anzeige.
 *
 * Nur lesen: Einträge entstehen dort, wo gehandelt wird (inc/audit.php),
 * und werden hier weder geändert noch gelöscht. Ein Protokoll, das sich
 * aus der Oberfläche heraus kürzen ließe, wäre keines.
 */
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';

$user = auth_require_admin();
$eintraege = audit_tail(200);

page_header(t('Protokoll'), true);
show_flash();
?>
<div class="card">
    <h2><?= t('Protokoll') ?> <span class="muted">(<?= t('die letzten %d Verwaltungshandlungen', 200) ?>)</span></h2>
    <p class="muted small"><?= t('Festgehalten wird, wer wann was verwaltet hat – Links gesperrt, Konten freigeschaltet, Domains geändert. Besucher tauchen hier nie auf: keine Klicks, keine Adressen, keine Weiterleitungen. Für ein zentrales Log liefert %s die Einträge als JSON-Zeilen.', '<code>tools/flatlink audit</code>') ?></p>
    <?php if ($eintraege === []): ?>
    <p class="muted"><?= t('Noch keine Einträge.') ?></p>
    <?php else: ?>
    <div class="table-scroll"><table>
        <tr><th><?= t('Zeit') ?></th><th><?= t('Konto') ?></th><th><?= t('Handlung') ?></th></tr>
        <?php foreach ($eintraege as $e): ?>
        <tr>
            <td class="small" style="white-space:nowrap"><?= e(date('d.m.Y H:i', strtotime((string)$e['t']))) ?></td>
            <td class="small"><?= e(user_display((string)($e['wer'] ?? ''))) ?></td>
            <td><?= e((string)$e['aktion']) ?><?php if (($e['objekt'] ?? '') !== ''): ?>
                <span class="muted small" style="font-family:var(--mono)"> · <?= e((string)$e['objekt']) ?></span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
