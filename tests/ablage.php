<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft die eine Ablage (4.0): Übernahme, Tabellen, Sitzungs-Handler.
 *
 * Der Umbau hat ein einziges Versprechen: KEIN DATENVERLUST. Jede bisherige
 * Datei wird eingelesen und umbenannt, nie gelöscht; jeder Wert kommt
 * unverändert in seiner Tabelle an. Dieses Versprechen steht hier fest –
 * zusammen mit den Eigenschaften, an denen sonst still etwas kaputtginge:
 * der Rundlauf der Typen durch die kv-Tabellen, das einmalige Einlösen von
 * Bestätigungen und das Sperrverhalten des Sitzungs-Handlers.
 *
 * Aufruf: php tests/ablage.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Nur auf der Kommandozeile.\n"); }

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/sso.php';

$_SESSION = [];
$fehler = 0;
function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? " – $detail" : '');
}

echo "Die eine Ablage\n\n";

// ---- 1) Übernahme: Dateien rein, nichts geht verloren ---------------------

// db_uebernahme() ist absichtlich von außen aufrufbar und idempotent – genau
// das nutzt dieser Test: Er legt Alt-Dateien hin, wie eine 3.x sie
// hinterlassen hätte, und lässt die Übernahme laufen.
$d = data_path();
$fixtures = [
    'groups.json' => ['test-ablage-gruppe' => ['name' => 'Prüfgruppe ÄÖÜ', 'perms' => ['api_access'], 'created' => '2026-01-01T00:00:00+00:00']],
    'pending-users.json' => ['test-ablage-wartend' => ['reason' => 'unbekannt', 'tries' => 3]],
];
foreach ($fixtures as $name => $inhalt) {
    file_put_contents($d . '/' . $name, json_encode($inhalt, JSON_UNESCAPED_UNICODE));
}
file_put_contents($d . '/pending/reg-' . str_repeat('ab', 32) . '.json',
    json_encode(['user' => 'test-ablage', 'expires' => time() + 3600]));
file_put_contents($d . '/audit.log',
    json_encode(['t' => '2026-01-02T00:00:00+00:00', 'wer' => 'test-ablage', 'aktion' => 'Übernahme-Probe']) . "\n");

db_uebernahme(db());
groups_all(true);

pruefe('Gruppe aus groups.json angekommen, Umlaute unversehrt',
    (group_get('test-ablage-gruppe')['name'] ?? '') === 'Prüfgruppe ÄÖÜ');
pruefe('SSO-Warteschlange angekommen',
    (int)(pending_users()['test-ablage-wartend']['tries'] ?? 0) === 3);
pruefe('Offene Bestätigung angekommen',
    pending_get('reg', str_repeat('ab', 32)) !== null);
$uebernommen = array_filter(['groups.json', 'pending-users.json', 'audit.log'],
    fn($f) => is_file($d . '/' . $f . '.uebernommen') && !is_file($d . '/' . $f));
pruefe('Alle Dateien umbenannt statt gelöscht', count($uebernommen) === 3,
    implode(' ', $uebernommen));
$alt = array_filter(audit_tail(500), fn($e) => ($e['aktion'] ?? '') === 'Übernahme-Probe');
pruefe('Audit-Zeile übernommen', $alt !== []);

// Nochmal laufen lassen: Es darf nichts doppelt entstehen
$audits = count(audit_tail(500));
db_uebernahme(db());
pruefe('Zweiter Lauf ist ein Leerlauf (idempotent)', count(audit_tail(500)) === $audits);

// ---- 2) Rundlauf der Typen durch die kv-Tabellen --------------------------

$sicherung = settings_stored();
$probe = ['zahl' => 42, 'wahr' => true, 'falsch' => false, 'liste' => [1, 2, 3],
          'tief' => ['a' => ['b' => 'c']], 'text' => 'Grüße & <Zeichen>'];
settings_save($probe + $sicherung);
$zurueck = settings_stored();
pruefe('Einstellungen: jeder Typ kommt zurück, wie er hineinging',
    array_intersect_key($zurueck, $probe) === $probe);
settings_save($sicherung);
pruefe('Entfernte Schlüssel verschwinden wirklich',
    !isset(settings_stored()['zahl']));

state_set('test-ablage', ['n' => 1]);
state_update('test-ablage', fn(array $s) => ['n' => $s['n'] + 1]);
pruefe('state_update liest-ändert-schreibt', (state_get('test-ablage')['n'] ?? 0) === 2);
state_set('test-ablage', []);

// ---- 3) Bestätigungen: genau einmal einlösbar ------------------------------

$token = pending_create('reg', ['user' => 'test-ablage']);
pruefe('Bestätigung lesbar ohne Verbrauch', pending_get('reg', $token) !== null
    && pending_get('reg', $token) !== null);
pruefe('Erste Einlösung klappt', pending_take('reg', $token) !== null);
pruefe('Zweite Einlösung wird abgewiesen', pending_take('reg', $token) === null);

// ---- 4) Der Sitzungs-Handler ----------------------------------------------

$h = new DbSitzungen();
$id = 'test-ablage-' . bin2hex(random_bytes(8));
pruefe('Unbekannte Sitzung liest sich leer', $h->read($id) === '');
$h->write($id, 'stand-eins');
$h->close();
pruefe('Geschriebenes kommt zurück', $h->read($id) === 'stand-eins');
$h->close();
pruefe('validateId kennt sie jetzt', $h->validateId($id));

// Das Lock: solange Anfrage A liest, wartet Anfrage B. Geprüft über einen
// Unterprozess, der dieselbe Sitzung anfasst, während wir sie halten.
// Nicht die Wartedauer schätzen (die hinge am Startverhalten des
// Unterprozesses), sondern Zeitpunkte vergleichen: B darf das Lock erst
// NACH As Freigabe bekommen haben.
$h->read($id);   // hält das flock
$code = 'require "' . __DIR__ . '/../inc/auth.php";'
    . '$h = new DbSitzungen();'
    . '$h->read(' . var_export($id, true) . ');'
    . 'printf("%.6f", microtime(true));'
    . '$h->close();';
$proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
usleep(400000);   // B soll jetzt sicher am Lock hängen
$freigabe = microtime(true);
$h->close();      // A gibt frei
$erhalten = (float)stream_get_contents($pipes[1]);
proc_close($proc);
pruefe('Zweiter Zugriff bekommt das Lock erst nach der Freigabe',
    $erhalten >= $freigabe, sprintf('%.0f ms danach', ($erhalten - $freigabe) * 1000));

$h->read($id);
$h->destroy($id);
$h->close();
pruefe('destroy entfernt Sitzung und Lock-Datei',
    !$h->validateId($id) && glob(data_path('locks') . '/sitzung-' . sha1($id) . '.lock') === []);

// gc räumt Altes ab, Frisches bleibt
$alt_id = 'test-ablage-alt';
db()->prepare('REPLACE INTO sessions (id, zugriff, data) VALUES (?, ?, ?)')
    ->execute([$alt_id, time() - 999999, 'alt']);
$h->write($id, 'frisch');
$h->gc(86400);
pruefe('gc: alte Sitzung weg, frische bleibt', !$h->validateId($alt_id) && $h->validateId($id));
$h->read($id); $h->destroy($id); $h->close();

// ---- 5) Audit: Reihenfolge und Werkzeug -----------------------------------

$vorher = audit_tail(1)[0]['t'] ?? '';
audit('Ablage-Probe eins');
audit('Ablage-Probe zwei');
$tail = audit_tail(2);
pruefe('audit_tail: neueste zuerst',
    ($tail[0]['aktion'] ?? '') === 'Ablage-Probe zwei' && ($tail[1]['aktion'] ?? '') === 'Ablage-Probe eins');
$cli = (string)shell_exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/flatlink') . ' audit --limit=2');
$zeilen = array_values(array_filter(explode("\n", trim($cli))));
pruefe('tools/flatlink audit liefert JSON-Zeilen, älteste zuerst',
    count($zeilen) === 2
    && (json_decode($zeilen[1], true)['aktion'] ?? '') === 'Ablage-Probe zwei');

// ---- Aufräumen ------------------------------------------------------------

db()->exec("DELETE FROM audit WHERE data LIKE '%Ablage-Probe%' OR data LIKE '%Übernahme-Probe%'");
db()->exec("DELETE FROM state WHERE key = 'test-ablage'");
group_delete('test-ablage-gruppe');
pending_user_drop('test-ablage-wartend');
foreach (glob($d . '/*.uebernommen') ?: [] as $f) {
    foreach (array_keys($fixtures) as $name) {
        if (basename($f) === $name . '.uebernommen') @unlink($f);
    }
    if (basename($f) === 'audit.log.uebernommen' && str_contains((string)file_get_contents($f), 'Übernahme-Probe')) @unlink($f);
}
foreach (glob($d . '/pending/*.uebernommen') ?: [] as $f) @unlink($f);
pruefe('Spuren beseitigt', group_get('test-ablage-gruppe') === null
    && !isset(pending_users()['test-ablage-wartend']));

echo "\n" . ($fehler === 0 ? "Alle Prüfungen bestanden.\n" : "$fehler Prüfung(en) fehlgeschlagen.\n");
exit($fehler === 0 ? 0 : 1);
