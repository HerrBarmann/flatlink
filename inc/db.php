<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Das SQLite-Backend – optional, für große Instanzen.
 *
 * Die Vorgabe bleibt die Datei-Ablage: kein Setup, Backup = Ordner kopieren.
 * Ab einigen zehntausend Konten oder wenn Listen über sehr große Bestände
 * gefiltert werden sollen, stößt eine einzelne JSON-Datei an PHPs
 * Speichergrenze – dafür gibt es diesen Schalter:
 *
 *     'storage' => 'sqlite',
 *
 * SQLite ist dabei keine Infrastruktur: eine Datei unter data/, die
 * Erweiterung pdo_sqlite bringt praktisch jedes PHP mit, es gibt keinen
 * Server, nichts zu warten. Das Backup bleibt das Kopieren des Ordners.
 *
 * WAS in die Datenbank wandert – und was nicht: Links und Konten, denn deren
 * Vollscans waren die gemessene Grenze. Klickzähler bleiben Einzeldateien
 * (der Weiterleitungspfad schreibt sie bei jedem Scan – gerade dort soll
 * kein gemeinsames Schreib-Lock entstehen), ebenso Einstellungen, Gruppen,
 * Logos, Rate-Limits und offene Bestätigungen: alles klein, nichts davon
 * wächst mit dem Bestand.
 *
 * WIE gespeichert wird: Der vollständige Datensatz liegt als JSON in der
 * Spalte `data` – dieselbe Wahrheit wie in der Datei-Ablage, nur anders
 * abgelegt. Die übrigen Spalten (owner, grp, type, created, email, role)
 * sind daraus abgeleitete Kopien für WHERE und ORDER BY; geschrieben werden
 * sie ausschließlich zusammen mit `data`, nie einzeln. Ein Wechsel zurück
 * zur Datei-Ablage ist damit jederzeit ein simples Auslesen.
 */

/**
 * Die Verbindung dieser Anfrage – oder null, wenn die Instanz auf der
 * Datei-Ablage läuft. Alle Backend-Weichen im Projekt hängen an diesem null.
 */
function db(): ?PDO
{
    static $pdo = null;
    static $aus = false;
    if ($aus) return null;
    if ($pdo !== null) return $pdo;

    if ((string)cfg('storage') !== 'sqlite') {
        $aus = true;
        return null;
    }

    $datei = (string)cfg('sqlite_file');
    if ($datei === '') $datei = data_path() . '/flatlink.sqlite';

    $pdo = new PDO('sqlite:' . $datei, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: Leser blockieren keine Schreiber und umgekehrt – das Gegenstück
    // zum Sperren einzelner Ablagen. busy_timeout wartet kurze Konflikte ab,
    // statt sie als Fehler an den Besucher durchzureichen.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    db_schema($pdo);
    return $pdo;
}

/** Das Schema – bei jedem Start geprüft, CREATE IF NOT EXISTS ist billig */
function db_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS links (
        code    TEXT PRIMARY KEY,
        owner   TEXT,
        grp     TEXT,
        type    TEXT NOT NULL DEFAULT \'random\',
        created TEXT,
        data    TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS links_owner ON links(owner)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS links_grp ON links(grp)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS links_created ON links(created)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        name  TEXT PRIMARY KEY,
        email TEXT,
        role  TEXT,
        data  TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS users_email ON users(email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS users_role ON users(role)');
}

// ---- Links ----------------------------------------------------------------

/** Einen Link-Datensatz mitsamt der abgeleiteten Spalten schreiben */
function db_link_put(PDO $pdo, string $code, array $l): void
{
    $st = $pdo->prepare('REPLACE INTO links (code, owner, grp, type, created, data)
        VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([
        $code,
        isset($l['owner']) && is_string($l['owner']) && $l['owner'] !== '' ? $l['owner'] : null,
        isset($l['group']) && is_string($l['group']) && $l['group'] !== '' ? $l['group'] : null,
        (string)($l['type'] ?? 'random'),
        (string)($l['created'] ?? ''),
        json_encode($l, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

/** @return array<string,array> code => Datensatz */
function db_links_rows(PDOStatement $st): array
{
    $out = [];
    while (($zeile = $st->fetch()) !== false) {
        $d = json_decode((string)$zeile['data'], true);
        if (is_array($d)) $out[(string)$zeile['code']] = $d;
    }
    return $out;
}

// ---- Konten ---------------------------------------------------------------

function db_user_put(PDO $pdo, string $name, array $u): void
{
    $st = $pdo->prepare('REPLACE INTO users (name, email, role, data) VALUES (?, ?, ?, ?)');
    $st->execute([
        $name,
        isset($u['email']) && is_string($u['email']) && $u['email'] !== '' ? strtolower($u['email']) : null,
        (string)($u['role'] ?? 'user'),
        json_encode($u, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function db_user_get(PDO $pdo, string $name): ?array
{
    $st = $pdo->prepare('SELECT data FROM users WHERE name = ?');
    $st->execute([$name]);
    $zeile = $st->fetch();
    if ($zeile === false) return null;
    $d = json_decode((string)$zeile['data'], true);
    return is_array($d) ? $d : null;
}

/** @return array<string,array> name => Datensatz */
function db_users_all(PDO $pdo): array
{
    $out = [];
    $st = $pdo->query('SELECT name, data FROM users');
    while (($zeile = $st->fetch()) !== false) {
        $d = json_decode((string)$zeile['data'], true);
        if (is_array($d)) $out[(string)$zeile['name']] = $d;
    }
    return $out;
}

/**
 * Geänderte Konten zurückschreiben – als Unterschied zwischen vorher und
 * nachher, nicht als Komplett-Neuschrieb: Ein Vorgang, der ein Konto
 * anfasst, soll nicht hunderttausend unveränderte Zeilen schreiben.
 */
function db_users_diff(PDO $pdo, array $vorher, array $nachher): void
{
    foreach ($vorher as $name => $u) {
        if (!isset($nachher[$name])) {
            $st = $pdo->prepare('DELETE FROM users WHERE name = ?');
            $st->execute([(string)$name]);
        }
    }
    foreach ($nachher as $name => $u) {
        if (!isset($vorher[$name]) || $vorher[$name] !== $u) {
            db_user_put($pdo, (string)$name, $u);
        }
    }
}
