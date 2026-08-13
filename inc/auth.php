<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // Hinter einem TLS-terminierenden Proxy sieht PHP nur eine HTTP-Verbindung
    // und würde das Cookie ohne 'secure' setzen. Der konfigurierte Wert ist
    // die verlässlichste Quelle; erst danach der Request.
    $configured = (string)cfg('base_url');
    $https = $configured !== ''
        ? str_starts_with($configured, 'https://')
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('kurzsid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function users_file(): string
{
    return data_path() . '/users.json';
}

/** @return array<string,array> username => {pass, role, created} */
function users_all(): array
{
    return json_read(users_file());
}

function users_exist(): bool
{
    return users_all() !== [];
}

/** @return ?array{name:string,role:string,auth:string,display:string} */
function auth_user(): ?array
{
    $name = $_SESSION['user'] ?? null;
    if (!is_string($name)) return null;
    $u = users_all()[$name] ?? null;
    if ($u === null) return null;
    return [
        'name' => $name,
        'role' => $u['role'],
        'auth' => $u['auth'] ?? 'local',
        'display' => (is_string($u['display_name'] ?? null) && trim($u['display_name']) !== '')
            ? trim($u['display_name']) : $name,
    ];
}

function auth_require(): array
{
    auth_boot();
    $u = auth_user();
    if ($u === null) redirect_to('login.php');
    return $u;
}

function auth_require_admin(): array
{
    $u = auth_require();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Nur für Administratoren.');
    }
    return $u;
}

/**
 * Login-Eingabe (Nutzername oder E-Mail) auf den Schlüssel in users.json auflösen.
 * Registrierte Konten sind direkt per E-Mail-Adresse geschlüsselt; ältere Konten
 * per Nutzername (optional mit separatem email-Feld).
 */
function user_resolve(string $ident): ?string
{
    $users = users_all();
    if (isset($users[$ident])) return $ident;
    $lower = strtolower($ident);
    if (isset($users[$lower])) return $lower;
    foreach ($users as $key => $u) {
        if (strtolower($u['email'] ?? '') === $lower) return (string)$key;
    }
    return null;
}

/**
 * Vergleichs-Hash für nicht existierende Konten: ein fester bcrypt-Wert, der
 * zu keinem Passwort passt. Kostet beim Prüfen dieselbe Zeit wie ein echter,
 * ohne beim Erzeugen CPU zu verbrennen.
 */
const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

/** Login mit Throttling: nach 5 Fehlversuchen 60 s Sperre pro IP+Name */
function auth_login(string $username, string $password): bool
{
    rate_limit_gc();
    $throttleFile = data_path('ratelimit') . '/login-' . ip_hash(client_ip() . '|' . $username) . '.json';
    $t = json_read($throttleFile, ['fails' => 0, 'until' => 0]);
    if ($t['fails'] >= 5 && time() < $t['until']) {
        sleep(2);
        return false;
    }

    $key = user_resolve($username);
    $u = $key !== null ? (users_all()[$key] ?? null) : null;
    $username = $key ?? $username;
    // Zentral verwaltete Konten (LDAP/SSO) haben keinen lokalen Passwort-Hash
    // und dürfen sich hier auch nicht anmelden – sonst wäre die zentrale
    // Anmeldung über ein lokal gesetztes Passwort umgehbar.
    if ($u !== null && ($u['auth'] ?? 'local') !== 'local') $u = null;
    // Immer verifizieren, damit Timing keinen Nutzernamen verrät. Der
    // Vergleichs-Hash ist eine Konstante – ihn bei jedem Fehlversuch neu zu
    // berechnen wäre ein billiger Weg, die CPU des Servers auszulasten.
    $hash = $u['pass'] ?? DUMMY_HASH;
    if ($u !== null && is_string($u['pass'] ?? null) && password_verify($password, $hash)) {
        @unlink($throttleFile);
        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        unset($_SESSION['csrf']);
        return true;
    }
    $t['fails']++;
    $t['until'] = time() + 60;
    json_write($throttleFile, $t);
    sleep(1);
    return false;
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** @return ?string Fehlermeldung oder null bei Erfolg */
function user_add(string $username, string $password, string $role): ?string
{
    if (preg_match('/^[a-zA-Z0-9._-]{2,32}$/', $username) !== 1) {
        return 'Nutzername: 2–32 Zeichen, nur Buchstaben, Zahlen, Punkt, Minus, Unterstrich.';
    }
    if (strlen($password) < 8) return 'Passwort: mindestens 8 Zeichen.';
    if (!in_array($role, ['admin', 'user'], true)) return 'Ungültige Rolle.';
    $err = null;
    json_update(users_file(), function (array $users) use ($username, $password, $role, &$err) {
        if (isset($users[$username])) {
            $err = 'Diesen Nutzer gibt es schon.';
            return null;
        }
        // Erstinstallation: Ohne dieses Zugeständnis gäbe es auf einer frischen
        // Instanz nie einen Admin – und damit keinen Zugang zu Nutzerverwaltung,
        // Einstellungen und Meldungen.
        $users[$username] = [
            'pass' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $users === [] ? 'admin' : $role,
            'created' => date('c'),
        ];
        return $users;
    });
    return $err;
}

function user_set_password(string $username, string $password): ?string
{
    if (strlen($password) < 8) return 'Passwort: mindestens 8 Zeichen.';
    $err = 'Nutzer nicht gefunden.';
    json_update(users_file(), function (array $users) use ($username, $password, &$err) {
        if (!isset($users[$username])) return null;
        $users[$username]['pass'] = password_hash($password, PASSWORD_DEFAULT);
        $err = null;
        return $users;
    });
    return $err;
}

/**
 * E-Mail-Adresse eines Kontos setzen (nach bestätigtem Besitz-Nachweis!).
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function user_set_email(string $username, string $email): ?string
{
    $email = strtolower(trim($email));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        return 'Ungültige E-Mail-Adresse.';
    }
    $err = 'Nutzer nicht gefunden.';
    json_update(users_file(), function (array $users) use ($username, $email, &$err) {
        if (!isset($users[$username])) return null;
        foreach ($users as $key => $u) {
            if ($key === $username) continue;
            if (strtolower($u['email'] ?? '') === $email || strtolower((string)$key) === $email) {
                $err = 'Diese Adresse ist bereits mit einem anderen Konto verknüpft.';
                return null;
            }
        }
        $users[$username]['email'] = $email;
        $err = null;
        return $users;
    });
    return $err;
}

/**
 * Anzeigename eines Kontos, sonst die Kennung selbst.
 *
 * Wichtig bei zentraler Anmeldung: Föderationen liefern häufig undurchsichtige
 * Kennungen (persistent-id, pairwise-id) – ohne Klarnamen wäre die
 * Nutzerverwaltung damit unbedienbar. Der Anzeigename ist rein kosmetisch;
 * identifiziert wird weiterhin über die Kennung.
 */
function user_display(string $username): string
{
    $n = users_all()[$username]['display_name'] ?? '';
    return is_string($n) && trim($n) !== '' ? trim($n) : $username;
}

/** Hat das Konto einen vom Schlüssel abweichenden Anzeigenamen? */
function user_has_display(string $username): bool
{
    return user_display($username) !== $username;
}

/**
 * Anzeigename setzen (leer = wieder die Kennung zeigen).
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function user_set_display_name(string $username, string $name): ?string
{
    // Steuerzeichen raus – der Wert kommt teils aus fremden Verzeichnissen
    $name = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
    if (mb_strlen($name) > 80) return 'Anzeigename: höchstens 80 Zeichen.';
    $err = 'Nutzer nicht gefunden.';
    json_update(users_file(), function (array $users) use ($username, $name, &$err) {
        if (!isset($users[$username])) return null;
        if ($name === '') {
            unset($users[$username]['display_name']);
        } else {
            $users[$username]['display_name'] = $name;
        }
        $err = null;
        return $users;
    });
    return $err;
}

/** Anzahl der Administratoren – für den Schutz vor dem Aussperren */
function admin_count(): int
{
    return count(array_filter(users_all(), fn($u) => ($u['role'] ?? '') === 'admin'));
}

/**
 * Rolle ändern. Der letzte Administrator kann nicht degradiert werden –
 * sonst bliebe die Instanz ohne Zugang zu Nutzern, Gruppen und Einstellungen.
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function user_set_role(string $username, string $role): ?string
{
    if (!in_array($role, ['admin', 'user'], true)) return 'Ungültige Rolle.';
    $users = users_all();
    if (!isset($users[$username])) return 'Nutzer nicht gefunden.';
    if ($role !== 'admin' && ($users[$username]['role'] ?? '') === 'admin' && admin_count() <= 1) {
        return 'Das ist der letzte Administrator – erst einen zweiten ernennen.';
    }
    json_update(users_file(), function (array $users) use ($username, $role) {
        if (!isset($users[$username])) return null;
        $users[$username]['role'] = $role;
        return $users;
    });
    return null;
}

/** @return ?string Fehlermeldung oder null bei Erfolg */
function user_delete(string $username): ?string
{
    $users = users_all();
    if (($users[$username]['role'] ?? '') === 'admin' && admin_count() <= 1) {
        return 'Das ist der letzte Administrator und kann nicht gelöscht werden.';
    }
    json_update(users_file(), function (array $users) use ($username) {
        unset($users[$username]);
        return $users;
    });
    return null;
}

// ---- Selbst-Registrierung (Konto per E-Mail, Rolle immer "user") ----

/** @return ?string Fehlermeldung oder null, wenn E-Mail und Passwort formal in Ordnung sind */
function register_validate(string $email, string $password): ?string
{
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        return 'Das sieht nicht nach einer gültigen E-Mail-Adresse aus.';
    }
    if (strlen($password) < 8) return 'Passwort: mindestens 8 Zeichen.';
    return null;
}

function user_email_taken(string $email): bool
{
    return user_resolve(strtolower(trim($email))) !== null;
}

/**
 * Konto nach bestätigtem Double-Opt-In anlegen (Passwort kommt bereits als Hash
 * aus dem Pending-Token – Klartext wird nie gespeichert).
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function user_activate(string $email, string $passHash): ?string
{
    $err = null;
    json_update(users_file(), function (array $users) use ($email, $passHash, &$err) {
        if (isset($users[$email])) {
            $err = 'Dieses Konto ist bereits aktiviert.';
            return null;
        }
        foreach ($users as $u) {
            if (strtolower($u['email'] ?? '') === $email) {
                $err = 'Dieses Konto ist bereits aktiviert.';
                return null;
            }
        }
        $users[$email] = [
            'pass' => $passHash,
            // Erstes Konto einer frischen Instanz wird Admin (siehe user_add)
            'role' => $users === [] ? 'admin' : 'user',
            'email' => $email,
            'created' => date('c'),
            // Double-Opt-In-Nachweis (DSGVO): Zeitpunkt der Bestätigung + IP-Hash
            'verified' => date('c'),
            'verified_ip' => ip_hash(),
        ];
        return $users;
    });
    return $err;
}

// ---- Einmal-Token (Registrierungs-Bestätigung, Passwort-Reset) in data/pending/ ----

function pending_gc(): void
{
    foreach (glob(data_path('pending') . '/*.json') ?: [] as $f) {
        $d = json_read($f);
        if (($d['expires'] ?? 0) < time()) @unlink($f);
    }
}

/** Token anlegen; $kind trennt die Namensräume ('reg', 'pwreset'). */
function pending_create(string $kind, array $data, int $ttl = 86400): string
{
    pending_gc();
    $token = bin2hex(random_bytes(32));
    json_write(data_path('pending') . '/' . $kind . '-' . $token . '.json', $data + ['expires' => time() + $ttl]);
    return $token;
}

/** Token lesen, ohne ihn zu verbrauchen (für Formular-Anzeige beim Reset). */
function pending_get(string $kind, string $token): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) return null;
    $d = json_read(data_path('pending') . '/' . $kind . '-' . $token . '.json');
    if ($d === [] || ($d['expires'] ?? 0) < time()) return null;
    return $d;
}

/** Token lesen UND verbrauchen (einmalige Einlösung). */
function pending_take(string $kind, string $token): ?array
{
    $d = pending_get($kind, $token);
    if ($d !== null) @unlink(data_path('pending') . '/' . $kind . '-' . $token . '.json');
    return $d;
}
