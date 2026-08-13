<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/safety.php';
require_once __DIR__ . '/../inc/groups.php';

$user = auth_require();
$isAdmin = $user['role'] === 'admin';

if (!user_can($user['name'], 'csv_import')) {
    page_header('CSV-Import', true);
    echo '<div class="card center"><h1>CSV-Import</h1>'
        . '<p>Für den Massen-Import fehlt deinem Konto die Berechtigung.</p>'
        . '<p class="muted small">Sie hängt an einer Gruppe – ein Administrator kann sie freischalten.</p>'
        . '<p><a class="btn" href="index.php">Zurück zu den Links</a></p></div>';
    page_footer();
    exit;
}

$assignable = $isAdmin ? array_keys(groups_all()) : user_groups($user['name']);
$mayCustom = user_can($user['name'], 'custom_code');

const IMPORT_MAX_ROWS = 100;
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $raw = '';
    if (isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['csv']['size'] > 100 * 1024) {
            flash('Datei zu groß (max. 100 KB).', 'err');
            redirect_to('import.php');
        }
        $raw = (string)file_get_contents($_FILES['csv']['tmp_name']);
    } else {
        $raw = (string)($_POST['daten'] ?? '');
    }
    $raw = str_replace("\xEF\xBB\xBF", '', $raw); // BOM entfernen

    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($l) => $l !== ''));
    // Kopfzeile („url;…") überspringen
    if ($lines !== [] && stripos($lines[0], 'http') !== 0) {
        array_shift($lines);
    }

    if ($lines === []) {
        flash('Keine Zeilen gefunden – Format: eine URL pro Zeile, optional „;wunsch-code;ablaufdatum“.', 'err');
        redirect_to('import.php');
    }
    if (count($lines) > IMPORT_MAX_ROWS) {
        flash('Maximal ' . IMPORT_MAX_ROWS . ' Zeilen pro Import (gefunden: ' . count($lines) . ') – bitte aufteilen.', 'err');
        redirect_to('import.php');
    }

    // Zeilen parsen (Trennzeichen ; oder ,)
    $rows = [];
    foreach ($lines as $i => $line) {
        $sep = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
        $cols = array_map('trim', str_getcsv($line, $sep));
        $url = (string)($cols[0] ?? '');
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && $url !== '') {
            $url = 'https://' . $url;
        }
        $rows[] = ['zeile' => $i + 1, 'url' => $url, 'code' => (string)($cols[1] ?? ''), 'expires' => (string)($cols[2] ?? '')];
    }

    // Eine Safe-Browsing-Anfrage für alle URLs zusammen
    $flagged = urls_flagged(array_column($rows, 'url'));

    $quotaLinks = user_limit($user['name'], 'links');
    $quotaCustom = (int)cfg('custom_code_quota');
    $minLen = (int)cfg('custom_code_min_len');
    $usedLinks = link_count($user['name']);
    $usedCustom = custom_code_count($user['name']);

    $group = trim((string)($_POST['group'] ?? ''));
    if ($group === '' || !in_array($group, $assignable, true)) $group = null;

    $results = [];
    $created = 0;
    foreach ($rows as $r) {
        $err = null;
        [$expOk, $expires] = parse_expiry($r['expires']);
        if (!valid_url($r['url'])) {
            $err = 'Ungültige URL';
        } elseif (in_array($r['url'], $flagged, true)) {
            $err = 'Als schädlich gemeldet (Safe Browsing)';
        } elseif (!$expOk) {
            $err = 'Ungültiges Ablaufdatum (JJJJ-MM-TT, frühestens heute)';
        } elseif (!$isAdmin && $usedLinks + $created >= $quotaLinks) {
            $err = 'Link-Limit erreicht (' . $quotaLinks . ')';
        } elseif ($r['code'] !== '') {
            if (!$mayCustom) {
                $err = 'Wunsch-Namen sind für dieses Konto nicht freigeschaltet';
            } elseif (!$isAdmin && mb_strlen($r['code']) < $minLen) {
                $err = 'Wunsch-Code zu kurz (mind. ' . $minLen . ' Zeichen)';
            } elseif (!$isAdmin && $quotaCustom > 0 && $usedCustom >= $quotaCustom) {
                $err = 'Wunsch-Code-Kontingent erreicht (' . $quotaCustom . ')';
            } elseif (!valid_code($r['code'])) {
                $err = 'Ungültiger oder reservierter Wunsch-Code';
            }
        }

        if ($err === null) {
            [$ok, $result] = link_create($r['url'], $r['code'] === '' ? null : $r['code'], $user['name'], $r['code'] === '' ? 'random' : 'custom', '', $expires, $group);
            if ($ok) {
                $created++;
                if ($r['code'] !== '') $usedCustom++;
                $results[] = ['zeile' => $r['zeile'], 'ok' => true, 'text' => short_url($result), 'url' => $r['url']];
                continue;
            }
            $err = $result;
        }
        $results[] = ['zeile' => $r['zeile'], 'ok' => false, 'text' => $err, 'url' => $r['url']];
    }
}

page_header('CSV-Import', true);
show_flash();
?>

<div class="card">
    <h2>CSV-Import <span class="muted">(bis zu <?= IMPORT_MAX_ROWS ?> Links auf einmal)</span></h2>
    <p class="muted small">Eine Zeile pro Link: <code>url;wunsch-code;ablaufdatum</code> —
    Wunsch-Code und Ablaufdatum (JJJJ-MM-TT) sind optional, als Trennzeichen geht Semikolon
    oder Komma, eine Kopfzeile wird erkannt und übersprungen. Alle Ziel-URLs werden vor dem
    Anlegen gesammelt auf Phishing/Malware geprüft.</p>

    <form method="post" action="" enctype="multipart/form-data" class="grid-form">
        <?= csrf_field() ?>
        <div>
            <label for="i-file">CSV-Datei</label>
            <input id="i-file" type="file" name="csv" accept=".csv,.txt">
        </div>
        <div>
            <label for="i-daten">… oder direkt einfügen</label>
            <textarea id="i-daten" name="daten" rows="6" style="width:100%; font-family:var(--mono); font-size:0.9rem; padding:0.6rem 0.75rem; border:1px solid var(--line); border-radius:var(--radius); background:var(--paper); color:var(--ink);" placeholder="https://example.com/sommer;sommerfest-2026;2026-09-30&#10;https://example.com/karte"></textarea>
        </div>
        <?php if ($assignable !== []): ?>
        <div>
            <label for="i-group">Gruppe für alle importierten Links <span class="muted">(optional)</span></label>
            <select id="i-group" name="group">
                <option value="">– keine, nur für dich –</option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Importieren</button>
    </form>
</div>

<?php if ($results !== null): $okCount = count(array_filter($results, fn($r) => $r['ok'])); ?>
<div class="card">
    <h2>Ergebnis <span class="muted"><?= $okCount ?> von <?= count($results) ?> angelegt</span></h2>
    <div class="table-scroll"><table>
        <tr><th>Zeile</th><th>Ziel-URL</th><th>Ergebnis</th></tr>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><?= (int)$r['zeile'] ?></td>
            <td class="url-cell" title="<?= e($r['url']) ?>"><?= e(mb_strimwidth($r['url'], 0, 50, '…')) ?></td>
            <td><?php if ($r['ok']): ?><a href="<?= e($r['text']) ?>" target="_blank" rel="noopener"><?= e($r['text']) ?></a>
                <?php else: ?><span class="badge badge-expired"><?= e($r['text']) ?></span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <p><a class="btn" href="index.php">Zu den Links</a> <a class="btn" href="import.php">Weiterer Import</a></p>
</div>
<?php endif; ?>
<?php page_footer(); ?>
