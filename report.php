<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/auth.php';

auth_boot();

$error = null;
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['website'] ?? '') !== '') {
        $error = t('Das hat nicht geklappt.');
    } elseif (!bucket_rate_ok('report', 5)) {
        $error = t('Zu viele Meldungen von dieser Adresse – bitte in einer Stunde erneut.');
    } else {
        // Eingabe darf der nackte Code oder die volle Kurz-URL sein
        $raw = trim((string)($_POST['code'] ?? ''));
        $code = preg_replace('#^https?://[^/]+/#i', '', $raw);
        $code = trim((string)$code, '/ ');
        $reason = (string)($_POST['reason'] ?? '');
        $text = mb_strimwidth(trim((string)($_POST['text'] ?? '')), 0, 1000, '…');

        if (!lookup_code_ok($code)) {
            $error = t('Bitte gib den Kurzlink an (z. B. %s oder nur den Code).', e(short_url('abc123')));
        } elseif (!in_array($reason, ['phishing', 'malware', 'spam', 'sonstiges'], true)) {
            $error = t('Bitte einen Grund auswählen.');
        } elseif (link_get($code) === null) {
            // Nicht existierende Codes stillschweigend annehmen – kein Orakel für Code-Enumeration
            $sent = true;
        } else {
            $file = data_path('reports') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
            // ip_hash() und nicht hash('sha256', …): Ein blanker SHA-256 über
            // eine IPv4-Adresse ist keine Anonymisierung. Dieselbe Stelle gab
            // es im öffentlichen Rate-Limit; sie wurde dort im Review von außen
            // gefunden, hier beim Nachziehen der Webhooks.
            json_write($file, [
                'code' => $code,
                'reason' => $reason,
                'text' => $text,
                'created' => date('c'),
                'ip_hash' => ip_hash(),
            ]);
            hook_fire('report.received', ['code' => $code, 'reason' => $reason, 'text' => $text]);
            $sent = true;
        }
    }
}

page_header(t('Missbrauch melden'), false,
    t('Kurzlink von %s melden: Phishing, Malware oder Spam – wir prüfen und sperren schnell.', cfg('site_name')),
    base_url() . '/report.php');
?>

<?php if ($sent): ?>
    <div class="card center">
        <h1><?= t('Danke.') ?></h1>
        <p><?= t('Deine Meldung ist eingegangen und wird geprüft. Gemeldete Links sperren wir im Zweifel schnell.') ?></p>
        <p><a class="btn" href="./"><?= t('Zur Startseite') ?></a></p>
    </div>
<?php else: ?>
    <div class="card narrow-wide">
        <h1><?= t('Missbrauch melden') ?></h1>
        <p class="muted"><?= t('Du hast einen %s-Kurzlink erhalten, der auf Phishing, Malware oder Spam zeigt? Sag uns Bescheid – wir prüfen jede Meldung und sperren missbräuchliche Links.', e(cfg('site_name'))) ?></p>

        <?php if ($error !== null): ?>
            <div class="flash flash-err"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="report.php">
            <?= csrf_field() ?>
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
            <label for="rp-code"><?= t('Der Kurzlink') ?></label>
            <input id="rp-code" type="text" name="code" required placeholder="<?= e(short_url('abc123')) ?>">
            <label for="rp-reason"><?= t('Grund') ?></label>
            <select id="rp-reason" name="reason" required>
                <option value=""><?= t('Bitte wählen…') ?></option>
                <option value="phishing"><?= t('Phishing / Betrug') ?></option>
                <option value="malware"><?= t('Malware / Schadsoftware') ?></option>
                <option value="spam"><?= t('Spam / unerwünschte Werbung') ?></option>
                <option value="sonstiges"><?= t('Sonstiges') ?></option>
            </select>
            <label for="rp-text"><?= t('Was ist passiert?') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
            <input id="rp-text" type="text" name="text" maxlength="1000" placeholder="<?= t('z. B. gefälschte Bank-Login-Seite') ?>">
            <p><button class="btn btn-primary" type="submit"><?= t('Melden') ?></button></p>
        </form>
    </div>
<?php endif; ?>
<?php page_footer(); ?>
