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
 * Links und Konten liegen in einer SQLite-Datei (inc/db.php) – eine Datei
 * unter data/, kein Server, nichts zu warten. Der vollständige Datensatz
 * steht als JSON in der Spalte `data`; owner, grp, type und created sind
 * daraus abgeleitete Kopien für WHERE und ORDER BY.
 *
 * Die Klickzähler bleiben bewusst Einzeldateien (data/clicks/): Der
 * Weiterleitungspfad schreibt sie bei jedem Scan, und genau dort soll kein
 * gemeinsames Schreib-Lock entstehen.
 */

/**
 * Alle Links. Setzt die Ablagen wieder zusammen – gebraucht für Listen,
 * Zählungen und das Aufräumen, nicht für den Weiterleitungspfad.
 *
 * @return array<string,array> code => {url, owner, type, created, updated}
 */
function links_all(): array
{
    return db_links_rows(db()->query('SELECT code, data FROM links'));
}

/**
 * Einen einzelnen Link holen – der heiße Pfad.
 * Liest genau eine Ablage statt der gesamten Sammlung.
 */
function link_get(string $code): ?array
{
    $st = db()->prepare('SELECT data FROM links WHERE code = ?');
    $st->execute([$code]);
    $zeile = $st->fetch();
    if ($zeile === false) return null;
    $d = json_decode((string)$zeile['data'], true);
    return is_array($d) ? $d : null;
}

/**
 * Schreibzugriff auf den Datensatz eines Codes, unter Sperre seiner Ablage.
 * $fn bekommt den Datensatz (oder null) und gibt den neuen zurück; null
 * löscht ihn.
 */
function link_write(string $code, callable $fn): bool
{
    // BEGIN IMMEDIATE nimmt das Schreib-Lock sofort: lesen, ändern,
    // schreiben als ein Vorgang.
    $pdo = db();
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
    $st = db()->prepare('SELECT code, data FROM links WHERE owner = ?');
    $st->execute([$owner]);
    return db_links_rows($st);
}

/** Die Codes einer Arbeitsgruppe @return string[] */
function link_codes_of_group(string $group): array
{
    $st = db()->prepare('SELECT code FROM links WHERE grp = ?');
    $st->execute([$group]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
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
    // Der Wiederholungslauf gegen Safe Browsing hängt an derselben
    // Gelegenheit – ein Besucher löst beides aus –, führt aber seinen
    // eigenen Zeitstempel: Aufräumen ist eine Jahresfrage, Missbrauch eine
    // Wochenfrage.
    require_once __DIR__ . '/safety.php';
    safety_recheck();

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
    return link_write($code, function (?array $l) use ($group) {
        if ($l === null) return false;
        if ($group === null || $group === '') unset($l['group']); else $l['group'] = $group;
        $l['updated'] = date('c');
        return $l;
    });
}

/** Anzahl aktiver Wunsch-Codes eines Kontos (für das Pro-Kontingent) */
function custom_code_count(string $owner): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM links WHERE owner = ? AND type = 'custom'");
    $st->execute([$owner]);
    return (int)$st->fetchColumn();
}

/** Anzahl aller aktiven Links eines Kontos (für das Tarif-Limit) */
function link_count(string $owner): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM links WHERE owner = ?');
    $st->execute([$owner]);
    return (int)$st->fetchColumn();
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
    link_write($code, fn(?array $l) => null);
    @unlink(clicks_file($code));
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
