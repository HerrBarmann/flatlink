<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';

$user = auth_require();

$code = (string)($_GET['c'] ?? '');
$link = lookup_code_ok($code) ? link_get($code) : null;
if ($link === null || !link_access($user, $link)) {
    http_response_code(404);
    page_header('Statistik', true);
    echo '<div class="card"><p>Link nicht gefunden (oder kein Zugriff).</p><p><a class="btn" href="index.php">Zurück</a></p></div>';
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
    echo "Tag;Klicks\n";
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

page_header('Statistik', true);
?>
<div class="card">
    <h2>Statistik <span class="muted">für</span> <?= e(short_url($code)) ?></h2>
    <p class="muted">Ziel: <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($link['url'], 0, 80, '…')) ?></a></p>
    <div class="stat-row">
        <div class="stat"><strong><?= (int)$clicks['n'] ?></strong><span>Klicks gesamt</span></div>
        <div class="stat"><strong><?= array_sum($series) ?></strong><span>letzte 30 Tage</span></div>
        <div class="stat"><strong><?= $clicks['last'] ? e(date('d.m.Y', strtotime($clicks['last']))) : '–' ?></strong><span>letzter Klick</span></div>
        <div class="stat"><strong><?= e(date('d.m.Y', strtotime($link['created']))) ?></strong><span>erstellt</span></div>
    </div>

    <h3>Klicks der letzten 30 Tage</h3>
    <?php
    // Balkendiagramm als Inline-SVG, 30 Balken
    $bw = 20; $gap = 4; $h = 120;
    $w = 30 * ($bw + $gap) - $gap;
    echo '<div class="table-scroll"><svg viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" width="' . $w . '" class="bars" role="img" aria-label="Klicks pro Tag">';
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
    <h3>Monatsübersicht</h3>
    <div class="table-scroll"><table>
        <tr><th>Monat</th><th>Klicks</th></tr>
        <?php foreach ($months as $m => $n): ?>
        <tr><td><?= e(date('m/Y', strtotime($m . '-01'))) ?></td><td><?= (int)$n ?></td></tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>

    <p><a class="btn" href="index.php">Zurück zu den Links</a>
       <a class="btn" href="qrdesign.php?c=<?= e(rawurlencode($code)) ?>">QR-Designer</a>
       <a class="btn" href="stats.php?c=<?= e(rawurlencode($code)) ?>&amp;format=csv">CSV-Export</a></p>
</div>
<?php page_footer(); ?>
