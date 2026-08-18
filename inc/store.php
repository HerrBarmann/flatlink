<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/hooks.php';

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
 * Aufruf-Limit erreicht? 0 oder fehlend heißt unbegrenzt.
 *
 * Geprüft wird gegen den Tageszähler-Gesamtwert, der ohnehin geführt wird –
 * es entsteht kein zusätzlicher Speicher. Bots zählen nicht (click_zaehlbar),
 * das Limit meint also echte Besuche.
 */
function link_ausgeschoepft(string $code, array $link): bool
{
    $m = (int)($link['max_visits'] ?? 0);
    return $m > 0 && (int)(clicks_get($code)['n'] ?? 0) >= $m;
}

/**
 * Noch nicht aktiv = Startdatum gesetzt und der Tag noch nicht erreicht.
 *
 * Das Gegenstück zum Ablauf, für Kampagnen, Semesterstarts, Pressetermine:
 * Der Code ist gedruckt und verteilt, das Ziel soll aber erst am Stichtag
 * erreichbar sein. Gültig ab dem genannten Tag, wie der Ablauf bis
 * einschließlich seines Tages gilt.
 */
function link_pending(array $link): bool
{
    $s = $link['starts'] ?? null;
    return $s !== null && date('Y-m-d') < $s;
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
    if (!$ok) return [false, t('Anlegen fehlgeschlagen.')];
    hook_fire('link.created', hook_link($code, link_get($code)));
    return [true, $code];
}

/**
 * Ziel und Zusatzangaben eines Links ändern.
 *
 * @param array{expires?:?string,title?:?string,tags?:string[]} $opts
 */
function link_update(string $code, string $url, array $opts = []): bool
{
    // Wer den Vorgang auslöst, steht in der Sitzung – im Schreib-Callback
    // wäre der Aufruf zu spät, er liefe dann innerhalb der Transaktion.
    $wer = function_exists('auth_user') ? (auth_user()['name'] ?? null) : null;
    $ok = link_write($code, function (?array $l) use ($url, $opts, $wer) {
        if ($l === null) return false;
        $vorher = (string)($l['url'] ?? '');
        $l['url'] = $url;
        $l['expires'] = $opts['expires'] ?? null;
        $l['updated'] = date('c');
        // Nur das Ziel wird nachgehalten: Es ist die eine Angabe, deren
        // stille Änderung jemandem schadet – ein gedruckter Code führt dann
        // woandershin, ohne dass es jemand merkt. Titel oder Schlagworte
        // sind Ordnung, keine Zusage.
        if ($vorher !== '' && $vorher !== $url) {
            $l = link_history_add($l, $vorher, $url, $wer);
        }
        return link_apply_meta($l, $opts);
    });
    if ($ok) hook_fire('link.updated', hook_link($code, link_get($code)));
    return $ok;
}

/** Wie viele Änderungen je Link aufgehoben werden */
const LINK_HISTORY_MAX = 20;

/**
 * Eine Ziel-Änderung in die Historie des Datensatzes schreiben.
 *
 * Aufgehoben werden die letzten LINK_HISTORY_MAX Änderungen – genug, um eine
 * stille Umleitung zu bemerken, und wenig genug, dass der Datensatz nicht
 * wächst, der bei jeder Weiterleitung gelesen wird.
 */
function link_history_add(array $l, string $von, string $nach, ?string $wer): array
{
    $h = (array)($l['history'] ?? []);
    $h[] = ['t' => date('c'), 'wer' => $wer ?? 'system', 'von' => $von, 'nach' => $nach];
    if (count($h) > LINK_HISTORY_MAX) $h = array_slice($h, -LINK_HISTORY_MAX);
    $l['history'] = $h;
    return $l;
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
    if (array_key_exists('starts', $opts)) {
        $st = $opts['starts'];
        if ($st === null || $st === '') unset($l['starts']); else $l['starts'] = (string)$st;
    }
    foreach (['og_title' => 120, 'og_text' => 200, 'og_image' => 500] as $feld => $max) {
        if (!array_key_exists($feld, $opts)) continue;
        $v = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$opts[$feld]));
        // Das Bild muss eine Adresse sein; Titel und Text sind freier Text
        if ($feld === 'og_image' && $v !== '' && !valid_url($v)) $v = '';
        if ($v === '') unset($l[$feld]); else $l[$feld] = mb_substr($v, 0, $max);
    }
    // Aufruf-Limit: 0/leer = unbegrenzt, Feld verschwindet dann
    if (array_key_exists('max_visits', $opts)) {
        $mv = (int)$opts['max_visits'];
        if ($mv <= 0) unset($l['max_visits']); else $l['max_visits'] = $mv;
    }
    // Sprache des Hauptziels – Grundlage der Sprachverhandlung (inc/routing.php)
    if (array_key_exists('lang', $opts)) {
        $sp = strtolower(trim((string)$opts['lang']));
        if (preg_match('/^[a-z]{2}$/', $sp) !== 1) unset($l['lang']); else $l['lang'] = $sp;
    }
    if (array_key_exists('rules', $opts)) {
        $r = (array)$opts['rules'];
        if ($r === []) unset($l['rules']); else $l['rules'] = array_values($r);
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

/**
 * Den Besitzer eines Links setzen – oder ihn herausnehmen.
 *
 * `null` heißt: Der Link gehört niemandem mehr persönlich. Das ist kein
 * Sonderfall, sondern der Normalzustand eines Links, der einer Arbeitsgruppe
 * gehört und dessen Anleger das Haus verlassen hat. Zugriff und Verwaltung
 * laufen dann über die Gruppe, die Weiterleitung ohnehin.
 *
 * Ohne Gruppe und ohne Besitzer wäre ein Link allerdings herrenlos – nur noch
 * für Administratoren auffindbar. Das prüft der Aufrufer.
 */
function link_set_owner(string $code, ?string $owner): bool
{
    return link_write($code, function (?array $l) use ($owner) {
        if ($l === null) return false;
        if ($owner === null || $owner === '') unset($l['owner']); else $l['owner'] = $owner;
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
    $ok = link_write($code, function (?array $l) use ($disabled) {
        if ($l === null) return false;
        if ($disabled) $l['disabled'] = true; else unset($l['disabled']);
        $l['updated'] = date('c');
        return $l;
    });
    if ($ok) hook_fire('link.blocked', hook_link($code, link_get($code)));
    return $ok;
}

function link_delete(string $code): void
{
    // Vor dem Löschen lesen: Danach gäbe es nichts mehr zu melden als den Code
    $l = link_get($code);
    link_write($code, fn(?array $l) => null);
    @unlink(clicks_file($code));
    hook_fire('link.deleted', hook_link($code, $l));
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

/** Wie viele verschiedene Werte je Merkmal aufgehoben werden */
const CLICK_DIM_MAX = 40;

/**
 * Woher ein Aufruf kam – als drei grobe Merkmale, nicht als Datensatz.
 *
 * Die häufigste Frage an eine Statistik ist „woher kommen meine Klicks?", und
 * sie ist der meistgenannte Grund, zu einem Dienst zu wechseln, der dafür
 * Besucher verfolgt. Das ist nicht nötig: Herkunftsseite, Sprache und
 * Gerätegattung stehen in der Anfrage selbst und lassen sich zählen, ohne
 * irgendetwas über den einzelnen Besuch zu behalten.
 *
 * Was hier NICHT entsteht: kein Datensatz je Aufruf, keine Uhrzeit, keine
 * Adresse, keine Browser-Kennung. Aus der Herkunft wird ausschließlich der
 * Host genommen – der Pfad einer verweisenden Seite kann eine Suchanfrage
 * oder eine Kennung enthalten und hat in einem Zähler nichts verloren. Aus
 * der Sprachliste werden zwei Buchstaben, aus der Browser-Kennung eines von
 * drei Wörtern. Damit bleibt jeder Wert eine Gruppe, keine Person.
 *
 * @return array<string,string> Feldname => Wert
 */
function click_dims(): array
{
    // Abschaltbar, und das ist kein Feigenblatt: Der Beleg „das hier ist
    // alles, was gespeichert wird" ist für manche Instanz das stärkste
    // Argument, und er wird mit jedem zusätzlichen Feld schwächer. Wer ihn
    // in seiner knappsten Form behalten will, schaltet die Merkmale aus und
    // hat wieder nichts als Zähler.
    if (!cfg('click_dims')) return [];

    $out = [];

    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $host = strtolower((string)(parse_url($ref, PHP_URL_HOST) ?? ''));
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        // Der eigene Host ist keine Herkunft, sondern der Weg über die
        // eigene Übersicht oder eine Bio-Seite.
        $eigen = strtolower((string)(parse_url(base_url(), PHP_URL_HOST) ?? ''));
        if ($host !== '' && $host !== $eigen && preg_match('/^[a-z0-9.-]{1,60}$/', $host) === 1) {
            $out['refs'] = $host;
        }
    }
    if (!isset($out['refs'])) $out['refs'] = '-';   // Direktaufruf, QR-Code, App

    $al = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if (preg_match('/^\s*([a-zA-Z]{2})/', $al, $m) === 1) {
        $out['langs'] = strtolower($m[1]);
    }

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua !== '') {
        $out['devs'] = preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $ua) === 1 ? 'tablet'
            : (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua) === 1 ? 'mobile' : 'desktop');
    }
    return $out;
}

/**
 * Einen Wert in einer Merkmalsliste hochzählen.
 *
 * Gedeckelt bei CLICK_DIM_MAX Einträgen: Ohne Grenze könnte jemand über
 * erfundene Herkunftsseiten die Zählerdatei beliebig wachsen lassen – sie
 * wird bei jeder Weiterleitung geschrieben. Ist die Liste voll, wandert
 * alles Weitere in einen Sammeleintrag; die Summe bleibt damit richtig,
 * auch wenn die Aufschlüsselung endet.
 */
function click_dim_bump(array $liste, string $wert): array
{
    if (!isset($liste[$wert]) && count($liste) >= CLICK_DIM_MAX) $wert = '*';
    $liste[$wert] = (int)($liste[$wert] ?? 0) + 1;
    return $liste;
}

/**
 * Aufruf zählen.
 *
 * Ohne $item ist der Kurzlink selbst gemeint – bei einer Link-in-Bio-Seite also
 * der Seitenaufruf. Mit $item ist ein einzelnes Ziel dieser Seite gemeint; die
 * Zählweise bleibt dieselbe, nur eine Ebene tiefer.
 */
/**
 * Zählt dieser Aufruf? Drei Ausnahmen, alle ohne einen Krümel Speicherung:
 *
 *  - **Bekannte Bots.** Vorschau-Dienste, Suchmaschinen, Monitoring: Jede in
 *    einen Chat geworfene Nachricht löste sonst einen „Klick" aus, und ein
 *    Uptime-Check zählte 1440 Besucher am Tag. Die Kennung wird geprüft und
 *    vergessen – gespeichert wird sie nicht. Weitergeleitet wird trotzdem.
 *  - **HEAD-Anfragen.** So fragt Werkzeug, nicht Publikum.
 *  - **Der Link-Besitzer selbst** (und seine Arbeitsgruppe): Wer seinen Link
 *    fünfmal testet, soll seine Kampagne nicht um fünf Klicks anheben. Geprüft
 *    wird NUR, wenn ohnehin ein Sitzungs-Keks mitkommt – für anonyme Besucher
 *    startet der Weiterleitungspfad weiterhin keine Session.
 */
function click_zaehlbar(?array $link = null): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') return false;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua !== '' && preg_match(
        '#facebookexternalhit|Twitterbot|Slackbot|Discordbot|WhatsApp|TelegramBot|LinkedInBot'
        . '|Mastodon|Pleroma|SkypeUriPreview|redditbot|Applebot|Googlebot|bingbot|DuckDuckBot'
        . '|Embedly|Iframely|vkShare|W3C_Validator|SignalBot|Threema'
        . '|\bbot\b|crawler|spider|slurp|HeadlessChrome|Lighthouse|GTmetrix|Pingdom|UptimeRobot'
        . '|curl/|Wget/|python-requests|libwww|Go-http-client#i', $ua) === 1) {
        return false;
    }
    if ($link !== null && isset($_COOKIE['kurzsid'])) {
        // Der Keks allein beweist nichts – erst die Sitzung sagt, wer da ist.
        require_once __DIR__ . '/auth.php';
        require_once __DIR__ . '/groups.php';
        auth_boot();
        $u = auth_user();
        if ($u !== null && link_access($u, $link)) return false;
    }
    return true;
}

function clicks_bump(string $code, ?int $item = null, ?int $weiche = null): void
{
    $herkunft = $item === null ? click_dims() : [];
    json_update(clicks_file($code), function (array $c) use ($item, $herkunft, $weiche) {
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
            $neu = $zaehle($c) + $c;
            foreach ($herkunft as $feld => $wert) {
                $neu[$feld] = click_dim_bump((array)($neu[$feld] ?? []), $wert);
            }
            // Welche Weiche gegriffen hat. Das ist keine Besuchereigenschaft,
            // sondern eine Eigenschaft des Links – deshalb unabhängig von
            // click_dims: Ohne diese Zahl wüsste niemand, ob eine gestellte
            // Weiche überhaupt je benutzt wird.
            if ($weiche !== null) {
                $r = (array)($neu['routes'] ?? []);
                $r[(string)$weiche] = (int)($r[(string)$weiche] ?? 0) + 1;
                $neu['routes'] = $r;
            }
            return $neu;
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

/** @return array<string,array{name:string,by:?string,created:string,shared?:string[]}> */
function logos_meta(): array
{
    return json_read(logos_meta_file());
}

/**
 * Eine hochgeladene Datei in die Bibliothek aufnehmen.
 *
 * Der Typ wird aus dem Inhalt bestimmt, nicht aus dem Dateinamen: Eine
 * „logo.png“, die in Wahrheit etwas anderes ist, hat hier nichts zu suchen.
 * SVG ist ein Dokument und kein Bild – gespeichert wird nur die bereinigte
 * Neufassung aus svg_clean(); was sich nicht bereinigen lässt, wird abgelehnt.
 *
 * Kontingent und Berechtigung prüft der Aufrufer – die hängen am Konto, nicht
 * an der Datei.
 *
 * @param array $datei Ein Eintrag aus $_FILES
 * @return array{0:?string,1:string} [Fehlertext oder null, Anzeigename]
 */
function logo_store(array $datei, string $wunschname, string $besitzer): array
{
    require_once __DIR__ . '/svg.php';
    $tmp = (string)($datei['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return [t('Es kam keine Datei an.'), ''];
    if ((int)($datei['size'] ?? 0) > 512 * 1024) return [t('Logo zu groß (max. 512 KB).'), ''];

    $ext = match ((new finfo(FILEINFO_MIME_TYPE))->file($tmp)) {
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        default => null,
    };
    if ($ext === null) return [t('Nur PNG, JPG, WebP oder SVG.'), ''];

    $svgSauber = null;
    if ($ext === 'svg') {
        $svgSauber = svg_clean((string)file_get_contents($tmp));
        if ($svgSauber === null) {
            return [t('Dieses SVG lässt sich nicht sicher übernehmen – bitte als einfache Grafik (Pfade und Flächen) exportieren oder PNG verwenden.'), ''];
        }
    }

    $id = bin2hex(random_bytes(8)) . '.' . $ext;
    $ziel = data_path('logos') . '/' . $id;
    $ok = $ext === 'svg'
        ? file_put_contents($ziel, $svgSauber) !== false
        : move_uploaded_file($tmp, $ziel);
    if (!$ok) return [t('Die Datei ließ sich nicht ablegen.'), ''];
    @chmod($ziel, 0600);

    // Anzeigename: Wunsch aus dem Formular, sonst der Name der Originaldatei
    $name = trim($wunschname);
    if ($name === '') $name = (string)pathinfo((string)($datei['name'] ?? ''), PATHINFO_FILENAME);
    $name = mb_strimwidth(trim($name), 0, 40, '…');
    if ($name === '') $name = 'Logo';
    logo_meta_set($id, $name, $besitzer);
    return [null, $name];
}

function logo_meta_set(string $id, string $name, ?string $by): void
{
    json_update(logos_meta_file(), function (array $m) use ($id, $name, $by) {
        // Eine vorhandene Freigabe überlebt das Umbenennen
        $m[$id] = ['name' => $name, 'by' => $by, 'created' => date('c'),
                   'shared' => (array)($m[$id]['shared'] ?? [])];
        return $m;
    });
}

/**
 * Ein Logo für Gruppen freigeben.
 *
 * Ein Logo gehört dem, der es hochgeladen hat – es zählt weiterhin auf dessen
 * Kontingent, und nur er (oder ein Administrator) darf es löschen. Die
 * Freigabe erlaubt anderen ausschließlich, es zu VERWENDEN.
 *
 * @param string[] $gruppen Gruppen-Kennungen; der Sonderwert '*' steht für
 *                          alle angemeldeten Konten. Leere Liste = nur selbst.
 */
function logo_share_set(string $id, array $gruppen): void
{
    $bekannt = function_exists('groups_all') ? groups_all() : [];
    $sauber = [];
    foreach ($gruppen as $g) {
        $g = (string)$g;
        if ($g === '*') { $sauber[] = '*'; continue; }
        if (isset($bekannt[$g])) $sauber[] = $g;
    }
    $sauber = array_values(array_unique($sauber));
    json_update(logos_meta_file(), function (array $m) use ($id, $sauber) {
        if (isset($m[$id])) $m[$id]['shared'] = $sauber;
        return $m;
    });
}

/**
 * Darf dieses Konto das Logo verwenden?
 *
 * @param array{name:string,by:?string,shared?:string[]} $meta
 */
function logo_visible_for(array $meta, string $username, string $role, array $gruppen): bool
{
    if ($role === 'admin') return true;
    if (($meta['by'] ?? null) === $username) return true;
    $shared = (array)($meta['shared'] ?? []);
    if (in_array('*', $shared, true)) return true;
    return array_intersect($shared, $gruppen) !== [];
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
    // ip_hash() und nicht hash('sha256', …): Ein blanker SHA-256 über eine
    // IPv4-Adresse ist keine Anonymisierung – der Adressraum ist so klein,
    // dass sich eine vollständige Tabelle in Minuten rechnen lässt. Alle
    // übrigen Stellen nutzten längst den HMAC; diese eine war übersehen.
    $file = data_path('ratelimit') . '/' . ip_hash($ip) . '.json';
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
