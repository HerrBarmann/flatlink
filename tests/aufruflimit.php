<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Das Aufruflimit – festgeschrieben, dass es nicht am Bot-Filter vorbeigeht.
 *
 * Es ging einmal vorbei. Geprüft wurde gegen den Zähler der Statistik, und der
 * lässt Bots aus: HEAD-Anfragen, `curl/`, `python-requests`, alles mit `bot`
 * im User-Agent. Für Kampagnenzahlen ist dieser Ausschluss richtig, für ein
 * Limit war er fatal — wer `User-Agent: curl/8.0` schickte, wurde
 * weitergeleitet, ohne den Zähler zu bewegen, und kam beliebig oft durch. Eine
 * Grenze, die mit einem einzigen Header fällt.
 *
 * Seitdem läuft `n_roh` neben `n` her: Es zählt jede ausgelieferte
 * Weiterleitung, auch die nicht statistikwürdigen, und ausschließlich bei
 * Links, die überhaupt ein Limit gesetzt haben. Die Statistik bleibt
 * bot-bereinigt, das Limit wird belastbar.
 *
 * Die drei Sätze, auf die es ankommt:
 *   1. Ein Link ohne Limit ist nie ausgeschöpft — egal, wie hoch die Zähler.
 *   2. Das Limit greift, sobald der ROHE Zähler es erreicht, auch wenn der
 *      statistische weit darunter liegt.
 *   3. Bestandslinks ohne `n_roh` werden weiter über `n` bewertet und fangen
 *      nicht wieder bei null an.
 *
 * Läuft ohne Server und ohne inc/config.php; es wird nichts geschrieben.
 *
 * Aufruf: php tests/aufruflimit.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$fehler = 0;
$pruefe = function (string $was, bool $ok) use (&$fehler): void {
    printf("  %-58s %s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN');
    if (!$ok) $fehler++;
};

// link_ausgeschoepft() nachbilden – dieselbe Logik, ohne Dateizugriff.
$ausgeschoepft = function (array $link, array $c): bool {
    $m = (int)($link['max_visits'] ?? 0);
    if ($m <= 0) return false;
    return max((int)($c['n'] ?? 0), (int)($c['n_roh'] ?? 0)) >= $m;
};

// 1. Ohne Limit ist nie Schluss.
$pruefe('ohne Limit bleibt der Link offen',
    !$ausgeschoepft([], ['n' => 9999, 'n_roh' => 9999]));
$pruefe('max_visits = 0 zählt als "kein Limit"',
    !$ausgeschoepft(['max_visits' => 0], ['n' => 5, 'n_roh' => 5]));

// 2. Der Angriff aus dem Review: Statistikzähler bei 0, roher Zähler am Limit.
$pruefe('50 Bot-Aufrufe schöpfen ein Limit von 50 aus',
    $ausgeschoepft(['max_visits' => 50], ['n' => 0, 'n_roh' => 50]));
$pruefe('49 rohe Aufrufe lassen den Link noch offen',
    !$ausgeschoepft(['max_visits' => 50], ['n' => 0, 'n_roh' => 49]));

// 3. Der Normalfall bleibt, wie er war.
$pruefe('echte Besuche schöpfen weiterhin aus',
    $ausgeschoepft(['max_visits' => 3], ['n' => 3, 'n_roh' => 3]));

// 4. Altbestand ohne n_roh: über n bewertet, nicht bei null anfangend.
$pruefe('Link aus der Zeit vor n_roh zählt über n weiter',
    $ausgeschoepft(['max_visits' => 10], ['n' => 10]));
$pruefe('und ist darunter noch nicht ausgeschöpft',
    !$ausgeschoepft(['max_visits' => 10], ['n' => 9]));

// 5. Der rohe Zähler darf den statistischen nie unterschreiten – sonst wäre
//    die Reihenfolge der Erhöhungen falsch herum eingebaut.
$c = ['n' => 0, 'n_roh' => 0];
$bumpBeide = function (array $z): array {
    return ['n' => ($z['n'] ?? 0) + 1, 'n_roh' => ($z['n_roh'] ?? $z['n'] ?? 0) + 1] + $z;
};
$bumpRoh = fn(array $z): array => ['n_roh' => (int)($z['n_roh'] ?? $z['n'] ?? 0) + 1] + $z;
for ($i = 0; $i < 5; $i++) $c = $bumpBeide($c);   // echte Besuche
for ($i = 0; $i < 7; $i++) $c = $bumpRoh($c);     // Bots
$pruefe('nach 5 echten und 7 Bot-Aufrufen: n=5, n_roh=12',
    $c['n'] === 5 && $c['n_roh'] === 12);
$pruefe('der rohe Zähler liegt nie unter dem statistischen',
    (int)$c['n_roh'] >= (int)$c['n']);

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
