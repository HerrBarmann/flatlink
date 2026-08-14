<?php
declare(strict_types=1);
/**
 * Programmierschnittstelle für Kurzlinks.
 *
 * Aufbau bewusst schlicht: eine Datei, JSON rein, JSON raus, ein Schlüssel im
 * Kopf der Anfrage. Keine Sitzung, kein Cookie – und das ist keine Sparsamkeit,
 * sondern Absicht: Würde die Schnittstelle das Sitzungs-Cookie akzeptieren,
 * könnte eine fremde Seite im Browser eines angemeldeten Nutzers Anfragen
 * stellen und dessen Links ändern. Ohne Cookie gibt es diese Angriffsfläche
 * nicht, und dann braucht es auch kein CSRF-Token.
 *
 * Adressierung:
 *   /api.php/links/abc123          – wenn der Server PATH_INFO liefert
 *   /api.php?p=/links/abc123       – Rückfall, wenn nicht
 *   /api/links/abc123              – mit der Regel aus .htaccess
 *
 * Anmeldung:
 *   Authorization: Bearer flk_…    – der übliche Weg
 *   X-Api-Key: flk_…               – Rückfall für Server, die den Kopf
 *                                    Authorization vor PHP wegnehmen
 *
 * Die Schnittstelle kann nichts, was das Konto nicht auch über die Oberfläche
 * könnte: Sämtliche Regeln kommen aus inc/linkrules.php, derselben Fassung.
 */
require_once __DIR__ . '/inc/linkrules.php';
require_once __DIR__ . '/inc/token.php';
require_once __DIR__ . '/inc/auth.php';

// ---- Ausgabe -------------------------------------------------------------

function api_out(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    nosniff_header();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_fail(int $status, string $code, string $message): never
{
    api_out($status, ['error' => ['code' => $code, 'message' => $message]]);
}

// ---- Anfrage einlesen ----------------------------------------------------

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$pfad = (string)($_SERVER['PATH_INFO'] ?? '');
if ($pfad === '') $pfad = (string)($_GET['p'] ?? '');
$teile = array_values(array_filter(explode('/', trim($pfad, '/')), fn($t) => $t !== ''));

/** Körper der Anfrage: JSON oder Formularfelder, beides ist erlaubt */
function api_body(): array
{
    static $body = null;
    if ($body !== null) return $body;
    $typ = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $roh = (string)file_get_contents('php://input');
    if (str_contains($typ, 'application/json')) {
        $d = json_decode($roh, true);
        if (!is_array($d)) {
            api_fail(400, 'invalid_json', 'Der Körper der Anfrage ist kein gültiges JSON.');
        }
        return $body = $d;
    }
    if ($roh !== '' && !str_contains($typ, 'multipart/')) {
        parse_str($roh, $d);
        return $body = (array)$d;
    }
    return $body = $_POST;
}

// ---- Anmeldung -----------------------------------------------------------

/** Schlüssel aus dem Kopf der Anfrage holen */
function api_key_from_request(): string
{
    // Manche Server reichen Authorization nicht durch, andere nur unter einem
    // umbenannten Schlüssel. Deshalb alle üblichen Stellen absuchen.
    $kandidaten = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];
    foreach ($kandidaten as $k) {
        if (is_string($k) && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $k, $m) === 1) {
            return $m[1];
        }
    }
    $x = $_SERVER['HTTP_X_API_KEY'] ?? null;
    return is_string($x) ? trim($x) : '';
}

$schluessel = api_key_from_request();
if ($schluessel === '') {
    header('WWW-Authenticate: Bearer');
    api_fail(401, 'no_key', 'Kein Zugangsschlüssel. Erwartet wird der Kopf '
        . '„Authorization: Bearer flk_…" oder „X-Api-Key: flk_…".');
}

// Versuche zählen, bevor der Schlüssel geprüft wird – sonst ließe sich hier
// ungebremst durchprobieren. Gezählt wird nach IP, denn einen gültigen
// Schlüssel gibt es in diesem Moment ja noch nicht.
if (!bucket_rate_ok('apiauth', 60)) {
    api_fail(429, 'too_many_attempts', 'Zu viele fehlgeschlagene Anmeldungen – bitte später erneut.');
}

$eintrag = token_find($schluessel);
if ($eintrag === null) {
    api_fail(401, 'bad_key', 'Dieser Zugangsschlüssel ist unbekannt oder zurückgezogen.');
}

$name = (string)$eintrag['user'];
$konto = users_all()[$name] ?? null;
if ($konto === null) {
    api_fail(401, 'no_account', 'Das Konto zu diesem Schlüssel gibt es nicht mehr.');
}
$user = ['name' => $name, 'role' => (string)($konto['role'] ?? 'user')];

if (!user_can($name, 'api_access')) {
    api_fail(403, 'no_permission', 'Diesem Konto fehlt die Berechtigung für die Schnittstelle.');
}

// Ab hier ist der Schlüssel bekannt – also nach ihm zählen und nicht nach der
// IP: Ein Server, der die Schnittstelle bedient, kommt immer von derselben.
$limit = max(1, (int)cfg('api_rate_limit'));
if (!bucket_rate_ok('api', $limit, $eintrag['id'])) {
    header('Retry-After: ' . (3600 - (int)date('i') * 60 - (int)date('s')));
    api_fail(429, 'rate_limited', 'Stundengrenze von ' . $limit . ' Anfragen erreicht.');
}

// ---- Darstellung eines Links ---------------------------------------------

function api_link(string $code, array $l): array
{
    return [
        'code' => $code,
        'short_url' => short_url($code),
        'url' => $l['url'] ?? null,
        'title' => $l['title'] ?? null,
        'tags' => array_values((array)($l['tags'] ?? [])),
        'type' => $l['type'] ?? 'random',
        'group' => $l['group'] ?? null,
        'owner' => $l['owner'] ?? null,
        'expires' => $l['expires'] ?? null,
        'expired' => link_expired($l),
        'password_protected' => isset($l['pass']),
        'disabled' => (bool)($l['disabled'] ?? false),
        'created' => $l['created'] ?? null,
        'updated' => $l['updated'] ?? null,
        'clicks' => (int)(clicks_get($code)['n'] ?? 0),
    ];
}

/** Link holen und Zugriff prüfen – oder mit der passenden Antwort aussteigen */
function api_link_or_fail(array $user, string $code): array
{
    $l = link_get($code);
    // Bewusst dieselbe Antwort für „gibt es nicht" und „gehört jemand anderem":
    // Sonst ließe sich über die Schnittstelle herausfinden, welche Kurzcodes
    // vergeben sind.
    if ($l === null || !link_access($user, $l)) {
        api_fail(404, 'not_found', 'Diesen Kurzlink gibt es nicht, oder er gehört einem anderen Konto.');
    }
    return $l;
}

// ---- Endpunkte -----------------------------------------------------------

$ressource = $teile[0] ?? '';

if ($ressource === 'me' && count($teile) === 1) {
    if ($method !== 'GET') api_fail(405, 'method_not_allowed', 'Hier ist nur GET vorgesehen.');
    api_out(200, [
        'account' => $name,
        'display_name' => user_has_display($name) ? user_display($name) : null,
        'role' => $user['role'],
        'groups' => user_groups($name),
        'permissions' => user_perms($name),
        'limits' => [
            'links' => limit_label(user_limit($name, 'links')),
            'links_used' => link_count($name),
            'stats_days' => limit_label(user_limit($name, 'stats_days')),
            'logos' => limit_label(user_limit($name, 'logos')),
        ],
        'assignable_groups' => link_rules_assignable($user),
        'rate_limit_per_hour' => $limit,
        'key' => ['id' => $eintrag['id'], 'label' => $eintrag['label'] ?? ''],
    ]);
}

if ($ressource === 'links') {
    // ---- Sammlung ----
    if (count($teile) === 1) {
        if ($method === 'GET') {
            $links = links_visible($user);
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $links = array_filter($links, fn($l, $c) => stripos((string)$c, $q) !== false
                    || stripos((string)($l['url'] ?? ''), $q) !== false
                    || stripos((string)($l['title'] ?? ''), $q) !== false
                    || in_array(mb_strtolower($q), (array)($l['tags'] ?? []), true), ARRAY_FILTER_USE_BOTH);
            }
            $g = (string)($_GET['group'] ?? '');
            if ($g !== '') {
                $links = array_filter($links, fn($l) => (string)($l['group'] ?? '') === $g);
            }
            $tag = mb_strtolower(trim((string)($_GET['tag'] ?? '')));
            if ($tag !== '') {
                $links = array_filter($links, fn($l) => in_array($tag, (array)($l['tags'] ?? []), true));
            }
            uasort($links, fn($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));

            $gesamt = count($links);
            $limitN = min(200, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $seite = array_slice($links, $offset, $limitN, true);

            $out = [];
            foreach ($seite as $c => $l) $out[] = api_link((string)$c, $l);
            api_out(200, ['total' => $gesamt, 'limit' => $limitN, 'offset' => $offset, 'links' => $out]);
        }

        if ($method === 'POST') {
            $in = api_body();
            [$err, $full, $opts] = link_rules_create($user, [
                'url' => (string)($in['url'] ?? ''),
                'code' => (string)($in['code'] ?? ''),
                'prefix' => (string)($in['prefix'] ?? ''),
                'group' => (string)($in['group'] ?? ''),
                'expires' => (string)($in['expires'] ?? ''),
                'title' => (string)($in['title'] ?? ''),
                'tags' => $in['tags'] ?? '',
            ]);
            if ($err !== null) api_fail(422, 'rejected', $err);

            [$ok, $ergebnis] = link_create($opts['url'], $full, $name,
                $full === null ? 'random' : 'custom', $opts);
            if (!$ok) api_fail(409, 'not_created', $ergebnis);

            $pass = (string)($in['password'] ?? '');
            if ($pass !== '') link_set_password($ergebnis, password_hash($pass, PASSWORD_DEFAULT));

            header('Location: ' . base_url() . '/api.php/links/' . rawurlencode($ergebnis));
            api_out(201, api_link($ergebnis, (array)link_get($ergebnis)));
        }
        api_fail(405, 'method_not_allowed', 'Hier sind GET und POST vorgesehen.');
    }

    // ---- Einzelner Link ----
    $code = $teile[1];
    $unter = $teile[2] ?? '';

    if ($unter === 'stats' && count($teile) === 3) {
        if ($method !== 'GET') api_fail(405, 'method_not_allowed', 'Hier ist nur GET vorgesehen.');
        api_link_or_fail($user, $code);
        $c = clicks_get($code);
        // Die Statistik reicht nur so weit zurück, wie das Konto sie sehen darf
        $tage = user_limit($name, 'stats_days');
        $days = (array)($c['days'] ?? []);
        if ($tage !== PHP_INT_MAX) {
            $grenze = date('Y-m-d', strtotime('-' . $tage . ' days'));
            $days = array_filter($days, fn($t) => $t >= $grenze, ARRAY_FILTER_USE_KEY);
        }
        ksort($days);
        api_out(200, [
            'code' => $code,
            'total' => (int)($c['n'] ?? 0),
            'last' => $c['last'] ?? null,
            'days' => (object)$days,
        ]);
    }

    if ($unter !== '') api_fail(404, 'not_found', 'Diesen Endpunkt gibt es nicht.');

    if ($method === 'GET') {
        api_out(200, api_link($code, api_link_or_fail($user, $code)));
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        $l = api_link_or_fail($user, $code);
        $in = api_body();
        // Nur übergebene Felder ändern. Ein Aufruf, der bloß das Ziel setzt,
        // darf nicht nebenbei den Namen löschen – anders als ein Formular,
        // das seine Felder immer vollständig mitschickt.
        $rein = [];
        foreach (['url', 'expires', 'group', 'title'] as $f) {
            if (array_key_exists($f, $in)) $rein[$f] = (string)$in[$f];
        }
        // Schlagworte dürfen als Liste oder als Zeichenkette mit Kommas kommen
        if (array_key_exists('tags', $in)) $rein['tags'] = $in['tags'];
        [$err, $opts] = link_rules_update($user, $l, $rein);
        if ($err !== null) api_fail(422, 'rejected', $err);

        link_update($code, $opts['url'], $opts);
        if (array_key_exists('group', $rein)) link_set_group($code, $opts['group']);
        if (array_key_exists('password', $in)) {
            $p = (string)$in['password'];
            link_set_password($code, $p === '' ? null : password_hash($p, PASSWORD_DEFAULT));
        }
        if (array_key_exists('disabled', $in)) {
            link_set_disabled($code, (bool)$in['disabled']);
        }
        api_out(200, api_link($code, (array)link_get($code)));
    }

    if ($method === 'DELETE') {
        api_link_or_fail($user, $code);
        link_delete($code);
        api_out(200, ['deleted' => $code]);
    }

    api_fail(405, 'method_not_allowed', 'Hier sind GET, PATCH und DELETE vorgesehen.');
}

api_fail(404, 'not_found', 'Unbekannter Endpunkt. Verfügbar sind /me und /links.');
