<?php
declare(strict_types=1);
/**
 * Zwei-Faktor-Anmeldung mit Einmalkennwörtern (TOTP, RFC 6238).
 *
 * Warum das hier gebraucht wird: Wer ein Konto dieses Dienstes übernimmt, kann
 * das Ziel eines Kurzlinks ändern – auch das eines Codes, der längst gedruckt
 * auf einer Speisekarte oder einem Schild klebt. Der Schaden trifft dann nicht
 * den Kontoinhaber, sondern jeden, der scannt. Ein Passwort allein ist dafür
 * eine dünne Tür.
 *
 * Alles hier ist reines PHP: HMAC-SHA1 und base32 bringt die Sprache mit, der
 * QR-Code kommt aus dem eigenen Encoder. Keine Fremdbibliothek, wie überall im
 * Projekt.
 */
require_once __DIR__ . '/auth.php';

/** Länge des Zeitfensters in Sekunden – 30 ist der Wert, den alle Apps erwarten */
const TOTP_PERIOD = 30;
/** Wie viele Fenster vor und nach dem aktuellen noch gelten (Uhren gehen auseinander) */
const TOTP_WINDOW = 1;

/** Zufälliges Geheimnis, base32-kodiert (160 Bit, wie in RFC 4226 empfohlen) */
function totp_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

function totp_base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

/**
 * Einmalkennwort für einen Zähler berechnen (HOTP, RFC 4226).
 *
 * Die „dynamische Trunkierung" am Ende sieht willkürlich aus, ist aber genau
 * so vorgeschrieben: Das letzte Halbbyte des Hashs bestimmt, ab welcher Stelle
 * die vier Bytes entnommen werden.
 */
function totp_code(string $secret, int $counter): string
{
    $key = totp_base32_decode($secret);
    $bin = pack('J', $counter);                 // 64 Bit, große Endianess
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $teil = ((ord($hash[$offset]) & 0x7F) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string)($teil % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Eingegebenes Kennwort prüfen.
 *
 * Gibt den benutzten Zähler zurück, damit der Aufrufer ihn festhalten kann –
 * dasselbe Kennwort darf kein zweites Mal gelten. Ohne diese Sperre könnte
 * jemand, der einmal über die Schulter geschaut hat, sich innerhalb desselben
 * halben Minutenfensters selbst anmelden.
 *
 * @return ?int Zähler des passenden Fensters oder null
 */
function totp_verify(string $secret, string $eingabe, int $zuletzt = 0): ?int
{
    $eingabe = preg_replace('/\D/', '', $eingabe) ?? '';
    if (strlen($eingabe) !== 6) return null;
    $jetzt = intdiv(time(), TOTP_PERIOD);
    for ($i = -TOTP_WINDOW; $i <= TOTP_WINDOW; $i++) {
        $c = $jetzt + $i;
        if ($c <= $zuletzt) continue;           // schon benutzt
        if (hash_equals(totp_code($secret, $c), $eingabe)) return $c;
    }
    return null;
}

/** Adresse für die Authenticator-App. Enthält das Geheimnis – niemals in eine URL! */
function totp_uri(string $konto, string $secret): string
{
    $aussteller = (string)cfg('site_name');
    return 'otpauth://totp/' . rawurlencode($aussteller . ':' . $konto)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($aussteller)
        . '&algorithm=SHA1&digits=6&period=' . TOTP_PERIOD;
}

/**
 * Den Einrichtungs-QR-Code als SVG zum unmittelbaren Einbetten.
 *
 * Bewusst hier erzeugt und nicht über qr.php geholt: Die Adresse enthält das
 * Geheimnis, und eine URL landet in Server-Protokollen, im Verlauf des
 * Browsers und im Referrer. Ein eingebettetes SVG geht diesen Weg nicht.
 */
function totp_qr_svg(string $konto, string $secret): string
{
    require_once __DIR__ . '/qrlib.php';
    $qr = QrCode::encode(totp_uri($konto, $secret), QrCode::ECC_M);
    $r = new QrRenderer($qr, ['size' => 220, 'margin' => 2, 'fg' => '#000000', 'bg' => '#ffffff']);
    return $r->svg();
}

// ---- Ablage am Konto ----------------------------------------------------

/** @return ?array{secret:string,confirmed:bool,last:int,recovery:string[]} */
function totp_get(string $user): ?array
{
    $t = users_all()[$user]['totp'] ?? null;
    return is_array($t) ? $t : null;
}

function totp_active(string $user): bool
{
    $t = totp_get($user);
    return $t !== null && !empty($t['confirmed']);
}

/** Neues, noch unbestätigtes Geheimnis hinterlegen */
function totp_begin(string $user): string
{
    $secret = totp_secret();
    json_update(users_file(), function (array $users) use ($user, $secret) {
        if (!isset($users[$user])) return null;
        $users[$user]['totp'] = ['secret' => $secret, 'confirmed' => false, 'last' => 0, 'recovery' => []];
        return $users;
    });
    return $secret;
}

/**
 * Einrichtung abschließen: Kennwort prüfen, dann Wiederherstellungscodes
 * erzeugen. Sie werden nur einmal zurückgegeben.
 *
 * @return ?string[] Codes im Klartext oder null bei falscher Eingabe
 */
function totp_confirm(string $user, string $eingabe): ?array
{
    $t = totp_get($user);
    if ($t === null || !empty($t['confirmed'])) return null;
    $c = totp_verify((string)$t['secret'], $eingabe, (int)($t['last'] ?? 0));
    if ($c === null) return null;

    $klar = [];
    $hashes = [];
    for ($i = 0; $i < 8; $i++) {
        // Aus einem Alphabet ohne verwechselbare Zeichen; 10 Stellen sind gut
        // 50 Bit – online nicht zu erraten, und abgeschrieben noch zumutbar.
        $code = '';
        for ($j = 0; $j < 10; $j++) {
            $code .= '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)];
        }
        $klar[] = substr($code, 0, 5) . '-' . substr($code, 5);
        // Ein gleichbleibender Abdruck genügt: Der Code ist zufällig und lang,
        // der Grund für langsame Passwortverfahren entfällt (wie bei den
        // API-Schlüsseln, siehe inc/token.php).
        $hashes[] = hash('sha256', $code);
    }
    json_update(users_file(), function (array $users) use ($user, $c, $hashes) {
        if (!isset($users[$user]['totp'])) return null;
        $users[$user]['totp']['confirmed'] = true;
        $users[$user]['totp']['last'] = $c;
        $users[$user]['totp']['recovery'] = $hashes;
        return $users;
    });
    return $klar;
}

/**
 * Anmeldung mit Einmalkennwort oder Wiederherstellungscode.
 *
 * Ein benutzter Wiederherstellungscode wird verbraucht – er ist für den einen
 * Fall gedacht, in dem das Telefon weg ist.
 */
function totp_check(string $user, string $eingabe): bool
{
    $t = totp_get($user);
    if ($t === null || empty($t['confirmed'])) return false;

    $c = totp_verify((string)$t['secret'], $eingabe, (int)($t['last'] ?? 0));
    if ($c !== null) {
        json_update(users_file(), function (array $users) use ($user, $c) {
            if (!isset($users[$user]['totp'])) return null;
            $users[$user]['totp']['last'] = $c;
            return $users;
        });
        return true;
    }

    $roh = strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', $eingabe));
    if (strlen($roh) !== 10) return false;
    $abdruck = hash('sha256', $roh);
    $treffer = false;
    json_update(users_file(), function (array $users) use ($user, $abdruck, &$treffer) {
        $liste = (array)($users[$user]['totp']['recovery'] ?? []);
        foreach ($liste as $i => $h) {
            if (hash_equals((string)$h, $abdruck)) {
                unset($liste[$i]);
                $users[$user]['totp']['recovery'] = array_values($liste);
                $treffer = true;
                return $users;
            }
        }
        return null;
    });
    return $treffer;
}

/** Zwei-Faktor abschalten */
function totp_disable(string $user): void
{
    json_update(users_file(), function (array $users) use ($user) {
        if (!isset($users[$user]['totp'])) return null;
        unset($users[$user]['totp']);
        return $users;
    });
}

/** Wie viele Wiederherstellungscodes noch übrig sind */
function totp_recovery_left(string $user): int
{
    return count((array)(totp_get($user)['recovery'] ?? []));
}

/**
 * Verlangt diese Instanz die zweite Stufe für dieses Konto?
 *
 * 'off' | 'admins' | 'all' – wer sie noch nicht eingerichtet hat, wird nach der
 * Anmeldung dorthin geführt, statt ausgesperrt zu werden.
 */
function totp_required(string $role): bool
{
    $modus = (string)(settings()['totp_required'] ?? 'off');
    return $modus === 'all' || ($modus === 'admins' && $role === 'admin');
}
