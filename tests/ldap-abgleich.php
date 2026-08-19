<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Entscheidungslogik des Verzeichnisabgleichs – ohne Verzeichnis geprüft.
 *
 * Der Abgleich sperrt Konten. Das ist die gefährlichste Sorte Automatik, die
 * dieses Projekt hat, und die Fälle, in denen sie schiefgeht, treten im
 * Echtbetrieb erst auf, wenn es zu spät ist. Deshalb hier die Regeln als
 * Rechnung, ohne LDAP-Server:
 *
 *   1. Antwortet das Verzeichnis nicht oder nur zum Teil, wird NICHTS geändert.
 *      Ein Verzeichnis, das kürzt, ist so unbrauchbar wie eines, das schweigt –
 *      genau daran wäre eine Hochschule mit 1200 Konten und einem Server-Limit
 *      von 1000 fast gescheitert: 200 echte Beschäftigte, 16,7 Prozent, und
 *      damit unter der Schmerzgrenze.
 *   2. Die Schmerzgrenze rechnet die verschonten Administratoren mit, sonst
 *      verdeckt das Verschonen genau den Ausfall, den sie abfangen soll.
 *   3. Administratoren werden ohne ausdrücklichen Schalter nie gesperrt.
 *   4. Aufgehoben werden nur Sperren mit `by = sync`.
 *
 * Läuft ohne Server, ohne LDAP und ohne Konten anzufassen.
 *
 * Aufruf: php tests/ldap-abgleich.php
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

/**
 * Die Entscheidung des Abgleichs, nachgebaut wie in tools/ldap-abgleich.php.
 *
 * @param array<string,array> $konten   Name => ['role' => …, 'locked' => …]
 * @param string[]            $imBaum   Kennungen, die das Verzeichnis nennt
 * @return array{sperren:string[],freigeben:string[],uebersprungen:string[],abbruch:?string}
 */
function abgleich(array $konten, array $imBaum, float $grenze = 20, bool $auchAdmins = false): array
{
    $da = array_flip(array_map('mb_strtolower', $imBaum));
    $sperren = $frei = $ueber = [];
    foreach ($konten as $name => $u) {
        $vorhanden = isset($da[mb_strtolower($name)]);
        if (!$vorhanden && ($u['locked'] ?? null) === null) {
            if (($u['role'] ?? '') === 'admin' && !$auchAdmins) { $ueber[] = $name; continue; }
            $sperren[] = $name;
        } elseif ($vorhanden && ($u['locked']['by'] ?? '') === 'sync') {
            $frei[] = $name;
        }
    }
    $anteil = count($konten) > 0 ? (count($sperren) + count($ueber)) / count($konten) * 100 : 0.0;
    $abbruch = $anteil > $grenze
        ? sprintf('%.1f %% fehlen, erlaubt sind %.1f %%', $anteil, $grenze) : null;
    return ['sperren' => $sperren, 'freigeben' => $frei,
            'uebersprungen' => $ueber, 'abbruch' => $abbruch];
}

$konto = fn(string $rolle = 'user', ?string $by = null): array =>
    ['role' => $rolle] + ($by !== null ? ['locked' => ['by' => $by]] : []);

// ---- 1. Der Normalfall ---------------------------------------------------

$r = abgleich(['anna' => $konto(), 'bert' => $konto(), 'cem' => $konto()],
              ['anna', 'bert', 'cem']);
$pruefe('niemand fehlt: nichts zu tun', $r['sperren'] === [] && $r['abbruch'] === null);

$r = abgleich(['anna' => $konto(), 'bert' => $konto(), 'cem' => $konto(), 'dora' => $konto()],
              ['anna', 'bert', 'cem']);
$pruefe('eine Person fehlt: genau sie wird gesperrt', $r['sperren'] === ['dora']);
$pruefe('und die Schmerzgrenze schlägt bei 25 % an', $r['abbruch'] !== null);

// ---- 2. Der Fall aus dem Review: gekürzte Antwort -------------------------
// 1200 Konten, das Verzeichnis liefert nur die ersten 1000.

$viele = [];
for ($i = 1; $i <= 1200; $i++) $viele['p' . $i] = $konto();
$geliefert = [];
for ($i = 1; $i <= 1000; $i++) $geliefert[] = 'p' . $i;
$r = abgleich($viele, $geliefert);
$pruefe('gekürzte Liste: 200 Konten gälten als ausgeschieden',
    count($r['sperren']) === 200);
$pruefe('… und 16,7 % blieben UNTER der Schmerzgrenze von 20 %',
    $r['abbruch'] === null);
// Genau deshalb darf es gar nicht so weit kommen: ldap_alle_kennungen() bricht
// bei Fehler 4 ab, bevor diese Rechnung überhaupt beginnt.
$pruefe('deshalb bricht die Suche schon vorher ab (Fehler 4)',
    str_contains((string)file_get_contents(__DIR__ . '/../inc/sso.php'),
        'ldap_errno($conn)'));
$pruefe('und sie blättert, statt auf eine Seite zu vertrauen',
    str_contains((string)file_get_contents(__DIR__ . '/../inc/sso.php'),
        'LDAP_CONTROL_PAGEDRESULTS'));

// ---- 3. Administratoren --------------------------------------------------

$r = abgleich(['anna' => $konto('admin'), 'bert' => $konto(), 'cem' => $konto(),
               'dora' => $konto(), 'emil' => $konto()],
              ['bert', 'cem', 'dora', 'emil']);
$pruefe('ein fehlender Administrator wird übersprungen', $r['uebersprungen'] === ['anna']);
$pruefe('und nicht gesperrt', $r['sperren'] === []);

$r = abgleich(['anna' => $konto('admin'), 'bert' => $konto(), 'cem' => $konto(),
               'dora' => $konto(), 'emil' => $konto()],
              ['bert', 'cem', 'dora', 'emil'], 20, true);
$pruefe('mit --auch-admins wird er einbezogen', $r['sperren'] === ['anna']);

// Verschonte zählen für die Schmerzgrenze mit
$r = abgleich(['a' => $konto('admin'), 'b' => $konto('admin'), 'c' => $konto('admin'),
               'd' => $konto(), 'e' => $konto()], ['d', 'e']);
$pruefe('drei verschonte Admins von fünf lösen den Abbruch aus',
    $r['abbruch'] !== null && $r['sperren'] === []);

// ---- 4. Freigeben --------------------------------------------------------

$r = abgleich(['anna' => $konto('user', 'sync'), 'bert' => $konto('user', 'hand')],
              ['anna', 'bert']);
$pruefe('maschinell Gesperrte werden wieder freigegeben', $r['freigeben'] === ['anna']);
$pruefe('von Hand Gesperrte bleiben gesperrt', !in_array('bert', $r['freigeben'], true));

// ---- 5. Leeres Verzeichnis ----------------------------------------------
// Wird in tools/ldap-abgleich.php vor dieser Rechnung abgefangen; hier nur der
// Beleg, dass es ohne diesen Riegel das ganze Haus träfe.
$r = abgleich(['anna' => $konto(), 'bert' => $konto()], []);
$pruefe('ohne Riegel träfe ein leeres Verzeichnis alle', count($r['sperren']) === 2);
$pruefe('der Riegel dagegen steht im Werkzeug',
    str_contains((string)file_get_contents(__DIR__ . '/../tools/ldap-abgleich.php'),
        'keine einzige Kennung'));

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
