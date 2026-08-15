<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * QR-Codes einer Auswahl auf einmal herunterladen.
 *
 * Der Anlass ist immer derselbe: Tischaufsteller für zwanzig Tische, Schilder
 * für eine Ausstellung, Aufkleber für eine Serie. Einzeln geholt sind das
 * zwanzig Klicks und zwanzig Dateien im Download-Ordner, deren Zuordnung man
 * hinterher raten muss. Deshalb liegt im Archiv auch eine Übersicht als CSV –
 * wer die Codes an eine Druckerei gibt, braucht die Zuordnung, nicht nur die
 * Bilder.
 */

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/domains.php';
require_once __DIR__ . '/../inc/qrlib.php';
require_once __DIR__ . '/../inc/zip.php';
require_once __DIR__ . '/../inc/qrpanel.php';

$user = auth_require();

/** Wie viele Codes höchstens in ein Archiv – darüber wird es eine Aufgabe für einen Server */
const QRZIP_MAX = 200;

$links = links_visible($user);
// Bio-Seiten haben ihren eigenen QR-Code auf der Seite selbst; in einer Serie
// von Tischaufstellern haben sie nichts zu suchen.
$links = array_filter($links, fn($l) => ($l['kind'] ?? '') !== 'bio');

// Dieselben Filter wie in der Liste, damit der Weg dorthin führt, wo man
// herkommt: nach Schlagwort filtern, dann „QR-Serie" – und die Auswahl steht
// schon. Ein zweites Aussuchen wäre genau die Arbeit, die diese Seite spart.
$gFilter = (string)($_GET['g'] ?? '');
$tagFilter = mb_strtolower(trim((string)($_GET['tag'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$gefiltert = $gFilter !== '' || $tagFilter !== '' || $q !== '';
if ($gFilter === '-') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === null);
} elseif ($gFilter !== '') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === $gFilter);
}
if ($tagFilter !== '') {
    $links = array_filter($links, fn($l) => in_array($tagFilter, (array)($l['tags'] ?? []), true));
}
if ($q !== '') {
    $links = array_filter($links, fn($l, $c) => stripos((string)$c, $q) !== false
        || stripos((string)($l['url'] ?? ''), $q) !== false
        || stripos((string)($l['title'] ?? ''), $q) !== false, ARRAY_FILTER_USE_BOTH);
}

$fehler = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'zip') {
    csrf_check();

    $gewaehlt = array_values(array_filter(
        (array)($_POST['codes'] ?? []),
        fn($c) => is_string($c) && isset($links[$c])
    ));

    // Dieselben Gestaltungsparameter wie in qr.php, aus dem gemeinsamen
    // Panel (inc/qrpanel.php). Gelesen wird mit denselben Mustern – was da
    // nicht hineinpasst, fällt still auf die Vorgabe zurück.
    $qpost = fn(string $k, string $vor, string $muster) =>
        preg_match($muster, (string)($_POST[$k] ?? '')) === 1 ? (string)$_POST[$k] : $vor;
    $format = $qpost('format', 'svg', '/^(svg|png)$/');
    $style  = $qpost('style', 'square', '/^(square|rounded|smooth|dot|diamond|bars-v|bars-h)$/');
    $eye    = $qpost('eye', 'square', '/^(square|rounded|circle|leaf)$/');
    $eyeCore = $qpost('eyecore', '', '/^(square|rounded|circle|leaf)$/');
    $hex = '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/';
    $fg = $qpost('fg', '#16181D', $hex);
    $bg = ($_POST['bgnone'] ?? '') === '1' ? 'none' : $qpost('bg', '#ffffff', $hex);
    // Augenfarben nur, wenn ausdrücklich gewünscht – sonst erben sie die Modulfarbe
    $eigenAugen = ($_POST['eyeown'] ?? '') === '1';
    $eyeFg = $eigenAugen ? $qpost('eyefg', '', $hex) : '';
    $eyeCoreFg = $eigenAugen ? $qpost('eyecorefg', '', $hex) : '';
    $grad = $qpost('grad', '', '/^(linear|radial)$/');
    $gradTo = $qpost('fg2', '#3B6EA8', $hex);
    $gradAngle = max(0, min(359, (int)($_POST['ga'] ?? 45)));
    $ecc = $qpost('ecc', 'M', '/^[LMQH]$/');
    $margin = max(0, min(10, (int)($_POST['margin'] ?? 4)));
    $ftext = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($_POST['ftext'] ?? '')));
    $ftext = $ftext === '' ? null : mb_strimwidth($ftext, 0, 24, '');
    // Logo aus der eigenen Bibliothek – nur, wer sie auch im Designer hätte
    $logoDatei = null;
    $logoId = $qpost('logo', '', '/^[a-f0-9]{16}\.(png|jpe?g|webp|svg)$/');
    if ($logoId !== '' && isset(qr_logo_choices($user)[$logoId])) {
        $kandidat = data_path('logos') . '/' . $logoId;
        if (is_file($kandidat)) $logoDatei = $kandidat;
    }
    if ($logoDatei !== null) $ecc = 'H'; // mit Logo braucht es hohe Fehlerkorrektur
    $ls = max(10, min(35, (int)($_POST['ls'] ?? 22))) / 100;
    $logoShape = $qpost('lshape', 'rounded', '/^(rounded|square|circle|none)$/');
    $size = max(256, min(2048, (int)($_POST['size'] ?? 1024)));

    if ($gewaehlt === []) {
        $fehler = t('Kein Link ausgewählt.');
    } elseif (count($gewaehlt) > QRZIP_MAX) {
        $fehler = t('Höchstens %d Codes auf einmal – ausgewählt sind %d.', QRZIP_MAX, count($gewaehlt));
    } else {
        // Rasterbilder kosten spürbar Rechenzeit. Lieber die Grenze anheben als
        // mittendrin abgeschnitten zu werden und ein kaputtes Archiv zu liefern.
        if ($format === 'png') @set_time_limit(max(60, count($gewaehlt) * 2));

        $zip = new ZipWriter();
        $csv = "code;kurzlink;ziel;name;schlagworte\n";

        foreach ($gewaehlt as $code) {
            $l = $links[$code];
            $kurz = short_url($code, (string)($l['domain'] ?? ''));

            // Die Absenderzeile richtet sich wie überall nach dem Besitzer des
            // Links, nicht nach dem, der gerade herunterlädt.
            $besitzer = $l['owner'] ?? null;
            $marke = (string)cfg('qr_brand_text');
            if ($marke !== '' && $besitzer !== null && user_can((string)$besitzer, 'qr_unbranded')) $marke = '';

            $glyphSvg = (string)cfg('qr_brand_glyph_svg');
            $glyphPng = (string)cfg('qr_brand_glyph_png');

            $eccLevel = ['L' => QrCode::ECC_L, 'M' => QrCode::ECC_M,
                'Q' => QrCode::ECC_Q, 'H' => QrCode::ECC_H][$ecc];
            $r = new QrRenderer(QrCode::encode($kurz, $eccLevel), [
                'style' => $style, 'eye' => $eye, 'fg' => $fg, 'bg' => $bg,
                'eyeCore' => $eyeCore, 'eyeFg' => $eyeFg, 'eyeCoreFg' => $eyeCoreFg,
                'grad' => $grad === '' ? null : $grad, 'gradTo' => $gradTo, 'gradAngle' => $gradAngle,
                'size' => $size, 'margin' => $margin,
                'logo' => $logoDatei, 'logoScale' => $ls, 'logoShape' => $logoShape,
                'frameText' => $ftext,
                'brandText' => $marke === '' ? null : $marke,
                'brandGlyphSvg' => $glyphSvg !== '' ? dirname(__DIR__) . '/assets/' . basename($glyphSvg) : null,
                'brandGlyphPng' => $glyphPng !== '' ? dirname(__DIR__) . '/assets/' . basename($glyphPng) : null,
            ]);

            $zip->add(qrzip_filename($code, (string)($l['title'] ?? ''), $format),
                $format === 'png' ? $r->png() : $r->svg(),
                strtotime((string)($l['created'] ?? 'now')) ?: time());

            $csv .= implode(';', array_map('qrzip_csv', [
                $code, $kurz, (string)($l['url'] ?? ''), (string)($l['title'] ?? ''),
                implode(', ', (array)($l['tags'] ?? [])),
            ])) . "\n";
        }

        // BOM, damit Excel die Umlaute nicht verstümmelt – dieselbe Rücksicht
        // wie beim Datenexport im Profil.
        $zip->add('uebersicht.csv', "\xEF\xBB\xBF" . $csv);

        $archiv = $zip->build();
        $name = 'qr-serie-' . date('Y-m-d') . '.zip';
        nosniff_header();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($archiv));
        header('Cache-Control: no-store');
        echo $archiv;
        exit;
    }
}

/** Dateiname im Archiv: Code, dazu der Name, falls einer gesetzt ist */
function qrzip_filename(string $code, string $titel, string $format): string
{
    $basis = str_replace('/', '-', $code);
    $slug = mb_strtolower(trim($titel));
    $slug = (string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
    $slug = trim($slug, '-');
    // Umlaute im Dateinamen sind erlaubt (der Name steht als UTF-8 im Archiv),
    // aber kurz muss es bleiben – manche Entpacker kürzen lange Pfade hart.
    if ($slug !== '') $basis .= '-' . mb_substr($slug, 0, 40);
    return $basis . '.' . $format;
}

/** Ein CSV-Feld für die Übersicht */
function qrzip_csv(string $wert): string
{
    $w = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $wert);
    // Führendes =, +, - oder @ macht aus einem Feld in Excel eine Formel.
    // Der Kurzlink ist harmlos, ein selbst vergebener Name muss es nicht sein.
    if ($w !== '' && str_contains('=+-@', $w[0])) $w = "'" . $w;
    return str_contains($w, ';') || str_contains($w, '"')
        ? '"' . str_replace('"', '""', $w) . '"' : $w;
}

// ---- Oberfläche ---------------------------------------------------------

// Nach einem Fehlversuch gilt, was angehakt war; frisch aufgerufen ist alles
// angehakt, was der Filter übrig gelassen hat.
$vorauswahl = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_flip(array_filter((array)($_POST['codes'] ?? []), 'is_string'))
    : $links;

page_header(t('QR-Serie'), true);
show_flash();
if ($fehler !== null) echo '<div class="flash flash-err">' . e($fehler) . '</div>';
?>
<div class="card">
    <h1><?= t('QR-Serie herunterladen') ?></h1>
    <p class="muted small"><?= t('Wähl die Links aus, die als QR-Codes in ein Archiv sollen. Im ZIP liegt zusätzlich eine Übersicht als CSV – wer die Codes an eine Druckerei gibt, braucht die Zuordnung von Datei zu Ziel, nicht nur die Bilder. Höchstens %d auf einmal.', QRZIP_MAX) ?></p>
    <?php if ($gefiltert): ?>
    <p class="muted small"><?= t('Gefiltert') ?>
        <?php if ($tagFilter !== ''): ?><?= t('nach Schlagwort') ?> <strong><?= e($tagFilter) ?></strong><?php endif; ?>
        <?php if ($gFilter !== ''): ?><?= t('nach Gruppe') ?> <strong><?= e($gFilter === '-' ? t('ohne Gruppe') : group_label($gFilter)) ?></strong><?php endif; ?>
        <?php if ($q !== ''): ?><?= t('nach') ?> „<strong><?= e($q) ?></strong>"<?php endif; ?>
        – <a href="qrzip.php"><?= t('alle anzeigen') ?></a></p>
    <?php endif; ?>

    <?php if ($links === []): ?>
    <p class="muted"><?= $gefiltert ? t('Zu diesem Filter gibt es keine Links.') . ' <a href="qrzip.php">' . t('Alle anzeigen.') . '</a>'
        : t('Noch keine Links vorhanden.') . ' <a href="index.php">' . t('Hier anlegen.') . '</a>' ?></p>
    <?php else: ?>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="zip">

        <?php $beispielCode = (string)array_key_first($links); ?>
        <div class="designer">
            <div class="card controls">
                <h3><?= t('Format') ?></h3>
                <div class="two-col">
                    <div>
                        <label for="z-format"><?= t('Format') ?></label>
                        <select id="z-format" name="format">
                            <option value="svg">SVG – <?= t('verlustfrei skalierbar, für den Druck') ?></option>
                            <option value="png">PNG – <?= t('Pixelbild, für Bildschirm und Office') ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="z-size"><?= t('Kantenlänge in Pixeln') ?> <span class="muted"><?= t('(nur PNG)') ?></span></label>
                        <input id="z-size" type="number" name="size" min="256" max="2048" step="128" value="1024">
                    </div>
                </div>
                <?= qr_design_panel([
                    // Klassische Formular-Abgabe: die Felder tragen name-Attribute.
                    // CMYK entfällt – das Archiv enthält SVG und PNG, keine Druckdateien.
                    'named' => true,
                    'print' => false,
                    'frame' => true,
                    'logos' => qr_logo_choices($user),
                ]) ?>
            </div>
            <div class="card preview" id="zip-stage" data-code="<?= e($beispielCode) ?>" data-base="../qr.php">
                <h3><?= t('Vorschau') ?> <span class="muted small"><?= t('auf') ?></span>
                    <button class="btn btn-small" type="button" data-pbg="#FAFCF6"><?= t('Hell') ?></button>
                    <button class="btn btn-small" type="button" data-pbg="#16181D"><?= t('Dunkel') ?></button>
                </h3>
                <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
                    <img id="qr-preview" src="" alt="<?= t('QR-Code-Vorschau') ?>" width="280">
                </div>
                <div id="lesbarkeit" class="lesbarkeit" aria-live="polite"></div>
                <p class="muted small"><?= t('Die Vorschau zeigt den ersten Link der Liste – die Gestaltung gilt für alle Codes im Archiv.') ?></p>
            </div>
        </div>

        <label class="check" style="margin-top:0.8rem">
            <input type="checkbox" data-checkall="codes[]"<?= $links !== [] && count($vorauswahl) >= count($links) ? ' checked' : '' ?>>
            <strong><?= t('Alle %d auswählen', count($links)) ?></strong>
        </label>

        <div class="check-list">
            <?php foreach ($links as $code => $l): ?>
            <label class="check">
                <input type="checkbox" name="codes[]" value="<?= e((string)$code) ?>"
                    <?= isset($vorauswahl[$code]) ? ' checked' : '' ?>>
                <span>
                    <code><?= e((string)$code) ?></code>
                    <?php if (($l['title'] ?? '') !== ''): ?>
                    <span class="link-title"><?= e((string)$l['title']) ?></span>
                    <?php endif; ?>
                    <br><span class="muted small"><?= e(mb_strimwidth((string)($l['url'] ?? ''), 0, 70, '…')) ?></span>
                </span>
            </label>
            <?php endforeach; ?>
        </div>

        <p><button class="btn btn-primary" type="submit"><?= t('Archiv herunterladen') ?></button></p>
    </form>
    <?php endif; ?>
</div>
<script src="../assets/qroptions.js" defer></script>
<script src="../assets/qrzip.js" defer></script>
<?php page_footer(); ?>
