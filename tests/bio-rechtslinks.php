<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Rechtslinks einer Bio-Seite – festgeschrieben, was durchkommt.
 *
 * Impressum und Datenschutz einer Bio-Seite trägt der Besitzer selbst ein.
 * Beides landet in der Fußzeile als Verweis, und ein Verweis ist die Stelle,
 * an der `javascript:` gefährlich wird.
 *
 * Die Prüfung stand einmal nur beim Ausgeben. Sie war dort korrekt, aber das
 * Muster ist das fragilere: Es hält nur so lange, wie JEDER künftige
 * Ausgabepfad durch dieselbe Funktion geht. Ein Export, eine API-Antwort oder
 * eine zweite Vorlage hätten den Schutz nicht gehabt. Seitdem prüft schon
 * `bio_write()`, und im Datensatz steht nichts Untaugliches mehr.
 *
 * Erlaubt ist zweierlei:
 *   1. eine absolute http(s)-Adresse, die dieselbe Prüfung besteht wie ein
 *      Linkziel,
 *   2. ein harmloser relativer Pfad auf eine Seite dieser Instanz.
 *
 * Läuft ohne Server; es wird nichts geschrieben.
 *
 * Aufruf: php tests/bio-rechtslinks.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
if (!is_file(__DIR__ . '/../inc/config.php')) {
    exit("inc/config.php fehlt – bitte aus inc/config.example.php anlegen.\n");
}

require_once __DIR__ . '/../inc/bio.php';

$fehler = 0;
$pruefe = function (string $ein, bool $erwartet) use (&$fehler): void {
    $ok = bio_legal_pruefen($ein) !== null;
    printf("  %-40s %-12s %s\n", $ein === '' ? '(leer)' : mb_substr($ein, 0, 38),
        $ok ? 'angenommen' : 'abgewiesen', $ok === $erwartet ? 'ok' : 'FEHLGESCHLAGEN');
    if ($ok !== $erwartet) $fehler++;
};

// Was durchkommen muss
$pruefe('https://example.org/impressum', true);
$pruefe('impressum.html', true);
$pruefe('datenschutz.php', true);
$pruefe('recht/impressum.html', true);

// Was nicht durchkommen darf
$pruefe('javascript:alert(1)', false);
$pruefe('JavaScript:alert(1)', false);
$pruefe('  javascript:alert(1)  ', false);
$pruefe('data:text/html;base64,PHNjcmlwdD4=', false);
$pruefe('vbscript:msgbox(1)', false);
$pruefe('../../etc/passwd', false);
$pruefe('recht/../../etc/passwd', false);
$pruefe('', false);
$pruefe(str_repeat('a', 301), false);
$pruefe('ftp://example.org/x', false);

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
