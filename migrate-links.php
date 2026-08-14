<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Einmalige Migration: eine große links.json → 256 kleine Ablagen.
 *
 * Warum: Der Weiterleitungspfad läuft bei jedem Scan eines gedruckten Codes.
 * Lag alles in einer Datei, musste er sie jedes Mal vollständig einlesen –
 * bei 100.000 Links rund 28 MB und 50 ms. Nach der Aufteilung liest er gut
 * hundert Kilobyte.
 *
 * Gefahrlos: Die Sammeldatei wird nicht gelöscht, sondern erst umbenannt,
 * nachdem nachgezählt wurde. Solange keine Ablagen existieren, liest die
 * Anwendung weiter aus ihr – die Instanz funktioniert also auch zwischen
 * Dateiupload und Migration.
 *
 * Kommandozeile:
 *     php migrate-links.php --dry-run     Probelauf, ändert nichts
 *     php migrate-links.php               führt sie aus
 *
 * Browser (als Administrator angemeldet): /migrate-links.php aufrufen.
 * Der Probelauf läuft von selbst, das Ausführen verlangt einen Klick –
 * bewusst per POST, damit sich der Vorgang nicht über ein eingebettetes
 * Bild auf einer fremden Seite auslösen lässt.
 */
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/auth.php';

$cli = PHP_SAPI === 'cli';
$run = false;

if ($cli) {
    $run = !in_array('--dry-run', $argv ?? [], true);
} else {
    auth_require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $run = ($_POST['run'] ?? '') === '1';
    }
}

/** Ausgabe je nach Umgebung: Klartext auf der Kommandozeile, Seite im Browser */
function fertig(string $titel, string $text, bool $cli, bool $knopf = false): never
{
    if ($cli) {
        echo $text . "\n";
        exit;
    }
    page_header($titel, true);
    echo '<div class="card narrow-wide"><h1>' . e($titel) . '</h1>'
        . '<div class="term">' . e($text) . '</div>';
    if ($knopf) {
        echo '<form method="post" action="" style="margin-top:1.2rem">' . csrf_field()
            . '<input type="hidden" name="run" value="1">'
            . '<button class="btn btn-primary" type="submit">Jetzt aufteilen</button>'
            . '</form>'
            . '<p class="muted small">Die alte Datei bleibt als Sicherung liegen.</p>';
    }
    echo '<p style="margin-top:1.2rem"><a class="btn" href="admin/settings.php">Zu den Einstellungen</a></p></div>';
    page_footer();
    exit;
}

$old = links_file();
$dir = data_path() . '/links';

if (is_dir($dir) && glob($dir . '/*.json') !== []) {
    fertig('Nichts zu tun', 'Diese Instanz läuft bereits auf der aufgeteilten Ablage.', $cli);
}
if (!is_file($old)) {
    fertig('Nichts zu tun', 'Keine links.json gefunden – eine frische Instanz legt die Ablagen selbst an.', $cli);
}

$links = json_read($old);
$n = count($links);
if ($n === 0) {
    fertig('Nichts zu tun', 'Die links.json enthält keine Einträge.', $cli);
}

// Nach Ablagen sortieren
$buckets = [];
foreach ($links as $code => $l) {
    $buckets[link_shard((string)$code)][(string)$code] = $l;
}
ksort($buckets);
$sizes = array_map('count', $buckets);

$bericht = sprintf("%d Links → %d Ablagen (%s KB Sammeldatei)\n", $n, count($buckets),
        number_format(filesize($old) / 1024, 0, ',', '.'))
    . sprintf("Einträge je Ablage: kleinste %d, größte %d, Schnitt %.1f", min($sizes), max($sizes),
        array_sum($sizes) / count($sizes));

if (!$run) {
    fertig('Probelauf', "[Probelauf – nichts geändert]\n\n" . $bericht
        . ($cli ? "\n\nZum echten Lauf ohne --dry-run aufrufen." : ''), $cli, !$cli);
}

if (!is_dir($dir)) mkdir($dir, 0700, true);
foreach ($buckets as $shard => $entries) {
    json_write($dir . '/' . $shard . '.json', $entries);
}

// Gegenprobe, bevor die Sammeldatei aus dem Weg geräumt wird
$check = 0;
foreach (glob($dir . '/*.json') as $f) {
    $check += count(json_read($f));
}
if ($check !== $n) {
    // Ablagen wieder entfernen; die Sammeldatei ist unberührt, es geht nichts verloren
    foreach (glob($dir . '/*.json') as $f) unlink($f);
    @rmdir($dir);
    fertig('Abgebrochen', "ABBRUCH: $check von $n Links in den Ablagen.\n"
        . 'Es wurde nichts geändert, links.json ist unverändert.', $cli);
}

// Erst jetzt umschalten – die Markierung entscheidet, ab wann gelesen wird
file_put_contents($dir . '/.aufgeteilt', "aufgeteilte Ablage, siehe inc/store.php\n");
rename($old, $old . '.vor-aufteilung');

fertig('Fertig', $bericht . "\n\n"
    . "Fertig: $n Links verteilt.\n"
    . "Die alte Datei liegt als links.json.vor-aufteilung daneben.\n"
    . "Wenn alles läuft, kann sie weg – vorher ein paar Kurzlinks prüfen.\n"
    . 'Diese Migrationsdatei kann anschließend vom Server gelöscht werden.', $cli);
