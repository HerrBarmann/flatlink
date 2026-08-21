<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft die getrennten Namensräume (5.0).
 *
 * Bis 4.5 gehörte ein Code der Instanz: Wer eine zweite Domain eintrug,
 * konnte unter ihr sämtliche Kurzlinks aller anderen abrufen. Für einen
 * Dienst, bei dem Kunden ihre eigene Domain mitbringen, war das falsch – die
 * zweite Domain ist ja gerade der Wunsch nach einem zweiten Namensraum.
 *
 * Seit 5.0 bestimmen (Domain, Code) gemeinsam einen Link. Hier steht fest,
 * was das heißt:
 *
 *   1. Derselbe Code lässt sich unter zwei Domains unabhängig anlegen und
 *      führt an zwei verschiedene Ziele.
 *   2. Die Klickstände zählen getrennt – Datei, Protokoll und Merkmalstöpfe.
 *   3. Löschen trifft nur die eine Domain.
 *   4. Ein Umzug nimmt den Zählstand mit und scheitert, wenn der Code am
 *      Ziel schon vergeben ist.
 *   5. Die Hauptdomain ist der leere String – Altbestand bleibt gültig.
 *   6. Die Übernahme einer Datenbank aus der Zeit davor verliert nichts.
 *
 * Aufruf: php tests/namensraeume.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/domains.php';

$fehler = 0;
function pruefe(string $was, bool $ok, string $zusatz = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $zusatz !== '' ? '  (' . $zusatz . ')' : '');
}

const NS_A = 'kunde-a.test';
const NS_B = 'kunde-b.test';
const NS_CODE = 'ns-probe';

// Aufräumen, falls ein früherer Lauf abgebrochen ist
foreach (['', NS_A, NS_B] as $d) link_delete(NS_CODE, $d);

echo "Getrennte Namensräume\n";

// ---- 1. Derselbe Code, drei Domains, drei Ziele ---------------------------
[$okA] = link_create('https://ziel-a.example/', NS_CODE, null, 'custom', ['domain' => NS_A]);
[$okB] = link_create('https://ziel-b.example/', NS_CODE, null, 'custom', ['domain' => NS_B]);
[$okH] = link_create('https://ziel-haupt.example/', NS_CODE, null, 'custom', []);
pruefe('Derselbe Code ist unter drei Domains anlegbar', $okA && $okB && $okH);
pruefe('… und führt je Domain woanders hin',
    (link_get(NS_CODE, NS_A)['url'] ?? '') === 'https://ziel-a.example/'
    && (link_get(NS_CODE, NS_B)['url'] ?? '') === 'https://ziel-b.example/'
    && (link_get(NS_CODE)['url'] ?? '') === 'https://ziel-haupt.example/');

// Der Kern der Sache: Vor 5.0 hätte der zweite Aufruf hier false geliefert,
// weil der Code „schon vergeben" war – über alle Domains hinweg.
[$nochmal, $meldung] = link_create('https://andere.example/', NS_CODE, null, 'custom', ['domain' => NS_A]);
pruefe('Unter DERSELBEN Domain bleibt der Code vergeben', $nochmal === false, (string)$meldung);

// ---- 2. Klickstände zählen getrennt --------------------------------------
$_SERVER['HTTP_REFERER'] = 'https://such.example/';
for ($i = 0; $i < 5; $i++) clicks_bump(NS_CODE, null, null, NS_A);
for ($i = 0; $i < 2; $i++) clicks_bump(NS_CODE, null, null, NS_B);
clicks_bump(NS_CODE);
pruefe('Klicks zählen je Domain getrennt',
    (int)clicks_get(NS_CODE, NS_A)['n'] === 5
    && (int)clicks_get(NS_CODE, NS_B)['n'] === 2
    && (int)clicks_get(NS_CODE)['n'] === 1,
    sprintf('a=%d b=%d haupt=%d', (int)clicks_get(NS_CODE, NS_A)['n'],
        (int)clicks_get(NS_CODE, NS_B)['n'], (int)clicks_get(NS_CODE)['n']));
pruefe('… und liegen in verschiedenen Dateien',
    clicks_file(NS_CODE, NS_A) !== clicks_file(NS_CODE, NS_B)
    && clicks_file(NS_CODE, NS_A) !== clicks_file(NS_CODE));
pruefe('Die Hauptdomain behält den nackten Dateinamen',
    basename(clicks_file(NS_CODE)) === rawurlencode(NS_CODE) . '.json');
pruefe('Auch die Merkmalstöpfe sind getrennt',
    (int)(clicks_dims_of(NS_CODE, NS_A)['refs']['such.example'] ?? 0) === 5
    && (int)(clicks_dims_of(NS_CODE, NS_B)['refs']['such.example'] ?? 0) === 2);

// ---- 3. Löschen trifft nur eine Domain -----------------------------------
link_delete(NS_CODE, NS_B);
pruefe('Löschen trifft nur die eine Domain',
    link_get(NS_CODE, NS_B) === null
    && link_get(NS_CODE, NS_A) !== null
    && link_get(NS_CODE) !== null);
// Seit 5.1 liegt der Zählstand in Tabellen; die Zusage ist dieselbe wie
// vorher: Der Stand der einen Domain geht mit, der der anderen bleibt.
pruefe('… und räumt nur deren Zählstand weg',
    (int)clicks_get(NS_CODE, NS_B)['n'] === 0
    && (int)clicks_get(NS_CODE, NS_A)['n'] === 5
    && clicks_dims_of(NS_CODE, NS_B) === []
    && clicks_dims_of(NS_CODE, NS_A) !== []);
pruefe('… und lässt keine Datei des gelöschten zurück',
    !is_file(clicks_file(NS_CODE, NS_B)) && !is_file(clicks_log_file(NS_CODE, NS_B)));

// ---- 4. Umzug ------------------------------------------------------------
[$umzugOk, $umzugFehler] = link_move(NS_CODE, NS_A, '');
pruefe('Umzug auf eine belegte Domain wird abgelehnt', $umzugOk === false, (string)$umzugFehler);
pruefe('… und lässt beide Links unangetastet',
    (link_get(NS_CODE, NS_A)['url'] ?? '') === 'https://ziel-a.example/'
    && (link_get(NS_CODE)['url'] ?? '') === 'https://ziel-haupt.example/');

[$umzugOk2] = link_move(NS_CODE, NS_A, NS_B);
pruefe('Umzug auf eine freie Domain gelingt', $umzugOk2 === true);
pruefe('… der Datensatz kommt vollständig an',
    link_get(NS_CODE, NS_A) === null
    && (link_get(NS_CODE, NS_B)['url'] ?? '') === 'https://ziel-a.example/'
    && (link_get(NS_CODE, NS_B)['domain'] ?? '') === NS_B);
pruefe('… und der Zählstand zieht mit',
    (int)clicks_get(NS_CODE, NS_B)['n'] === 5
    && (int)(clicks_dims_of(NS_CODE, NS_B)['refs']['such.example'] ?? 0) === 5);

// ---- 5. Karten schlüsseln eindeutig --------------------------------------
$gesehen = [];
foreach (links_each() as $schluessel => $l) {
    if ((string)($l['_code'] ?? '') !== NS_CODE) continue;
    $gesehen[$schluessel] = (string)($l['domain'] ?? '');
}
pruefe('links_each() verliert keinen der gleichnamigen Links',
    count($gesehen) === 2 && in_array(NS_B, $gesehen, true) && in_array('', $gesehen, true),
    implode(' | ', array_keys($gesehen)));
pruefe('Der Schlüssel der Hauptdomain ist der nackte Code',
    isset($gesehen[NS_CODE]) && $gesehen[NS_CODE] === '');

// ---- 6. Die Nebenwege: was aus einem Datensatz herausgelesen wird --------
//
// Review 5.0.1, F1: Der Namensraum-Umbau hat die Auflösung erreicht, aber
// nicht jeden Weg, auf dem ein Datensatz nach außen geht. Drei hook_fire()
// schlugen ohne Domain nach und trugen bei gleichem Code die Daten des
// FREMDEN Links in die Webhook-Nutzlast.
//
// Die Behebung sitzt an der Wurzel: link_get() markiert, was es zurückgibt,
// mit dem Namensraum, in dem es gefunden wurde. Daraus folgt jeder
// `$l['domain']`-Leser – hook_link(), api_link(), short_url(), das Routing.
// Deshalb steht diese Invariante hier fest, und zwar auch gegen einen
// Datensatz, dessen gespeichertes Feld etwas anderes behauptet.
require_once __DIR__ . '/../inc/hooks.php';

$lb = link_get(NS_CODE, NS_B);
$lh = link_get(NS_CODE);
pruefe('link_get() markiert mit dem Namensraum, in dem es gefunden wurde',
    ($lb['domain'] ?? null) === NS_B && !isset($lh['domain']));
pruefe('… und legt den nackten Code dazu',
    ($lb['_code'] ?? '') === NS_CODE && ($lh['_code'] ?? '') === NS_CODE);

// Ein Datensatz, dessen JSON-Feld lügt: Die Spalte gewinnt. Ohne diese
// Zusicherung hinge jeder Leser daran, dass alle Schreibpfade das Feld
// mitpflegen – eine Annahme, die genau einmal gebrochen werden muss.
link_write(NS_CODE, function (?array $l) {
    if ($l === null) return false;
    $l['domain'] = 'gelogen.test';
    return $l;
}, NS_B);
pruefe('Ein widersprüchliches Feld im Datensatz sticht nicht',
    (link_get(NS_CODE, NS_B)['domain'] ?? '') === NS_B,
    'gelesen: ' . (link_get(NS_CODE, NS_B)['domain'] ?? '—'));
link_write(NS_CODE, function (?array $l) {
    if ($l === null) return false;
    $l['domain'] = NS_B;
    return $l;
}, NS_B);

// Die Nutzlast, die an einen Webhook ginge – über genau die Funktion, die
// link_create(), link_update() und link_set_disabled() dafür aufrufen.
//
// Bis 5.0.2 stand das Nachschlagen im Aufruf selbst. Ein Test konnte den
// Fehler von 5.0.1 deshalb nur am Quelltext festmachen: hook_fire() schickt
// über das Netz, und cfg('webhooks') lässt sich zur Laufzeit nicht umbiegen.
// Eine solche Prüfung hält zwar den Rückbau auf, bricht aber bei jeder
// Umformatierung. Seit link_ereignis() ist der Fehler selbst aufrufbar –
// geprüft wird die Sache statt ihrer Schreibweise.
$nutzB = link_ereignis(NS_CODE, NS_B);
$nutzH = link_ereignis(NS_CODE, '');
pruefe('Die Webhook-Nutzlast trägt das Ziel DIESES Links',
    $nutzB['url'] === 'https://ziel-a.example/' && $nutzH['url'] === 'https://ziel-haupt.example/',
    $nutzB['url'] . ' vs. ' . $nutzH['url']);
pruefe('… und eine Kurzadresse unter der richtigen Domain',
    str_contains($nutzB['short_url'], NS_B) && !str_contains($nutzH['short_url'], NS_B),
    $nutzB['short_url']);
pruefe('Die beiden Namensräume liefern verschiedene Nutzlasten',
    $nutzB['url'] !== $nutzH['url'] && $nutzB['short_url'] !== $nutzH['short_url']);

// Die Domain lässt sich nicht mehr versehentlich weglassen: Sie ist ein
// Pflichtargument. Genau ihr Fehlen war der Defekt von 5.0.1 – jetzt wäre es
// ein TypeError statt eines stillen Rückfalls auf die Hauptdomain.
$pflicht = false;
try {
    $ruf = 'link_ereignis';
    $ruf(NS_CODE);
} catch (ArgumentCountError) {
    $pflicht = true;
}
pruefe('Die Domain ist Pflicht, kein stiller Rückfall', $pflicht);

foreach (['', NS_A, NS_B] as $d) link_delete(NS_CODE, $d);

// ---- 7. Übernahme einer Datenbank von vor 5.0 ----------------------------
echo "\nÜbernahme aus der Zeit vor den Namensräumen\n";
$tmp = sys_get_temp_dir() . '/flatlink-ns-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/clicks', 0700, true);
$alt = new PDO('sqlite:' . $tmp . '/alt.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$alt->exec('CREATE TABLE links (code TEXT PRIMARY KEY, owner TEXT, grp TEXT,
    type TEXT NOT NULL DEFAULT \'random\', created TEXT, data TEXT NOT NULL)');
$alt->exec('CREATE TABLE clickdims (code TEXT, feld TEXT, wert TEXT, n INTEGER,
    PRIMARY KEY (code, feld, wert))');
$alt->exec("INSERT INTO links VALUES ('haupt','dennis',NULL,'custom','2026-01-01',
    '{\"url\":\"https://h.example\",\"owner\":\"dennis\"}')");
$alt->exec("INSERT INTO links VALUES ('beim-kunden','dennis',NULL,'custom','2026-02-01',
    '{\"url\":\"https://k.example\",\"domain\":\"kunde-a.test\"}')");
$alt->exec("INSERT INTO clickdims VALUES ('beim-kunden','dev','mobile',7)");
$alt->exec("INSERT INTO clickdims VALUES ('haupt','dev','desktop',3)");
file_put_contents($tmp . '/clicks/beim-kunden.json', '{"n":7,"last":"2026-02-01","days":{}}');
file_put_contents($tmp . '/clicks/beim-kunden.log', "{\"d\":\"2026-02-01\"}\n");
file_put_contents($tmp . '/clicks/haupt.json', '{"n":3,"last":"2026-01-01","days":{}}');

db_namensraeume($alt, $tmp . '/clicks');

$zeilen = $alt->query('SELECT domain, code FROM links ORDER BY code')->fetchAll();
pruefe('Kein Link geht verloren', count($zeilen) === 2);
pruefe('Die Domain aus dem Datensatz wird zur Spalte',
    ($zeilen[0]['domain'] ?? null) === 'kunde-a.test' && ($zeilen[0]['code'] ?? '') === 'beim-kunden'
    && ($zeilen[1]['domain'] ?? null) === '' && ($zeilen[1]['code'] ?? '') === 'haupt');
pruefe('Der Schlüssel ist danach zusammengesetzt',
    (string)$alt->query("SELECT COUNT(*) FROM pragma_index_info('sqlite_autoindex_links_1')")->fetchColumn() === '2');
pruefe('Klickdateien einer Zusatzdomain werden umbenannt',
    is_file($tmp . '/clicks/' . rawurlencode('kunde-a.test/beim-kunden') . '.json')
    && is_file($tmp . '/clicks/' . rawurlencode('kunde-a.test/beim-kunden') . '.log')
    && !is_file($tmp . '/clicks/beim-kunden.json'));
pruefe('Die der Hauptdomain bleiben, wo sie waren',
    is_file($tmp . '/clicks/haupt.json'));
$dims = $alt->query('SELECT code, n FROM clickdims ORDER BY code')->fetchAll();
pruefe('Auch die Merkmalstöpfe ziehen um',
    ($dims[0]['code'] ?? '') === 'haupt'
    && ($dims[1]['code'] ?? '') === 'kunde-a.test/beim-kunden'
    && (int)($dims[1]['n'] ?? 0) === 7);

// Ein zweiter Lauf darf nichts mehr tun – die Übernahme ist wiederholbar
db_namensraeume($alt, $tmp . '/clicks');
pruefe('Ein zweiter Lauf ist ein Leerlauf',
    (int)$alt->query('SELECT COUNT(*) FROM links')->fetchColumn() === 2);

// ---- Abbruch mittendrin --------------------------------------------------
//
// Der gefährlichste Zustand, den die Übernahme hinterlassen kann: Sie stirbt
// zwischen dem Umbenennen der alten Tabelle und dem Umfüllen. Beim nächsten
// Start legt `CREATE TABLE IF NOT EXISTS links` in db_schema() dann eine
// LEERE Tabelle in neuer Form an – und ein Schnellausstieg „domain-Spalte ist
// da, also fertig" ließe den ganzen Bestand verwaist in links_alt liegen.
// Genau dieser Zustand wird hier gebaut.
$alt->exec('ALTER TABLE links RENAME TO links_alt');
$alt->exec('CREATE TABLE links (domain TEXT NOT NULL DEFAULT \'\', code TEXT NOT NULL,
    owner TEXT, grp TEXT, type TEXT, created TEXT, data TEXT NOT NULL, PRIMARY KEY (domain, code))');
pruefe('Abbruch nachgestellt: links leer, links_alt voll',
    (int)$alt->query('SELECT COUNT(*) FROM links')->fetchColumn() === 0
    && (int)$alt->query('SELECT COUNT(*) FROM links_alt')->fetchColumn() === 2);

db_namensraeume($alt, $tmp . '/clicks');
pruefe('Der nächste Start bergt den Bestand',
    (int)$alt->query('SELECT COUNT(*) FROM links')->fetchColumn() === 2);
pruefe('… und lässt keine Leiche stehen',
    $alt->query("SELECT name FROM sqlite_master WHERE name = 'links_alt'")->fetchAll() === []);

$alt = null;
foreach (glob($tmp . '/clicks/*') ?: [] as $f) @unlink($f);
@unlink($tmp . '/alt.sqlite');
@rmdir($tmp . '/clicks');
@rmdir($tmp);

echo "\n" . ($fehler === 0 ? "Alles in Ordnung.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
