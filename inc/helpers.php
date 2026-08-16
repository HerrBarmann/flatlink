<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/qrlib.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

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

/**
 * Die Adresse, unter der ein Kurzlink steht.
 *
 * $domain ist der Wert aus dem Link selbst; leer heißt Hauptdomain. Bewusst
 * ein Parameter und kein Nachschlagen: Wer die Liste zeichnet, hat den Link
 * längst in der Hand, und ein Zugriff auf die Ablage je Zeile wäre teuer.
 */
function short_url(string $code, string $domain = ''): string
{
    if ($domain === '') return base_url() . '/' . $code;
    require_once __DIR__ . '/domains.php';
    return domain_url($domain) . '/' . $code;
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---- JSON-Dateien mit Locking ----

/**
 * Ein Feld für eine CSV-Ausgabe säubern und quoten.
 *
 * Steuerzeichen raus; ein führendes =, +, - oder @ macht aus einem Feld in
 * Excel eine Formel – der Kurzlink ist harmlos, ein selbst vergebener Name
 * muss es nicht sein. Semikolon und Anführungszeichen werden RFC-konform
 * eingefasst.
 */
function csv_feld(string $wert): string
{
    $w = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $wert);
    if ($w !== '' && str_contains('=+-@', $w[0])) $w = "'" . $w;
    return str_contains($w, ';') || str_contains($w, '"') || str_contains($w, ',')
        ? '"' . str_replace('"', '""', $w) . '"' : $w;
}

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
            'domains' => (array)cfg('domains'),
            'language' => (string)cfg('language'),
            'ext_stores' => (array)cfg('ext_stores'),
            'ext_download' => (bool)cfg('ext_download'),
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

/**
 * Das versteckte Kennungsfeld für Formulare, die nur ein Passwort enthalten.
 *
 * Passwortverwaltungen (iOS, Google, Bitwarden) ordnen ein Passwort dem Konto
 * zu, dessen Kennung im selben Formular steht. Fehlt sie – bei der zweiten
 * Stufe der Anmeldung, beim Passwortwechsel im Profil, beim Zurücksetzen –,
 * sehen sie ein herrenloses Passwort und fragen nach dem Benutzernamen.
 * Wer dort nichts einträgt, sammelt mit jeder Anmeldung einen weiteren
 * namenlosen Eintrag an, und beim nächsten Mal wird wieder gefragt.
 *
 * Das Feld ist ausgeblendet und schreibgeschützt; es dient allein der
 * Zuordnung. Der Name beginnt mit einem Unterstrich, damit es nirgends mit
 * einem verarbeiteten Formularfeld kollidiert.
 */
function username_hint(string $kennung): string
{
    if ($kennung === '') return '';
    return '<input type="text" name="_konto" value="' . e($kennung)
        . '" autocomplete="username" readonly tabindex="-1" aria-hidden="true"'
        . ' style="position:absolute;left:-9999px;width:1px;height:1px">';
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
    return url_reject_reason($url) === null;
}

/**
 * Eine eingetippte Adresse vervollständigen.
 *
 * „example.com/pfad" meint https://example.com/pfad – das Schema wegzulassen
 * ist der Normalfall beim Abtippen. Steht aber schon eines da, bleibt es
 * stehen, auch ein unbrauchbares: Aus „ftp://x.de" wurde bisher
 * „https://ftp://x.de" und daraus beim Speichern ein kaputter Link. Besser
 * eine ehrliche Fehlermeldung als ein Kurzlink, der ins Nichts führt.
 */
function url_normalize(string $roh): string
{
    $roh = trim($roh);
    if ($roh === '') return '';
    // Ein Schema erkennt man am Doppelpunkt vor dem ersten Schrägstrich
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $roh) === 1) return $roh;
    return 'https://' . $roh;
}

/**
 * Warum eine Ziel-Adresse abgelehnt wird – oder null, wenn sie in Ordnung ist.
 *
 * Eigene Funktion, damit die Oberfläche den echten Grund nennen kann. „Nur
 * http/https erlaubt" auf eine Adresse zu antworten, die mit https:// beginnt,
 * ist die Sorte Fehlermeldung, an der Leute zu Recht verzweifeln.
 */
function url_reject_reason(string $url): ?string
{
    if (strlen($url) > cfg('max_url_length')) {
        return t('Die Ziel-Adresse ist zu lang (max. %d Zeichen).', (int)cfg('max_url_length'));
    }
    if (preg_match('#^https?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return t('Ungültige Ziel-URL (nur http/https).');
    }

    $teile = parse_url($url);
    if (!is_array($teile) || ($teile['host'] ?? '') === '') {
        return t('Ungültige Ziel-URL (nur http/https).');
    }

    // Kein Nutzerteil vor dem Host: https://sparkasse.de@boese.tld/ führt zu
    // boese.tld, liest sich aber wie die Bank. Ein Kurzlink verbirgt sein Ziel
    // ohnehin – ihm auch noch eine falsche Fährte mitzugeben, ist zu viel.
    if (isset($teile['user']) || isset($teile['pass'])) {
        return t('Adressen mit einem Namen vor dem Host (%s) sind nicht erlaubt – sie lesen sich wie eine Seite und führen zu einer anderen.', 'https://beispiel.de@andere.tld/');
    }

    if (url_intern_gesperrt((string)$teile['host'])) {
        return t('Dieses Ziel liegt in einem privaten Adressbereich (%s). Auf einer erreichbaren Instanz wäre der Kurzlink nur eine Verpackung für eine interne Adresse.', e((string)$teile['host']));
    }
    return null;
}

/**
 * Zeigt der Host ins eigene Netz, und ist das hier verboten?
 *
 * Für den Server selbst ist das keine Gefahr – er ruft Ziele nie ab. Auf
 * einer Instanz im Intranet aber, also genau dort, wo flatlink hingehört,
 * wird ein Kurzlink sonst zur hübschen Verpackung für interne Adressen:
 * kurz.hochschule.de/personal führt auf 10.0.0.5, und niemand sieht es dem
 * Link an. Wer das braucht (etwa eine rein interne Instanz), schaltet
 * 'allow_private_targets' ein.
 */
function url_intern_gesperrt(string $host): bool
{
    if (cfg('allow_private_targets')) return false;
    $host = strtolower(trim($host, '[]'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
        return true;
    }
    // Namen lösen wir bewusst NICHT auf: Das wäre eine Netzanfrage je
    // Formularabsendung und damit selbst ein Hebel. Geprüft wird, was
    // unmittelbar dasteht.
    $bin = @inet_pton($host);
    if ($bin === false) return false;
    return ip_in_list($host, [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '169.254.0.0/16', '0.0.0.0/8', '100.64.0.0/10',
        '::1/128', 'fc00::/7', 'fe80::/10', '::/128',
    ]);
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
 * Startdatum lesen – wie parse_expiry, nur ohne Vergangenheits-Sperre in der
 * Bedeutung: Ein Startdatum von heute heißt „ab sofort", eines von gestern
 * ist harmlos (der Link ist dann längst aktiv) und wird stillschweigend
 * übernommen. Zurückgewiesen wird nur Unsinn im Format.
 *
 * @return array{0:bool,1:?string} [gültig, Datum oder null]
 */
function parse_start(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [true, null];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return [false, null];
    [$y, $m, $d] = array_map('intval', explode('-', $raw));
    if (!checkdate($m, $d, $y)) return [false, null];
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
    if ($trusted === [] || !ip_in_list($remote, $trusted)) return $remote;

    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    foreach (array_reverse(explode(',', $xff)) as $part) {
        $ip = trim($part);
        if ($ip === '' || ip_in_list($ip, $trusted)) continue;
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) return $ip;
    }
    return $remote;
}

/**
 * Steht die Adresse in der Liste? Einträge dürfen Adressen oder Bereiche
 * in CIDR-Schreibweise sein (10.0.0.0/8, 2400:cb00::/32).
 *
 * Ohne Bereiche ist die Liste hinter einem Proxy-Verbund wie Cloudflare
 * nicht zu pflegen – und wer sie nicht pflegen kann, trägt am Ende gar
 * nichts ein (dann zählen alle Limits auf die Proxy-Adresse) oder vertraut
 * dem Weiterleitungs-Kopf blind. Beides ist schlimmer als 15 Zeilen Code.
 *
 * @param string[] $liste
 */
function ip_in_list(string $ip, array $liste): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    foreach ($liste as $eintrag) {
        $eintrag = trim((string)$eintrag);
        if ($eintrag === '') continue;
        if (!str_contains($eintrag, '/')) {
            if ($eintrag === $ip) return true;
            continue;
        }
        [$netz, $bits] = explode('/', $eintrag, 2);
        $netzBin = @inet_pton(trim($netz));
        // Ein Bereich gilt nur innerhalb derselben Adressfamilie
        if ($netzBin === false || strlen($netzBin) !== strlen($bin)) continue;
        $bits = (int)$bits;
        if ($bits < 0 || $bits > strlen($bin) * 8) continue;
        $ganze = intdiv($bits, 8);
        $rest = $bits % 8;
        if ($ganze > 0 && substr($bin, 0, $ganze) !== substr($netzBin, 0, $ganze)) continue;
        if ($rest > 0) {
            $maske = chr((0xFF << (8 - $rest)) & 0xFF);
            if ((($bin[$ganze] ?? "\0") & $maske) !== (($netzBin[$ganze] ?? "\0") & $maske)) continue;
        }
        return true;
    }
    return false;
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
    return hash_hmac('sha256', ip_bucket($ip === '' ? client_ip() : $ip), instance_secret());
}

/**
 * Die Einheit, auf die ein Limit zählt.
 *
 * Bei IPv4 die Adresse selbst. Bei IPv6 die ersten 64 Bit: Jeder Anschluss
 * bekommt dort ein ganzes Präfix zugeteilt und kann darin durch Milliarden
 * Adressen wandern. Zählte man je Adresse, wäre das Limit wirkungslos – und
 * jede neue Adresse hinterließe eine eigene Datei, bis das Verzeichnis
 * unbenutzbar wird. Ein Präfix ist ohnehin die ehrlichere Einheit: Dahinter
 * steckt ein Haushalt, kein Gerät.
 */
function ip_bucket(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) return $ip;   // IPv4 oder Unsinn
    // ::ffff:1.2.3.4 ist eine IPv4-Adresse im v6-Kleid und bleibt vollständig
    if (str_starts_with($bin, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) return $ip;
    return (string)inet_ntop(substr($bin, 0, 8) . str_repeat("\x00", 8)) . '/64';
}

/**
 * Rate-Limit-/Login-Schutzdateien (gehashte IPs) älter als 24 h aufräumen.
 *
 * Nur in etwa jedem hundertsten Aufruf: Das glob() liest das ganze
 * Verzeichnis, und genau dann, wenn jemand das Limit angreift, wäre das die
 * teuerste Stelle des Systems – der Angriff finanzierte seine eigene Bremse.
 * Bei hundertfach seltenerem Lauf bleibt der Bestand trotzdem klein, weil
 * jeder Lauf alles Abgelaufene mitnimmt.
 */
function rate_limit_gc(bool $sofort = false): void
{
    if (!$sofort && random_int(1, 100) !== 1) return;
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
    // Eine Nebendomain liefert nur Kurzlinks aus. Alles andere – Startseite,
    // Generatoren, Verwaltung – gehört auf die Hauptdomain. Ausgenommen sind
    // die Seiten von go.php: Sie gehören zu einem Code, der unter genau dieser
    // Adresse gedruckt wurde.
    require_once __DIR__ . '/domains.php';
    if (!domain_is_resolver()) domain_force_main();

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
        // Erste Station beim Tabben: über die Kopfzeile hinweg zum Inhalt
        . '<a class="skip" href="#inhalt">' . t('Zum Inhalt springen') . '</a>'
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
        echo '<a href="' . $adm . 'index.php">' . t('Links') . '</a> '
            . '<a href="' . $pub . 'qr-designer.php">' . t('QR-Designer') . '</a> '
            . '<a href="' . $adm . 'qrzip.php">' . t('QR-Serie') . '</a> ';
        // Nur zeigen, wo es auch benutzbar ist – ein Punkt, der zur Absage
        // führt, ist keine Werbung, sondern eine Sackgasse.
        if (function_exists('user_can') && user_can($u['name'], 'bio_page')) {
            echo '<a href="' . $adm . 'bio.php">' . t('Link-in-Bio') . '</a> ';
        }
        // Die Klappe zeigt, was das Konto auch benutzen darf: Administratoren
        // alles, eine Redaktion nur die Meldungen. Wer nichts davon hat,
        // sieht die Klappe gar nicht.
        $verwaltung = [];
        if ($u['role'] === 'admin') {
            $verwaltung = [
                'users.php' => t('Nutzer'),
                'groups.php' => t('Gruppen'),
                'reports.php' => t('Meldungen'),
                'audit.php' => t('Protokoll'),
                'settings.php' => t('Einstellungen'),
            ];
        } else {
            // Auf Seiten ohne Rechte-Schicht steht user_can() noch nicht bereit;
            // die Navigation läuft aber überall.
            require_once __DIR__ . '/groups.php';
            if (user_can($u['name'], 'reports_manage')) {
                $verwaltung = ['reports.php' => t('Meldungen')];
            }
        }
        if ($verwaltung !== []) {
            $hier = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $drin = isset($verwaltung[$hier]);
            echo '<details class="nav-more"><summary' . ($drin ? ' class="here"' : '') . '>'
                . ($drin ? e($verwaltung[$hier]) : t('Verwaltung')) . '</summary><div class="nav-panel">';
            foreach ($verwaltung as $datei => $label) {
                echo '<a href="' . $adm . $datei . '"' . ($datei === $hier ? ' class="here"' : '') . '>'
                    . e($label) . '</a>';
            }
            echo '</div></details> ';
        }
        echo '<a class="who" href="' . $adm . 'profile.php" title="' . t('Profil / Passwort ändern') . '">'
            . e(mb_strimwidth($u['display'] ?? $u['name'], 0, 28, '…')) . '</a> '
            . '<a class="btn btn-small" href="' . $adm . 'logout.php">' . t('Abmelden') . '</a>';
    } else {
        if (settings()['registration'] === 'on') echo '<a href="' . $pub . 'register.php">' . t('Registrieren') . '</a> ';
        echo '<a href="' . $adm . '">' . t('Login') . '</a>';
    }
    echo '</nav>';
    echo '</header><main id="inhalt">';
}

/**
 * Herkunftszeile: Kiwi-Zeichen + Hinweis auf das Ursprungsprojekt.
 *
 * flatlink ist der offene Kern von 1337.kiwi. Die Zeile weist das aus, ohne
 * die Instanz zu vereinnahmen – der Kiwi steht hier für die Herkunft, nicht
 * für den Betreiber.
 *
 * **Diese Funktion ist der Bezugspunkt der Lizenz-Zusatzbedingung** nach
 * §7(b) AGPL (siehe LICENSE): Jede Oberfläche muss „flatlink" nennen und auf
 * das Projekt verlinken. Wer die Zeile umgestaltet oder übersetzt, bleibt im
 * Rahmen – wer sie entfernt, braucht eine schriftliche Freistellung. Der
 * Schalter 'show_origin' erteilt sie nicht.
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
        ? t('flatlink ist ein Open-Source-Projekt von ')
        : t('Läuft mit flatlink, einem Open-Source-Projekt von ');
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
    $links = '<a href="' . $root . '/report.php">' . t('Missbrauch melden') . '</a>';
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
    // Übersetzungen für die Skripte – ein JSON-Datenblock, kein ausführbares
    // Inline-Skript, deshalb verträgt er sich mit der CSP
    echo lang_js();
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
