<?php
declare(strict_types=1);

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
    if ($resp === false) return [];

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['matches'])) return [];

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

/** Einzel-URL-Variante (siehe urls_flagged) */
function url_flagged(string $url): bool
{
    return urls_flagged([$url]) !== [];
}
