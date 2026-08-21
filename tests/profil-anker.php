<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Sprungmarken im Profil – festgeschrieben, dass sie zusammenpassen.
 *
 * Das Profil ist eine lange Seite. Wer unten einen Zugangsschlüssel anlegt,
 * landete nach dem Absenden wieder ganz oben und musste sich zurückscrollen –
 * bei den Schlüsseln und dem Verbindungscode, wo man mehrere Schritte
 * hintereinander macht, jedes Mal aufs Neue. Seitdem hängt an der Umleitung
 * ein Anker.
 *
 * Der bleibt nur so lange richtig, wie die Marke im HTML wirklich existiert.
 * Ein Tippfehler fällt sonst niemandem auf: Der Browser springt einfach nicht,
 * und das sieht aus wie vorher. Deshalb hier zwei Prüfungen:
 *
 *   1. Jeder Anker, auf den umgeleitet wird, kommt als id im HTML vor.
 *   2. Jede Aktion, die auf profile.php zurückführt, hat einen Anker – außer
 *      denen, die bewusst keinen brauchen.
 *
 * Läuft ohne Server und ohne inc/config.php; die Datei wird nur gelesen.
 *
 * Aufruf: php tests/profil-anker.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}

$quelle = (string)file_get_contents(__DIR__ . '/../admin/profile.php');

$fehler = 0;
$pruefe = function (string $was, bool $ok) use (&$fehler): void {
    printf("  %-58s %s\n", $was, $ok ? 'ok' : 'FEHLGESCHLAGEN');
    if (!$ok) $fehler++;
};

// Welche Marken vergibt die Seite? Seit 4.4 sitzen die ids an den
// einklappbaren <details>-Abschnitten, nicht mehr an den Überschriften.
preg_match_all('/<details class="abschnitt" id="([a-z0-9-]+)"/', $quelle, $m);
$vorhanden = $m[1];
$pruefe('die Seite vergibt Sprungmarken', count($vorhanden) >= 7);

// Welche Anker steuert der match()-Ausdruck an?
preg_match_all("/=> '#([a-z0-9-]+)'/", $quelle, $m2);
$angesteuert = array_unique($m2[1]);
$pruefe('es wird auf Anker umgeleitet', count($angesteuert) >= 6);

foreach ($angesteuert as $a) {
    $pruefe("Anker #$a hat eine Entsprechung im HTML", in_array($a, $vorhanden, true));
}

// Jede Aktion braucht einen Anker – bis auf die, die keine Seite ausliefern
// (die drei Passkey-Fälle antworten mit JSON) und den Standardfall.
preg_match_all("/\\\$action === '([a-z_]+)'/", $quelle, $m3);
$aktionen = array_unique($m3[1]);
$ohne = ['pk_challenge', 'pk_register', 'pk_remove'];  // JSON bzw. über den JSON-Weg
foreach ($aktionen as $a) {
    if (in_array($a, $ohne, true)) continue;
    $trifft = str_starts_with($a, 'token_') || str_starts_with($a, 'totp_')
        || preg_match("/\\\$action === '" . preg_quote($a, '/') . "'[^=]*=> '#/", $quelle) === 1
        || strpos($quelle, "\$action === '$a'," ) !== false
        || strpos($quelle, "\$action === '$a'  " ) !== false;
    $pruefe("Aktion '$a' kehrt zu einem Abschnitt zurück", $trifft);
}

// Die Umleitung muss eine VOLLSTÄNDIGE Adresse tragen. Ein relativer
// Location-Header wird von manchen vorgeschalteten Servern in eine absolute
// Adresse umgeschrieben – und dabei geht das Fragment verloren. Der Anker
// stimmt dann, aber man landet trotzdem oben. Auf einem Apache ohne Proxy
// fällt das nie auf, weshalb es nur auf einer von zwei Instanzen auftrat.
// Seit 4.4 sind die Abschnitte eingeklappt; der Rücksprung muss den
// Ziel-Abschnitt serverseitig öffnen (?zeige=…), sonst landet man zwar am
// Anker, sieht aber eine zugeklappte Zeile – ohne JavaScript für immer.
$pruefe('der Rücksprung öffnet den Abschnitt serverseitig (?zeige=)',
    str_contains($quelle, "'?zeige=' . substr(\$anker, 1)")
    && str_contains($quelle, "\$auf = fn(string \$id): string => \$zeige === \$id ? ' open' : ''"));

$pruefe('die Umleitung nennt eine vollständige Adresse',
    str_contains($quelle, "base_url() . '/admin/profile.php'"));
$pruefe('keine relative Umleitung mehr übrig',
    !str_contains($quelle, "redirect_to('profile.php"));

// Und der Stil muss den Absprung abfangen, sonst klebt die Überschrift oben.
$css = (string)file_get_contents(__DIR__ . '/../assets/style.css');
$pruefe('der Stil hält Abstand zum Fensterrand', str_contains($css, 'scroll-margin-top'));

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
