<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/helpers.php';

/**
 * ---------------------------------------------------------------------------
 *  Ablage der Kurzlinks
 * ---------------------------------------------------------------------------
 *
 * Die Links liegen nicht in einer Datei, sondern auf 256 Ablagen verteilt.
 * Zugeordnet wird über die ersten zwei Zeichen des Code-Hashes – das streut
 * gleichmäßig und funktioniert mit jeder Code-Form, auch mit Namensräumen
 * wie "bib/oeffnungszeiten".
 *
 * Der Grund ist der Weiterleitungspfad: Er ist der einzige Vorgang, der bei
 * jedem einzelnen Scan eines gedruckten Codes läuft. Läge alles in einer
 * Datei, müsste er sie jedes Mal vollständig einlesen und dekodieren – bei
 * 100.000 Links rund 28 MB und 50 ms. So liest er gut hundert Kilobyte.
 *
 * Nebeneffekt: Schreibvorgänge sperren nur noch ihre eigene Ablage statt der
 * gesamten Sammlung.
 */

function links_dir(): string
{
    return data_path('links');
}

/** Alte Sammeldatei – nur noch für den Lesefallback vor der Migration */
function links_file(): string
{
    return data_path() . '/links.json';
}

/**
 * Läuft diese Instanz schon auf der aufgeteilten Ablage?
 *
 * Solange nicht, wird weiter aus der alten Sammeldatei gelesen. Damit bleibt
 * eine Instanz zwischen dem Einspielen der neuen Dateien und dem Ausführen
 * der Migration durchgehend funktionsfähig.
 */
function links_sharded(): bool
{
    static $yes = null;
    if ($yes === null) {
        // Bewusst ohne data_path(): das legt fehlende Verzeichnisse an und
        // würde die Prüfung damit immer bejahen
        $dir = (string)cfg('data_dir');
        $base = $dir !== '' ? rtrim($dir, '/') : dirname(__DIR__) . '/data';
        // Ausschlaggebend ist eine ausdrückliche Markierung, nicht die bloße
        // Existenz des Verzeichnisses. Sonst würde ein versehentlich
        // angelegtes data/links/ eine noch nicht migrierte Instanz auf leere
        // Ablagen umschalten: Die alten Links wären unsichtbar, neue landeten
        // daneben. Die Markierung setzen nur die Migration und die erste
        // Schreiboperation einer frischen Instanz.
        $yes = is_file($base . '/links/.aufgeteilt') || !is_file($base . '/links.json');
    }
    return $yes;
}

/** Ablage-Kennung eines Codes */
function link_shard(string $code): string
{
    return substr(sha1($code), 0, 2);
}

function link_shard_file(string $code): string
{
    return links_dir() . '/' . link_shard($code) . '.json';
}

/**
 * Alle Links. Setzt die Ablagen wieder zusammen – gebraucht für Listen,
 * Zählungen und das Aufräumen, nicht für den Weiterleitungspfad.
 *
 * @return array<string,array> code => {url, owner, type, created, updated}
 */
function links_all(): array
{
    if (($pdo = db()) !== null) {
        return db_links_rows($pdo->query('SELECT code, data FROM links'));
    }
    $all = [];
    foreach (link_store_files() as $f) {
        foreach (json_read($f) as $code => $l) $all[$code] = $l;
    }
    return $all;
}

/**
 * Einen einzelnen Link holen – der heiße Pfad.
 * Liest genau eine Ablage statt der gesamten Sammlung.
 */
function link_get(string $code): ?array
{
    if (($pdo = db()) !== null) {
        $st = $pdo->prepare('SELECT data FROM links WHERE code = ?');
        $st->execute([$code]);
        $zeile = $st->fetch();
        if ($zeile === false) return null;
        $d = json_decode((string)$zeile['data'], true);
        return is_array($d) ? $d : null;
    }
    if (!links_sharded()) return json_read(links_file())[$code] ?? null;
    return json_read(link_shard_file($code))[$code] ?? null;
}

/**
 * Alle Dateien, in denen Links stehen – eine Ablage je Datei, vor der
 * Migration die einzelne Sammeldatei. Für Vorgänge, die jeden Link anfassen.
 *
 * @return string[]
 */
function link_store_files(): array
{
    if (!links_sharded()) return is_file(links_file()) ? [links_file()] : [];
    return glob(links_dir() . '/*.json') ?: [];
}

/**
 * Schreibzugriff auf den Datensatz eines Codes, unter Sperre seiner Ablage.
 * $fn bekommt den Datensatz (oder null) und gibt den neuen zurück; null
 * löscht ihn.
 */
function link_write(string $code, callable $fn): bool
{
    if (($pdo = db()) !== null) {
        // BEGIN IMMEDIATE nimmt das Schreib-Lock sofort – das Gegenstück zum
        // flock der Datei-Ablage: lesen, ändern, schreiben als ein Vorgang.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT data FROM links WHERE code = ?');
            $st->execute([$code]);
            $zeile = $st->fetch();
            $alt = $zeile === false ? null : json_decode((string)$zeile['data'], true);
            $neu = $fn(is_array($alt) ? $alt : null);
            if ($neu === false) {                     // false = nichts ändern
                $pdo->exec('ROLLBACK');
                return false;
            }
            if ($neu === null) {
                $del = $pdo->prepare('DELETE FROM links WHERE code = ?');
                $del->execute([$code]);
            } else {
                db_link_put($pdo, $code, $neu);
            }
            $pdo->exec('COMMIT');
            return true;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
    $file = links_sharded() ? link_shard_file($code) : links_file();
    // Frische Instanz: Markierung anlegen, sobald zum ersten Mal geschrieben
    // wird. Ab da ist der Zustand ausdrücklich festgehalten.
    if (links_sharded()) {
        $marker = links_dir() . '/.aufgeteilt';
        if (!is_file($marker)) file_put_contents($marker, "aufgeteilte Ablage, siehe inc/store.php\n");
    }
    $changed = false;
    json_update($file, function (array $links) use ($code, $fn, &$changed) {
        $new = $fn($links[$code] ?? null);
        if ($new === false) return null;          // false = nichts ändern
        $changed = true;
        if ($new === null) unset($links[$code]); else $links[$code] = $new;
        return $links;
    });
    return $changed;
}

// ---- Besitzer- und Gruppen-Index ------------------------------------------
//
// Die Ablage ist nach Code-Hash aufgeteilt – gut für die Weiterleitung, blind
// für die Frage „welche Links gehören diesem Konto?". Die beantwortete bisher
// ein Vollscan über alles, und der wächst mit der Instanz: Bei einer Million
// Links kostete schon das Anlegen eines einzigen über eine Sekunde, weil die
// Limit-Prüfung den gesamten Bestand las.
//
// Der Index ist eine ABLEITUNG der Ablage, nie eine zweite Wahrheit: Er nennt
// nur Codes, die Datensätze bleiben in den Ablagen. Fehlt er oder ist er
// zweifelhaft, wird er aus der Ablage neu gebaut (Marker `owners/fertig`
// löschen genügt); ein Code, der im Index steht, aber in der Ablage fehlt,
// wird beim Lesen still übergangen. Ohne aufgeteilte Ablage (Altbestand vor
// migrate-links.php) bleibt alles beim Vollscan.
//
// Mit Blick auf ein späteres Datenbank-Backend sind die Leser hier genau die
// Abfragen, die dort ein Index beantworten würde: links_of_owner() ist
// `WHERE owner = ?`, link_count() ist `COUNT(*)` – die Aufrufer müssten sich
// nicht ändern.

function owner_index_dir(): string
{
    return links_dir() . '/owners';
}

/** Der Index ist wie die Ablage aufgeteilt – nur nach Besitzer- statt Code-Hash */
function owner_index_file(string $owner): string
{
    return owner_index_dir() . '/' . substr(sha1($owner), 0, 2) . '.json';
}

function group_index_file(): string
{
    return links_dir() . '/groups-index.json';
}

/**
 * Steht der Index bereit? Baut ihn bei Bedarf auf – genau einmal: Wer den
 * Aufbau-Lock nicht bekommt, arbeitet diesmal ohne Index weiter, statt zu
 * warten oder doppelt zu bauen.
 */
function link_index_ready(): bool
{
    // Mit Datenbank gibt es keinen Datei-Index – dort beantworten die
    // Spalten owner und grp dieselben Fragen unmittelbar.
    if (db() !== null) return false;
    if (!links_sharded()) return false;
    if (is_file(owner_index_dir() . '/fertig')) return true;

    // Höchstens ein Bauversuch je Anfrage: Schlägt er fehl (etwa wegen
    // Dateirechten), soll nicht jede weitere Abfrage derselben Seite den
    // Bestand erneut durchkämmen – der Vollscan-Fallback ist dann billiger.
    static $versucht = false;
    if ($versucht) return false;
    $versucht = true;

    $lock = fopen(links_dir() . '/.index-bau.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return false;
    }
    try {
        if (is_file(owner_index_dir() . '/fertig')) return true; // war schneller
        $dir = owner_index_dir();
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        $owners = [];   // xx => owner => code => type
        $groups = [];   // group => code => true
        foreach (links_all() as $code => $l) {
            $o = $l['owner'] ?? null;
            if (is_string($o) && $o !== '') {
                $owners[substr(sha1($o), 0, 2)][$o][(string)$code] = (string)($l['type'] ?? 'random');
            }
            $g = $l['group'] ?? null;
            if (is_string($g) && $g !== '') $groups[$g][(string)$code] = true;
        }
        foreach ($owners as $xx => $daten) {
            json_write($dir . '/' . $xx . '.json', $daten);
        }
        json_write(group_index_file(), $groups);
        // Die Markierung macht den Index gültig – erst prüfen, dann bejahen:
        // Ein als fertig gemeldeter Index, dessen Markierung nie ankam, hieße
        // bei jeder Anfrage ein Neuaufbau.
        return file_put_contents($dir . '/fertig',
            "abgeleitet aus der Ablage, siehe inc/store.php\n") !== false;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Einen Link im Index eintragen (nach dem Schreiben in die Ablage) */
function link_index_add(string $code, ?string $owner, ?string $group, string $type): void
{
    if (db() !== null) return; // die Spalten schreibt link_write bereits mit
    if (!is_file(owner_index_dir() . '/fertig')) return;
    if (is_string($owner) && $owner !== '') {
        json_update(owner_index_file($owner), function (array $idx) use ($owner, $code, $type) {
            $idx[$owner][$code] = $type;
            return $idx;
        });
    }
    if (is_string($group) && $group !== '') {
        json_update(group_index_file(), function (array $idx) use ($group, $code) {
            $idx[$group][$code] = true;
            return $idx;
        });
    }
}

/**
 * Einen Link aus dem Index austragen – nur die übergebenen Schlüssel.
 *
 * Eine dabei leer werdende Index-Datei bleibt stehen: Sie zu löschen wäre
 * ein Wettlauf mit einem gleichzeitigen Eintrag, und ein leeres Wörterbuch
 * liest sich genauso wie ein fehlendes.
 */
function link_index_remove(string $code, ?string $owner, ?string $group): void
{
    if (db() !== null) return;
    if (!is_file(owner_index_dir() . '/fertig')) return;
    if (is_string($owner) && $owner !== '') {
        json_update(owner_index_file($owner), function (array $idx) use ($owner, $code) {
            unset($idx[$owner][$code]);
            if (($idx[$owner] ?? []) === []) unset($idx[$owner]);
            return $idx;
        });
    }
    if (is_string($group) && $group !== '') {
        json_update(group_index_file(), function (array $idx) use ($group, $code) {
            unset($idx[$group][$code]);
            if (($idx[$group] ?? []) === []) unset($idx[$group]);
            return $idx;
        });
    }
}

/**
 * Alle Links eines Kontos – ohne die übrige Sammlung anzufassen.
 *
 * Die Codes kommen aus dem Index, die Datensätze aus den Ablagen; gelesen
 * wird jede betroffene Ablage genau einmal. Ein Konto mit zehn Links liest
 * damit ein paar Kilobyte, egal ob die Instanz hundert oder eine Million
 * Links trägt.
 *
 * @return array<string,array> code => Datensatz
 */
function links_of_owner(string $owner): array
{
    if (($pdo = db()) !== null) {
        $st = $pdo->prepare('SELECT code, data FROM links WHERE owner = ?');
        $st->execute([$owner]);
        return db_links_rows($st);
    }
    if (!link_index_ready()) {
        return array_filter(links_all(), fn($l) => ($l['owner'] ?? null) === $owner);
    }
    $codes = array_keys(json_read(owner_index_file($owner))[$owner] ?? []);
    $nachAblage = [];
    foreach ($codes as $c) $nachAblage[link_shard((string)$c)][] = (string)$c;
    $out = [];
    foreach ($nachAblage as $xx => $liste) {
        $ablage = json_read(links_dir() . '/' . $xx . '.json');
        foreach ($liste as $c) {
            // Im Index, aber nicht in der Ablage: die Ablage hat recht
            if (isset($ablage[$c])) $out[$c] = $ablage[$c];
        }
    }
    return $out;
}

/** Die Codes einer Arbeitsgruppe @return string[] */
function link_codes_of_group(string $group): array
{
    if (($pdo = db()) !== null) {
        $st = $pdo->prepare('SELECT code FROM links WHERE grp = ?');
        $st->execute([$group]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!link_index_ready()) return [];
    return array_keys(json_read(group_index_file())[$group] ?? []);
}

/**
 * Link anlegen. Gibt bei Erfolg den Code zurück, sonst eine Fehlermeldung.
 * @return array{0:bool,1:string} [ok, code|fehler]
 */
/** Abgelaufen = Ablaufdatum gesetzt und der Tag ist vorbei (gültig bis einschließlich des Tages) */
function link_expired(array $link): bool
{
    $e = $link['expires'] ?? null;
    return $e !== null && date('Y-m-d') > $e;
}

/**
 * Automatisches Aufräumen (AGB § 2): Links, deren letzter Aufruf länger als
 * cfg('link_gc_years') Jahre zurückliegt (nie aufgerufene: ab Erstellung).
 *
 * Zweistufig: Gehört der Link zu einem Konto mit hinterlegter E-Mail-Adresse,
 * geht einen Monat vor der Löschung eine Warnung raus (eine Sammel-Mail pro
 * Konto); gelöscht wird frühestens 30 Tage nach der Warnung. Anonyme Links
 * ohne Kontaktmöglichkeit werden nach Ablauf der Frist direkt gelöscht.
 *
 * Läuft ohne Cronjob – zeitgesteuert höchstens einmal pro Woche, angestoßen
 * von der Link-Erstellung. Gesperrte Links bleiben bewusst bestehen, damit
 * ihre Codes nicht neu vergeben werden.
 */
/**
 * Wöchentliche Wartung, angestoßen von der Link-Erstellung statt von einem
 * Cronjob. Läuft unabhängig davon, ob das Aufräumen der Links eingeschaltet
 * ist – manches muss auch dann passieren.
 */
function links_gc(): void
{
    $marker = data_path() . '/links-gc.json';
    if (is_file($marker) && filemtime($marker) > time() - 7 * 86400) return;
    json_write($marker, ['last_run' => date('c')]);

    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/mail.php';

    verified_ip_gc();

    $years = (int)cfg('link_gc_years');
    if ($years < 1) return;
    $yearsLong = max($years, (int)cfg('link_gc_years_unreachable'));

    // Kurze Frist nur, wo eine Warnung möglich ist; sonst die lange
    $deleteCutoff = strtotime('-' . $years . ' years');
    $warnCutoff = strtotime('-' . $years . ' years +30 days'); // 1 Monat vor Ablauf
    $deleteCutoffLong = strtotime('-' . $yearsLong . ' years');
    $warnFile = data_path() . '/links-gc-warned.json';
    $warned = json_read($warnFile);
    $trustedBase = base_url(true);
    $log = function (string $line): void {
        file_put_contents(data_path() . '/links-gc.log', date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    };

    /** E-Mail-Adresse zum Besitzer eines Links (null = nicht erreichbar) */
    $ownerMail = function (?string $owner): ?string {
        if ($owner === null) return null;
        $u = user_get($owner);
        if ($u === null) return null;
        $mail = $u['email'] ?? (filter_var($owner, FILTER_VALIDATE_EMAIL) !== false ? $owner : null);
        return is_string($mail) ? $mail : null;
    };

    $toWarn = []; // email => [code => lastUse]
    foreach (links_all() as $code => $l) {
        $code = (string)$code;
        if (!empty($l['disabled'])) continue;
        // Nur der Zeitpunkt zählt – dafür genügt das Änderungsdatum der
        // Klickdatei. Ein stat() statt Öffnen, Lesen und Dekodieren; bei
        // vielen Links macht das den Unterschied zwischen Sekunden und Minuten.
        $cf = clicks_file($code);
        $lastUse = is_file($cf) ? filemtime($cf) : strtotime((string)($l['created'] ?? ''));
        if ($lastUse === false || $lastUse >= $warnCutoff) {
            // Wieder genutzt: eventuelle Warn-Markierung zurücksetzen
            if (isset($warned[$code])) unset($warned[$code]);
            continue;
        }

        $mail = $ownerMail($l['owner'] ?? null);
        if ($mail === null) {
            // Nicht warnbar: lange Frist, dafür ohne Vorwarnung
            if ($lastUse < $deleteCutoffLong) {
                link_delete($code);
                $log('gelöscht (ohne Warnweg, ' . $yearsLong . ' Jahre): ' . $code . ' (letzte Nutzung: ' . date('Y-m-d', $lastUse) . ')');
            }
        } elseif ($lastUse < $deleteCutoff && isset($warned[$code]) && strtotime((string)$warned[$code]) < time() - 30 * 86400) {
            link_delete($code);
            unset($warned[$code]);
            $log('gelöscht (nach Warnung): ' . $code . ' (letzte Nutzung: ' . date('Y-m-d', $lastUse) . ')');
        } elseif (!isset($warned[$code])) {
            $toWarn[$mail][$code] = $lastUse;
        }
    }

    foreach ($toWarn as $mail => $codes) {
        $lines = '';
        foreach ($codes as $code => $lastUse) {
            // Die Aufräum-Warnung läuft im Hintergrund, ausgelöst durch einen
            // beliebigen Besucher – dessen Host-Header darf die Adressen in
            // der Mail nicht bestimmen
            $lines .= '  ' . ($trustedBase !== '' ? $trustedBase . '/' . $code : (string)$code)
                . ' (letzte Nutzung: ' . date('d.m.Y', $lastUse) . ")\n";
        }
        $ok = mail_send($mail, t('Lange ungenutzte Kurzlinks werden bald gelöscht'),
            t("Hallo,") . "\n\n"
            . t("die folgenden Kurzlinks deines Kontos wurden seit fast %d Jahren\nnicht ein einziges Mal aufgerufen und werden daher in etwa einem Monat\nautomatisch gelöscht (AGB § 2):", $years) . "\n\n"
            . $lines . "\n"
            . t("Ein einziger Aufruf genügt, um die Frist vollständig zurückzusetzen.\nNichts weiter nötig, wenn die Links weg können.") . "\n\n"
            . "– " . cfg('site_name'));
        if ($ok) {
            foreach ($codes as $code => $lastUse) {
                $warned[$code] = date('c');
                $log('gewarnt: ' . $code . ' → ' . $mail);
            }
        }
    }
    json_write($warnFile, $warned);
}

/**
 * Kurzlink anlegen.
 *
 * Alles außer Ziel, Code, Besitzer und Art steht in $opts. Die Alternative
 * wären inzwischen acht Stellungsparameter, bei denen der Aufrufer vier
 * `null` hintereinander schreiben müsste, um an den fünften zu kommen –
 * und bei jeder neuen Eigenschaft würde es schlimmer.
 *
 * Erkannte Schlüssel: prefix (nur für die Codesuche, wird nicht gespeichert),
 * expires, group, title, tags.
 *
 * @param array{prefix?:string,expires?:?string,group?:?string,title?:?string,tags?:string[]} $opts
 * @return array{0:bool,1:string} [Erfolg, Code oder Fehlermeldung]
 */
function link_create(string $url, ?string $code, ?string $owner, string $type, array $opts = []): array
{
    links_gc();

    $prefix = (string)($opts['prefix'] ?? '');
    $expires = $opts['expires'] ?? null;
    $group = $opts['group'] ?? null;

    if ($code === null) {
        $code = link_random_code($prefix);
        if ($code === null) {
            return [false, t('Kein freier Code gefunden – Code-Länge in config.php erhöhen.')];
        }
    }

    $taken = false;
    $ok = link_write($code, function (?array $existing) use ($url, $owner, $type, $expires, $group, $opts, &$taken) {
        if ($existing !== null) { $taken = true; return false; }
        $new = [
            'url' => $url,
            'owner' => $owner,
            'type' => $type,
            'expires' => $expires,
            'created' => date('c'),
            'updated' => date('c'),
        ];
        // Nur setzen, wenn es wirklich eine Gruppe gibt – kein null-Ballast
        // in den Datensätzen der Instanzen, die ohne Gruppen arbeiten
        if ($group !== null && $group !== '') $new['group'] = $group;
        return link_apply_meta($new, $opts);
    });

    if ($taken) return [false, t('Dieser Code ist schon vergeben.')];
    if ($ok) {
        // Aufbau anstoßen, nicht nur pflegen: Auf einer Instanz, deren Listen
        // nur Administratoren sehen, liefe sonst nie ein Leser, der den Index
        // erstmals ableitet – Admin-Wege umgehen die Limit-Prüfungen.
        // Der frisch angelegte Link ist im Aufbau bereits enthalten; das
        // link_index_add danach ist dann ein Wiederholen desselben Eintrags.
        link_index_ready();
        $g = is_string($group) && $group !== '' ? $group : null;
        link_index_add($code, $owner, $g, $type);
    }
    return $ok ? [true, $code] : [false, t('Anlegen fehlgeschlagen.')];
}

/**
 * Ziel und Zusatzangaben eines Links ändern.
 *
 * @param array{expires?:?string,title?:?string,tags?:string[]} $opts
 */
function link_update(string $code, string $url, array $opts = []): bool
{
    return link_write($code, function (?array $l) use ($url, $opts) {
        if ($l === null) return false;
        $l['url'] = $url;
        $l['expires'] = $opts['expires'] ?? null;
        $l['updated'] = date('c');
        return link_apply_meta($l, $opts);
    });
}

/**
 * Titel und Schlagworte in einen Datensatz übernehmen.
 *
 * Leere Angaben löschen das Feld, statt einen leeren String abzulegen: Ein
 * Datensatz soll nur enthalten, was auch gesetzt ist – das hält die Ablagen
 * klein, die bei jeder Weiterleitung gelesen werden.
 */
function link_apply_meta(array $l, array $opts): array
{
    if (array_key_exists('title', $opts)) {
        $t = trim((string)($opts['title'] ?? ''));
        // Steuerzeichen raus: Der Titel landet in Listen, CSV und JSON-Export
        $t = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $t));
        if ($t === '') unset($l['title']); else $l['title'] = mb_substr($t, 0, 120);
    }
    if (array_key_exists('tags', $opts)) {
        $tags = tags_normalize($opts['tags']);
        if ($tags === []) unset($l['tags']); else $l['tags'] = $tags;
    }
    if (array_key_exists('domain', $opts)) {
        // Die Hauptdomain wird nicht mitgeschrieben: Sie ergibt sich aus der
        // Konfiguration und würde beim Umzug einer Instanz sonst falsch stehen.
        $d = (string)($opts['domain'] ?? '');
        if ($d === '') unset($l['domain']); else $l['domain'] = $d;
    }
    return $l;
}

/** Höchstzahl Schlagworte je Link – mehr ordnet nicht, sondern verwirrt */
const TAGS_MAX = 8;

/**
 * Schlagworte säubern.
 *
 * Angenommen wird eine Liste oder eine Zeichenkette mit Kommas. Vergleich und
 * Ablage laufen in Kleinschreibung: „Kampagne" und „kampagne" sollen dieselbe
 * Schublade sein, sonst hat man nach einer Woche beide.
 *
 * @param string|array $roh
 * @return string[]
 */
function tags_normalize($roh): array
{
    $teile = is_array($roh) ? $roh : explode(',', (string)$roh);
    $out = [];
    foreach ($teile as $t) {
        $t = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$t));
        // Leerraum in der Mitte zusammenfassen, damit „a  b" und „a b" gleich sind
        $t = (string)preg_replace('/\s+/u', ' ', $t);
        $t = mb_strtolower(mb_substr($t, 0, 24));
        if ($t === '' || in_array($t, $out, true)) continue;
        $out[] = $t;
        if (count($out) >= TAGS_MAX) break;
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** Schlagworte eines Links als Eingabetext */
function tags_text(?array $l): string
{
    return implode(', ', (array)($l['tags'] ?? []));
}

/**
 * Alle vergebenen Schlagworte mit ihrer Häufigkeit, für Filterlisten.
 *
 * @param array<string,array> $links
 * @return array<string,int> Schlagwort => Anzahl, häufigste zuerst
 */
function tags_counts(array $links): array
{
    $z = [];
    foreach ($links as $l) {
        foreach ((array)($l['tags'] ?? []) as $t) {
            $z[$t] = ($z[$t] ?? 0) + 1;
        }
    }
    arsort($z);
    return $z;
}

/** Gruppenzuordnung eines Links setzen ($group = null hebt sie auf) */
function link_set_group(string $code, ?string $group): bool
{
    $vorher = link_get($code);
    $ok = link_write($code, function (?array $l) use ($group) {
        if ($l === null) return false;
        if ($group === null || $group === '') unset($l['group']); else $l['group'] = $group;
        $l['updated'] = date('c');
        return $l;
    });
    if ($ok && $vorher !== null) {
        $alt = $vorher['group'] ?? null;
        $neu = $group === '' ? null : $group;
        if ($alt !== $neu) {
            link_index_remove($code, null, $alt);
            link_index_add($code, null, $neu, (string)($vorher['type'] ?? 'random'));
        }
    }
    return $ok;
}

/** Anzahl aktiver Wunsch-Codes eines Kontos (für das Pro-Kontingent) */
function custom_code_count(string $owner): int
{
    if (($pdo = db()) !== null) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM links WHERE owner = ? AND type = 'custom'");
        $st->execute([$owner]);
        return (int)$st->fetchColumn();
    }
    if (link_index_ready()) {
        $meine = json_read(owner_index_file($owner))[$owner] ?? [];
        return count(array_filter($meine, fn($typ) => $typ === 'custom'));
    }
    $n = 0;
    foreach (links_all() as $l) {
        if (($l['owner'] ?? null) === $owner && ($l['type'] ?? '') === 'custom') $n++;
    }
    return $n;
}

/** Anzahl aller aktiven Links eines Kontos (für das Tarif-Limit) */
function link_count(string $owner): int
{
    if (($pdo = db()) !== null) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM links WHERE owner = ?');
        $st->execute([$owner]);
        return (int)$st->fetchColumn();
    }
    if (link_index_ready()) {
        return count(json_read(owner_index_file($owner))[$owner] ?? []);
    }
    $n = 0;
    foreach (links_all() as $l) {
        if (($l['owner'] ?? null) === $owner) $n++;
    }
    return $n;
}

/** Passwortschutz setzen (Hash) oder entfernen (null) – Pro-Feature */
function link_set_password(string $code, ?string $hash): bool
{
    return link_write($code, function (?array $l) use ($hash) {
        if ($l === null) return false;
        if ($hash === null) unset($l['pass']); else $l['pass'] = $hash;
        $l['updated'] = date('c');
        return $l;
    });
}

/** Link wegen Missbrauchs sperren/entsperren (gesperrte Links antworten mit 410) */
function link_set_disabled(string $code, bool $disabled): bool
{
    return link_write($code, function (?array $l) use ($disabled) {
        if ($l === null) return false;
        if ($disabled) $l['disabled'] = true; else unset($l['disabled']);
        $l['updated'] = date('c');
        return $l;
    });
}

function link_delete(string $code): void
{
    $vorher = link_get($code);
    link_write($code, fn(?array $l) => null);
    @unlink(clicks_file($code));
    if ($vorher !== null) {
        link_index_remove($code, $vorher['owner'] ?? null, $vorher['group'] ?? null);
    }
}

/** Freien Zufallscode suchen (innerhalb des Locks aufgerufen), optional unter einem Prefix ("p/abc123") */
/**
 * Freien Zufallscode finden.
 *
 * Geprüft wird nur die Ablage des Kandidaten, nicht die gesamte Sammlung –
 * ein Lesevorgang von wenigen Kilobyte statt der ganzen Datei.
 */
function link_random_code(string $prefix = ''): ?string
{
    $alphabet = cfg('alphabet');
    $len = cfg('code_length');
    $max = strlen($alphabet) - 1;
    for ($try = 0; $try < 50; $try++) {
        $seg = '';
        for ($i = 0; $i < $len; $i++) {
            $seg .= $alphabet[random_int(0, $max)];
        }
        if (!valid_code($seg)) continue;
        $code = $prefix === '' ? $seg : $prefix . '/' . $seg;
        if (link_get($code) === null) return $code;
    }
    return null;
}

// ---- Klickzähler (eigene Mini-Datei pro Code, hält links.json aus dem Redirect-Pfad raus) ----

function clicks_file(string $code): string
{
    // rawurlencode macht auch Prefix-Codes ("p/abc") zu kollisionsfreien Dateinamen
    return data_path('clicks') . '/' . rawurlencode($code) . '.json';
}

function clicks_get(string $code): array
{
    return json_read(clicks_file($code), ['n' => 0, 'last' => null, 'days' => []]);
}

/**
 * Aufruf zählen.
 *
 * Ohne $item ist der Kurzlink selbst gemeint – bei einer Link-in-Bio-Seite also
 * der Seitenaufruf. Mit $item ist ein einzelnes Ziel dieser Seite gemeint; die
 * Zählweise bleibt dieselbe, nur eine Ebene tiefer.
 */
function clicks_bump(string $code, ?int $item = null): void
{
    json_update(clicks_file($code), function (array $c) use ($item) {
        $today = date('Y-m-d');
        $zaehle = function (array $z) use ($today): array {
            $days = $z['days'] ?? [];
            $days[$today] = ($days[$today] ?? 0) + 1;
            // Historie begrenzen: älteste Tage raus (400 deckt die 12-Monats-Statistik)
            if (count($days) > 400) {
                ksort($days);
                $days = array_slice($days, -400, null, true);
            }
            // Bewusst nur tagesgenau: Bei einem Link mit wenigen Aufrufen wäre ein
            // sekundengenauer Zeitpunkt der einzige Wert im gesamten Bestand, über
            // den sich ein einzelner Besuch zeitlich verorten – und mit anderen
            // Quellen zusammenführen – ließe. Für „letzter Aufruf" genügt der Tag.
            return ['n' => ($z['n'] ?? 0) + 1, 'last' => $today, 'days' => $days];
        };
        if ($item === null) {
            // Die Ziel-Zähler bleiben unangetastet – deshalb wird ergänzt und
            // nicht ersetzt.
            return $zaehle($c) + $c;
        }
        $c['items'] ??= [];
        $c['items'][(string)$item] = $zaehle((array)($c['items'][(string)$item] ?? []));
        return $c;
    }, ['n' => 0, 'last' => null, 'days' => []]);
}

// ---- QR-Logos: Metadaten (Anzeigenamen) zu den zufälligen Datei-IDs ----

function logos_meta_file(): string
{
    return data_path() . '/logos.json';
}

/** @return array<string,array{name:string,by:?string,created:string}> */
function logos_meta(): array
{
    return json_read(logos_meta_file());
}

function logo_meta_set(string $id, string $name, ?string $by): void
{
    json_update(logos_meta_file(), function (array $m) use ($id, $name, $by) {
        $m[$id] = ['name' => $name, 'by' => $by, 'created' => date('c')];
        return $m;
    });
}

function logo_meta_delete(string $id): void
{
    json_update(logos_meta_file(), function (array $m) use ($id) {
        unset($m[$id]);
        return $m;
    });
}

// ---- Rate-Limit für die öffentliche Erzeugung ----

function rate_limit_ok(string $ip): bool
{
    rate_limit_gc();
    $file = data_path('ratelimit') . '/' . hash('sha256', $ip) . '.json';
    $hour = date('YmdH');
    $ok = true;
    json_update($file, function (array $d) use ($hour, &$ok) {
        if (($d['hour'] ?? '') !== $hour) {
            $d = ['hour' => $hour, 'n' => 0];
        }
        if ($d['n'] >= (int)settings()['public_rate_limit']) {
            $ok = false;
            return null;
        }
        $d['n']++;
        return $d;
    });
    return $ok;
}
