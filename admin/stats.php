<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bio.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/routing.php';

$user = auth_require();

$code = (string)($_GET['c'] ?? '');
$link = lookup_code_ok($code) ? link_get($code) : null;
if ($link === null || !link_access($user, $link)) {
    http_response_code(404);
    page_header(t('Statistik'), true);
    echo '<div class="card"><p>' . t('Link nicht gefunden (oder kein Zugriff).') . '</p><p><a class="btn" href="index.php">' . t('Zurück') . '</a></p></div>';
    page_footer();
    exit;
}

$clicks = clicks_get($code);
$days = $clicks['days'] ?? [];
$statsDays = user_limit($user['name'], 'stats_days');

// CSV-Export: alle Tageswerte innerhalb der konfigurierten Tiefe
if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="klicks-' . str_replace('/', '-', $code) . '.csv"');
    $cutoff = date('Y-m-d', strtotime('-' . $statsDays . ' days'));
    ksort($days);
    echo t('Tag') . ';' . t('Klicks') . "\n";
    foreach ($days as $day => $n) {
        if ($day >= $cutoff) echo $day . ';' . (int)$n . "\n";
    }
    exit;
}

// Letzte 30 Tage als lückenlose Reihe (Diagramm)
$series = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $series[$day] = (int)($days[$day] ?? 0);
}
$max = max(1, max($series));

// Monatssummen innerhalb der konfigurierten Statistik-Tiefe
$months = [];
$cutoff = date('Y-m-d', strtotime('-' . $statsDays . ' days'));
foreach ($days as $day => $n) {
    if ($day >= $cutoff) {
        $m = substr((string)$day, 0, 7);
        $months[$m] = ($months[$m] ?? 0) + (int)$n;
    }
}
krsort($months);

page_header(t('Statistik'), true);
?>
<div class="card">
    <h2><?= t('Statistik') ?> <span class="muted"><?= t('für') ?></span> <?= e(short_url($code)) ?></h2>
    <?php if (bio_is($link)): ?>
    <p class="muted"><?= t('Link-in-Bio-Seite %s mit %d Zielen', '<strong>' . e((string)($link['title'] ?? $code)) . '</strong>', count((array)($link['items'] ?? []))) ?> · <a href="bio.php?edit=<?= e(rawurlencode($code)) ?>"><?= t('bearbeiten') ?></a></p>
    <?php else: ?>
    <p class="muted"><?= t('Ziel:') ?> <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($link['url'], 0, 80, '…')) ?></a></p>
    <?php endif; ?>
    <div class="stat-row">
        <div class="stat"><strong><?= (int)$clicks['n'] ?></strong><span><?= t('Klicks gesamt') ?></span></div>
        <div class="stat"><strong><?= array_sum($series) ?></strong><span><?= t('letzte 30 Tage') ?></span></div>
        <div class="stat"><strong><?= $clicks['last'] ? e(date('d.m.Y', strtotime($clicks['last']))) : '–' ?></strong><span><?= t('letzter Klick') ?></span></div>
        <div class="stat"><strong><?= e(date('d.m.Y', strtotime($link['created']))) ?></strong><span><?= t('erstellt') ?></span></div>
    </div>

    <?php if (bio_is($link)):
        $items = array_values((array)($link['items'] ?? []));
        $je = (array)($clicks['items'] ?? []);
        $summe = 0;
        foreach ($je as $z) $summe += (int)($z['n'] ?? 0);
    ?>
    <h3><?= t('Klicks je Ziel') ?></h3>
    <p class="muted small"><?= t('Oben stehen die Aufrufe der Seite, hier die Klicks auf die einzelnen Ziele – beides als Zahl je Tag, wie überall.') ?></p>
    <div class="table-scroll"><table>
        <tr><th><?= t('Ziel') ?></th><th><?= t('Adresse') ?></th><th><?= t('Klicks') ?></th><th><?= t('Anteil') ?></th></tr>
        <?php foreach ($items as $i => $item): $n = (int)($je[(string)$i]['n'] ?? 0); ?>
        <tr>
            <td><?= e((string)($item['label'] ?? '')) ?></td>
            <td class="url-cell" title="<?= e((string)$item['url']) ?>"><?= e(mb_strimwidth((string)$item['url'], 0, 50, '…')) ?></td>
            <td><?= $n ?></td>
            <td class="small"><?= $summe > 0 ? round($n * 100 / $summe) . '&nbsp;%' : '<span class="muted">–</span>' ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>

    <h3><?= t('Klicks der letzten 30 Tage') ?></h3>
    <?php
    // Balkendiagramm als Inline-SVG, 30 Balken
    $bw = 20; $gap = 4; $h = 120;
    $w = 30 * ($bw + $gap) - $gap;
    echo '<div class="table-scroll"><svg viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" width="' . $w . '" class="bars" role="img" aria-label="' . t('Klicks pro Tag') . '">';
    $x = 0;
    foreach ($series as $day => $n) {
        $bh = (int)round($n / $max * ($h - 14));
        $y = $h - $bh;
        echo '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . max($bh, 1) . '" rx="2" class="' . ($n > 0 ? 'bar' : 'bar-zero') . '">'
            . '<title>' . e(date('d.m.', strtotime($day))) . ': ' . $n . '</title></rect>';
        if ($n > 0) {
            echo '<text x="' . ($x + $bw / 2) . '" y="' . ($y - 4) . '" text-anchor="middle" font-size="10" class="chart-label">' . $n . '</text>';
        }
        // Jeden 5. Tag beschriften
        if (date('j', strtotime($day)) % 5 === 0) {
            echo '<text x="' . ($x + $bw / 2) . '" y="' . ($h + 14) . '" text-anchor="middle" font-size="9" class="chart-label">' . e(date('d.m.', strtotime($day))) . '</text>';
        }
        $x += $bw + $gap;
    }
    echo '</svg></div>';
    ?>

    <?php if (count($months) > 1): ?>
    <h3><?= t('Monatsübersicht') ?></h3>
    <div class="table-scroll"><table>
        <tr><th><?= t('Monat') ?></th><th><?= t('Klicks') ?></th></tr>
        <?php foreach ($months as $m => $n): ?>
        <tr><td><?= e(date('m/Y', strtotime($m . '-01'))) ?></td><td><?= (int)$n ?></td></tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>

    <p class="cta"><a class="btn" href="index.php"><?= t('Zurück zu den Links') ?></a>
       <a class="btn" href="qrdesign.php?c=<?= e(rawurlencode($code)) ?>">QR-Designer</a>
       <a class="btn" href="stats.php?c=<?= e(rawurlencode($code)) ?>&amp;format=csv"><?= t('CSV-Export') ?></a></p>
</div>

<?php
// Woher die Aufrufe kamen – drei Merkmale, jedes eine Gruppe und keine Person.
$dims = [
    'refs' => [t('Herkunft'), t('Von welcher Seite aus der Kurzlink angeklickt wurde. %s steht für Direktaufrufe: getippt, aus einem QR-Code, aus einer App oder von einer Seite, die ihre Herkunft nicht mitschickt. Aufgehoben wird nur der Hostname – der Pfad einer verweisenden Seite kann eine Suchanfrage enthalten.')],
    'devs' => [t('Gerät'), t('Grobe Gattung aus der Browser-Kennung: Handy, Tablet, Rechner. Die Kennung selbst wird nicht gespeichert.')],
    'langs' => [t('Sprache'), t('Die bevorzugte Sprache des Browsers, auf zwei Buchstaben gekürzt.')],
];
$hatDims = false;
foreach ($dims as $feld => $_) if (!empty($clicks[$feld])) $hatDims = true;
if ($hatDims): ?>
<div class="card">
    <h2><?= t('Woher die Klicks kamen') ?></h2>
    <p class="muted small"><?= t('Gezählt wird je Merkmal, nicht je Besuch: Es entsteht kein Datensatz pro Aufruf, keine Uhrzeit, keine Adresse, keine Browser-Kennung. Jede Zeile ist eine Summe über alle Aufrufe seit dem Anlegen – aus ihr lässt sich kein einzelner Besuch herauslesen.') ?></p>
    <div class="dim-grid">
    <?php foreach ($dims as $feld => [$titel, $hilfe]):
        $werte = (array)($clicks[$feld] ?? []);
        if ($werte === []) continue;
        arsort($werte);
        $summe = max(1, array_sum($werte)); ?>
        <div class="dim-block">
            <h3><?= e($titel) ?></h3>
            <table class="dim-table">
            <?php foreach (array_slice($werte, 0, 12, true) as $wert => $n):
                $label = $wert === '-' ? t('Direkt') : ($wert === '*' ? t('Übrige') : $wert);
                if ($feld === 'devs') $label = ['mobile' => t('Handy'), 'tablet' => t('Tablet'), 'desktop' => t('Rechner')][$wert] ?? $label; ?>
                <tr>
                    <td class="dim-name" title="<?= e((string)$wert) ?>"><?= e(mb_strimwidth((string)$label, 0, 24, '…')) ?></td>
                    <td class="dim-bar"><span style="width:<?= round((int)$n / $summe * 100) ?>%"></span></td>
                    <td class="dim-n"><?= (int)$n ?></td>
                </tr>
            <?php endforeach; ?>
            </table>
            <p class="muted small"><?= $hilfe === '' ? '' : e(sprintf($hilfe, '„' . t('Direkt') . '"')) ?></p>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php $weichen = array_values((array)($link['rules'] ?? [])); if ($weichen !== []):
    $treffer = (array)($clicks['routes'] ?? []);
    $summe = array_sum(array_map('intval', $treffer)); ?>
<div class="card">
    <h2><?= t('Weichen') ?></h2>
    <p class="muted small"><?= t('Die erste zutreffende Weiche gewinnt. Gezählt wird, wie oft jede gegriffen hat – nicht, wer sie ausgelöst hat: Gerät, Sprache und Land werden bei der Anfrage geprüft und danach vergessen.') ?></p>
    <div class="table-scroll"><table>
        <tr><th><?= t('Wenn') ?></th><th><?= t('Ziel') ?></th><th><?= t('Griff') ?></th></tr>
        <?php foreach ($weichen as $i => $r): $n = (int)($treffer[(string)$i] ?? 0); ?>
        <tr>
            <td class="small" style="white-space:nowrap"><?= e(route_label($r)) ?></td>
            <td class="url-cell" title="<?= e((string)($r['url'] ?? '')) ?>"><?= e(mb_strimwidth((string)($r['url'] ?? ''), 0, 52, '…')) ?></td>
            <td class="small" style="text-align:right;font-family:var(--mono)"><?= $n > 0 ? (int)$n : '–' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td class="small muted"><?= t('sonst') ?></td>
            <td class="url-cell muted" title="<?= e((string)($link['url'] ?? '')) ?>"><?= e(mb_strimwidth((string)($link['url'] ?? ''), 0, 52, '…')) ?></td>
            <td class="small muted" style="text-align:right;font-family:var(--mono)"><?= max(0, (int)($clicks['n'] ?? 0) - $summe) ?></td>
        </tr>
    </table></div>
</div>
<?php endif; ?>

<?php $historie = array_reverse((array)($link['history'] ?? [])); if ($historie !== []): ?>
<div class="card">
    <h2><?= t('Änderungen am Ziel') ?></h2>
    <p class="muted small"><?= t('Ein gedruckter Code lässt sich nicht zurückrufen – wohin er führt, schon. Deshalb steht hier, wer das Ziel wann geändert hat. Die letzten %d Änderungen; Name, Schlagwörter und Gestaltung bleiben außen vor, sie sind Ordnung und keine Zusage.', LINK_HISTORY_MAX) ?></p>
    <div class="table-scroll"><table>
        <tr><th><?= t('Zeit') ?></th><th><?= t('Konto') ?></th><th><?= t('Von') ?></th><th><?= t('Nach') ?></th></tr>
        <?php foreach ($historie as $h): ?>
        <tr>
            <td class="small" style="white-space:nowrap"><?= e(date('d.m.Y H:i', strtotime((string)($h['t'] ?? '')))) ?></td>
            <td class="small"><?= e(user_display((string)($h['wer'] ?? ''))) ?></td>
            <td class="url-cell" title="<?= e((string)($h['von'] ?? '')) ?>"><?= e(mb_strimwidth((string)($h['von'] ?? ''), 0, 44, '…')) ?></td>
            <td class="url-cell" title="<?= e((string)($h['nach'] ?? '')) ?>"><?= e(mb_strimwidth((string)($h['nach'] ?? ''), 0, 44, '…')) ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>
<?php page_footer(); ?>
