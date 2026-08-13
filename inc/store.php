<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function links_file(): string
{
    return data_path() . '/links.json';
}

/** @return array<string,array> alle Links: code => {url, owner, type, created, updated} */
function links_all(): array
{
    return json_read(links_file());
}

function link_get(string $code): ?array
{
    return links_all()[$code] ?? null;
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
function links_gc(): void
{
    $years = (int)cfg('link_gc_years');
    if ($years < 1) return;
    $yearsLong = max($years, (int)cfg('link_gc_years_unreachable'));

    $marker = data_path() . '/links-gc.json';
    if (is_file($marker) && filemtime($marker) > time() - 7 * 86400) return;
    json_write($marker, ['last_run' => date('c')]);

    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/mail.php';

    // Kurze Frist nur, wo eine Warnung möglich ist; sonst die lange
    $deleteCutoff = strtotime('-' . $years . ' years');
    $warnCutoff = strtotime('-' . $years . ' years +30 days'); // 1 Monat vor Ablauf
    $deleteCutoffLong = strtotime('-' . $yearsLong . ' years');
    $warnFile = data_path() . '/links-gc-warned.json';
    $warned = json_read($warnFile);
    $log = function (string $line): void {
        file_put_contents(data_path() . '/links-gc.log', date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    };

    /** E-Mail-Adresse zum Besitzer eines Links (null = nicht erreichbar) */
    $ownerMail = function (?string $owner): ?string {
        if ($owner === null) return null;
        $u = users_all()[$owner] ?? null;
        if ($u === null) return null;
        $mail = $u['email'] ?? (filter_var($owner, FILTER_VALIDATE_EMAIL) !== false ? $owner : null);
        return is_string($mail) ? $mail : null;
    };

    $toWarn = []; // email => [code => lastUse]
    foreach (links_all() as $code => $l) {
        $code = (string)$code;
        if (!empty($l['disabled'])) continue;
        $clicks = clicks_get($code);
        $lastUse = $clicks['last'] !== null
            ? strtotime((string)$clicks['last'])
            : strtotime((string)($l['created'] ?? ''));
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
            $lines .= '  ' . short_url((string)$code) . ' (letzte Nutzung: ' . date('d.m.Y', $lastUse) . ")\n";
        }
        $ok = mail_send($mail, 'Lange ungenutzte Kurzlinks werden bald gelöscht',
            "Hallo,\n\n"
            . "die folgenden Kurzlinks deines Kontos wurden seit fast " . $years . " Jahren\n"
            . "nicht ein einziges Mal aufgerufen und werden daher in etwa einem Monat\n"
            . "automatisch gelöscht (AGB § 2):\n\n"
            . $lines . "\n"
            . "Ein einziger Aufruf genügt, um die Frist vollständig zurückzusetzen.\n"
            . "Nichts weiter nötig, wenn die Links weg können.\n\n"
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

function link_create(string $url, ?string $code, ?string $owner, string $type, string $prefix = '', ?string $expires = null, ?string $group = null): array
{
    links_gc();
    $result = [false, 'Unbekannter Fehler'];
    json_update(links_file(), function (array $links) use ($url, $code, $owner, $type, $prefix, $expires, $group, &$result) {
        if ($code === null) {
            $code = link_random_code($links, $prefix);
            if ($code === null) {
                $result = [false, 'Kein freier Code gefunden – Code-Länge in config.php erhöhen.'];
                return null;
            }
        } elseif (isset($links[$code])) {
            $result = [false, 'Dieser Code ist schon vergeben.'];
            return null;
        }
        $links[$code] = [
            'url' => $url,
            'owner' => $owner,
            'type' => $type,
            'expires' => $expires,
            'created' => date('c'),
            'updated' => date('c'),
        ];
        // Nur setzen, wenn es wirklich eine Gruppe gibt – kein null-Ballast
        // in den Datensätzen der Instanzen, die ohne Gruppen arbeiten
        if ($group !== null && $group !== '') $links[$code]['group'] = $group;
        $result = [true, $code];
        return $links;
    });
    return $result;
}

function link_update(string $code, string $url, ?string $expires = null): bool
{
    $ok = false;
    json_update(links_file(), function (array $links) use ($code, $url, $expires, &$ok) {
        if (!isset($links[$code])) return null;
        $links[$code]['url'] = $url;
        $links[$code]['expires'] = $expires;
        $links[$code]['updated'] = date('c');
        $ok = true;
        return $links;
    });
    return $ok;
}

/** Gruppenzuordnung eines Links setzen ($group = null hebt sie auf) */
function link_set_group(string $code, ?string $group): bool
{
    $ok = false;
    json_update(links_file(), function (array $links) use ($code, $group, &$ok) {
        if (!isset($links[$code])) return null;
        if ($group === null || $group === '') {
            unset($links[$code]['group']);
        } else {
            $links[$code]['group'] = $group;
        }
        $links[$code]['updated'] = date('c');
        $ok = true;
        return $links;
    });
    return $ok;
}

/** Anzahl aktiver Wunsch-Codes eines Kontos (für das Pro-Kontingent) */
function custom_code_count(string $owner): int
{
    $n = 0;
    foreach (links_all() as $l) {
        if (($l['owner'] ?? null) === $owner && ($l['type'] ?? '') === 'custom') $n++;
    }
    return $n;
}

/** Anzahl aller aktiven Links eines Kontos (für das Tarif-Limit) */
function link_count(string $owner): int
{
    $n = 0;
    foreach (links_all() as $l) {
        if (($l['owner'] ?? null) === $owner) $n++;
    }
    return $n;
}

/** Passwortschutz setzen (Hash) oder entfernen (null) – Pro-Feature */
function link_set_password(string $code, ?string $hash): bool
{
    $ok = false;
    json_update(links_file(), function (array $links) use ($code, $hash, &$ok) {
        if (!isset($links[$code])) return null;
        if ($hash === null) {
            unset($links[$code]['pass']);
        } else {
            $links[$code]['pass'] = $hash;
        }
        $links[$code]['updated'] = date('c');
        $ok = true;
        return $links;
    });
    return $ok;
}

/** Link wegen Missbrauchs sperren/entsperren (gesperrte Links antworten mit 410) */
function link_set_disabled(string $code, bool $disabled): bool
{
    $ok = false;
    json_update(links_file(), function (array $links) use ($code, $disabled, &$ok) {
        if (!isset($links[$code])) return null;
        if ($disabled) {
            $links[$code]['disabled'] = true;
        } else {
            unset($links[$code]['disabled']);
        }
        $links[$code]['updated'] = date('c');
        $ok = true;
        return $links;
    });
    return $ok;
}

function link_delete(string $code): void
{
    json_update(links_file(), function (array $links) use ($code) {
        unset($links[$code]);
        return $links;
    });
    @unlink(clicks_file($code));
}

/** Freien Zufallscode suchen (innerhalb des Locks aufgerufen), optional unter einem Prefix ("p/abc123") */
function link_random_code(array $existing, string $prefix = ''): ?string
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
        if (!isset($existing[$code])) return $code;
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

function clicks_bump(string $code): void
{
    json_update(clicks_file($code), function (array $c) {
        $today = date('Y-m-d');
        $days = $c['days'] ?? [];
        $days[$today] = ($days[$today] ?? 0) + 1;
        // Historie begrenzen: älteste Tage raus (400 deckt die 12-Monats-Statistik des Pro-Tarifs)
        if (count($days) > 400) {
            ksort($days);
            $days = array_slice($days, -400, null, true);
        }
        return ['n' => ($c['n'] ?? 0) + 1, 'last' => date('c'), 'days' => $days];
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
