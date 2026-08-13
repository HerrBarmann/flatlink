<?php
declare(strict_types=1);
/**
 * Einmalige Migration: eine große links.json → 256 kleine Ablagen.
 *
 * Warum: Der Weiterleitungspfad läuft bei jedem Scan eines gedruckten Codes.
 * Lag alles in einer Datei, musste er sie jedes Mal vollständig einlesen –
 * bei 100.000 Links rund 28 MB und 50 ms. Nach der Aufteilung liest er gut
 * hundert Kilobyte.
 *
 * Gefahrlos: Die Sammeldatei wird nicht gelöscht, sondern nur umbenannt.
 * Solange keine Ablagen existieren, liest die Anwendung weiter aus ihr –
 * die Instanz funktioniert also auch zwischen Dateiupload und Migration.
 *
 * Aufruf auf der Kommandozeile:
 *     php migrate-links.php --dry-run     Probelauf, ändert nichts
 *     php migrate-links.php               führt sie aus
 *
 * Oder im Browser, angemeldet als Administrator:
 *     /migrate-links.php                  Probelauf
 *     /migrate-links.php?run=1            führt sie aus
 */
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/auth.php';

$cli = PHP_SAPI === 'cli';
if ($cli) {
    $dry = in_array('--dry-run', $argv ?? [], true);
} else {
    auth_require_admin();
    header('Content-Type: text/plain; charset=UTF-8');
    $dry = ($_GET['run'] ?? '') !== '1';
}

$old = links_file();
$dir = data_path() . '/links';

if (is_dir($dir) && glob($dir . '/*.json') !== []) {
    exit("Diese Instanz läuft bereits auf der aufgeteilten Ablage. Nichts zu tun.\n");
}
if (!is_file($old)) {
    exit("Keine links.json gefunden – eine frische Instanz legt die Ablagen selbst an.\n");
}

$links = json_read($old);
$n = count($links);
if ($n === 0) {
    exit("Die links.json enthält keine Einträge. Nichts zu tun.\n");
}

// Nach Ablagen sortieren
$buckets = [];
foreach ($links as $code => $l) {
    $buckets[link_shard((string)$code)][(string)$code] = $l;
}
ksort($buckets);

printf("%s%d Links → %d Ablagen (%s KB Sammeldatei)\n",
    $dry ? '[Probelauf] ' : '', $n, count($buckets), number_format(filesize($old) / 1024, 0, ',', '.'));

$sizes = array_map(fn($b) => count($b), $buckets);
printf("  Einträge je Ablage: kleinste %d, größte %d, Schnitt %.1f\n",
    min($sizes), max($sizes), array_sum($sizes) / count($sizes));

if ($dry) {
    echo "\nEs wurde nichts geändert.\n";
    echo $cli ? "Zum echten Lauf ohne --dry-run aufrufen.\n"
              : "Zum echten Lauf ?run=1 an die Adresse hängen.\n";
    exit;
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
    exit("ABBRUCH: $check von $n Links in den Ablagen. Nichts geändert, links.json unverändert.\n");
}

// Erst jetzt zur Seite legen – ab hier greift die aufgeteilte Ablage
$backup = $old . '.vor-aufteilung';
rename($old, $backup);

echo "\nFertig: $n Links auf " . count($buckets) . " Ablagen verteilt.\n";
echo "Die alte Datei liegt als " . basename($backup) . " daneben.\n";
echo "Wenn alles läuft, kann sie weg – vorher ein paar Kurzlinks im Browser prüfen.\n";
echo "Diese Migrationsdatei kann anschließend vom Server gelöscht werden.\n";
