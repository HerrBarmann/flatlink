<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * QR-Designer – die einzige Fassung, für Angemeldete wie für Gäste.
 *
 * Es gab hiervon einmal zwei Seiten: eine öffentliche mit weniger Optionen und
 * eine im Login-Bereich mit mehr. Dieselbe Beschriftung in der Navigation führte
 * damit je nach Anmeldung woandershin – und beide Fassungen pflegten ihre
 * Gestaltungsoptionen doppelt, was sie zwangsläufig auseinanderlaufen ließ.
 *
 * Jetzt entscheidet nicht die Datei, sondern das Konto: Wer angemeldet ist,
 * bekommt zusätzlich die Auswahl der eigenen Links, die Logo-Bibliothek, den
 * Rahmentext und den Druck-PDF-Export. Wer es nicht ist, sieht Farben und
 * Formen – und kann sich anmelden.
 */
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/qrlib.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/groups.php';
require_once __DIR__ . '/inc/safety.php';
require_once __DIR__ . '/inc/linkrules.php';
require_once __DIR__ . '/inc/qrpanel.php';
require_once __DIR__ . '/inc/svg.php';

auth_boot();
$user = auth_user();
$mode = settings()['public_mode'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if ($user === null && $mode === 'off') {
            flash(t('Die öffentliche Erstellung ist derzeit deaktiviert.'), 'err');
            redirect_to('qr-designer.php');
        }
        // Honeypot: echte Browser lassen das Feld leer
        if (($_POST['website'] ?? '') !== '') {
            flash(t('Das hat nicht geklappt.'), 'err');
            redirect_to('qr-designer.php');
        }
        if ($user !== null) {
            // Angemeldet: dieselben Regeln wie überall
            [$err, $full, $opts] = link_rules_create($user, [
                'url' => (string)($_POST['url'] ?? ''),
                'title' => (string)($_POST['title'] ?? ''),
            ]);
            if ($err !== null) {
                flash($err, 'err');
                redirect_to('qr-designer.php');
            }
            [$ok, $ergebnis] = link_create($opts['url'], $full, $user['name'], 'random', $opts);
        } else {
            // Gast: Rate-Limit und der öffentliche Namensraum
            $url = trim((string)($_POST['url'] ?? ''));
            if ($url !== '' && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $url = 'https://' . $url;
            }
            if (!valid_url($url)) {
                flash('Das sieht nicht nach einer gültigen Adresse aus (http/https).', 'err');
                redirect_to('qr-designer.php');
            }
            if (!rate_limit_ok(client_ip())) {
                flash(t('Rate-Limit erreicht – bitte später wieder vorbeischauen.'), 'err');
                redirect_to('qr-designer.php');
            }
            if (url_flagged($url)) {
                flash(t('Diese Ziel-URL ist als schädlich gemeldet und kann nicht verkürzt werden.'), 'err');
                redirect_to('qr-designer.php');
            }
            $prefix = $mode === 'prefix' ? settings()['public_prefix'] : '';
            [$ok, $ergebnis] = link_create($url, null, null, 'random', ['prefix' => $prefix]);
        }
        if (!$ok) {
            flash($ergebnis, 'err');
            redirect_to('qr-designer.php');
        }
        redirect_to('qr-designer.php?c=' . rawurlencode($ergebnis));
    }

    redirect_to('qr-designer.php' . (isset($_POST['c']) ? '?c=' . urlencode((string)$_POST['c']) : ''));
}

// ---- Link-Auswahl ----
// Angemeldete wählen aus ihren Links; Gäste gestalten genau den Code, der in
// der Adresse steht – meist der, den sie gerade angelegt haben. Ein Kurzcode
// ist ohnehin öffentlich, hier wird also nichts preisgegeben, was nicht schon
// in der URL stünde.
$links = $user !== null ? links_visible($user) : [];
ksort($links);

$code = (string)($_GET['c'] ?? '');
if ($user !== null) {
    if ($code !== '' && !isset($links[$code])) $code = '';
    if ($code === '' && $links !== []) $code = (string)array_key_first($links);
} else {
    $l = $code !== '' && lookup_code_ok($code) ? link_get($code) : null;
    if ($l === null || !empty($l['disabled']) || link_expired($l)) $code = '';
}

$logos = qr_logo_choices($user);

// Die Instanz darf oberhalb und unterhalb eigene Texte einhängen – dort steht
// bei 1337.kiwi der Erklärtext, der die Seite auffindbar macht.
page_header(t('QR-Code-Generator'), false,
    function_exists('designer_description') ? designer_description() : null,
    base_url() . '/qr-designer.php');
show_flash();
// Eine Hülle um das Werkzeug – ohne eigene Bedeutung im Kern, aber der Griff,
// an dem eine Instanz es gestalten kann. Bei 1337.kiwi liegt darauf dieselbe
// dunkle Fläche wie bei den übrigen QR-Generatoren.
// Der Vorspann steht VOR der Hülle: Bei den übrigen Generatoren sitzt die
// Überschrift ebenfalls über der Fläche, nicht darin.
// Eine Instanz mit eigenem Vorspann behält ihn; sonst steht hier dieselbe
// Überschrift-und-Zeile wie über den anderen Generatoren. Ohne sie sprang die
// Seite beim Wechsel der Reiter, weil dem Designer die Kopfhöhe fehlte.
if (function_exists('designer_intro')) {
    echo designer_intro();
} else {
    echo '<div class="hero"><h1>' . t('QR-Code-Generator') . '</h1>'
        . '<p class="sub">' . t('Farben, Formen, Logo und Druckdateien – für einen Kurzlink oder für eine feste Adresse.') . '</p></div>';
}
echo '<div class="designer-stage">';
if (function_exists('qr_type_nav')) echo qr_type_nav('link');

// Zwei Wege zum selben Designer. Der Unterschied ist keine Spielerei, sondern
// die Entscheidung, die man vor dem Drucken einmal treffen muss:
//   statisch – die Adresse steht im Code. Er braucht uns danach nicht mehr,
//              aber das Ziel steht fest, solange der Aufkleber klebt.
//   Kurzlink – der Code zeigt auf uns, das Ziel ist jederzeit änderbar, und
//              es gibt eine Klickzahl.
$statisch = ($_GET['m'] ?? '') === 'statisch' || isset($_GET['u']);
$statischText = trim((string)($_GET['u'] ?? ''));
?>
<div class="card">
    <div class="modus-wahl">
        <a class="btn btn-small<?= $statisch ? '' : ' btn-primary' ?>" href="qr-designer.php"><?= t('Mit Kurzlink') ?></a>
        <a class="btn btn-small<?= $statisch ? ' btn-primary' : '' ?>" href="qr-designer.php?m=statisch"><?= t('Ohne Kürzen') ?></a>
    </div>
    <?php if ($statisch): ?>
    <h2><?= t('QR-Code für eine Adresse') ?></h2>
    <p class="muted small"><?= t('Die Adresse steht unmittelbar im Code. Nichts wird gespeichert, nichts läuft über uns – der Code funktioniert auch dann noch, wenn es diesen Dienst nicht mehr gibt. Dafür steht das Ziel fest: Ändern lässt es sich später nur mit einem %sKurzlink%s, und eine Klickzahl gibt es hier nicht.', '<a href="qr-designer.php">', '</a>') ?></p>
    <label for="opt-u"><?= t('Adresse oder Text') ?></label>
    <input id="opt-u" type="text" value="<?= e($statischText) ?>" autofocus
           placeholder="https://example.com/eine/sehr/lange/adresse"
           aria-describedby="u-hinweis">
    <p class="muted small" id="u-hinweis"><?= t('Auch %s, %s oder einfach ein Text. Bis zu %s Zeichen – lange Adressen mit Kampagnen-Parametern passen also hinein.',
        '<code>mailto:</code>', '<code>tel:</code>',
        number_format(QrCode::maxBytes(QrCode::ECC_L), 0, t(','), t('.'))) ?></p>
    </div>
    <?php else: ?>
    <h2><?= t('Neuer QR-Code') ?></h2>
    <p class="muted small"><?= t('Adresse eintragen – wir legen den Kurzlink an und öffnen ihn gleich im Designer. Das Ziel lässt sich später ändern, ohne den gedruckten Code auszutauschen.') ?></p>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label for="d-url"><?= t('Wohin soll der QR-Code führen?') ?></label>
        <div class="short-row">
            <input id="d-url" type="text" name="url" placeholder="https://example.com/…" required
                   <?= $code === '' ? 'autofocus' : '' ?>>
            <button class="btn btn-primary" type="submit"><?= t('Anlegen') ?></button>
        </div>
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
        <?php if ($user !== null): ?>
        <label for="d-title"><?= t('Name') ?> <span class="muted">(<?= t('optional, nur für deine Übersicht') ?>)</span></label>
        <input id="d-title" type="text" name="title" maxlength="120" placeholder="<?= t('z. B. Speisekarte Sommer') ?>">
        <?php endif; ?>
    </form>
    <?php endif; ?>
    <?php if ($user === null && !$statisch): ?>
    <p class="muted small"><?= t('Ohne Konto ist das Ziel dauerhaft fest. Mit %skostenlosem Konto%s lässt es sich später ändern, ohne den gedruckten Code auszutauschen – dazu kommen eigenes Logo, Rahmentext und Druck-PDF.', '<a href="register.php">', '</a>') ?></p>
    <?php endif; ?>
<?php if (!$statisch): ?></div><?php endif; ?>

<?php if ($code === '' && !$statisch): ?>
    <div class="card"><p class="muted"><?= t('Sobald ein Kurzlink da ist, erscheint hier der Designer – mit Farben, Formen%s.', $user !== null ? t(', Logo, Rahmen und Druck-PDF') : '') ?></p></div>
</div><!-- /.designer-stage -->
<?php if (function_exists('designer_outro')) echo designer_outro(); ?>
<?php page_footer(); exit; endif; ?>

<?php if (!$statisch): ?>
<div class="card">
    <h2>QR-Designer <span class="muted"><?= t('für') ?></span> <?= e(short_url($code, (string)($link['domain'] ?? ''))) ?></h2>
    <?php if ($links !== [] && !$statisch): ?>
    <form method="get" action="" class="short-row">
        <select name="c" data-autosubmit>
            <?php foreach ($links as $c => $l): ?>
                <option value="<?= e((string)$c) ?>"<?= $c === $code ? ' selected' : '' ?>><?= e((string)$c) ?> → <?= e(mb_strimwidth($l['url'], 0, 40, '…')) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="designer">
    <div class="card controls">
        <?= qr_design_panel([
            // Rahmentext gibt es mit Konto; Logos, wenn das Konto sie hochladen darf
            'frame' => $user !== null,
            'logos' => $logos,
        ]) ?>

        <?php if ($user !== null): ?>
        <?php // Die Bibliothek steht für sich – hier führt nur der Weg dorthin. ?>
        <p class="muted small">
            <?= $logos === []
                ? t('Noch kein Logo vorhanden. In der %sLogo-Bibliothek%s lädst du eines hoch.',
                    '<a href="admin/logos.php">', '</a>')
                : t('Hochladen, umbenennen, freigeben: %sLogo-Bibliothek%s',
                    '<a href="admin/logos.php">', '</a>') ?>
        </p>
        <?php endif; ?>
    </div>

    <div class="card preview" id="qr-stage" data-code="<?= e($code) ?>" data-base="qr.php"<?= $statisch ? ' data-mode="url"' : '' ?>>
        <h3><?= t('Vorschau') ?> <span class="muted small"><?= t('auf') ?></span>
            <button class="btn btn-small" type="button" data-pbg="#FAFCF6"><?= t('Hell') ?></button>
            <button class="btn btn-small" type="button" data-pbg="#16181D"><?= t('Dunkel') ?></button>
        </h3>
        <div id="preview-stage" style="display:inline-block; padding:1.2rem; border-radius:6px; background:#FAFCF6;">
            <img id="qr-preview" src="" alt="<?= t('QR-Code-Vorschau') ?>" width="320">
        </div>
        <div id="lesbarkeit" class="lesbarkeit" aria-live="polite"></div>
        <div class="qr-links">
            <a id="dl-svg" class="btn" href="#">SVG</a>
            <a id="dl-png" class="btn" href="#">PNG</a>
            <a id="dl-pdf" class="btn" href="#" title="<?= t('Vektor-PDF für den Druck') ?>">PDF</a>
            <a id="dl-eps" class="btn" href="#" title="<?= t('EPS für Satz und Belichtung') ?>">EPS</a>
            <?php if ($user !== null): ?>
            <select id="opt-size" title="<?= t('PNG-Auflösung') ?>">
                <option value="512">512 px</option>
                <option value="1024" selected>1024 px</option>
                <option value="2048">2048 px</option>
            </select>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- /.designer-stage -->
<?php if (function_exists('designer_outro')) echo designer_outro(); ?>
<script src="assets/qroptions.js" defer></script>
<script src="assets/qrdesign.js" defer></script>
<?php page_footer(); ?>
