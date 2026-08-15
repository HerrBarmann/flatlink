<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/helpers.php';

/**
 * Mehrere Ziel-URLs in EINEM Aufruf gegen Google Safe Browsing v4 prüfen
 * (die API akzeptiert bis zu 500 Einträge pro Anfrage – wichtig für den CSV-Import).
 *
 * Ohne konfigurierten API-Key oder bei API-Fehlern wird NICHT blockiert
 * (fail-open: Verfügbarkeit vor Strenge) – Treffer landen in data/safety.log.
 *
 * @param string[] $urls
 * @return string[] die als schädlich gemeldeten URLs (leer = alles sauber oder Prüfung nicht möglich)
 */
function urls_flagged(array $urls): array
{
    $key = (string)cfg('safe_browsing_key');
    $urls = array_values(array_unique(array_filter($urls)));
    if ($key === '' || $urls === []) return [];

    $payload = json_encode([
        'client' => ['clientId' => cfg('site_name'), 'clientVersion' => '1.0'],
        'threatInfo' => [
            'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE'],
            'platformTypes' => ['ANY_PLATFORM'],
            'threatEntryTypes' => ['URL'],
            'threatEntries' => array_map(fn(string $u) => ['url' => $u], $urls),
        ],
    ]);

    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents(
        'https://safebrowsing.googleapis.com/v4/threatMatches:find?key=' . urlencode($key),
        false,
        $ctx
    );
    if ($resp === false) {
        safety_fail_note();
        return [];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        safety_fail_note();
        return [];
    }
    safety_fail_reset();
    if (empty($data['matches'])) return [];

    $flagged = [];
    foreach ($data['matches'] as $m) {
        $u = $m['threat']['url'] ?? null;
        if ($u === null) continue;
        $flagged[] = $u;
        file_put_contents(data_path() . '/safety.log',
            date('c') . ' ' . ($m['threatType'] ?? '?') . ' ' . $u . "\n", FILE_APPEND | LOCK_EX);
    }
    return array_unique($flagged);
}

/**
 * Eine fehlgeschlagene Prüfung vermerken.
 *
 * Die Prüfung ist bewusst fail-open: Ein Ausfall bei Google darf nicht dazu
 * führen, dass niemand mehr einen Link anlegen kann. Der Preis ist, dass ein
 * dauerhafter Ausfall – abgelaufener Schlüssel, gesperrtes Kontingent,
 * geschlossener Ausgang – lautlos ist: Es sieht alles aus wie immer, nur
 * geprüft wird nichts mehr. Deshalb wird mitgezählt, seit wann.
 */
function safety_fail_note(): void
{
    $f = data_path() . '/safety-fails.json';
    json_update($f, function (array $d) {
        $d['n'] = (int)($d['n'] ?? 0) + 1;
        if (($d['seit'] ?? '') === '') $d['seit'] = date('c');
        $d['zuletzt'] = date('c');
        return $d;
    });
}

/** Nach einer geglückten Prüfung ist die Strähne vorbei */
function safety_fail_reset(): void
{
    $f = data_path() . '/safety-fails.json';
    if (is_file($f)) @unlink($f);
}

/**
 * Läuft die Prüfung gerade ins Leere?
 * @return array{n:int,seit:string,zuletzt:string}|null
 */
function safety_fail_state(): ?array
{
    $d = json_read(data_path() . '/safety-fails.json');
    return isset($d['n']) && (int)$d['n'] > 0 ? $d : null;
}

/** Einzel-URL-Variante (siehe urls_flagged) */
function url_flagged(string $url): bool
{
    return urls_flagged([$url]) !== [];
}

/**
 * Den Bestand erneut gegen Safe Browsing prüfen.
 *
 * Die Prüfung beim Anlegen fängt, was schon bösartig IST. Der häufigere Fall
 * bei einem öffentlichen Dienst ist der andere: Eine harmlose Seite wird
 * Wochen später übernommen, und der gedruckte Code zeigt seither auf
 * Schadcode. Dagegen hilft nur, gelegentlich nachzusehen.
 *
 * Wie das Aufräumen (links_gc) hängt der Lauf an einem Zeitstempel und wird
 * von irgendeinem Besucher ausgelöst – kein Cronjob nötig. Er kostet auch
 * bei großen Beständen wenig, weil Safe Browsing 500 Adressen je Anfrage
 * annimmt und nur geprüft wird, was noch nie oder lange nicht dran war.
 *
 * Was gefunden wird, wird NICHT gelöscht, sondern gesperrt: Der Code
 * antwortet dann mit 410 statt weiterzuleiten, bleibt aber nachvollziehbar
 * und wird nicht neu vergeben. Ein Fehlalarm ist damit in der
 * Meldungsverwaltung mit einem Klick zurückzunehmen.
 *
 * @param bool $sofort true = ohne auf den Zeitstempel zu sehen (Knopf)
 * @return array{geprueft:int,gesperrt:string[]}|null null = übersprungen
 */
function safety_recheck(bool $sofort = false): ?array
{
    if ((string)cfg('safe_browsing_key') === '') return null;
    $tage = (int)cfg('safety_recheck_days');
    if (!$sofort && $tage < 1) return null;

    $marker = data_path() . '/safety-recheck.json';
    if (!$sofort && is_file($marker) && filemtime($marker) > time() - $tage * 86400) return null;
    json_write($marker, ['last_run' => date('c')]);

    require_once __DIR__ . '/store.php';

    // Nur aktive Links: Gesperrte sind schon erledigt, abgelaufene leiten
    // ohnehin nicht mehr weiter – beide brauchen keine Anfrage an Google.
    $kandidaten = [];
    foreach (links_all() as $code => $l) {
        if (!empty($l['disabled']) || link_expired($l)) continue;
        $url = (string)($l['url'] ?? '');
        if ($url === '') continue;
        $kandidaten[(string)$code] = $url;
    }
    if ($kandidaten === []) return ['geprueft' => 0, 'gesperrt' => []];

    // In Blöcken zu 500 – so viele nimmt die Schnittstelle je Anfrage an
    $gesperrt = [];
    foreach (array_chunk($kandidaten, 500, true) as $block) {
        $treffer = urls_flagged(array_values($block));
        if ($treffer === []) continue;
        foreach ($block as $code => $url) {
            if (!in_array($url, $treffer, true)) continue;
            link_set_disabled($code, true);
            $gesperrt[] = $code;
            safety_log('recheck-gesperrt', $code, $url);
        }
    }
    return ['geprueft' => count($kandidaten), 'gesperrt' => $gesperrt];
}

/** Eine Zeile ins Sicherheitsprotokoll */
function safety_log(string $ereignis, string $code, string $url): void
{
    file_put_contents(data_path() . '/safety.log',
        date('c') . ' ' . $ereignis . ' ' . $code . ' ' . $url . "\n",
        FILE_APPEND | LOCK_EX);
}
