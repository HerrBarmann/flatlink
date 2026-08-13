<?php
declare(strict_types=1);

require_once __DIR__ . '/qrlib.php';

function cfg(?string $key = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Konfiguration fehlt. Bitte inc/config.example.php nach inc/config.php kopieren und anpassen.');
        }
        // Beispieldatei als Fallback, damit neue Optionen nach einem Update
        // nicht sofort zu Notices führen
        $cfg = array_merge(require __DIR__ . '/config.example.php', require $file);
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function data_path(string $sub = ''): string
{
    $base = dirname(__DIR__) . '/data';
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
        file_put_contents($base . '/.htaccess', "Require all denied\n");
        file_put_contents($base . '/index.html', '');
    }
    if ($sub !== '') {
        $dir = $base . '/' . $sub;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        return $dir;
    }
    return $base;
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** Basis-URL des Dienstes, ohne Slash am Ende */
function base_url(): string
{
    $configured = cfg('base_url');
    if ($configured !== '') return rtrim($configured, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // www. taucht in Kurzlinks niemals auf – die Marke ist die nackte Domain
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);
    // Verzeichnis des Webroot-Scripts (index.php, go.php, qr.php liegen im Root)
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (str_ends_with($dir, '/admin')) $dir = substr($dir, 0, -6);
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function short_url(string $code): string
{
    return base_url() . '/' . $code;
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---- JSON-Dateien mit Locking ----

function json_read(string $file, array $default = []): array
{
    if (!is_file($file)) return $default;
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/** Atomar schreiben: Tempdatei + rename */
function json_write(string $file, array $data): void
{
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, $file);
}

/**
 * Read-Modify-Write unter exklusivem Lock.
 * $fn bekommt die Daten und gibt die neuen zurück (oder null = nichts ändern).
 */
function json_update(string $file, callable $fn, array $default = []): array
{
    $lock = fopen($file . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $data = json_read($file, $default);
        $new = $fn($data);
        if ($new !== null) {
            json_write($file, $new);
            $data = $new;
        }
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ---- Laufzeit-Einstellungen (vom Admin änderbar, data/settings.json) ----

function settings(): array
{
    static $s = null;
    if ($s === null) {
        $defaults = [
            'public_mode' => 'on',          // on | prefix | off
            'public_prefix' => 'p',         // Namensraum für öffentliche Links im Prefix-Modus
            'public_rate_limit' => cfg('public_rate_limit'),
            'registration' => 'on',         // Selbst-Registrierung: on | off
        ];
        $s = array_merge($defaults, json_read(data_path() . '/settings.json'));
    }
    return $s;
}

function settings_save(array $new): void
{
    json_write(data_path() . '/settings.json', $new);
}

// ---- CSRF ----

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden und erneut versuchen.');
    }
}

// ---- Validierung ----

function valid_code(string $code): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code) === 1
        && !in_array(strtolower($code), cfg('reserved'), true);
}

/** Form eines gespeicherten Codes, inkl. optionalem Prefix-Segment (z. B. "p/abc123") */
function lookup_code_ok(string $code): bool
{
    return preg_match('#^[A-Za-z0-9_-]{1,64}(/[A-Za-z0-9_-]{1,64})?$#', $code) === 1;
}

function valid_url(string $url): bool
{
    return strlen($url) <= cfg('max_url_length')
        && preg_match('#^https?://#i', $url) === 1
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Ablaufdatum aus Formulareingabe parsen.
 * @return array{0:bool,1:?string} [ok, 'YYYY-MM-DD'|null] – leer = kein Ablauf
 */
function parse_expiry(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [true, null];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return [false, null];
    [$y, $m, $d] = array_map('intval', explode('-', $raw));
    if (!checkdate($m, $d, $y)) return [false, null];
    if ($raw < date('Y-m-d')) return [false, null];
    return [true, $raw];
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Rate-Limit-/Login-Schutzdateien (gehashte IPs) älter als 24 h aufräumen */
function rate_limit_gc(): void
{
    foreach (glob(data_path('ratelimit') . '/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
}

/** Generisches Stunden-Rate-Limit pro IP für beliebige Aktionen (Registrierung, Reset, Meldung) */
function bucket_rate_ok(string $bucket, int $limit): bool
{
    rate_limit_gc();
    $file = data_path('ratelimit') . '/' . $bucket . '-' . hash('sha256', client_ip()) . '.json';
    $hour = date('YmdH');
    $ok = true;
    json_update($file, function (array $d) use ($hour, $limit, &$ok) {
        if (($d['hour'] ?? '') !== $hour) $d = ['hour' => $hour, 'n' => 0];
        if ($d['n'] >= $limit) {
            $ok = false;
            return null;
        }
        $d['n']++;
        return $d;
    });
    return $ok;
}

// ---- Mini-Templating ----

/**
 * Seitenkopf.
 *
 * @param ?string $desc       Meta-Description (nur für öffentliche Seiten setzen)
 * @param ?string $canonical  Kanonische URL, falls die Seite indexiert werden soll
 */
function page_header(string $title, bool $admin = false, ?string $desc = null, ?string $canonical = null): void
{
    $site = e(cfg('site_name'));
    $root = $admin ? '..' : '.';
    $GLOBALS['_page_root'] = $root;
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' – ' . $site . '</title>';
    if ($desc !== null) {
        echo '<meta name="description" content="' . e($desc) . '">';
    }
    if ($canonical !== null) {
        echo '<link rel="canonical" href="' . e($canonical) . '">';
    }
    if ($admin) {
        echo '<meta name="robots" content="noindex">';
    }
    echo '<link rel="stylesheet" href="' . $root . '/assets/style.css">';
    // Eigenes Aussehen: assets/custom.css wird nach dem Standard-Stylesheet
    // geladen und überschreibt es damit. Die Datei ist per .gitignore
    // ausgenommen und übersteht jedes Update. Der Zeitstempel im
    // Query-String sorgt dafür, dass Browser Änderungen sofort sehen.
    $custom = dirname(__DIR__) . '/assets/custom.css';
    if (is_file($custom)) {
        echo '<link rel="stylesheet" href="' . $root . '/assets/custom.css?v=' . filemtime($custom) . '">';
    }
    $favicon = (string)cfg('favicon');
    if ($favicon !== '') {
        echo '<link rel="icon" href="' . $root . '/assets/' . e($favicon) . '">';
    }
    echo '</head><body><div class="wrap">';

    // Wortmarke, optional mit eigenem Logo davor
    $logo = (string)cfg('logo');
    $mark = $logo !== ''
        ? '<img class="brand-logo" src="' . $root . '/assets/' . e($logo) . '" alt="">' . $site
        : $site;
    echo '<header class="site-head"><a class="brand" href="' . $root . '/">' . $mark . '</a>';
    // Einheitliche, session-sensitive Navigation auf allen Seiten.
    // (Seiten ohne Session-Start – z. B. die 404-Seite des Redirect-Handlers –
    // zeigen die Gast-Variante; der heiße Redirect-Pfad bleibt Session-frei.)
    $u = function_exists('auth_user') ? auth_user() : null;
    $adm = $admin ? '' : 'admin/'; // Pfad zum Login-Bereich
    $pub = $admin ? '../' : '';    // Pfad zu öffentlichen Seiten
    echo '<nav>';
    if ($u !== null) {
        echo '<a href="' . $adm . 'index.php">Links</a> '
            . '<a href="' . $adm . 'qrdesign.php">QR-Designer</a> ';
        if ($u['role'] === 'admin') {
            echo '<a href="' . $adm . 'users.php">Nutzer</a> '
                . '<a href="' . $adm . 'groups.php">Gruppen</a> '
                . '<a href="' . $adm . 'reports.php">Meldungen</a> '
                . '<a href="' . $adm . 'settings.php">Einstellungen</a> ';
        }
        echo '<a class="who" href="' . $adm . 'profile.php" title="Profil / Passwort ändern">' . e($u['name']) . '</a> '
            . '<a class="btn btn-small" href="' . $adm . 'logout.php">Abmelden</a>';
    } else {
        if (settings()['registration'] === 'on') echo '<a href="' . $pub . 'register.php">Registrieren</a> ';
        echo '<a href="' . $adm . '">Login</a>';
    }
    echo '</nav>';
    echo '</header><main>';
}

/**
 * Herkunftszeile: Kiwi-Zeichen + Hinweis auf das Ursprungsprojekt.
 *
 * flatlink ist der offene Kern von 1337.kiwi. Die Zeile weist das aus, ohne
 * die Instanz zu vereinnahmen – der Kiwi steht hier für die Herkunft, nicht
 * für den Betreiber. Wer sie nicht möchte, setzt 'show_origin' auf false;
 * die Lizenz verlangt sie nicht.
 */
function origin_note(): string
{
    if (!cfg('show_origin')) return '';
    static $glyph = null;
    if ($glyph === null) {
        $file = dirname(__DIR__) . '/assets/origin-kiwi.svg';
        $svg = is_file($file) ? (string)file_get_contents($file) : '';
        // Inline statt <img>, damit der Vogel die Textfarbe erbt (currentColor)
        // und in beiden Themes richtig sitzt
        $glyph = $svg === '' ? '' : str_replace('<svg ', '<svg class="origin-mark" aria-hidden="true" focusable="false" ', $svg);
    }
    $self = cfg('site_name') === 'flatlink'
        ? 'flatlink ist ein Open-Source-Projekt von '
        : 'Läuft mit flatlink, einem Open-Source-Projekt von ';
    return '<p class="origin">' . $glyph . '<span>' . $self
        . '<a href="https://flatlink.1337.kiwi" target="_blank" rel="noopener">1337.kiwi</a>'
        . '</span></p>';
}

/**
 * Seitenfuß.
 *
 * Wer diese Software öffentlich betreibt, ist je nach Land zu Angaben wie
 * Impressum und Datenschutzerklärung verpflichtet – eigene Seiten anlegen
 * und hier verlinken.
 */
function page_footer(): void
{
    $root = $GLOBALS['_page_root'] ?? '.';
    $links = '<a href="' . $root . '/report.php">Missbrauch melden</a>';
    // Eigene Fußzeilen-Links aus der Konfiguration – hier gehören Impressum
    // und Datenschutzerklärung hin, zu denen öffentliche Instanzen je nach
    // Land verpflichtet sind. Relative Ziele werden auf den Webroot bezogen.
    foreach ((array)cfg('footer_links') as $label => $url) {
        $href = preg_match('#^(https?:)?//#', (string)$url) === 1
            ? (string)$url
            : $root . '/' . ltrim((string)$url, '/');
        $links .= ' · <a href="' . e($href) . '">' . e((string)$label) . '</a>';
    }
    echo '</main><footer class="site-foot"><p>' . e(cfg('site_name'))
        . ' · ' . $links . '</p>' . origin_note() . '</footer></div></body></html>';
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function show_flash(): void
{
    if ($f = flash()) {
        echo '<div class="flash flash-' . e($f[1]) . '">' . e($f[0]) . '</div>';
    }
}
