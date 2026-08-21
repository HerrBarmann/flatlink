<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft die Klickzählung nach dem Umbau auf Anhängen + späte Verdichtung.
 *
 * Die Zusage ist einfach und darf nie brechen: Egal wann gefaltet wird und
 * wie oft – am Ende stehen exakt die Zahlen, die die alte
 * Lesen-Ändern-Schreiben-Zählung geliefert hätte. Jeder Aufruf zählt einmal,
 * Herkunft, Weichen und Bio-Ziele landen in ihren Töpfen, und das Aufruflimit
 * sieht auch die noch ungefalteten Zeilen.
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

echo "Klickzählung: anhängen, falten, stimmen\n\n";

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

pruefe('Vor dem Falten: Protokoll da, Basis noch leer',
    is_file(clicks_log_file($code)) && (json_read(clicks_file($code))['n'] ?? 0) === 0);

// ---- Das Limit sieht ungefaltete Zeilen ------------------------------------

$link = link_get($code);
$link['max_visits'] = 21;
pruefe('Limit 21: bei 15 Scans + 6 Bots ausgeschöpft (Bio-Ziele zählen nicht)',
    link_ausgeschoepft($code, $link) === true);
$link['max_visits'] = 22;
pruefe('Limit 22: noch nicht ausgeschöpft', link_ausgeschoepft($code, $link) === false);

// ---- Falten ----------------------------------------------------------------

$c = clicks_get($code);
$heute = date('Y-m-d');
pruefe('n zählt die 15 Scans', (int)($c['n'] ?? 0) === 15);
pruefe('n_roh zählt alle 21 Auslieferungen', (int)($c['n_roh'] ?? 0) === 21);
pruefe('Tageszähler stimmt', (int)($c['days'][$heute] ?? 0) === 15);
pruefe('Herkunft gefaltet', (int)($c['refs']['such.example'] ?? 0) === 15);
pruefe('Sprache gefaltet', (int)($c['langs']['de'] ?? 0) === 15);
pruefe('Weiche 2 fünfmal benutzt', (int)($c['routes']['2'] ?? 0) === 5);
pruefe('Bio-Ziel 7 viermal', (int)($c['items']['7']['n'] ?? 0) === 4);
pruefe('Protokoll nach dem Falten leer', filesize(clicks_log_file($code)) === 0);

// ---- F1 (Review 4.2.0): keine Zeile beschreibt einen einzelnen Besuch ------

clicks_bump($code);   // ein frischer Aufruf mit Merkmalen
$zeilen = file(clicks_log_file($code), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$mitMerkmal = array_filter($zeilen, fn($z) => str_contains($z, '"h":')
    || str_contains($z, 'refs') || str_contains($z, 'devs') || str_contains($z, 'langs'));
pruefe('Das Protokoll enthält KEINE Merkmale mehr – nur Zählzeilen',
    $mitMerkmal === [] && $zeilen !== []);
pruefe('Merkmale kommen trotzdem in den Töpfen an (direkt gezählt)',
    (int)(clicks_dims_of($code)['refs']['such.example'] ?? 0) >= 16);

// Altbestand aus 4.1/4.2: eine Tupelzeile wird weiterhin korrekt gefaltet
file_put_contents(clicks_log_file($code),
    json_encode(['d' => date('Y-m-d'), 'h' => ['refs' => 'altbestand.example'], 'w' => 9]) . "\n",
    FILE_APPEND);
$cAlt = clicks_get($code);
pruefe('Alte Tupelzeile zählt Besuch UND Merkmal (Merkmal landet in der Tabelle)',
    (int)$cAlt['n'] === 17 && (int)($cAlt['refs']['altbestand.example'] ?? 0) === 1
    && (int)($cAlt['routes']['9'] ?? 0) === 1
    && (int)(clicks_dims_of($code)['refs']['altbestand.example'] ?? 0) === 1);

// ---- Der Deckel: das Protokoll wächst nicht unbegrenzt ----------------------

for ($i = 0; $i < 900; $i++) clicks_bump($code);
clearstatcache();
pruefe('Anhängen faltet selbst, bevor das Protokoll groß wird',
    filesize(clicks_log_file($code)) <= CLICKS_FOLD_AB + 4096,
    filesize(clicks_log_file($code)) . ' Bytes');
pruefe('… und die Zählung stimmt trotzdem aufs Stück',
    (int)clicks_get($code)['n'] === 917);

// ---- Sicherung enthält nur Summen -------------------------------------------

clicks_bump($code);
require_once __DIR__ . '/../inc/zip.php';
require_once __DIR__ . '/../inc/backup.php';
[$zip] = backup_build();
clearstatcache();
pruefe('Sicherung faltet vorher: Protokoll danach leer',
    filesize(clicks_log_file($code)) === 0 && strlen($zip) > 1000);
pruefe('… ohne dass ein Aufruf verloren ginge', (int)clicks_get($code)['n'] === 918);

// ---- Nochmal falten ändert nichts ------------------------------------------

$c2 = clicks_get($code);
pruefe('Zweites Falten ist ein Leerlauf', $c2 == clicks_get($code));

// ---- Weiterzählen nach dem Falten ------------------------------------------

clicks_bump($code);
$c3 = clicks_get($code);
pruefe('Nach dem Falten geht es nahtlos weiter', (int)$c3['n'] === 919 && (int)$c3['n_roh'] === 925);

// ---- Aufräumen -------------------------------------------------------------

link_delete($code);
// Auch die Sperrdatei: Sie blieb bis 4.5 liegen – ein Inode je gelöschtem
// Link. Auf Shared Hosting ist das Inode-Kontingent die eigentliche
// Obergrenze, und ein Leck darin fällt erst auf, wenn nichts mehr geht.
pruefe('Testlink samt Protokoll, Töpfen UND Sperrdatei entfernt',
    !is_file(clicks_file($code)) && !is_file(clicks_log_file($code))
    && !is_file(clicks_file($code) . '.lock')
    && clicks_dims_of($code) === []);

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
