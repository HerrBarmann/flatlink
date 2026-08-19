<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
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

/**
 * Die drei Funktionen hierunter sind die gesamte Konten-Ablage – jeder
 * Zugriff im Projekt läuft über sie (SQLite, siehe inc/db.php): users_all()
 * als SELECT über alles, user_get() als SELECT eines Kontos, users_update()
 * als Transaktion.
 */

/**
 * Alle Konten, je Anfrage nur einmal geladen.
 *
 * Der Zwischenspeicher gilt für die Dauer eines Aufrufs – genau richtig,
 * denn eine einzige Seite fragt die Liste leicht fünfmal an (Anmeldung,
 * Navigation, Anzeigenamen in jeder Zeile), und zwischen zwei Anfragen
 * lebt der Prozess ohnehin nicht weiter. $frisch verwirft ihn nur; neu
 * geladen wird erst, wenn wieder jemand fragt.
 *
 * @return array<string,array> username => {pass, role, created}
 */
function users_all(bool $frisch = false): array
{
    static $cache = null;
    if ($frisch) {
        $cache = null;
        return [];
    }
    if ($cache === null) {
        $cache = db_users_all(db());
    }
    return $cache;
}

/**
 * Ein einzelnes Konto – die häufigste Frage an die Ablage.
 *
 * Mit Datenbank ein gezielter SELECT: Genau deshalb trägt eine Instanz dort
 * hunderttausend Konten, ohne sie je alle in den Speicher zu heben.
 */
function user_get(string $username): ?array
{
    return db_user_get(db(), $username);
}

/**
 * Konten ändern – die einzige Schreibstelle.
 *
 * $fn bekommt das Konten-Verzeichnis und gibt das neue zurück (null = nichts
 * ändern). $konto nennt das eine Konto, das der Vorgang anfasst – dann lädt
 * das Datenbank-Backend nur diesen Datensatz statt aller. Vorgänge, die die
 * Gesamtsicht brauchen (Erstinstallations-Erkennung, Duplikatsuche über
 * E-Mail-Adressen, Aufräumen über alle), lassen $konto weg.
 *
 * Datei-Ablage: unter Lock (json_update), $konto ist dort ohne Belang.
 */
function users_update(callable $fn, ?string $konto = null): array
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ($konto !== null) {
            $alt = db_user_get($pdo, $konto);
            $vorher = $alt === null ? [] : [$konto => $alt];
        } else {
            $vorher = db_users_all($pdo);
        }
        $nachher = $fn($vorher);
        if ($nachher !== null) {
            db_users_diff($pdo, $vorher, $nachher);
        }
        $pdo->exec('COMMIT');
        users_all(true);
        return $nachher ?? $vorher;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function users_exist(): bool
{
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/**
 * Wer wartet gerade auf die zweite Stufe?
 *
 * Der Zustand verfällt nach zehn Minuten – wer die Anmeldung offen liegen
 * lässt, soll sie nicht Stunden später an derselben Sitzung fortsetzen können.
 */
/**
 * Hat dieses Konto eine zweite Stufe – gleich welcher Art?
 *
 * Passkey und Einmalkennwort sind gleichwertige Antworten auf dieselbe Frage.
 * Diese eine Stelle entscheidet, damit die Anmeldung, der Zwang aus den
 * Einstellungen und die Anzeige nicht auseinanderlaufen können.
 */
function second_factor_active(string $user): bool
{
    require_once __DIR__ . '/totp.php';
    require_once __DIR__ . '/webauthn.php';
    return totp_active($user) || passkeys_active($user);
}

// ---- Sitzungen je Konto ---------------------------------------------------
//
// Jede Anmeldung wird am Konto vermerkt – als Hash der Session-Kennung, nie
// als Kennung selbst: Wer die Ablage liest, kann damit keine Sitzung
// übernehmen. So bekommt das Profil eine Liste der aktiven Anmeldungen und
// den Hebel „überall sonst abmelden": Was nicht (mehr) im Verzeichnis
// steht, ist bei der nächsten Anfrage abgemeldet. Ein Passwortwechsel
// widerruft die übrigen Sitzungen gleich mit.
//
// Konten aus der Zeit vor dieser Liste haben das Feld noch nicht – ihre
// laufende Sitzung bleibt gültig und trägt sich beim nächsten Aufruf selbst
// ein, statt alle beim Update auszusperren.

/** Der Fingerabdruck dieser Sitzung – ein Hash, nie die Kennung selbst */
function session_fingerprint(): string
{
    return hash('sha256', session_id());
}

/**
 * Grobe Gerätekennzeichnung für die Liste im Profil – Browser- und
 * Systemfamilie, ohne Versionen: gerade genug, um die eigene Sitzung von
 * einer fremden zu unterscheiden, und zu wenig für ein Fingerprinting.
 */
function session_geraet(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $browser = match (true) {
        str_contains($ua, 'Firefox') => 'Firefox',
        str_contains($ua, 'Edg') => 'Edge',
        str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Chrome') => 'Chrome',
        str_contains($ua, 'Safari') => 'Safari',
        $ua === '' => '',
        default => t('Browser'),
    };
    $system = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };
    return trim($browser . ($browser !== '' && $system !== '' ? ' · ' : '') . $system);
}

/**
 * Tote Einträge aus der Sitzungsliste werfen.
 *
 * Eine PHP-Sitzung verschwindet serverseitig, sobald sie länger als
 * `session.gc_maxlifetime` unberührt bleibt – auf vielen Servern nach 24
 * Minuten. Der Eintrag im Konto überlebt sie aber und steht weiter als
 * „angemeldetes Gerät" in der Liste, obwohl niemand mehr damit hereinkommt.
 * So füllt sich die Liste ausgerechnet bei jemandem, der immer dieselben
 * zwei Browser benutzt: Jede neue Anmeldung legt einen Eintrag an, keiner
 * geht je wieder weg.
 *
 * Die Frist liegt bewusst weit über `gc_maxlifetime`. PHP räumt
 * zufallsgesteuert auf (`session.gc_probability`), eine Sitzung kann ihre
 * Frist also deutlich überleben. Fiele hier ein Eintrag, dessen Sitzung noch
 * lebt, flöge derjenige beim nächsten Aufruf aus dem Konto – ein
 * stehengebliebener Eintrag ist der harmlosere Fehler.
 *
 * @param array<string,array<string,mixed>> $s
 * @return array<string,array<string,mixed>>
 */
function sessions_prune(array $s): array
{
    $frist = max(2 * (int)ini_get('session.gc_maxlifetime'), 86400);
    $grenze = time() - $frist;
    foreach ($s as $fp => $e) {
        // Ohne Zeitstempel: stehenlassen. Das sind Einträge aus der Zeit vor
        // diesem Feld, und ein Rauswurf wäre hier ein Rauswurf aus dem Konto.
        $roh = (string)($e['zuletzt'] ?? '');
        if ($roh === '') continue;
        if ((strtotime($roh) ?: 0) < $grenze) unset($s[$fp]);
    }
    return $s;
}

/** Die laufende Sitzung am Konto vermerken (bei jeder Anmeldung) */
function session_register(string $username): void
{
    $fp = session_fingerprint();
    users_update(function (array $users) use ($username, $fp) {
        if (!isset($users[$username])) return null;
        $s = sessions_prune((array)($users[$username]['sessions'] ?? []));
        $s[$fp] = ['seit' => date('c'), 'zuletzt' => date('c'), 'geraet' => session_geraet()];
        // Höchstens zehn: mehr parallele Anmeldungen hat niemand, und die
        // Liste im Profil soll eine Liste bleiben, kein Archiv
        if (count($s) > 10) {
            uasort($s, fn($a, $b) => strcmp((string)($a['zuletzt'] ?? ''), (string)($b['zuletzt'] ?? '')));
            $s = array_slice($s, count($s) - 10, null, true);
        }
        $users[$username]['sessions'] = $s;
        return $users;
    }, $username);
}

/**
 * Gilt die laufende Sitzung noch? Nebenbei: Altbestand trägt sich nach,
 * und der Zeitstempel wird höchstens alle zehn Minuten geschrieben – nicht
 * bei jeder Anfrage.
 *
 * @return bool false = widerrufen, die Sitzung ist zu beenden
 */
function session_check(string $username, array $u): bool
{
    // Einmal je Anfrage genügt – auth_user() läuft viele Male
    static $ergebnis = null;
    if ($ergebnis !== null) return $ergebnis;

    $fp = session_fingerprint();
    if (!isset($u['sessions'])) {
        // Konto aus der Zeit vor der Sitzungsliste: gültig, und ab jetzt geführt
        session_register($username);
        return $ergebnis = true;
    }
    if (!isset($u['sessions'][$fp])) {
        return $ergebnis = false;  // widerrufen (oder woanders abgemeldet)
    }
    $zuletzt = strtotime((string)($u['sessions'][$fp]['zuletzt'] ?? '')) ?: 0;
    if ($zuletzt < time() - 600) {
        users_update(function (array $users) use ($username, $fp) {
            if (!isset($users[$username]['sessions'][$fp])) return null;
            $users[$username]['sessions'][$fp]['zuletzt'] = date('c');
            $users[$username]['sessions'] = sessions_prune($users[$username]['sessions']);
            return $users;
        }, $username);
    }
    return $ergebnis = true;
}

/**
 * Sitzungen eines Kontos widerrufen.
 *
 * $behalten nennt den Fingerabdruck, der bleiben darf (die eigene Sitzung
 * bei „überall sonst abmelden"); $einzeln widerruft genau einen. Ohne
 * beides fliegen alle – etwa wenn ein Administrator ein fremdes Passwort
 * neu setzt.
 */
function sessions_revoke(string $username, ?string $behalten = null, ?string $einzeln = null): void
{
    users_update(function (array $users) use ($username, $behalten, $einzeln) {
        if (!isset($users[$username])) return null;
        $s = (array)($users[$username]['sessions'] ?? []);
        if ($einzeln !== null) {
            unset($s[$einzeln]);
        } else {
            $s = $behalten !== null && isset($s[$behalten]) ? [$behalten => $s[$behalten]] : [];
        }
        $users[$username]['sessions'] = $s;
        return $users;
    }, $username);
}

function auth_pending(): ?string
{
    $n = $_SESSION['pending_user'] ?? null;
    if (!is_string($n)) return null;
    if ((int)($_SESSION['pending_since'] ?? 0) < time() - 600) {
        unset($_SESSION['pending_user'], $_SESSION['pending_since']);
        return null;
    }
    return user_get($n) !== null ? $n : null;
}

/** Zweite Stufe bestanden: aus dem wartenden wird ein angemeldeter Zustand */
function auth_pending_complete(): void
{
    $n = auth_pending();
    if ($n === null) return;
    session_regenerate_id(true);
    unset($_SESSION['pending_user'], $_SESSION['pending_since'], $_SESSION['csrf']);
    $_SESSION['user'] = $n;
    session_register($n);
}

/** @return ?array{name:string,role:string,auth:string,display:string} */
/**
 * Ist dieses Konto gesperrt?
 *
 * Gesperrt heißt: Anmeldung schlägt fehl, laufende Sitzungen enden beim
 * nächsten Aufruf, Zugangsschlüssel greifen nicht mehr. Was NICHT passiert:
 * Es wird nichts gelöscht. Links, Gruppenzugehörigkeit, Statistik und QR-Codes
 * bleiben, wie sie waren – gedruckte Codes eines ausgeschiedenen Mitarbeiters
 * sollen nicht ins Leere zeigen, nur weil sein Konto zugeht.
 *
 * Das ist der Unterschied zum Löschen, und er ist der Grund, warum es beides
 * gibt: Sperren ist umkehrbar, Löschen nicht.
 */
function user_locked(?array $u): bool
{
    return $u !== null && ($u['locked'] ?? null) !== null;
}

/** Warum und seit wann – für die Anzeige, nicht für die Entscheidung */
function user_lock_note(?array $u): string
{
    $l = $u['locked'] ?? null;
    if (!is_array($l)) return '';
    $wann = isset($l['at']) ? date('d.m.Y', strtotime((string)$l['at']) ?: time()) : '';
    $grund = trim((string)($l['reason'] ?? ''));
    return trim($grund . ($wann !== '' ? " ($wann)" : ''));
}

/**
 * Konto sperren oder entsperren.
 *
 * $grund landet im Datensatz und in der Anzeige – bei einem maschinellen
 * Abgleich ist er die einzige Spur, warum jemand plötzlich nicht mehr
 * hereinkommt. Beim Sperren fliegen die Sitzungen; die Zugangsschlüssel
 * bleiben liegen und greifen einfach nicht mehr, damit ein Entsperren sie
 * nicht alle neu verteilen muss.
 */
function user_set_locked(string $name, bool $gesperrt, string $grund = ''): bool
{
    $vorher = user_get($name);
    if ($vorher === null) return false;
    users_update(function (array $users) use ($name, $gesperrt, $grund) {
        if (!isset($users[$name])) return null;
        if ($gesperrt) {
            $users[$name]['locked'] = ['at' => date('c'), 'reason' => mb_substr($grund, 0, 200)];
        } else {
            unset($users[$name]['locked']);
        }
        return $users;
    }, $name);
    if ($gesperrt) sessions_revoke($name);
    return true;
}

function auth_user(): ?array
{
    $name = $_SESSION['user'] ?? null;
    if (!is_string($name)) return null;
    $u = user_get($name);
    if ($u === null) return null;
    // Gesperrt: Die laufende Sitzung endet hier, nicht erst beim nächsten
    // Anmeldeversuch. Sonst arbeitete jemand nach der Sperre weiter, bis er
    // sich zufällig abmeldet.
    if (user_locked($u)) {
        auth_logout();
        return null;
    }
    // Widerrufene Sitzung: hier endet sie – beim nächsten Aufruf jeder
    // geschützten Seite, egal wo sie herkam
    if (!session_check($name, $u)) {
        auth_logout();
        return null;
    }
    return [
        'name' => $name,
        'role' => $u['role'],
        'auth' => $u['auth'] ?? 'local',
        'display' => (is_string($u['display_name'] ?? null) && trim($u['display_name']) !== '')
            ? trim($u['display_name']) : $name,
    ];
}

/**
 * Angemeldetes Konto verlangen.
 *
 * $frei nimmt Seiten aus, die auch ohne eingerichtete zweite Stufe erreichbar
 * bleiben müssen – sonst führte der Zwang zu ihrer Einrichtung im Kreis.
 */
function auth_require(bool $frei = false): array
{
    // Die Verwaltung gehört auf die Hauptdomain – vor allem anderen, damit
    // unter einer Nebendomain gar nicht erst eine Sitzung entsteht.
    require_once __DIR__ . '/domains.php';
    domain_force_main();
    auth_boot();
    $u = auth_user();
    // Absolut, nicht relativ: Dateien außerhalb von admin/ landeten sonst auf
    // einem /login.php, das es nicht gibt
    if ($u === null) redirect_to(base_url() . '/admin/login.php');

    // Verlangt die Instanz die zweite Stufe, führt der Weg zuerst dorthin.
    // Nicht aussperren, sondern hinführen: Wer sie nicht eingerichtet hat, hat
    // meist nur noch nichts davon gewusst.
    if (!$frei) {
        require_once __DIR__ . '/totp.php';
        if (totp_required($u['role']) && !second_factor_active($u['name'])) {
            flash(t('Diese Instanz verlangt eine Zwei-Faktor-Anmeldung – bitte hier einrichten.'), 'err');
            redirect_to(base_url() . '/admin/profile.php');
        }
    }
    return $u;
}

function auth_require_admin(): array
{
    $u = auth_require();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        nosniff_header();
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
    $lower = strtolower($ident);
    $st = db()->prepare('SELECT name FROM users WHERE name = ? OR name = ? OR email = ? LIMIT 1');
    $st->execute([$ident, $lower, $lower]);
    $name = $st->fetchColumn();
    return $name === false ? null : (string)$name;
}

/**
 * Vergleichs-Hash für nicht existierende Konten: ein fester bcrypt-Wert, der
 * zu keinem Passwort passt. Kostet beim Prüfen dieselbe Zeit wie ein echter,
 * ohne beim Erzeugen CPU zu verbrennen.
 */
const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

/** Die Datei, in der die Fehlversuche zu IP+Kennung stehen */
function login_throttle_file(string $username): string
{
    return data_path('ratelimit') . '/login-' . ip_hash(client_ip() . '|' . $username) . '.json';
}

/**
 * Wie lange ist diese Kennung von dieser Adresse aus noch gesperrt?
 *
 * Aufrufer beantworten damit sofort mit 429, statt den Versuch überhaupt
 * zu prüfen. Früher wartete die Antwort stattdessen zwei Sekunden – das
 * bremste zwar den Angreifer, band aber für die Dauer einen PHP-Prozess.
 * Auf einem Massenhoster mit einer Handvoll Prozessen ist das kein Schutz,
 * sondern ein Hebel: Wer genug Fehlversuche parallel abfeuert, legt die
 * Instanz für alle lahm – und zwar gerade WEIL die Bremse greift. Zählen
 * kostet nichts, Warten kostet den Platz eines echten Besuchers.
 *
 * @return int Sekunden, 0 = nicht gesperrt
 */
function login_throttle_left(string $username): int
{
    $t = json_read(login_throttle_file($username), ['fails' => 0, 'until' => 0]);
    if ((int)($t['fails'] ?? 0) < 5) return 0;
    return max(0, (int)($t['until'] ?? 0) - time());
}

/** Login mit Throttling: nach 5 Fehlversuchen 60 s Sperre pro IP+Name */
/**
 * Anmeldung mit Kennung und Passwort.
 *
 * $needs2fa wird gesetzt, wenn Passwort und Kennung stimmen, aber noch die
 * zweite Stufe fehlt. Das Konto ist dann NICHT angemeldet – die Sitzung merkt
 * sich nur, wer gerade an der Tür steht.
 */
function auth_login(string $username, string $password, ?bool &$needs2fa = null): bool
{
    $needs2fa = false;
    rate_limit_gc();
    $throttleFile = login_throttle_file($username);
    $t = json_read($throttleFile, ['fails' => 0, 'until' => 0]);
    if ($t['fails'] >= 5 && time() < $t['until']) {
        return false;
    }

    $key = user_resolve($username);
    $u = $key !== null ? (users_all()[$key] ?? null) : null;
    $username = $key ?? $username;
    // Zentral verwaltete Konten (LDAP/SSO) haben keinen lokalen Passwort-Hash
    // und dürfen sich hier auch nicht anmelden – sonst wäre die zentrale
    // Anmeldung über ein lokal gesetztes Passwort umgehbar.
    if ($u !== null && ($u['auth'] ?? 'local') !== 'local') $u = null;
    // Gesperrt zählt wie „gibt es nicht": Die Prüfung läuft trotzdem gegen
    // den Dummy-Hash weiter, damit die Antwortzeit nicht verrät, ob ein
    // Konto existiert und nur gesperrt ist.
    if (user_locked($u)) $u = null;
    // Immer verifizieren, damit Timing keinen Nutzernamen verrät. Der
    // Vergleichs-Hash ist eine Konstante – ihn bei jedem Fehlversuch neu zu
    // berechnen wäre ein billiger Weg, die CPU des Servers auszulasten.
    $hash = $u['pass'] ?? DUMMY_HASH;
    if ($u !== null && is_string($u['pass'] ?? null) && password_verify($password, $hash)) {
        @unlink($throttleFile);
        session_regenerate_id(true);
        unset($_SESSION['csrf']);
        // Zweite Stufe, falls eingerichtet: Das Konto gilt erst als angemeldet,
        // wenn auch das Einmalkennwort stimmt. Die Weiche sitzt hier und nicht
        // in der Anmeldeseite, damit sie kein künftiger zweiter Anmeldeweg
        // versehentlich umgeht.
        require_once __DIR__ . '/totp.php';
        if (second_factor_active($username)) {
            $_SESSION['pending_user'] = $username;
            $_SESSION['pending_since'] = time();
            $needs2fa = true;
            return true;
        }
        $_SESSION['user'] = $username;
        session_register($username);
        return true;
    }
    $t['fails']++;
    $t['until'] = time() + 60;
    json_write($throttleFile, $t);
    login_failure_note();
    return false;
}

/**
 * Fehlversuche über die ganze Instanz mitzählen.
 *
 * Die Sperre pro IP+Nutzername greift gegen das Durchprobieren eines Kontos.
 * Verteiltes Ausprobieren (ein Versuch je Konto über viele Konten) läuft
 * daran vorbei, weil kein einzelner Zähler auffällig wird. Ein gemeinsamer
 * Stundenzähler bremst genau das.
 *
 * Bewusst kein instanzweiter Riegel: Er wäre ein Weg, alle auszusperren –
 * ein Angreifer müsste nur genug Fehlversuche erzeugen. Gebremst wird
 * deshalb an der Quelle: Fällt der Stundenzähler auffällig aus, gilt für
 * jede einzelne Adresse eine harte Obergrenze an Fehlversuchen. Wer selbst
 * nichts falsch macht, merkt davon nichts.
 *
 * Früher stand hier ein sleep(2). Es verzögerte den Angreifer und band
 * dabei einen PHP-Prozess – auf kleinen Instanzen der wirksamere Angriff.
 */
function login_failure_note(): void
{
    $file = data_path('ratelimit') . '/login-global.json';
    $hour = date('YmdH');
    $n = 0;
    json_update($file, function (array $d) use ($hour, &$n) {
        if (($d['hour'] ?? '') !== $hour) $d = ['hour' => $hour, 'n' => 0];
        $d['n']++;
        $n = $d['n'];
        return $d;
    });
    // Oberhalb der Auffälligkeitsschwelle bekommt jede Adresse ein eigenes,
    // enges Fehlversuchs-Kontingent. bucket_rate_ok zählt nur – niemand
    // wartet, und gesperrt ist immer nur die Quelle.
    if ($n > 30) bucket_rate_ok('loginfail', 10);
}

/**
 * Darf von dieser Adresse überhaupt noch ein Anmeldeversuch kommen?
 *
 * Greift nur, wenn instanzweit auffällig viel schiefgeht (siehe
 * login_failure_note) – im Normalbetrieb ist das Kontingent nie erschöpft.
 */
function login_source_ok(): bool
{
    $file = data_path('ratelimit') . '/loginfail-' . ip_hash() . '.json';
    $d = json_read($file, []);
    return ($d['hour'] ?? '') !== date('YmdH') || (int)($d['n'] ?? 0) < 10;
}

function auth_logout(): void
{
    // Erst austragen, dann zerstören – solange die Kennung noch da ist
    $name = $_SESSION['user'] ?? null;
    if (is_string($name) && session_id() !== '') {
        sessions_revoke($name, null, session_fingerprint());
    }
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
        return t('Nutzername: 2–32 Zeichen, nur Buchstaben, Zahlen, Punkt, Minus, Unterstrich.');
    }
    if (strlen($password) < 8) return t('Passwort: mindestens 8 Zeichen.');
    if (!in_array($role, ['admin', 'user'], true)) return t('Ungültige Rolle.');
    $err = null;
    users_update(function (array $users) use ($username, $password, $role, &$err) {
        if (isset($users[$username])) {
            $err = t('Diesen Nutzer gibt es schon.');
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
    if (strlen($password) < 8) return t('Passwort: mindestens 8 Zeichen.');
    $err = t('Nutzer nicht gefunden.');
    users_update(function (array $users) use ($username, $password, &$err) {
        if (!isset($users[$username])) return null;
        $users[$username]['pass'] = password_hash($password, PASSWORD_DEFAULT);
        $err = null;
        return $users;
    }, $username);
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
        return t('Ungültige E-Mail-Adresse.');
    }
    $err = t('Nutzer nicht gefunden.');
    users_update(function (array $users) use ($username, $email, &$err) {
        if (!isset($users[$username])) return null;
        foreach ($users as $key => $u) {
            if ($key === $username) continue;
            if (strtolower($u['email'] ?? '') === $email || strtolower((string)$key) === $email) {
                $err = t('Diese Adresse ist bereits mit einem anderen Konto verknüpft.');
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
    if (mb_strlen($name) > 80) return t('Anzeigename: höchstens 80 Zeichen.');
    $err = t('Nutzer nicht gefunden.');
    users_update(function (array $users) use ($username, $name, &$err) {
        if (!isset($users[$username])) return null;
        if ($name === '') {
            unset($users[$username]['display_name']);
        } else {
            $users[$username]['display_name'] = $name;
        }
        $err = null;
        return $users;
    }, $username);
    return $err;
}

/**
 * Den Double-Opt-In-Nachweis nach zwölf Monaten entfernen.
 *
 * Der Zeitpunkt der Bestätigung bleibt; nur der IP-Hash fällt weg. Er ist
 * pseudonym, nicht anonym – ihn unbefristet aufzubewahren wäre für einen
 * Nachweis, den niemand mehr anzweifelt, nicht zu rechtfertigen.
 */
function verified_ip_gc(): void
{
    $grenze = date('c', strtotime('-12 months'));
    users_update(function (array $users) use ($grenze) {
        $touched = false;
        foreach ($users as $k => $u) {
            if (isset($u['verified_ip']) && (string)($u['verified'] ?? '') < $grenze) {
                unset($users[$k]['verified_ip']);
                $touched = true;
            }
        }
        return $touched ? $users : null;
    });
}

/** Anzahl der Administratoren – für den Schutz vor dem Aussperren */
function admin_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

/**
 * Rolle ändern. Der letzte Administrator kann nicht degradiert werden –
 * sonst bliebe die Instanz ohne Zugang zu Nutzern, Gruppen und Einstellungen.
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function user_set_role(string $username, string $role): ?string
{
    if (!in_array($role, ['admin', 'user'], true)) return t('Ungültige Rolle.');
    $users = users_all();
    if (!isset($users[$username])) return t('Nutzer nicht gefunden.');
    if ($role !== 'admin' && ($users[$username]['role'] ?? '') === 'admin' && admin_count() <= 1) {
        return t('Das ist der letzte Administrator – erst einen zweiten ernennen.');
    }
    users_update(function (array $users) use ($username, $role) {
        if (!isset($users[$username])) return null;
        $users[$username]['role'] = $role;
        return $users;
    }, $username);
    return null;
}

/** @return ?string Fehlermeldung oder null bei Erfolg */
/**
 * Ein Konto löschen – samt allem, was daran hängt.
 *
 * Bis 2.8.0 löschte diese Funktion nur den Konteneintrag; das Aufräumen stand
 * ausschließlich in account_delete(), also im Weg der Selbstlöschung. Damit
 * räumten zwei Wege zum selben Ziel unterschiedlich auf: Wer von der
 * Verwaltung gelöscht wurde, hinterließ seinen Namen im Besitzerfeld jedes
 * Links, dazu herrenlose Links, gültige Zugangsschlüssel und offene
 * Bestätigungen. Jetzt geht beides hier durch.
 *
 * Was mit den Links geschieht:
 *
 *   - **Links einer Arbeitsgruppe** verlieren den Besitzer und bleiben der
 *     Gruppe. Genau dafür gibt es Gruppen; ein ausgeschiedener Kollege soll
 *     das gemeinsame Plakat nicht mitnehmen. Hier gibt es nichts zu wählen.
 *   - **Links ohne Gruppe** wären danach herrenlos. Deshalb entscheidet der
 *     Aufrufer: `delete` löscht sie (so bei der Selbstlöschung), `transfer`
 *     überträgt sie an das Konto in $an – für den Fall, dass gedruckte Codes
 *     im Umlauf sind, die weiter funktionieren müssen.
 *
 * @param string  $linkModus 'delete' oder 'transfer'
 * @param ?string $an        Zielkonto bei 'transfer'
 */
function user_delete(string $username, string $linkModus = 'delete', ?string $an = null): ?string
{
    // Beide gehören zur Grundausstattung, stehen aber nicht in auth.php selbst.
    // require_once statt Verlass darauf, dass der Aufrufer sie schon geladen
    // hat: Ein Konto halb zu löschen wäre schlimmer als ein Ladefehler.
    require_once __DIR__ . '/store.php';
    require_once __DIR__ . '/token.php';

    $users = users_all();
    if (($users[$username]['role'] ?? '') === 'admin' && admin_count() <= 1) {
        return t('Das ist der letzte Administrator und kann nicht gelöscht werden.');
    }
    if ($linkModus === 'transfer' && ($an === null || !isset($users[$an]))) {
        return t('Das Zielkonto für die Links gibt es nicht.');
    }

    foreach (links_of_owner($username) as $code => $l) {
        if ((string)($l['group'] ?? '') !== '') {
            link_set_owner((string)$code, null);
        } elseif ($linkModus === 'transfer') {
            link_set_owner((string)$code, $an);
        } else {
            link_delete((string)$code);
        }
    }

    // Offene Bestätigungen (E-Mail-Wechsel, Passwort-Reset) mitnehmen – sie
    // tragen die Kennung und wären sonst noch stundenlang einlösbar.
    foreach (glob(data_path('pending') . '/*.json') ?: [] as $f) {
        $d = json_read($f);
        if (($d['user'] ?? null) === $username) @unlink($f);
    }
    // Zugangsschlüssel gehören zum Konto und dürfen es nicht überleben. Die
    // Schnittstelle weist sie zwar ohnehin ab, sobald das Konto fehlt – aber
    // liegen bleiben müssen sie deshalb nicht.
    tokens_drop_user($username);

    users_update(function (array $users) use ($username) {
        unset($users[$username]);
        return $users;
    }, $username);
    return null;
}

// ---- Selbst-Registrierung (Konto per E-Mail, Rolle immer "user") ----

/** @return ?string Fehlermeldung oder null, wenn E-Mail und Passwort formal in Ordnung sind */
function register_validate(string $email, string $password): ?string
{
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        return t('Das sieht nicht nach einer gültigen E-Mail-Adresse aus.');
    }
    if (strlen($password) < 8) return t('Passwort: mindestens 8 Zeichen.');
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
    users_update(function (array $users) use ($email, $passHash, &$err) {
        if (isset($users[$email])) {
            $err = t('Dieses Konto ist bereits aktiviert.');
            return null;
        }
        foreach ($users as $u) {
            if (strtolower($u['email'] ?? '') === $email) {
                $err = t('Dieses Konto ist bereits aktiviert.');
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
