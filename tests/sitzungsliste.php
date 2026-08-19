<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Liste der angemeldeten Geräte – festgeschrieben, dass sie sich leert.
 *
 * Sie tat es einmal nicht. Ein Eintrag entstand bei jeder Anmeldung und ging
 * nur wieder weg, wenn ihn die Obergrenze von zehn verdrängte oder jemand ihn
 * von Hand widerrief. Die PHP-Sitzung dahinter verschwindet aber serverseitig
 * schon nach `session.gc_maxlifetime` – vielerorts nach 24 Minuten. Wer den
 * Rechner eine halbe Stunde stehen lässt, meldet sich beim nächsten Mal neu an
 * und bekommt einen zweiten Eintrag; der erste bleibt stehen. Nach ein paar
 * Tagen steht dort ein Dutzend „angemeldeter Geräte", obwohl es zwei sind.
 *
 * Gefährlich war das nicht – hereinkommen konnte mit den toten Einträgen
 * niemand, die Sitzung war ja weg. Aber eine Liste, die Angemeldete zeigt, die
 * es nicht gibt, ist genau dort wertlos, wo man sie braucht: beim Nachsehen,
 * ob jemand Fremdes drin ist.
 *
 * Die drei Sätze, auf die es ankommt:
 *   1. Was länger tot ist als die Frist, fliegt.
 *   2. Was frisch ist, bleibt – auch wenn es viele sind.
 *   3. Einträge ohne Zeitstempel bleiben. Sie stammen aus der Zeit vor dem
 *      Feld, und ein Rauswurf wäre ein Rauswurf aus dem Konto.
 *
 * Läuft ohne Server und ohne inc/config.php und legt nichts an.
 *
 * Aufruf: php tests/sitzungsliste.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

require_once __DIR__ . '/../inc/auth.php';

$fehler = 0;
$pruefe = function (string $was, bool $ok) use (&$fehler): void {
    printf("  %-58s %s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN');
    if (!$ok) $fehler++;
};

$frist = max(2 * (int)ini_get('session.gc_maxlifetime'), 86400);
$eintrag = fn(int $alterSekunden): array => [
    'seit' => date('c', time() - $alterSekunden),
    'zuletzt' => date('c', time() - $alterSekunden),
    'geraet' => 'Firefox · macOS',
];

// 1. Der Fall aus der Meldung: dieselben zwei Browser, über Tage.
$liste = [
    'a' => $eintrag(4 * 86400),   // vorgestern und älter – tot
    'b' => $eintrag(3 * 86400),
    'c' => $eintrag(2 * 86400),
    'd' => $eintrag(30),          // eben aktiv – lebt
];
$rest = sessions_prune($liste);
$pruefe('Karteileichen von vor Tagen fliegen', !isset($rest['a'], $rest['b'], $rest['c']));
$pruefe('die frische Sitzung bleibt', isset($rest['d']));

// 2. Nichts Frisches darf fallen – sonst fliegt jemand aus dem Konto.
$frisch = [];
for ($i = 0; $i < 12; $i++) $frisch['f' . $i] = $eintrag($i * 60);
$pruefe('zwölf frische Sitzungen bleiben vollzählig', count(sessions_prune($frisch)) === 12);

// 3. Die Frist selbst: knapp darunter bleibt, deutlich darüber fliegt.
$grenzfall = ['knapp' => $eintrag($frist - 3600), 'drueber' => $eintrag($frist + 3600)];
$rest = sessions_prune($grenzfall);
$pruefe('knapp innerhalb der Frist bleibt', isset($rest['knapp']));
$pruefe('jenseits der Frist fliegt', !isset($rest['drueber']));

// 4. Die Frist liegt weit über der Lebensdauer einer Sitzung. Läge sie
//    darunter, würden Einträge lebender Sitzungen entfernt – und deren
//    Besitzer beim nächsten Aufruf abgemeldet.
$pruefe('die Frist übertrifft gc_maxlifetime deutlich',
    $frist >= 2 * (int)ini_get('session.gc_maxlifetime') && $frist >= 86400);

// 5. Altbestand ohne Zeitstempel bleibt unangetastet.
$alt = ['x' => ['seit' => date('c', time() - 9 * 86400), 'geraet' => 'Safari · iOS']];
$pruefe('Einträge ohne Zeitstempel bleiben', count(sessions_prune($alt)) === 1);

// 6. Eine leere Liste bleibt leer und wirft nicht.
$pruefe('leere Liste bleibt leer', sessions_prune([]) === []);

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
