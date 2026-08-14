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
        load_local();
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

/**
 * Optionale Erweiterungen dieser Instanz.
 *
 * Existiert inc/local.php, wird sie einmalig geladen. Dort können eigene
 * Hilfsfunktionen liegen, die nur diese Installation braucht – etwa
 * Bausteine für eigene Zusatzseiten. Die Datei ist per .gitignore
 * ausgenommen und übersteht damit jedes Update.
 *
 * Sie kann nur ergänzen, nicht ersetzen: Vorhandene Funktionen lassen sich
 * in PHP nicht überschreiben. Für Aussehen und Beschriftungen gibt es
 * assets/custom.css und die Konfiguration.
 */
function load_local(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $file = __DIR__ . '/local.php';
    if (is_file($file)) require_once $file;
}

/**
 * Verzeichnis der Laufzeitdaten.
 *
 * Standardmäßig liegt es neben der Anwendung – also im Webroot. Der Schutz
 * besteht dort nur aus einer .htaccess, die nginx, Caddy und LiteSpeed
 * schlicht ignorieren. Wer kann, setzt 'data_dir' auf einen absoluten Pfad
 * außerhalb des Webroots; dann ist das Verzeichnis über den Webserver
 * grundsätzlich nicht erreichbar.
 *
 * Was hier liegt, ist keine Nebensache: Passwort-Hashes, gültige
 * Reset-Token, im Log-Modus sämtliche Mails im Klartext.
 */
function data_path(string $sub = ''): string
{
    $configured = (string)cfg('data_dir');
    $base = $configured !== '' ? rtrim($configured, '/') : dirname(__DIR__) . '/data';
    if (!is_dir($base)) {
        mkdir($base, 0700, true);
        // Doppelter Boden für den Fall, dass das Verzeichnis doch im Webroot
        // liegt und der Webserver .htaccess auswertet
        file_put_contents($base . '/.htaccess', "Require all denied\n");
        file_put_contents($base . '/index.html', '');
    }
    if ($sub !== '') {
        $dir = $base . '/' . $sub;
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        return $dir;
    }
    return $base;
}

/**
 * Liegt das Datenverzeichnis im Webroot und wäre damit auf einem Webserver
 * ohne .htaccess-Auswertung abrufbar? Für den Hinweis im Admin-Bereich.
 */
function data_dir_in_webroot(): bool
{
    return (string)cfg('data_dir') === '';
}

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Basis-URL des Dienstes, ohne Slash am Ende.
 *
 * Ohne konfigurierten Wert wird sie aus dem Request erraten – bequem, aber
 * vom Aufrufer steuerbar: Der Host-Header ist eine Nutzereingabe. Für alles,
 * was ein Angreifer nicht beeinflussen darf – allen voran Links in
 * verschickten Mails –, gehört deshalb $trusted = true gesetzt. Dann gilt
 * ausschließlich der konfigurierte Wert; fehlt er, kommt ein leerer String
 * zurück und der Aufrufer muss abbrechen.
 */
function base_url(bool $trusted = false): string
{
    $configured = cfg('base_url');
    if ($configured !== '') return rtrim($configured, '/');
    if ($trusted) return '';
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

/**
 * Atomar schreiben: Tempdatei + rename.
 *
 * Der Rückgabewert von file_put_contents wird geprüft – sonst schöbe ein
 * voller Datenträger eine halbe oder leere Tempdatei über die echten Daten
 * und löschte damit sämtliche Links.
 */
function json_write(string $file, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Daten lassen sich nicht als JSON schreiben: ' . json_last_error_msg());
    }
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    $written = file_put_contents($tmp, $json, LOCK_EX);
    // Vor dem Umbenennen setzen – danach gäbe es ein Zeitfenster mit
    // Standardrechten. Auf Shared Hosting mit gemeinsamer Gruppe zählt das.
    @chmod($tmp, 0600);
    if ($written === false || $written !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException('Schreiben nach ' . basename($file) . ' fehlgeschlagen – Datenträger voll oder keine Rechte.');
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Umbenennen nach ' . basename($file) . ' fehlgeschlagen.');
    }
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
        // Vorgaben kommen aus der Konfigurationsdatei; was hier zur Laufzeit
        // geändert wird, überschreibt sie. So lässt sich der Grundrahmen einer
        // Instanz in der Oberfläche anpassen, ohne per FTP an eine PHP-Datei zu
        // müssen – und die Datei bleibt trotzdem die Quelle für eine frische
        // Installation.
        $defaults = [
            'public_mode' => 'on',          // on | prefix | off
            'public_prefix' => 'p',         // Namensraum für öffentliche Links im Prefix-Modus
            'public_rate_limit' => cfg('public_rate_limit'),
            'registration' => 'on',         // Selbst-Registrierung: on | off
            'limits' => (array)cfg('limits'),
            'default_perms' => (array)cfg('default_perms'),
            'custom_code_min_len' => (int)cfg('custom_code_min_len'),
            'custom_code_quota' => (int)cfg('custom_code_quota'),
            'totp_required' => (string)cfg('totp_required'),
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
        nosniff_header();
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

/**
 * Adresse des Aufrufers.
 *
 * Standardmäßig REMOTE_ADDR – der einzige Wert, den der Webserver selbst
 * feststellt. X-Forwarded-For blind zu vertrauen wäre schlimmer als das
 * Problem: Jeder könnte sich damit eine beliebige Adresse geben und
 * Rate-Limits umgehen.
 *
 * Steht die Anfrage aber nachweislich von einem eingetragenen Reverse Proxy,
 * ist REMOTE_ADDR für alle Besucher gleich – dann kollabieren Rate-Limit und
 * Login-Sperre auf einen einzigen Zähler. In diesem Fall wird X-Forwarded-For
 * von rechts nach links gelesen und der erste Eintrag genommen, der kein
 * bekannter Proxy ist: der letzte Wert, den ein Angreifer nicht mehr
 * überschreiben konnte.
 */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = (array)cfg('trusted_proxies');
    if ($trusted === [] || !in_array($remote, $trusted, true)) return $remote;

    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    foreach (array_reverse(explode(',', $xff)) as $part) {
        $ip = trim($part);
        if ($ip === '' || in_array($ip, $trusted, true)) continue;
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) return $ip;
    }
    return $remote;
}

/**
 * Zufälliges Geheimnis dieser Instanz, beim ersten Bedarf erzeugt.
 * Dient als Schlüssel für IP-Hashes – ohne es sind die nicht rückrechenbar.
 */
function instance_secret(): string
{
    static $secret = null;
    if ($secret !== null) return $secret;
    $file = data_path() . '/secret.key';
    if (is_file($file)) {
        $secret = trim((string)file_get_contents($file));
        if ($secret !== '') return $secret;
    }
    // Atomar über Tempdatei plus rename – sonst könnten zwei gleichzeitige
    // Erstaufrufe je ein Geheimnis erzeugen und das zweite das erste
    // überschreiben; bereits geschriebene Hashes zeigten dann ins Leere.
    $secret = bin2hex(random_bytes(32));
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    $written = file_put_contents($tmp, $secret);
    if ($written === false || $written !== strlen($secret)) {
        @unlink($tmp);
        // Stillschweigend weiterzumachen hieße: bei jedem Aufruf ein neues
        // Geheimnis, und Rate-Limit wie Login-Sperre fielen unbemerkt aus.
        throw new RuntimeException('Geheimnis der Instanz lässt sich nicht schreiben – ist ' . basename(dirname($file)) . ' beschreibbar?');
    }
    @chmod($tmp, 0600);
    // Gewinnt ein anderer Prozess das Rennen, gilt dessen Geheimnis
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Geheimnis der Instanz lässt sich nicht ablegen.');
    }
    clearstatcache(true, $file);
    return $secret = trim((string)file_get_contents($file));
}

/**
 * IP-Adresse für die Ablage verschlüsseln.
 *
 * Ein blanker SHA-256 über eine IPv4-Adresse ist KEINE Anonymisierung: Der
 * gesamte Adressraum umfasst nur 2^32 Werte, eine vollständige Tabelle ist in
 * Minuten erzeugt. Mit einem instanzeigenen Geheimnis als Schlüssel ist die
 * Rückrechnung ohne Serverzugriff dagegen ausgeschlossen.
 *
 * Auch dann bleibt der Wert pseudonym, nicht anonym – er gehört in die
 * Datenschutzerklärung und braucht eine Aufbewahrungsfrist.
 */
function ip_hash(string $ip = ''): string
{
    return hash_hmac('sha256', $ip === '' ? client_ip() : $ip, instance_secret());
}

/** Rate-Limit-/Login-Schutzdateien (gehashte IPs) älter als 24 h aufräumen */
function rate_limit_gc(): void
{
    foreach (glob(data_path('ratelimit') . '/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
}

/**
 * Generisches Stunden-Rate-Limit für beliebige Aktionen.
 *
 * Gezählt wird je IP-Hash – oder je übergebener Kennung, wenn die IP nicht der
 * richtige Maßstab ist: Ein Server, der die API bedient, kommt immer von
 * derselben Adresse; dort ist der Zugangsschlüssel die sinnvolle Einheit.
 */
function bucket_rate_ok(string $bucket, int $limit, ?string $identity = null): bool
{
    rate_limit_gc();
    $wer = $identity !== null ? substr(hash('sha256', $identity), 0, 32) : ip_hash();
    $file = data_path('ratelimit') . '/' . $bucket . '-' . $wer . '.json';
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
 * Sicherheits-Kopfzeilen.
 *
 * Die Content-Security-Policy kommt ohne 'unsafe-inline' für Skripte aus –
 * dafür liegt sämtliches JavaScript in assets/ statt im Markup. Sie kann
 * einen künftigen XSS-Fund zwar nicht verhindern, aber seine Wirkung stark
 * begrenzen: Eingeschleuster Code ließe sich nicht ausführen und nichts
 * nach außen senden.
 *
 * img-src erlaubt zusätzlich data: und blob:. Beides braucht die Oberfläche:
 * data: für eingebettete Logos in erzeugten SVGs, blob: für die Live-Vorschau
 * der QR-Generatoren, die ihr Bild aus einer POST-Antwort baut, statt die
 * Eingaben in eine Adresszeile zu schreiben. Beide Formen können keinen Code
 * ausführen.
 *
 * Bei Stilen bleibt 'unsafe-inline' stehen. An etlichen Stellen hängen
 * style-Attribute im Markup; sie zu entfernen wäre viel Arbeit für wenig
 * Gewinn, denn ein style-Attribut kann keinen Code ausführen.
 */
/**
 * Die eine Kopfzeile, die auf JEDER Antwort stehen sollte – auch auf Bildern
 * und kurzen Fehlerausgaben. Sie verhindert, dass der Browser den Inhaltstyp
 * errät und etwas als HTML ausführt, das als Bild oder Text gemeint war.
 */
function nosniff_header(): void
{
    if (!headers_sent()) header('X-Content-Type-Options: nosniff');
}

function security_headers(): void
{
    if (headers_sent()) return;
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; "
        . "connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    // Ältere Browser, die frame-ancestors nicht kennen
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    // Beim Verlassen der Seite nur die Herkunft mitgeben, nie den vollen Pfad –
    // ein Kurzlink im Referrer würde das Ziel an Dritte verraten
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (str_starts_with((string)cfg('base_url'), 'https://')) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

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
    security_headers();
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' – ' . $site . '</title>';
    if ($desc !== null) {
        // Beschreibung plus Open Graph: bestimmt, wie die Seite aussieht, wenn
        // jemand sie in einem Messenger oder sozialen Netz teilt. Ohne die
        // Angaben zeigen die meisten Dienste nur eine nackte Adresse.
        echo '<meta name="description" content="' . e($desc) . '">'
            . '<meta property="og:title" content="' . e($title) . ' – ' . $site . '">'
            . '<meta property="og:description" content="' . e($desc) . '">'
            . '<meta property="og:site_name" content="' . $site . '">'
            . '<meta property="og:type" content="website">'
            . '<meta name="twitter:card" content="summary">';
        $og = (string)cfg('og_image');
        if ($og !== '') {
            echo '<meta property="og:image" content="' . e(base_url() . '/assets/' . $og) . '">';
        }
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
    // Weitere Symbole und Farben der Instanz, alle optional
    foreach ((array)cfg('icons') as $rel => $file) {
        echo '<link rel="' . e((string)$rel) . '" href="' . $root . '/assets/' . e((string)$file) . '">';
    }
    foreach ((array)cfg('theme_color') as $scheme => $color) {
        echo '<meta name="theme-color" media="(prefers-color-scheme: ' . e((string)$scheme) . ')"'
            . ' content="' . e((string)$color) . '">';
    }
    // Eine Klasse am <body>, an der sich ganze Gestaltungsvarianten aufhängen
    // lassen: In assets/custom.css alles darunter schreiben, und ein leerer
    // Wert in der Konfiguration nimmt die Variante wieder zurück – ohne die
    // Stile zu löschen und ohne eine einzige Vorlage anzufassen.
    $bodyClass = trim((string)cfg('body_class'));
    echo '<script src="' . $root . '/assets/app.js" defer></script>'
        . '</head><body' . ($bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '') . '>'
        . '<div class="wrap">';

    // Wortmarke, optional mit eigenem Logo davor. Der Name steckt in einem
    // eigenen Element, und heißt die Instanz wie eine Domain, ist der Teil ab
    // dem ersten Punkt noch einmal getrennt auszeichenbar – beides nur, damit
    // sich die Marke über assets/custom.css gestalten lässt.
    $name = (string)cfg('site_name');
    $dot = strpos($name, '.');
    $inner = ($dot === false || $dot === 0)
        ? e($name)
        : e(substr($name, 0, $dot)) . '<span class="brand-tld">' . e(substr($name, $dot)) . '</span>';
    $logo = (string)cfg('logo');
    $mark = ($logo !== '' ? '<img class="brand-logo" src="' . $root . '/assets/' . e($logo) . '" alt="">' : '')
        . '<span class="brand-name">' . $inner . '</span>';
    echo '<header class="site-head"><a class="brand" href="' . $root . '/">' . $mark . '</a>';
    // Einheitliche, session-sensitive Navigation auf allen Seiten.
    // (Seiten ohne Session-Start – z. B. die 404-Seite des Redirect-Handlers –
    // zeigen die Gast-Variante; der heiße Redirect-Pfad bleibt Session-frei.)
    $u = function_exists('auth_user') ? auth_user() : null;
    $adm = $admin ? '' : 'admin/'; // Pfad zum Login-Bereich
    $pub = $admin ? '../' : '';    // Pfad zu öffentlichen Seiten
    echo '<nav>';  // umbruchfähig; die Verwaltungsklappe darf auf schmalen Schirmen eine eigene Zeile bekommen
    // Zusätzliche Einträge dieser Instanz. 'nav_links' erscheint immer,
    // 'nav_links_guest' nur für Nichtangemeldete – nützlich für Seiten, die
    // im Login-Bereich ohnehin über einen eigenen Punkt erreichbar sind.
    $extra = (array)cfg('nav_links');
    if ($u === null) $extra += (array)cfg('nav_links_guest');
    foreach ($extra as $label => $url) {
        $href = preg_match('#^(https?:)?//#', (string)$url) === 1
            ? (string)$url
            : $pub . ltrim((string)$url, '/');
        echo '<a href="' . e($href) . '">' . e((string)$label) . '</a> ';
    }
    if ($u !== null) {
        echo '<a href="' . $adm . 'index.php">Links</a> '
            . '<a href="' . $pub . 'qr-designer.php">QR-Designer</a> ';
        // Nur zeigen, wo es auch benutzbar ist – ein Punkt, der zur Absage
        // führt, ist keine Werbung, sondern eine Sackgasse.
        if (function_exists('user_can') && user_can($u['name'], 'bio_page')) {
            echo '<a href="' . $adm . 'bio.php">Link-in-Bio</a> ';
        }
        if ($u['role'] === 'admin') {
            // Die vier Verwaltungspunkte hinter einer Klappe. Sie werden selten
            // gebraucht, machten aber die Hälfte der Kopfzeile aus – auf dem
            // Handy brach sie dadurch auf drei Zeilen um.
            $verwaltung = [
                'users.php' => 'Nutzer',
                'groups.php' => 'Gruppen',
                'reports.php' => 'Meldungen',
                'settings.php' => 'Einstellungen',
            ];
            $hier = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $drin = isset($verwaltung[$hier]);
            echo '<details class="nav-more"><summary' . ($drin ? ' class="here"' : '') . '>'
                . ($drin ? e($verwaltung[$hier]) : 'Verwaltung') . '</summary><div class="nav-panel">';
            foreach ($verwaltung as $datei => $label) {
                echo '<a href="' . $adm . $datei . '"' . ($datei === $hier ? ' class="here"' : '') . '>'
                    . e($label) . '</a>';
            }
            echo '</div></details> ';
        }
        echo '<a class="who" href="' . $adm . 'profile.php" title="Profil / Passwort ändern">'
            . e(mb_strimwidth($u['display'] ?? $u['name'], 0, 28, '…')) . '</a> '
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
        . '<a href="https://1337.kiwi/flatlink" target="_blank" rel="noopener">1337.kiwi</a>'
        . '</span></p>';
}

/**
 * Seitenfuß.
 *
 * Wer diese Software öffentlich betreibt, ist je nach Land zu Angaben wie
 * Impressum und Datenschutzerklärung verpflichtet – eigene Seiten anlegen
 * und hier verlinken.
 */
/**
 * Ein weiteres Skript für diese Seite anfordern.
 *
 * Wird vor dem schließenden </body> ausgegeben. Vor dieser Stelle hängten
 * einzelne Seiten ihr <script> hinter page_footer() – also hinter </html>,
 * wo es zwar noch lief, aber nichts mehr zu suchen hatte.
 */
function page_script(string $datei): void
{
    $GLOBALS['_page_js'][] = $datei;
}

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
        . ' · ' . $links . '</p>' . origin_note() . '</footer></div>';
    foreach ((array)($GLOBALS['_page_js'] ?? []) as $js) {
        echo '<script src="' . e($root . '/' . ltrim($js, '/')) . '" defer></script>';
    }
    echo '</body></html>';
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
