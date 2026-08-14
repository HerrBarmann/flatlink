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
        $error = 'Das hat nicht geklappt.';
    } elseif (!bucket_rate_ok('report', 5)) {
        $error = 'Zu viele Meldungen von dieser Adresse – bitte in einer Stunde erneut.';
    } else {
        // Eingabe darf der nackte Code oder die volle Kurz-URL sein
        $raw = trim((string)($_POST['code'] ?? ''));
        $code = preg_replace('#^https?://[^/]+/#i', '', $raw);
        $code = trim((string)$code, '/ ');
        $reason = (string)($_POST['reason'] ?? '');
        $text = mb_strimwidth(trim((string)($_POST['text'] ?? '')), 0, 1000, '…');

        if (!lookup_code_ok($code)) {
            $error = 'Bitte gib den Kurzlink an (z. B. ' . e(short_url('abc123')) . ' oder nur den Code).';
        } elseif (!in_array($reason, ['phishing', 'malware', 'spam', 'sonstiges'], true)) {
            $error = 'Bitte einen Grund auswählen.';
        } elseif (link_get($code) === null) {
            // Nicht existierende Codes stillschweigend annehmen – kein Orakel für Code-Enumeration
            $sent = true;
        } else {
            $file = data_path('reports') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
            json_write($file, [
                'code' => $code,
                'reason' => $reason,
                'text' => $text,
                'created' => date('c'),
                'ip_hash' => hash('sha256', client_ip()),
            ]);
            $sent = true;
        }
    }
}

page_header('Missbrauch melden', false,
    'Kurzlink von ' . cfg('site_name') . ' melden: Phishing, Malware oder Spam – wir prüfen und sperren schnell.',
    base_url() . '/report.php');
?>

<?php if ($sent): ?>
    <div class="card center">
        <h1>Danke.</h1>
        <p>Deine Meldung ist eingegangen und wird geprüft. Gemeldete Links sperren wir im Zweifel schnell.</p>
        <p><a class="btn" href="./">Zur Startseite</a></p>
    </div>
<?php else: ?>
    <div class="card narrow-wide">
        <h1>Missbrauch melden</h1>
        <p class="muted">Du hast einen <?= e(cfg('site_name')) ?>-Kurzlink erhalten, der auf Phishing, Malware
        oder Spam zeigt? Sag uns Bescheid – wir prüfen jede Meldung und sperren missbräuchliche Links.</p>

        <?php if ($error !== null): ?>
            <div class="flash flash-err"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="report.php">
            <?= csrf_field() ?>
            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
            <label for="rp-code">Der Kurzlink</label>
            <input id="rp-code" type="text" name="code" required placeholder="<?= e(short_url('abc123')) ?>">
            <label for="rp-reason">Grund</label>
            <select id="rp-reason" name="reason" required>
                <option value="">Bitte wählen…</option>
                <option value="phishing">Phishing / Betrug</option>
                <option value="malware">Malware / Schadsoftware</option>
                <option value="spam">Spam / unerwünschte Werbung</option>
                <option value="sonstiges">Sonstiges</option>
            </select>
            <label for="rp-text">Was ist passiert? <span class="muted">(optional)</span></label>
            <input id="rp-text" type="text" name="text" maxlength="1000" placeholder="z. B. gefälschte Bank-Login-Seite">
            <p><button class="btn btn-primary" type="submit">Melden</button></p>
        </form>
    </div>
<?php endif; ?>
<?php page_footer(); ?>
