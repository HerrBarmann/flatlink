<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * DIE Ablage: eine SQLite-Datei unter data/, eine Naht für alles.
 *
 * Kein Server, nichts einzurichten, nichts zu warten – die Erweiterung
 * pdo_sqlite bringt praktisch jedes PHP mit, und das Backup bleibt das
 * Kopieren des data/-Ordners.
 *
 * Seit 4.0 liegt hier der gesamte Zustand, der wächst oder geteilt werden
 * muss: Links, Konten, Zugangsschlüssel, Einstellungen, Gruppen,
 * Logo-Metadaten, SSO-Warteschlange, E-Mail-Bestätigungen, Audit-Protokoll,
 * Sitzungen und die Betriebs-Marker (state). Das Muster ist überall
 * dasselbe: Der vollständige Datensatz liegt als JSON in der Spalte `data`,
 * die übrigen Spalten sind abgeleitete Kopien für WHERE und ORDER BY und
 * werden ausschließlich zusammen mit `data` geschrieben, nie einzeln.
 *
 * Diese Datei ist die eine Stelle, die ein anderes Backend (etwa MySQL)
 * ersetzen müsste – deshalb steht hier ausschließlich Standard-SQL plus
 * drei benannte Ausnahmen: die PRAGMAs beim Verbindungsaufbau, BEGIN
 * IMMEDIATE in den Schreib-Helfern und PRAGMA user_version als
 * Schema-Weiche.
 *
 * Was bewusst DRAUSSEN bleibt, jeweils mit Grund:
 *   - clicks/: je Code eine verdichtete Basis (.json) und ein
 *     Anhang-Protokoll (.log). Ein Scan hängt eine Zeile an – kein Lesen,
 *     kein gemeinsames Lock; verdichtet wird beim Lesen der Statistik.
 *     Das ist der Grund, warum eine CPU tausende Scans je Sekunde schafft.
 *   - ratelimit/: flüchtige Zähler auf heißen Fehlerpfaden; nach einem
 *     Verlust fängt die Zählung harmlos von vorn an.
 *   - secret.key: muss vor und unabhängig von der Datenbank existieren.
 *   - mail.log, links-gc.log: Betriebslogs zum Mitlesen, keine Daten.
 *   - logos/ (Binärdateien): werden als Dateien ausgeliefert; in der
 *     Datenbank liegen nur ihre Metadaten.
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

    // PERSISTENT ist hier der halbe Weiterleitungspfad: Der erste Zugriff
    // einer frischen Verbindung muss den WAL-Index einblenden und kostet
    // damit rund eine Millisekunde – mehr als alles Übrige einer
    // Weiterleitung zusammen. Eine wiederverwendete Verbindung im selben
    // PHP-Worker zahlt davon nichts (gemessen: 0,005 ms statt 1,2 ms).
    $pdo = new PDO('sqlite:' . db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => true,
    ]);
    // Der Preis der Wiederverwendung, sauber bezahlt: Stirbt ein Skript
    // zwischen BEGIN IMMEDIATE und COMMIT, brächte die Verbindung ihre
    // offene Transaktion mit in die nächste Anfrage – und hielte das
    // Schreib-Lock, bis der Worker stirbt. PDO::inTransaction() sieht per
    // exec() begonnene Transaktionen nicht, deshalb der blinde ROLLBACK:
    // Auf einer sauberen Verbindung wirft er und wird verschluckt.
    try { $pdo->exec('ROLLBACK'); } catch (PDOException) {}
    // busy_timeout wartet kurze Schreibkonflikte ab, statt sie als Fehler
    // durchzureichen. synchronous=NORMAL ist die für WAL empfohlene Stufe:
    // fsync nur am Checkpoint statt je Commit – ein Stromausfall kann die
    // letzten Augenblicke kosten, die Datei aber nie beschädigen.
    // (journal_mode=WAL steht in der Datei selbst und wird einmal in
    // db_schema() gesetzt, nicht bei jeder Verbindung – das Statement
    // gehörte zu den teuersten des ganzen Aufbaus. Ein foreign_keys-PRAGMA
    // entfällt: Das Schema erklärt bewusst keine Fremdschlüssel, die
    // Bezüge leben in den JSON-Dokumenten.)
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    // Ein einziger billiger Lesezugriff statt einem Dutzend CREATE IF NOT
    // EXISTS bei jeder Anfrage: Das Schema läuft nur, wenn die Fassung in
    // der Datei nicht die erwartete ist.
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() !== DB_FASSUNG) {
        db_schema($pdo);
    }
    return $pdo;
}

/**
 * Fassung des Schemas – bei jeder Änderung an db_schema() hochzählen.
 * db_schema() bleibt vollständig idempotent (CREATE IF NOT EXISTS, Übernahme
 * nur bei vorhandener Datei); die Nummer erspart nur die Prüfung.
 */
const DB_FASSUNG = 5;

/** Das Schema – läuft nur bei neuer DB_FASSUNG, siehe db() */
function db_schema(PDO $pdo): void
{
    // WAL steht persistent in der Datei – einmal hier gesetzt, gilt es für
    // jede künftige Verbindung, ohne dass die dafür bezahlt.
    $pdo->exec('PRAGMA journal_mode = WAL');
    // Ein Kurzlink wird seit 5.0 durch (domain, code) bestimmt, nicht mehr
    // allein durch den Code: Wer eine zweite Domain einträgt, will einen
    // zweiten Namensraum – kunde-a.example/shop und kunde-b.example/shop
    // sind zwei verschiedene Links. Die Hauptdomain trägt den leeren String;
    // so bleiben alle bestehenden Datensätze gültig, ohne umgeschrieben zu
    // werden. Aus demselben Grund steht die Domain vor dem Code: Der
    // Primärschlüssel-Index trägt damit auch die Abfrage „alle Links einer
    // Domain".
    $pdo->exec('CREATE TABLE IF NOT EXISTS links (
        domain  TEXT NOT NULL DEFAULT \'\',
        code    TEXT NOT NULL,
        owner   TEXT,
        grp     TEXT,
        type    TEXT NOT NULL DEFAULT \'random\',
        created TEXT,
        data    TEXT NOT NULL,
        PRIMARY KEY (domain, code)
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

    // ---- Der übrige Zustand (seit 4.0) ------------------------------------

    // Laufzeit-Einstellungen: Schlüssel → JSON-Wert. Vorher data/settings.json.
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    // Betriebs-Marker (GC-Stand, Warnliste, Safety-Zähler, Selbsttest):
    // dieselbe Form, aber ein eigener Namensraum. In einer Tabelle mit den
    // Einstellungen läge Maschinenzustand neben Admin-Entscheidungen, und
    // ein Export der Einstellungen nähme ihn ungewollt mit.
    $pdo->exec('CREATE TABLE IF NOT EXISTS state (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    // Gruppen: Kennung → Datensatz. Vorher data/groups.json.
    $pdo->exec('CREATE TABLE IF NOT EXISTS groups (
        id   TEXT PRIMARY KEY,
        data TEXT NOT NULL
    )');
    // Logo-Metadaten (die Bilddateien selbst bleiben unter data/logos/)
    $pdo->exec('CREATE TABLE IF NOT EXISTS logos (
        id   TEXT PRIMARY KEY,
        data TEXT NOT NULL
    )');
    // SSO-Warteschlange: Konten, die auf Freischaltung warten
    $pdo->exec('CREATE TABLE IF NOT EXISTS pending_users (
        name TEXT PRIMARY KEY,
        data TEXT NOT NULL
    )');
    // E-Mail-Bestätigungen und Passwort-Resets: kind-token → Datensatz.
    // expires als eigene Spalte, damit das Aufräumen ein DELETE ist.
    $pdo->exec('CREATE TABLE IF NOT EXISTS confirmations (
        id      TEXT PRIMARY KEY,
        expires INTEGER NOT NULL,
        data    TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS confirmations_expires ON confirmations(expires)');
    // Audit: fortlaufend, neueste zuletzt. INTEGER PRIMARY KEY ist in SQLite
    // die rowid und in MySQL später ein AUTO_INCREMENT – bewusst kein
    // AUTOINCREMENT-Schlüsselwort.
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit (
        id   INTEGER PRIMARY KEY,
        t    TEXT NOT NULL,
        data TEXT NOT NULL
    )');
    // Klick-Merkmale: zeitlos-kumulative Töpfe (Herkunft, Gerät, Sprache,
    // Weiche) je Code. Seit 4.4 werden sie DIREKT gezählt statt je Aufruf
    // protokolliert – ein UPSERT je Merkmal, und nirgends liegt je ein
    // Datensatz, der Merkmale eines einzelnen Besuchs verbindet (Review
    // 4.3.0, N1). Der Weiterleitungspfad verträgt das: verschränkt gemessen
    // über 100.000 Merkmal-Transaktionen je Sekunde bei acht Schreibern.
    $pdo->exec('CREATE TABLE IF NOT EXISTS clickdims (
        code TEXT NOT NULL,
        feld TEXT NOT NULL,
        wert TEXT NOT NULL,
        n    INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (code, feld, wert)
    )');

    // Der Zählstand eines Links – seit 5.1 in der Datenbank statt in zwei
    // Dateien je Link.
    //
    // Auf Shared Hosting ist nicht der Plattenplatz die Obergrenze, sondern
    // das Inode-Kontingent: Ein angeklickter Link belegte drei Inoden
    // (Basis, deren Sperrdatei, Anhang-Protokoll), bei üblichen 250.000
    // Inoden also rund 83.000 Links. Basis und Sperrdatei entfallen damit;
    // das Protokoll bleibt, weil es der heiße Pfad ist (siehe clicks_bump).
    //
    // `item` trennt den Link selbst ('') von den Zielen einer Bio-Seite
    // ('0', '1', …). So braucht es keine zweite Tabelle für dieselbe Sache.
    // Der Schlüssel ist derselbe wie bei clickdims und beim Dateinamen:
    // clicks_schluessel($code, $domain).
    $pdo->exec('CREATE TABLE IF NOT EXISTS clickbase (
        schluessel TEXT NOT NULL,
        item       TEXT NOT NULL DEFAULT \'\',
        n          INTEGER NOT NULL DEFAULT 0,
        n_roh      INTEGER NOT NULL DEFAULT 0,
        last       TEXT,
        PRIMARY KEY (schluessel, item)
    )');
    // Die Tageszähler. Eine Zeile je Link, Ziel und Tag – das ist die Form,
    // in der sie geschrieben werden (n = n + 1), ohne einen JSON-Block neu
    // zu bauen. Genau das war an der Basisdatei teuer.
    $pdo->exec('CREATE TABLE IF NOT EXISTS clickdays (
        schluessel TEXT NOT NULL,
        item       TEXT NOT NULL DEFAULT \'\',
        tag        TEXT NOT NULL,
        n          INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (schluessel, item, tag)
    )');

    // Sitzungen: id → serialisierte PHP-Sitzung. zugriff für das Aufräumen.
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        id      TEXT PRIMARY KEY,
        zugriff INTEGER NOT NULL,
        data    BLOB NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS sessions_zugriff ON sessions(zugriff)');

    db_namensraeume($pdo);
    db_uebernahme($pdo);
    $pdo->exec('PRAGMA user_version = ' . DB_FASSUNG);
}

/**
 * Einmalige Übernahme der bisherigen Datei-Ablagen.
 *
 * Das Muster stammt von der tokens.json-Übernahme und ist die Zusage gegen
 * Datenverlust: Jede Datei wird eingelesen und danach UMBENANNT statt
 * gelöscht – ein Zurück bleibt möglich, solange niemand sie wegräumt. Bricht
 * der Vorgang mittendrin ab, steht user_version noch auf der alten Fassung,
 * der nächste Aufruf setzt neu an, und REPLACE macht jeden Import beliebig
 * wiederholbar.
 *
 * PHP-Sitzungsdateien werden bewusst NICHT übernommen: Ihr Ablageort gehört
 * dem PHP des Hosters, nicht uns – dort fremde Dateien zu lesen wäre der
 * falsche Griff. Die eine sichtbare Folge: Nach diesem Update meldet sich
 * jeder einmal neu an.
 */
/**
 * Bestehende Datenbanken auf getrennte Namensräume heben (Fassung 3 → 4).
 *
 * `CREATE TABLE IF NOT EXISTS` lässt eine vorhandene Tabelle unangetastet –
 * eine vor 5.0 angelegte `links` hat deshalb weder die Spalte `domain` noch
 * den zusammengesetzten Schlüssel. Beides wird hier nachgezogen.
 *
 * Die Domain steht schon im Datensatz: Sie war bisher reine Anzeige (unter
 * welcher Adresse der Link ausgegeben wird), jetzt wird sie Teil der
 * Identität. Genau deshalb kann dabei nichts kollidieren – bisher war der
 * Code über ALLE Domains hinweg eindeutig, und das ist ein Sonderfall von
 * „je Domain eindeutig". Kein Link ändert seinen Code, keiner verschwindet.
 *
 * Umbenannt werden müssen dagegen die Klickstände von Links auf einer
 * Zusatzdomain: Ihre Dateien lagen unter dem nackten Code, ab jetzt liegen
 * sie unter „domain/code". Wer nur die Hauptdomain betreibt – der Regelfall –
 * hat hier nichts zu tun.
 */
function db_namensraeume(PDO $pdo, ?string $klickVerz = null): void
{
    $tabellen = array_map('strval', $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN));
    $spalten = array_map('strval', $pdo->query('PRAGMA table_info(links)')->fetchAll(PDO::FETCH_COLUMN, 1));
    // links_alt aufzuräumen ist wichtiger als der Schnellausstieg: Bräche ein
    // früherer Lauf zwischen Umbenennen und Löschen ab, hätte der Neustart
    // oben ein leeres `links` angelegt (CREATE TABLE IF NOT EXISTS greift ja) –
    // und der Schnellausstieg ließe den ganzen Bestand verwaist in links_alt
    // liegen. Deshalb erst prüfen, ob so eine Leiche herumsteht.
    if (in_array('domain', $spalten, true) && !in_array('links_alt', $tabellen, true)) return;

    // Eine Transaktion um den ganzen Umbau. SQLite kann DDL zurückrollen –
    // stirbt der Vorgang mittendrin, steht der Bestand unverändert da und
    // user_version bleibt auf der alten Fassung, sodass der nächste Start es
    // erneut versucht.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (!in_array('links_alt', $tabellen, true)) {
            $pdo->exec('ALTER TABLE links RENAME TO links_alt');
        } else {
            // Aus einem abgebrochenen Lauf: Was in `links` steht, stammt
            // nicht von hier (die Tabelle war leer, als sie entstand).
            $pdo->exec('DROP TABLE IF EXISTS links');
        }
        $pdo->exec('CREATE TABLE links (
            domain  TEXT NOT NULL DEFAULT \'\',
            code    TEXT NOT NULL,
            owner   TEXT,
            grp     TEXT,
            type    TEXT NOT NULL DEFAULT \'random\',
            created TEXT,
            data    TEXT NOT NULL,
            PRIMARY KEY (domain, code)
        )');
        // json_extract steht in jedem SQLite, das PHP 8.1 mitbringt – die
        // Domain aus dem Datensatz zu ziehen ist damit ein einziges
        // INSERT … SELECT statt eines Durchlaufs durch Millionen Zeilen in PHP.
        $pdo->exec("INSERT INTO links (domain, code, owner, grp, type, created, data)
            SELECT COALESCE(json_extract(data, '$.domain'), ''), code, owner, grp, type, created, data
            FROM links_alt");
        $pdo->exec('DROP TABLE links_alt');
        $pdo->exec('CREATE INDEX IF NOT EXISTS links_owner ON links(owner)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS links_grp ON links(grp)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS links_created ON links(created)');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable) {}
        throw $e;
    }

    // Erst NACH dem COMMIT: Ein Dateiumbenennen lässt sich nicht zurückrollen.
    // Klickstände der Zusatzdomains nachziehen – die Schleife läuft nur über
    // Links MIT Domain; auf den allermeisten Instanzen ist die Menge leer.
    // Bricht sie ab, ist der Schaden ein verlorener Zählstand, kein Link.
    $st = $pdo->query("SELECT domain, code FROM links WHERE domain <> ''");
    $verz = $klickVerz ?? data_path('clicks');
    while (($z = $st->fetch()) !== false) {
        $alt = $verz . '/' . rawurlencode((string)$z['code']);
        $neu = $verz . '/' . rawurlencode((string)$z['domain'] . '/' . (string)$z['code']);
        foreach (['.json', '.log', '.json.lock'] as $endung) {
            if (is_file($alt . $endung) && !file_exists($neu . $endung)) {
                @rename($alt . $endung, $neu . $endung);
            }
        }
        $u = $pdo->prepare('UPDATE clickdims SET code = ? WHERE code = ?');
        $u->execute([(string)$z['domain'] . '/' . (string)$z['code'], (string)$z['code']]);
    }
}

function db_uebernahme(PDO $pdo): void
{
    $d = data_path();

    $alt = $d . '/tokens.json';
    if (is_file($alt)) {
        foreach (json_read($alt) as $abdruck => $e) {
            if (is_array($e) && is_string($abdruck) && $abdruck !== '') {
                db_token_put($pdo, $abdruck, $e);
            }
        }
        @rename($alt, $alt . '.uebernommen');
    }

    // Schlüssel→Wert-Dateien in ihre jeweilige Tabelle
    foreach ([
        'settings.json' => 'settings',
        'groups.json' => 'groups',
        'logos.json' => 'logos',
        'pending-users.json' => 'pending_users',
    ] as $datei => $tabelle) {
        $pfad = $d . '/' . $datei;
        if (!is_file($pfad)) continue;
        $spalte = ['settings' => 'key', 'groups' => 'id', 'logos' => 'id', 'pending_users' => 'name'][$tabelle];
        $wert = $tabelle === 'settings' ? 'value' : 'data';
        $st = $pdo->prepare("REPLACE INTO $tabelle ($spalte, $wert) VALUES (?, ?)");
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach (json_read($pfad) as $k => $v) {
                $st->execute([(string)$k, json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        @rename($pfad, $pfad . '.uebernommen');
    }

    // Betriebs-Marker: mehrere kleine Dateien, ein Namensraum
    foreach ([
        'links-gc.json' => 'links-gc',
        'links-gc-warned.json' => 'links-gc-warned',
        'safety-fails.json' => 'safety-fails',
        'webroot-probe.json' => 'probe',
    ] as $datei => $key) {
        $pfad = $d . '/' . $datei;
        if (!is_file($pfad)) continue;
        db_state_set($pdo, $key, json_read($pfad));
        @rename($pfad, $pfad . '.uebernommen');
    }

    // Offene Bestätigungen: eine Datei je Vorgang → eine Zeile je Vorgang
    foreach (glob($d . '/pending/*.json') ?: [] as $pfad) {
        $e = json_read($pfad);
        $id = basename($pfad, '.json');
        if ($e !== []) {
            $st = $pdo->prepare('REPLACE INTO confirmations (id, expires, data) VALUES (?, ?, ?)');
            $st->execute([$id, (int)($e['expires'] ?? 0),
                json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        }
        @rename($pfad, $pfad . '.uebernommen');
    }

    // Audit-Protokoll: eine JSON-Zeile je Ereignis, Reihenfolge bleibt
    $pfad = $d . '/audit.log';
    if (is_file($pfad)) {
        $st = $pdo->prepare('INSERT INTO audit (t, data) VALUES (?, ?)');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach (file($pfad, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
                $e = json_decode($zeile, true);
                if (is_array($e) && isset($e['t'])) {
                    $st->execute([(string)$e['t'], json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        @rename($pfad, $pfad . '.uebernommen');
    }
}

// ---- Links ----------------------------------------------------------------

/** Einen Link-Datensatz mitsamt der abgeleiteten Spalten schreiben */
function db_link_put(PDO $pdo, string $code, array $l, string $domain = ''): void
{
    $st = $pdo->prepare('REPLACE INTO links (domain, code, owner, grp, type, created, data)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
        $domain,
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
        if (!is_array($d)) continue;
        $out[db_link_schluessel($zeile)] = db_link_kennzeichnen($d, $zeile);
    }
    return $out;
}

/**
 * Der Karten-Schlüssel eines Links.
 *
 * Seit die Namensräume getrennt sind, ist der Code allein nicht mehr
 * eindeutig – kunde-a.example/shop und kunde-b.example/shop sind zwei Links.
 * Ein Array, das nach Code schlüsselt, verlöre einen davon. Der Schlüssel
 * trägt deshalb die Domain, wo eine da ist; auf der Hauptdomain bleibt es der
 * nackte Code, damit gespeicherte Verweise weiter passen.
 *
 * @param array{domain?:string,code:string} $zeile
 */
function db_link_schluessel(array $zeile): string
{
    $d = (string)($zeile['domain'] ?? '');
    return $d === '' ? (string)$zeile['code'] : $d . '/' . (string)$zeile['code'];
}

/**
 * Code und Domain in den Datensatz legen.
 *
 * Der Aufrufer bekommt damit beides aus der Hand, ohne den Schlüssel zerlegen
 * zu müssen: `_code` ist der Code zum Anzeigen, `domain` die Domain zum
 * Weiterreichen. `_code` trägt einen Unterstrich, weil es kein gespeichertes
 * Feld ist, sondern beim Lesen entsteht.
 *
 * @param array{domain?:string,code:string} $zeile
 */
function db_link_kennzeichnen(array $d, array $zeile): array
{
    $d['_code'] = (string)$zeile['code'];
    $dom = (string)($zeile['domain'] ?? '');
    if ($dom !== '') $d['domain'] = $dom; else unset($d['domain']);
    return $d;
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

// ---- Schlüssel→Wert: settings und state ------------------------------------

/** @return array<string,mixed> der komplette Inhalt einer kv-Tabelle */
function db_kv_all(PDO $pdo, string $tabelle): array
{
    $out = [];
    foreach ($pdo->query("SELECT key, value FROM $tabelle") as $z) {
        $v = json_decode((string)$z['value'], true);
        if (json_last_error() === JSON_ERROR_NONE) $out[(string)$z['key']] = $v;
    }
    return $out;
}

/**
 * Eine kv-Tabelle vollständig auf den übergebenen Stand bringen.
 *
 * Vollständig, nicht additiv: settings_save() hat schon immer den ganzen
 * Stand geschrieben, und ein Schlüssel, den der Aufrufer entfernt hat, muss
 * auch aus der Tabelle verschwinden.
 */
function db_kv_replace(PDO $pdo, string $tabelle, array $daten): void
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec("DELETE FROM $tabelle");
        $st = $pdo->prepare("REPLACE INTO $tabelle (key, value) VALUES (?, ?)");
        foreach ($daten as $k => $v) {
            $st->execute([(string)$k, json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Einen einzelnen Betriebs-Marker lesen (state) */
function db_state_get(PDO $pdo, string $key, mixed $default = []): mixed
{
    $st = $pdo->prepare('SELECT value FROM state WHERE key = ?');
    $st->execute([$key]);
    $z = $st->fetch();
    if ($z === false) return $default;
    $v = json_decode((string)$z['value'], true);
    return json_last_error() === JSON_ERROR_NONE ? $v : $default;
}

/** Einen einzelnen Betriebs-Marker schreiben (state) */
function db_state_set(PDO $pdo, string $key, mixed $wert): void
{
    $st = $pdo->prepare('REPLACE INTO state (key, value) VALUES (?, ?)');
    $st->execute([$key, json_encode($wert, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

/**
 * Einen Marker lesen-ändern-schreiben, atomar.
 *
 * Ersetzt json_update() für die Marker: $fn bekommt den Stand und gibt den
 * neuen zurück (null = nichts ändern). BEGIN IMMEDIATE übernimmt die Rolle,
 * die vorher die .lock-Datei hatte.
 */
function db_state_update(PDO $pdo, string $key, callable $fn, mixed $default = []): mixed
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $daten = db_state_get($pdo, $key, $default);
        $neu = $fn($daten);
        if ($neu !== null) {
            db_state_set($pdo, $key, $neu);
            $daten = $neu;
        }
        $pdo->exec('COMMIT');
        return $daten;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

// ---- Verzeichnisse aus id → Datensatz (groups, logos, pending_users) -------

/** @return array<string,array> vollständiger Inhalt, Schlüssel → Datensatz */
function db_map_all(PDO $pdo, string $tabelle, string $spalte): array
{
    $out = [];
    foreach ($pdo->query("SELECT $spalte AS k, data FROM $tabelle") as $z) {
        $d = json_decode((string)$z['data'], true);
        if (is_array($d)) $out[(string)$z['k']] = $d;
    }
    return $out;
}

/**
 * Ein Verzeichnis lesen-ändern-schreiben, atomar und als Unterschied.
 *
 * Dasselbe Muster wie db_users_diff(): $fn bekommt alles, zurückgeschrieben
 * wird nur, was sich geändert hat, gelöscht, was fehlt.
 */
function db_map_update(PDO $pdo, string $tabelle, string $spalte, callable $fn): array
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $vorher = db_map_all($pdo, $tabelle, $spalte);
        $nachher = $fn($vorher);
        if ($nachher === null) {
            $pdo->exec('COMMIT');
            return $vorher;
        }
        $del = $pdo->prepare("DELETE FROM $tabelle WHERE $spalte = ?");
        $put = $pdo->prepare("REPLACE INTO $tabelle ($spalte, data) VALUES (?, ?)");
        foreach ($vorher as $k => $v) {
            if (!isset($nachher[$k])) $del->execute([(string)$k]);
        }
        foreach ($nachher as $k => $v) {
            if (!isset($vorher[$k]) || $vorher[$k] !== $v) {
                $put->execute([(string)$k, json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
        }
        $pdo->exec('COMMIT');
        return $nachher;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

// ---- Bestätigungen ---------------------------------------------------------

function db_confirmation_put(PDO $pdo, string $id, int $expires, array $daten): void
{
    $st = $pdo->prepare('REPLACE INTO confirmations (id, expires, data) VALUES (?, ?, ?)');
    $st->execute([$id, $expires, json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

function db_confirmation_get(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare('SELECT data FROM confirmations WHERE id = ?');
    $st->execute([$id]);
    $z = $st->fetch();
    if ($z === false) return null;
    $d = json_decode((string)$z['data'], true);
    return is_array($d) ? $d : null;
}

// ---- Audit -----------------------------------------------------------------

function db_audit_add(PDO $pdo, string $t, array $eintrag): void
{
    $st = $pdo->prepare('INSERT INTO audit (t, data) VALUES (?, ?)');
    $st->execute([$t, json_encode($eintrag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

/** @return array<int,array> neueste zuerst */
function db_audit_tail(PDO $pdo, int $anzahl): array
{
    $st = $pdo->prepare('SELECT data FROM audit ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, max(1, $anzahl), PDO::PARAM_INT);
    $st->execute();
    $out = [];
    while (($z = $st->fetch()) !== false) {
        $d = json_decode((string)$z['data'], true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}

// ---- Sitzungen -------------------------------------------------------------

/**
 * PHP-Sitzungen in der Datenbank.
 *
 * Die Daten liegen in der Tabelle sessions; das LOCK je Sitzung bleibt eine
 * flock()-Datei. Das ist kein Rückfall in die Dateiablage, sondern die
 * bewusste Trennung von Zustand und Koordination: SQLite kennt nur ein
 * datenbankweites Schreib-Lock – hielten wir es für die Dauer der Anfrage,
 * stünde jede Anfrage hinter jeder anderen. Die flock-Datei sperrt genau
 * EINE Sitzung, hält null Zustand und wird beim Aufräumen weggeworfen.
 *
 * Warum überhaupt sperren: Ohne das Lock gewänne bei parallelen Anfragen
 * derselben Sitzung der letzte Schreiber. Damit ließe sich eine verbrauchte
 * WebAuthn-Aufgabe im Rennen ein zweites Mal einreichen – genau die Zusage
 * „fünf Minuten und genau einmal" aus inc/webauthn.php hinge dann am Zufall.
 * Mit Lock verhält sich der Handler exakt wie PHPs Dateiablage: Die zweite
 * Anfrage wartet, bis die erste fertig ist.
 *
 * Ein späteres MySQL-Backend ersetzt die flock-Datei durch
 * SELECT … FOR UPDATE – die Schnittstelle bleibt dieselbe.
 */
class DbSitzungen implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** @var resource|null */
    private $lock = null;
    private string $gelesen = '';

    private function lockDatei(string $id): string
    {
        // sha1 statt roher id: Die id ist Nutzereingabe (Cookie) und darf
        // nie zum Dateinamen werden.
        return data_path('locks') . '/sitzung-' . sha1($id) . '.lock';
    }

    public function open(string $path, string $name): bool { return true; }

    public function close(): bool
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
        return true;
    }

    public function read(string $id): string|false
    {
        $this->lock = fopen($this->lockDatei($id), 'c') ?: null;
        if ($this->lock !== null) flock($this->lock, LOCK_EX);
        $st = db()->prepare('SELECT data FROM sessions WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch();
        $this->gelesen = $z === false ? '' : (string)$z['data'];
        return $this->gelesen;
    }

    public function write(string $id, string $data): bool
    {
        if ($data === $this->gelesen && $data !== '') {
            return $this->updateTimestamp($id, $data);
        }
        $st = db()->prepare('REPLACE INTO sessions (id, zugriff, data) VALUES (?, ?, ?)');
        $st->execute([$id, time(), $data]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $st = db()->prepare('DELETE FROM sessions WHERE id = ?');
        $st->execute([$id]);
        @unlink($this->lockDatei($id));
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $st = db()->prepare('DELETE FROM sessions WHERE zugriff < ?');
        $st->execute([time() - $max_lifetime]);
        // Verwaiste Lock-Dateien gleich mit – sie tragen keinen Zustand
        foreach (glob(data_path('locks') . '/sitzung-*.lock') ?: [] as $f) {
            if (@filemtime($f) < time() - $max_lifetime) @unlink($f);
        }
        return $st->rowCount();
    }

    public function validateId(string $id): bool
    {
        $st = db()->prepare('SELECT 1 FROM sessions WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $st = db()->prepare('UPDATE sessions SET zugriff = ? WHERE id = ?');
        $st->execute([time(), $id]);
        return true;
    }
}

/**
 * Wöchentliches Aufräumen der Sitzungs-Tabelle, gerufen aus links_gc().
 *
 * Nötig, weil PHPs eigener Aufräumer (session.gc_probability) auf manchen
 * Distributionen abgeschaltet ist – dort räumte bisher ein Cron die
 * DATEI-Ablage, und der kennt unsere Tabelle nicht. Die Frist ist dieselbe
 * wie in sessions_prune(): weit über gc_maxlifetime, denn den Rauswurf
 * erledigt ohnehin session_check() – hier geht es nur darum, dass die
 * Tabelle nicht endlos wächst.
 */
function db_sessions_sweep(PDO $pdo): void
{
    $frist = max(2 * (int)ini_get('session.gc_maxlifetime'), 86400);
    $st = $pdo->prepare('DELETE FROM sessions WHERE zugriff < ?');
    $st->execute([time() - $frist]);
    foreach (glob(data_path('locks') . '/sitzung-*.lock') ?: [] as $f) {
        if (@filemtime($f) < time() - $frist) @unlink($f);
    }
}
