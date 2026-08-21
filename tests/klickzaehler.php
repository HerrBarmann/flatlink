<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft die Klickzählung, seit sie ohne Datei auskommt (5.2).
 *
 * Die Zusage ist einfach und darf nie brechen: Jeder Aufruf zählt einmal,
 * Herkunft, Weichen und Bio-Ziele landen in ihren Töpfen, das Aufruflimit
 * sieht den vollen Stand – und Zählstände aus jeder früheren Ablage werden
 * vollständig übernommen: die verdichtete Basis (bis 5.0), das
 * Anhang-Protokoll (bis 5.1) samt seiner Zeilenformate aus 4.1 bis 4.3.
 *
 * Aufruf: php tests/klickzaehler.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/routing.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? " – $detail" : '');
}

echo "Klickzählung ohne Datei\n\n";

$code = 'test-klick';
link_delete($code);
[$ok] = link_create('https://example.org/klick', $code, null, 'custom');
if (!$ok) exit("Testlink ließ sich nicht anlegen.\n");

// Herkunft vorgeben, damit click_dims etwas zu melden hat
$_SERVER['HTTP_REFERER'] = 'https://such.example/ergebnis';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';

// ---- 25 Aufrufe, gemischt -------------------------------------------------

for ($i = 0; $i < 10; $i++) clicks_bump($code);              // gewöhnliche Scans
for ($i = 0; $i < 5; $i++)  clicks_bump($code, null, 2);     // über Weiche 2
for ($i = 0; $i < 4; $i++)  clicks_bump($code, 7);           // Bio-Ziel 7
for ($i = 0; $i < 6; $i++)  clicks_roh_bump($code);          // Bot-Aufrufe (nur roh)

$basisRoh = static function (string $c): int {
    $st = db()->prepare("SELECT n FROM clickbase WHERE schluessel = ? AND item = ''");
    $st->execute([$c]);
    $n = (int)$st->fetchColumn();
    $st->closeCursor();
    return $n;
};
pruefe('Der Stand steht sofort in der Tabelle, ohne Datei',
    $basisRoh($code) === 15 && !is_file(clicks_log_file($code)));

// ---- Das Limit sieht den vollen Stand --------------------------------------

$link = link_get($code);
$link['max_visits'] = 21;
pruefe('Limit 21: bei 15 Scans + 6 Bots ausgeschöpft (Bio-Ziele zählen nicht)',
    link_ausgeschoepft($code, $link) === true);
$link['max_visits'] = 22;
pruefe('Limit 22: noch nicht ausgeschöpft', link_ausgeschoepft($code, $link) === false);

// ---- Die Zahlen ------------------------------------------------------------

$c = clicks_get($code);
$heute = date('Y-m-d');
pruefe('n zählt die 15 Scans', (int)($c['n'] ?? 0) === 15);
pruefe('n_roh zählt alle 21 Auslieferungen', (int)($c['n_roh'] ?? 0) === 21);
pruefe('Tageszähler stimmt', (int)($c['days'][$heute] ?? 0) === 15);
pruefe('Herkunft gefaltet', (int)($c['refs']['such.example'] ?? 0) === 15);
pruefe('Sprache gefaltet', (int)($c['langs']['de'] ?? 0) === 15);
pruefe('Weiche 2 fünfmal benutzt', (int)($c['routes']['2'] ?? 0) === 5);
pruefe('Bio-Ziel 7 viermal', (int)($c['items']['7']['n'] ?? 0) === 4);

// ---- F1 (Review 4.2.0): kein Datensatz je Aufruf ---------------------------
//
// Der Befund von damals lautete: Solange Merkmale protokolliert wurden,
// verriet ihre Nachbarschaft im Protokoll, was zusammengehörte. Seit 5.2
// gibt es das Protokoll gar nicht mehr – es entsteht nirgends ein Eintrag
// je Aufruf, nur Summen.
clicks_bump($code);
pruefe('Es entsteht keine Datei je Aufruf',
    glob(data_path('clicks') . '/' . rawurlencode($code) . '*') === []);
pruefe('Merkmale kommen trotzdem in den Töpfen an',
    (int)(clicks_dims_of($code)['refs']['such.example'] ?? 0) >= 16);

// Altbestand aus 4.1/4.2: eine Tupelzeile wird weiterhin korrekt eingelesen
file_put_contents(clicks_log_file($code),
    json_encode(['d' => date('Y-m-d'), 'h' => ['refs' => 'altbestand.example'], 'w' => 9]) . "\n",
    FILE_APPEND);
$cAlt = clicks_get($code);
pruefe('Alte Tupelzeile zählt Besuch UND Merkmal (Merkmal landet in der Tabelle)',
    (int)$cAlt['n'] === 17 && (int)($cAlt['refs']['altbestand.example'] ?? 0) === 1
    && (int)($cAlt['routes']['9'] ?? 0) === 1
    && (int)(clicks_dims_of($code)['refs']['altbestand.example'] ?? 0) === 1);
pruefe('… und das eingelesene Protokoll ist danach weg',
    !is_file(clicks_log_file($code)));

// ---- Viele Aufrufe ---------------------------------------------------------

for ($i = 0; $i < 900; $i++) clicks_bump($code);
clearstatcache();
pruefe('900 Aufrufe hinterlassen keine einzige Datei',
    glob(data_path('clicks') . '/' . rawurlencode($code) . '*') === []);
pruefe('… und die Zählung stimmt aufs Stück',
    (int)clicks_get($code)['n'] === 917);

// ---- Sicherung enthält nur Summen -------------------------------------------

clicks_bump($code);
require_once __DIR__ . '/../inc/zip.php';
require_once __DIR__ . '/../inc/backup.php';
[$zip] = backup_build();
pruefe('Die Sicherung trägt nur Summen hinaus', strlen($zip) > 1000);
pruefe('… ohne dass ein Aufruf verloren ginge', (int)clicks_get($code)['n'] === 918);

// ---- Zweimal lesen ändert nichts -------------------------------------------

$c2 = clicks_get($code);
pruefe('Zweites Lesen ist ein Leerlauf', $c2 == clicks_get($code));

clicks_bump($code);
$c3 = clicks_get($code);
pruefe('Weiterzählen geht nahtlos', (int)$c3['n'] === 919 && (int)$c3['n_roh'] === 925);

// ---- Gezählt wird NACH der Weiterleitung (Review 5.2.0, F2) ---------------
//
// Seit 5.2 ist Zählen eine Schreib-Transaktion. Steht sie vor dem
// Location-Kopf, wartet der Besucher auf das Schreib-Lock – gemessen 3,92 s,
// wenn eine Massenänderung es gerade klammert. weiterleitung() dreht das um.
//
// Geprüft wird hier der Quelltext, nicht das Verhalten: Der Unterschied zeigt
// sich nur unter einem gehaltenen Lock, und ein Test, der auf Zeitfenster
// baut, wird früher oder später launisch. Was diese Prüfung sicher kann, ist
// den Rückfall aufhalten – dass jemand die Reihenfolge wieder umdreht.
foreach (['go.php', 'inc/bio.php'] as $datei) {
    $q = (string)file_get_contents(__DIR__ . '/../' . $datei);
    // Ein clicks_bump/clicks_roh_bump, dem ein header('Location' FOLGT, wäre
    // Ein clicks_bump/clicks_roh_bump, dem ein header('Location' FOLGT, wäre
    // die alte Reihenfolge. Innerhalb des Nachlaufs steht der Kopf davor.
    // Der Punkt steht für das Anführungszeichen – so bleibt das Muster frei
    // von Escape-Klimmzügen.
    $alteReihenfolge = preg_match(
        '/clicks_(roh_)?bump\([^;]*;[\s\S]{0,200}?header\(\s*.Location/', $q) === 1;
    pruefe("$datei zählt nicht vor dem Location-Kopf", !$alteReihenfolge);
}
// Die Bio-Seite zählte bis 5.2.2 vor dem ersten Byte Ausgabe (Review 5.2.2,
// F1). Sie ist die öffentliche Landeseite – bei gehaltenem Schreib-Lock sah
// der Besucher fünf Sekunden lang nichts. Gemessen mit eingebauter Sonde:
// Ausgabe nach 22 ms, Zählung danach 233 ms bei busy_timeout 200.
$bio = (string)file_get_contents(__DIR__ . '/../inc/bio.php');
pruefe('inc/bio.php zählt nicht vor der Seitenausgabe',
    preg_match('/clicks_bump\([^;]*;[\s\S]{0,400}?echo\s+.<!doctype/', $bio) !== 1);

// Und die Sprache: Commit 95f5121 hat das festverdrahtete lang="de" aus
// helpers.php und routing.php geholt, die Bio-Seite aber übersehen. Auf einer
// englischen Instanz behauptete ausgerechnet die öffentlichste Seite, sie sei
// deutsch – ein Verstoß gegen WCAG 3.1.1, an einer Stelle, für die es eine
// Muster-Selbsterklärung gibt.
foreach (['inc/bio.php', 'inc/routing.php', 'inc/helpers.php'] as $datei) {
    pruefe("$datei verdrahtet keine Sprache fest",
        !str_contains((string)file_get_contents(__DIR__ . '/../' . $datei), 'html lang="de"'));
}

pruefe('weiterleitung() gibt Sitzungssperre und Wartezeit frei',
    str_contains((string)file_get_contents(__DIR__ . '/../inc/routing.php'), 'session_write_close')
    && str_contains((string)file_get_contents(__DIR__ . '/../inc/routing.php'), 'busy_timeout = 200'));

// ---- Aufräumen -------------------------------------------------------------

link_delete($code);
// Seit 5.1 steht der Zählstand in drei Tabellen statt in Dateien; geprüft
// wird, dass ALLE mitgehen. Die Dateiprüfungen bleiben stehen, weil die
// Sperrdatei bis 4.5 liegen blieb – ein Inode je gelöschtem Link, und auf
// Shared Hosting ist das Kontingent die eigentliche Obergrenze.
pruefe('Testlink samt Protokoll, Töpfen UND Sperrdatei entfernt',
    !is_file(clicks_file($code)) && !is_file(clicks_log_file($code))
    && !is_file(clicks_file($code) . '.lock')
    && clicks_dims_of($code) === []
    && $basisRoh($code) === 0
    && (int)db()->query("SELECT COUNT(*) FROM clickdays WHERE schluessel = '" . $code . "'")->fetchColumn() === 0);

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
