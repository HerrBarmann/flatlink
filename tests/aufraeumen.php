<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft das automatische Aufräumen ungenutzter Links – und vor allem, dass es
 * den Einstellungen folgt.
 *
 * Hier wird gelöscht, und Gelöschtes kommt nicht wieder: ein gedruckter
 * QR-Code, dessen Ziel verschwindet, ist ein Schild, das ins Leere zeigt.
 * Deshalb steht hier fest, was der Fall sein muss, bevor irgendetwas
 * verschwindet – und dass eine 0 in den Grundregeln wirklich alles anhält.
 *
 * Die Fristen kommen seit 3.9 aus settings(), nicht mehr nur aus
 * inc/config.php. settings() hält seinen Stand in einer statischen Variablen;
 * im Betrieb gilt je Anfrage genau eine Einstellung, und genauso läuft jedes
 * Szenario hier in einem eigenen Unterprozess.
 *
 * Aufruf: php tests/aufraeumen.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';

const GC_CODES = ['gc-anon-alt', 'gc-anon-jung', 'gc-mail-alt', 'gc-frisch'];

// ---- Unterprozess: einen Lauf mit einer Einstellung ausführen -------------
if (($argv[1] ?? '') !== '') {
    // Der Wochenmarker verhindert sonst den zweiten Lauf am selben Tag.
    @unlink(data_path() . '/links-gc.json');
    links_gc();
    $da = array_keys(links_all());
    echo implode(',', array_values(array_filter(GC_CODES, fn($c) => in_array($c, $da, true)))), "\n";
    exit;
}

$fehler = 0;
function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? " – $detail" : '');
}

echo "Aufräumen ungenutzter Links\n\n";

$sicherung = settings();
$warnDatei = data_path() . '/links-gc-warned.json';
$warnSicherung = is_file($warnDatei) ? (string)file_get_contents($warnDatei) : null;

/** Eine Einstellung setzen und einen Lauf in einem eigenen Prozess anstoßen. */
function lauf(int $kurz, int $lang, string $notiz = ''): array
{
    global $sicherung;
    settings_save([
        'link_gc_years' => $kurz,
        'link_gc_years_unreachable' => $lang,
        'link_gc_note' => $notiz,
    ] + $sicherung);
    $out = trim((string)shell_exec(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' lauf'));
    return $out === '' ? [] : explode(',', $out);
}

/** Ein Link mit zurückdatiertem Anlagedatum – nie aufgerufen. */
function alt_anlegen(string $code, ?string $owner, int $jahre): void
{
    link_delete($code);
    [$ok, $ergebnis] = link_create('https://example.org/' . $code, $code, $owner, 'link');
    if (!$ok) exit("Link $code ließ sich nicht anlegen: $ergebnis\n");
    @unlink(clicks_file($code));   // nie aufgerufen
    link_write($code, function (array $l) use ($jahre) {
        $l['created'] = date('c', strtotime('-' . $jahre . ' years'));
        return $l;
    });
}

// ---- Vorbereitung ---------------------------------------------------------
// Erst alles ausschalten: link_create() stößt selbst einen Lauf an, und der
// dürfte die eben gebauten Fälle sonst gleich wieder abräumen.
settings_save(['link_gc_years' => 0, 'link_gc_years_unreachable' => 0] + $sicherung);

$konto = 'test-aufraeumen';
if (user_get($konto) === null) {
    $err = user_add($konto, 'Pruef-Passwort-123!', 'user');
    if ($err !== null) exit("Konto ließ sich nicht anlegen: $err\n");
}
users_update(function (array $users) use ($konto) {
    $users[$konto]['email'] = 'aufraeumen@example.org';
    return $users;
}, $konto);

alt_anlegen('gc-anon-alt',  null,   6);   // niemand erreichbar, sehr alt
alt_anlegen('gc-anon-jung', null,   3);   // niemand erreichbar, unter der langen Frist
alt_anlegen('gc-mail-alt',  $konto, 3);   // erreichbar, über der kurzen Frist
alt_anlegen('gc-frisch',    $konto, 0);   // heute angelegt
@unlink($warnDatei);

// ---- Aus heißt aus --------------------------------------------------------

$da = lauf(0, 0);
pruefe('Bei 0 bleibt alles stehen', count($da) === 4, implode(' ', $da));

$da = lauf(0, 5);
pruefe('Auch eine lange Frist allein löscht nichts (kurze = 0 hält alles an)',
    count($da) === 4, implode(' ', $da));

// ---- Der erste echte Lauf: warnen, nicht löschen --------------------------

$da = lauf(2, 5);
pruefe('Anonymer Link über der langen Frist ist weg', !in_array('gc-anon-alt', $da, true));
pruefe('Anonymer Link unter der langen Frist bleibt', in_array('gc-anon-jung', $da, true));
pruefe('Frischer Link bleibt unangetastet', in_array('gc-frisch', $da, true));
pruefe('Erreichbarer Link wird zunächst nur gewarnt, nicht gelöscht',
    in_array('gc-mail-alt', $da, true));

$warnungen = json_read($warnDatei);
pruefe('… und steht als gewarnt vermerkt', isset($warnungen['gc-mail-alt']));

// ---- Die Quellenangabe in der Warnmail -----------------------------------
// Bis 3.9 stand dort fest „(AGB § 2)" – die Bedingungen von 1337.kiwi, auf
// jeder anderen Instanz eine Quellenangabe ins Nichts.

/** Was seit einer Marke in data/mail.log dazugekommen ist. */
function mailseit(int $ab): string
{
    $datei = data_path() . '/mail.log';
    if (!is_file($datei)) return '';
    $h = fopen($datei, 'rb');
    fseek($h, $ab);
    $neu = (string)stream_get_contents($h);
    fclose($h);
    return $neu;
}
function mailstand(): int
{
    $datei = data_path() . '/mail.log';
    return is_file($datei) ? (int)filesize($datei) : 0;
}

alt_anlegen('gc-mail-alt', $konto, 3);
@unlink($warnDatei);
$ab = mailstand();
lauf(2, 5, 'AGB § 2');
$post = mailseit($ab);
pruefe('Quellenangabe aus der Einstellung steht in der Warnmail', str_contains($post, 'automatisch gelöscht (AGB § 2):'));

alt_anlegen('gc-mail-alt', $konto, 3);
@unlink($warnDatei);
$ab = mailstand();
lauf(2, 5);
$post = mailseit($ab);
pruefe('Ohne Einstellung endet der Satz einfach – keine fremde Quelle',
    str_contains($post, 'automatisch gelöscht:') && !str_contains($post, 'gelöscht ('));

alt_anlegen('gc-mail-alt', $konto, 3);
@unlink($warnDatei);
lauf(2, 5);

// ---- Nach der Warnfrist ---------------------------------------------------

$da = lauf(2, 5);
pruefe('Direkt danach immer noch da – die 30 Tage laufen',
    in_array('gc-mail-alt', $da, true));

// Die Warnung 31 Tage zurückdatieren
$warnungen['gc-mail-alt'] = date('c', time() - 31 * 86400);
json_write($warnDatei, $warnungen);
$da = lauf(2, 5);
pruefe('31 Tage nach der Warnung gelöscht', !in_array('gc-mail-alt', $da, true));

// ---- Ein Aufruf setzt die Frist zurück ------------------------------------

alt_anlegen('gc-mail-alt', $konto, 3);
@unlink($warnDatei);
touch(clicks_file('gc-mail-alt'));   // heute aufgerufen
$da = lauf(2, 5);
pruefe('Ein einziger Aufruf rettet den Link', in_array('gc-mail-alt', $da, true));
pruefe('… und nimmt auch die Warn-Markierung zurück',
    !isset(json_read($warnDatei)['gc-mail-alt']));

// ---- Gesperrte Links bleiben ---------------------------------------------

alt_anlegen('gc-anon-alt', null, 6);
link_set_disabled('gc-anon-alt', true);
$da = lauf(2, 5);
pruefe('Gesperrte Links bleiben stehen, damit ihr Code nicht neu vergeben wird',
    in_array('gc-anon-alt', $da, true));

// ---- Aufräumen ------------------------------------------------------------

foreach (GC_CODES as $c) link_delete($c);
user_delete($konto);
settings_save($sicherung);
if ($warnSicherung !== null) { file_put_contents($warnDatei, $warnSicherung); } else { @unlink($warnDatei); }
pruefe('Testlinks und -konto wieder entfernt',
    array_intersect(GC_CODES, array_keys(links_all())) === [] && user_get($konto) === null);

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
