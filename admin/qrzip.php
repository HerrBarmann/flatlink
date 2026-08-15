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

    $format = in_array((string)($_POST['format'] ?? 'svg'), ['svg', 'png'], true)
        ? (string)$_POST['format'] : 'svg';
    $style = in_array((string)($_POST['style'] ?? ''), ['square', 'rounded', 'dot'], true)
        ? (string)$_POST['style'] : 'square';
    $eye = in_array((string)($_POST['eye'] ?? ''), ['square', 'rounded', 'circle'], true)
        ? (string)$_POST['eye'] : 'square';
    $farbe = fn(string $k, string $vor) => preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', (string)($_POST[$k] ?? '')) === 1
        ? (string)$_POST[$k] : $vor;
    $fg = $farbe('fg', '#16181D');
    $bg = $farbe('bg', '#ffffff');
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

            $r = new QrRenderer(QrCode::encode($kurz, QrCode::ECC_M), [
                'style' => $style, 'eye' => $eye, 'fg' => $fg, 'bg' => $bg,
                'size' => $size, 'margin' => 4,
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
        <div class="two-col">
            <div>
                <label for="z-style"><?= t('Module') ?></label>
                <select id="z-style" name="style">
                    <option value="square"><?= t('eckig') ?></option>
                    <option value="rounded"><?= t('abgerundet') ?></option>
                    <option value="dot"><?= t('Punkte') ?></option>
                </select>
            </div>
            <div>
                <label for="z-eye"><?= t('Ecken') ?></label>
                <select id="z-eye" name="eye">
                    <option value="square"><?= t('eckig') ?></option>
                    <option value="rounded"><?= t('abgerundet') ?></option>
                    <option value="circle"><?= t('rund') ?></option>
                </select>
            </div>
        </div>
        <div class="two-col">
            <div><label for="z-fg"><?= t('Vordergrund') ?></label><input id="z-fg" type="color" name="fg" value="#16181D"></div>
            <div><label for="z-bg"><?= t('Hintergrund') ?></label><input id="z-bg" type="color" name="bg" value="#ffffff"></div>
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
<?php page_footer(); ?>
