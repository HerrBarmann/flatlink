<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';

$user = auth_require();
$isAdmin = $user['role'] === 'admin';

$logosDir = data_path('logos');

// ---- Logo-Upload / -Löschung ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload-logo' && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['logo']['tmp_name'];
        $ownLogos = count(array_filter(logos_meta(), fn($m) => ($m['by'] ?? null) === $user['name']));
        if (!user_can($user['name'], 'logo_upload')) {
            flash('Für eigene Logos fehlt deinem Konto die Berechtigung.', 'err');
        } elseif (!$isAdmin && $ownLogos >= user_limit($user['name'], 'logos')) {
            flash('Logo-Bibliothek voll (max. ' . user_limit($user['name'], 'logos')
                . ') – lösche zuerst ein Logo.', 'err');
        } elseif ($_FILES['logo']['size'] > 512 * 1024) {
            flash('Logo zu groß (max. 512 KB).', 'err');
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $ext = match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => null,
            };
            if ($ext === null) {
                flash('Nur PNG, JPG, WebP oder SVG.', 'err');
            } else {
                $id = bin2hex(random_bytes(8)) . '.' . $ext;
                move_uploaded_file($tmp, $logosDir . '/' . $id);
                // Anzeigename: Wunschname aus dem Formular, sonst der Original-Dateiname
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    $name = (string)pathinfo((string)($_FILES['logo']['name'] ?? ''), PATHINFO_FILENAME);
                }
                $name = mb_strimwidth(trim($name), 0, 40, '…');
                logo_meta_set($id, $name === '' ? 'Logo' : $name, $user['name']);
                flash('Logo hochgeladen.');
            }
        }
    } elseif ($action === 'delete-logo') {
        $id = (string)($_POST['logo'] ?? '');
        $mine = ($isAdmin || ((logos_meta()[$id]['by'] ?? null) === $user['name']));
        if ($mine && preg_match('/^[a-f0-9]{16}\.(png|jpe?g|webp|svg)$/', $id) && is_file($logosDir . '/' . $id)) {
            unlink($logosDir . '/' . $id);
            logo_meta_delete($id);
            flash('Logo gelöscht.');
        } else {
            flash('Kein Zugriff auf dieses Logo.', 'err');
        }
    }
    redirect_to('qrdesign.php' . (isset($_POST['c']) ? '?c=' . urlencode((string)$_POST['c']) : ''));
}

// ---- Link-Auswahl ----
$links = links_visible($user);
ksort($links);

$code = (string)($_GET['c'] ?? '');
if ($code !== '' && !isset($links[$code])) $code = '';
if ($code === '' && $links !== []) $code = (string)array_key_first($links);

$logoFiles = array_values(array_filter(scandir($logosDir), fn($f) => preg_match('/^[a-f0-9]{16}\.(png|jpe?g|webp|svg)$/', $f)));
$logoMeta = logos_meta();
// id => Anzeigename (Altbestand ohne Metadaten behält die Datei-ID als Namen), sortiert nach Name.
// Sichtbarkeit: jeder sieht nur die eigenen Logos; Admins sehen alle (inkl. Altbestand ohne Besitzer).
$logos = [];
foreach ($logoFiles as $f) {
    if (!$isAdmin && ($logoMeta[$f]['by'] ?? null) !== $user['name']) continue;
    $ext = strtoupper(pathinfo($f, PATHINFO_EXTENSION));
    $logos[$f] = ($logoMeta[$f]['name'] ?? $f) . ' (' . $ext . ')';
}
asort($logos, SORT_NATURAL | SORT_FLAG_CASE);

page_header('QR-Designer', true);
show_flash();

if ($code === ''): ?>
    <div class="card"><p>Noch keine Links vorhanden – <a href="index.php">leg erst einen an</a>.</p></div>
<?php page_footer(); exit; endif; ?>

<div class="card">
    <h2>QR-Designer <span class="muted">für</span> <?= e(short_url($code)) ?></h2>
    <form method="get" action="" class="short-row">
        <select name="c" data-autosubmit>
            <?php foreach ($links as $c => $l): ?>
                <option value="<?= e((string)$c) ?>"<?= $c === $code ? ' selected' : '' ?>><?= e((string)$c) ?> → <?= e(mb_strimwidth($l['url'], 0, 40, '…')) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="designer">
    <div class="card controls">
        <h3>Gestaltung</h3>
        <label>Modul-Form</label>
        <select id="opt-style">
            <option value="square">Quadratisch</option>
            <option value="rounded">Abgerundet</option>
            <option value="dot">Punkte</option>
        </select>
        <label>Augen-Form</label>
        <select id="opt-eye">
            <option value="square">Quadratisch</option>
            <option value="rounded">Abgerundet</option>
            <option value="circle">Kreis</option>
        </select>
        <div class="two-col">
            <div><label>Vordergrund</label><input id="opt-fg" type="color" value="#16181D"></div>
            <div><label>Hintergrund</label><input id="opt-bg" type="color" value="#ffffff"></div>
        </div>
        <label>Farbvorlagen</label>
        <div class="actions">
            <button class="btn btn-small" type="button" data-preset="#16181D|#ffffff">Klassik</button>
            <button class="btn btn-small" type="button" data-preset="#2C5480|#ffffff">Akzent</button>
            <button class="btn btn-small" type="button" data-preset="#16181D|#F3F4F6">Papier</button>
            <button class="btn btn-small" type="button" data-preset="#ffffff|#16181D" title="Achtung: invertierte Codes lesen manche Scanner nicht">Invertiert</button>
        </div>
        <label>Fehlerkorrektur <span class="muted">(mit Logo automatisch H)</span></label>
        <select id="opt-ecc">
            <option value="L">L – 7 %</option>
            <option value="M" selected>M – 15 %</option>
            <option value="Q">Q – 25 %</option>
            <option value="H">H – 30 %</option>
        </select>
        <label>Rand (Quiet-Zone): <span id="margin-val">4</span> Module</label>
        <input id="opt-margin" type="range" min="0" max="10" value="4">

        <h3>Rahmen</h3>
        <label for="opt-ftext">Text unter dem Code <span class="muted">(leer = kein Rahmen, max. 24 Zeichen)</span></label>
        <div class="short-row">
            <input id="opt-ftext" type="text" maxlength="24" placeholder="z. B. Scan mich!">
            <button class="btn btn-small" type="button" id="ftext-preset">„Scan mich!"</button>
        </div>

        <h3>Logo</h3>
        <select id="opt-logo">
            <option value="">Kein Logo</option>
            <?php foreach ($logos as $id => $name): ?>
                <option value="<?= e($id) ?>"><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Logo-Größe: <span id="ls-val">22</span> %</label>
        <input id="opt-ls" type="range" min="10" max="35" value="22">
        <p class="muted small">SVG-Logos erscheinen nur im SVG-Export (PNG kann sie nicht rastern).</p>

        <form method="post" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload-logo">
            <input type="hidden" name="c" value="<?= e($code) ?>">
            <label for="logo-name">Anzeigename <span class="muted">(leer = Dateiname)</span></label>
            <input id="logo-name" type="text" name="name" maxlength="40" placeholder="z. B. Firmenlogo weiß">
            <div class="short-row" style="margin-top:0.5rem">
                <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" required>
                <button class="btn btn-small" type="submit">Hochladen</button>
            </div>
        </form>
        <?php if ($logos !== []): ?>
        <form method="post" action="" class="short-row" data-confirm="Ausgewähltes Logo löschen?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete-logo">
            <input type="hidden" name="c" value="<?= e($code) ?>">
            <select name="logo"><?php foreach ($logos as $id => $name): ?><option value="<?= e($id) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
            <button class="btn btn-small btn-danger" type="submit">Logo löschen</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card preview" id="qr-stage" data-code="<?= e($code) ?>">
        <h3>Vorschau <span class="muted small">auf</span>
            <button class="btn btn-small" type="button" data-pbg="#FAFCF6">Hell</button>
            <button class="btn btn-small" type="button" data-pbg="#16181D">Dunkel</button>
        </h3>
        <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
            <img id="qr-preview" src="" alt="QR-Code-Vorschau" width="320">
        </div>
        <div class="qr-links">
            <a id="dl-svg" class="btn" href="#">SVG</a>
            <a id="dl-png" class="btn" href="#">PNG</a>
            <a id="dl-pdf" class="btn" href="#" title="80 mm breit, ~650 dpi">PDF (Druck)</a>
            <select id="opt-size" title="PNG-Auflösung">
                <option value="512">512 px</option>
                <option value="1024" selected>1024 px</option>
                <option value="2048">2048 px</option>
            </select>
        </div>
    </div>
</div>

<script src="../assets/qrdesign.js" defer></script>
<?php page_footer(); ?>
