<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Lässt die Kommandozeilen-Werkzeuge einmal durchlaufen.
 *
 * Diese Prüfung sichert keine Ausgabe zu, nur eines: dass sie überhaupt
 * laufen. Das klingt bescheiden und fängt trotzdem eine Fehlerklasse ab, die
 * hier schon zweimal zugeschlagen hat:
 *
 *   5.0.1 – drei `hook_fire()`-Aufrufe wurden beim Namensraum-Umbau nicht
 *           mitgezogen;
 *   5.2.0 – `tools/backup-export.php` rief weiter `clicks_fold()`, eine
 *           Funktion, die derselbe Release entfernt hatte. Das Werkzeug
 *           starb mit einem Fatal Error, NACHDEM es schon Dateien ins Ziel
 *           geschrieben hatte – ein halber Sicherungsstand, und das
 *           ausgerechnet direkt nach einem Umbau der Datenhaltung.
 *
 * Beide Male ging es um einen Nebenweg, den kein Test berührte. Die
 * Web-Oberfläche wird von den übrigen Prüfungen abgedeckt, die Werkzeuge
 * bisher von keiner.
 *
 * Aufgerufen werden nur Befehle ohne Nebenwirkung – Auflisten und Hilfe –
 * plus ein Export in ein Wegwerf-Verzeichnis. Der Bestand bleibt unberührt.
 *
 * Aufruf: php tests/werkzeuge.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}

/** @return array{0:int,1:string} [Rückgabewert, Ausgabe samt Fehlerkanal] */
function lauf(string $befehl): array
{
    $ausgabe = [];
    $rc = 0;
    exec($befehl . ' 2>&1', $ausgabe, $rc);
    return [$rc, implode("\n", $ausgabe)];
}

$php = escapeshellarg(PHP_BINARY);
$wurzel = dirname(__DIR__);

echo "Die Kommandozeilen-Werkzeuge laufen durch\n";

$probe = 'werkzeug-probe';
link_delete($probe);
link_create('https://example.org/w', $probe, null, 'custom', []);

// ---- tools/flatlink: Befehle ohne Nebenwirkung ---------------------------
foreach ([
    'Hilfe'            => '',
    'konto:liste'      => 'konto:liste',
    'link:liste'       => 'link:liste --limit=3',
    'audit'            => 'audit --limit=3',
] as $was => $args) {
    [$rc, $aus] = lauf("$php " . escapeshellarg("$wurzel/tools/flatlink") . " $args");
    $kaputt = stripos($aus, 'Fatal error') !== false || stripos($aus, 'Uncaught') !== false
        || stripos($aus, 'Undefined function') !== false;
    pruefe("flatlink $was läuft durch", !$kaputt,
        $kaputt ? substr(preg_replace('/\s+/', ' ', $aus), 0, 100) : '');
}

// ---- tools/backup-export.php in ein Wegwerf-Verzeichnis -------------------
//
// Der Altbestand entsteht ERST HIER, unmittelbar vor dem Export. Legte man
// ihn oben an, hätte `link:liste` ihn längst eingelesen – clicks_get() tut
// das bei jedem Link – und die Zeile, um die es geht, würde nie erreicht.
// (Genau so ist mir dieser Test beim ersten Anlauf durchgerutscht: Er blieb
// grün, obwohl der Fehler wieder eingebaut war.)
file_put_contents(clicks_file($probe),
    json_encode(['n' => 5, 'last' => date('Y-m-d'), 'days' => [date('Y-m-d') => 5]]));
file_put_contents(clicks_log_file($probe), json_encode(['d' => date('Y-m-d')]) . "\n");
pruefe('Altbestand da: Basis und Protokoll von vor 5.2',
    is_file(clicks_file($probe)) && is_file(clicks_log_file($probe)));

$ziel = sys_get_temp_dir() . '/flatlink-werkzeug-' . bin2hex(random_bytes(4));
[$rc, $aus] = lauf("$php " . escapeshellarg("$wurzel/tools/backup-export.php") . ' ' . escapeshellarg($ziel));
$kaputt = stripos($aus, 'Fatal error') !== false || stripos($aus, 'Uncaught') !== false;
pruefe('backup-export läuft mit Altbestand durch', !$kaputt,
    $kaputt ? substr(preg_replace('/\s+/', ' ', $aus), 0, 120) : '');
pruefe('… und schreibt einen vollständigen Stand, keinen halben',
    is_file($ziel . '/datenbank.sql') && filesize($ziel . '/datenbank.sql') > 1000 && $rc === 0,
    'Rückgabewert ' . $rc);
pruefe('… und liest den Altbestand dabei ein',
    !is_file(clicks_file($probe)) && !is_file(clicks_log_file($probe)));
pruefe('… ohne einen Klick zu verlieren',
    (int)clicks_get($probe)['n'] === 6, 'n=' . (int)clicks_get($probe)['n']);

// ---- Aufräumen -----------------------------------------------------------
foreach (glob($ziel . '/*') ?: [] as $f) is_dir($f) ? null : @unlink($f);
$rmdir = static function (string $d) use (&$rmdir): void {
    foreach (glob($d . '/*') ?: [] as $f) is_dir($f) ? $rmdir($f) : @unlink($f);
    @rmdir($d);
};
$rmdir($ziel);
link_delete($probe);
pruefe('Testlink und Wegwerf-Verzeichnis entfernt',
    link_get($probe) === null && !is_dir($ziel));

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
