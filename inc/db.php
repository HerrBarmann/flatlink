<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Die Ablage für Links und Konten: eine SQLite-Datei unter data/.
 *
 * Kein Server, nichts einzurichten, nichts zu warten – die Erweiterung
 * pdo_sqlite bringt praktisch jedes PHP mit, und das Backup bleibt das
 * Kopieren des data/-Ordners.
 *
 * Der vollständige Datensatz liegt als JSON in der Spalte `data`; die
 * übrigen Spalten (owner, grp, type, created, email, role) sind daraus
 * abgeleitete Kopien für WHERE und ORDER BY und werden ausschließlich
 * zusammen mit `data` geschrieben, nie einzeln.
 *
 * Klickzähler bleiben bewusst Einzeldateien (der Weiterleitungspfad
 * schreibt sie bei jedem Scan – dort soll kein gemeinsames Schreib-Lock
 * entstehen), ebenso Einstellungen, Gruppen, Logos, Rate-Limits und offene
 * Bestätigungen: alles klein, nichts davon wächst mit dem Bestand.
 */

/** Die Verbindung dieser Anfrage – eine je Prozess, mehr braucht es nicht */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Die eine Diagnose, die auf Shared Hosting weiterhilft: Fehlt die
    // Erweiterung, soll hier ein Satz stehen statt eines nackten Fatal Error.
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('Die PHP-Erweiterung pdo_sqlite fehlt. Sie gehört zur Standardausstattung – beim Hoster lässt sie sich in den PHP-Einstellungen einschalten.');
    }

    $pdo = new PDO('sqlite:' . db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // WAL: Leser blockieren keine Schreiber und umgekehrt. busy_timeout
    // wartet kurze Konflikte ab, statt sie als Fehler durchzureichen.
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
    // Zugangsschlüssel: nachgeschlagen wird über den Abdruck (Primärschlüssel),
    // aufgelistet je Konto. Beides ohne die übrigen Zeilen anzufassen – bei
    // jedem API-Aufruf die ganze Liste zu lesen war die eine Stelle, an der
    // die alte Ablage mit der Zahl der Konten mitwuchs.
    $pdo->exec('CREATE TABLE IF NOT EXISTS tokens (
        fingerprint TEXT PRIMARY KEY,
        id          TEXT,
        owner       TEXT,
        created     TEXT,
        data        TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS tokens_owner ON tokens(owner)');

    // Einmalige Übernahme einer vorhandenen tokens.json: Wer von einer
    // älteren Fassung kommt, soll seine Schlüssel behalten, ohne etwas zu
    // tun. Die Datei wird danach umbenannt statt gelöscht – ein Zurück
    // bleibt möglich, solange niemand sie wegräumt.
    $alt = data_path() . '/tokens.json';
    if (is_file($alt)) {
        foreach (json_read($alt) as $abdruck => $e) {
            if (is_array($e) && is_string($abdruck) && $abdruck !== '') {
                db_token_put($pdo, $abdruck, $e);
            }
        }
        @rename($alt, $alt . '.uebernommen');
    }
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

// ---- Zugangsschlüssel ------------------------------------------------------

function db_token_put(PDO $pdo, string $abdruck, array $e): void
{
    $st = $pdo->prepare('REPLACE INTO tokens (fingerprint, id, owner, created, data)
        VALUES (?, ?, ?, ?, ?)');
    $st->execute([
        $abdruck,
        (string)($e['id'] ?? ''),
        (string)($e['user'] ?? ''),
        (string)($e['created'] ?? ''),
        json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function db_token_get(PDO $pdo, string $abdruck): ?array
{
    $st = $pdo->prepare('SELECT data FROM tokens WHERE fingerprint = ?');
    $st->execute([$abdruck]);
    $zeile = $st->fetch();
    if ($zeile === false) return null;
    $d = json_decode((string)$zeile['data'], true);
    return is_array($d) ? $d : null;
}

/** Pfad der Datenbank-Datei (leer konfiguriert = data/flatlink.sqlite) */
function db_file(): string
{
    $datei = (string)cfg('sqlite_file');
    return $datei !== '' ? $datei : data_path() . '/flatlink.sqlite';
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
