<?php
declare(strict_types=1);
/**
 * Passkeys (WebAuthn / FIDO2) als zweiter Faktor.
 *
 * Was Passkeys besser können als Einmalkennwörter: Sie sind **an die Domain
 * gebunden**. Eine nachgebaute Anmeldeseite kann sich ein TOTP-Kennwort
 * durchreichen lassen und binnen Sekunden selbst einlösen – einen Passkey gibt
 * der Browser dort schlicht nicht heraus, weil die Herkunft nicht stimmt. Das
 * ist der eigentliche Gewinn, nicht die Bequemlichkeit.
 *
 * **Vorsicht beim Lesen und Ändern:** Anders als bei TOTP, wo ein falscher
 * Code schlicht nicht passt, funktioniert eine WebAuthn-Anmeldung auch dann
 * tadellos, wenn man einen Prüfschritt vergisst – nur ist sie dann wertlos.
 * Die vier Schritte, die den Schutz ausmachen, sind unten einzeln benannt und
 * dürfen nicht wegoptimiert werden:
 *
 *   1. Die Aufgabe (Challenge) muss die sein, die wir selbst gestellt haben.
 *   2. Die Herkunft (Origin) muss unsere sein – hier hängt die Phishing-Abwehr.
 *   3. Der Abdruck der Domain (rpIdHash) muss zu unserer Domain passen.
 *   4. Die Unterschrift muss zum hinterlegten Schlüssel passen.
 *
 * Alles in reinem PHP: Der CBOR-Teil ist selbst geschrieben, die Prüfung der
 * Unterschrift erledigt OpenSSL, das PHP ohnehin mitbringt.
 */
require_once __DIR__ . '/auth.php';

// ---- Kodierungen ---------------------------------------------------------

function b64u_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_decode(string $txt): string
{
    $t = strtr($txt, '-_', '+/');
    $rest = strlen($t) % 4;
    if ($rest !== 0) $t .= str_repeat('=', 4 - $rest);
    $bin = base64_decode($t, true);
    return $bin === false ? '' : $bin;
}

// ---- CBOR ----------------------------------------------------------------

/**
 * CBOR-Teilmenge dekodieren (RFC 8949).
 *
 * Authenticatoren benutzen nur wenige Typen: ganze Zahlen mit und ohne
 * Vorzeichen, Byte- und Textketten, Listen und Abbildungen. Fließkomma, Tags
 * und unbestimmte Längen kommen nicht vor und werden deshalb abgelehnt, statt
 * halbgar unterstützt zu werden.
 *
 * @param int $pos Leseposition, wird fortgeschrieben
 */
function cbor_decode(string $bin, int &$pos = 0)
{
    if ($pos >= strlen($bin)) throw new RuntimeException('CBOR: unerwartetes Ende.');
    $b = ord($bin[$pos++]);
    $major = $b >> 5;
    $info = $b & 0x1F;

    $laenge = function () use ($bin, &$pos, $info): int {
        if ($info < 24) return $info;
        $n = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new RuntimeException('CBOR: unbestimmte Länge nicht unterstützt.'),
        };
        if ($pos + $n > strlen($bin)) throw new RuntimeException('CBOR: unerwartetes Ende.');
        $wert = 0;
        for ($i = 0; $i < $n; $i++) $wert = ($wert << 8) | ord($bin[$pos++]);
        return $wert;
    };

    switch ($major) {
        case 0: return $laenge();                       // unsigned
        case 1: return -1 - $laenge();                  // negativ
        case 2:                                          // Bytekette
        case 3:                                          // Textkette
            $n = $laenge();
            if ($pos + $n > strlen($bin)) throw new RuntimeException('CBOR: unerwartetes Ende.');
            $s = substr($bin, $pos, $n);
            $pos += $n;
            return $s;
        case 4:                                          // Liste
            $n = $laenge();
            $out = [];
            for ($i = 0; $i < $n; $i++) $out[] = cbor_decode($bin, $pos);
            return $out;
        case 5:                                          // Abbildung
            $n = $laenge();
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $k = cbor_decode($bin, $pos);
                $out[is_int($k) ? $k : (string)$k] = cbor_decode($bin, $pos);
            }
            return $out;
        case 7:
            if ($info === 20) return false;
            if ($info === 21) return true;
            if ($info === 22) return null;
            throw new RuntimeException('CBOR: einfacher Wert nicht unterstützt.');
        default:
            throw new RuntimeException('CBOR: Typ ' . $major . ' nicht unterstützt.');
    }
}

// ---- COSE-Schlüssel in ein von OpenSSL lesbares Format -------------------

/** Länge nach DER kodieren */
function der_len(int $n): string
{
    if ($n < 0x80) return chr($n);
    $b = '';
    while ($n > 0) { $b = chr($n & 0xFF) . $b; $n >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}

/** Ganze Zahl nach DER (führendes Nullbyte, wenn das oberste Bit gesetzt ist) */
function der_int(string $bin): string
{
    $bin = ltrim($bin, "\x00");
    if ($bin === '') $bin = "\x00";
    if (ord($bin[0]) & 0x80) $bin = "\x00" . $bin;
    return "\x02" . der_len(strlen($bin)) . $bin;
}

function der_seq(string $inhalt): string
{
    return "\x30" . der_len(strlen($inhalt)) . $inhalt;
}

function pem(string $der, string $typ = 'PUBLIC KEY'): string
{
    return "-----BEGIN $typ-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $typ-----\n";
}

/**
 * Öffentlichen Schlüssel aus der COSE-Struktur bauen.
 *
 * Unterstützt ES256 (das, was Telefone und Sicherheitsschlüssel praktisch
 * immer liefern) und RS256 (ältere Windows-Hello-Installationen).
 *
 * @return array{0:string,1:int} [PEM, Algorithmus-Kennung]
 */
function cose_to_pem(array $cose): array
{
    $kty = $cose[1] ?? null;   // 1 = kty
    $alg = (int)($cose[3] ?? 0);

    if ($kty === 2 && $alg === -7) {          // EC2 / ES256 über P-256
        if ((int)($cose[-1] ?? 0) !== 1) {
            throw new RuntimeException('Nur die Kurve P-256 wird unterstützt.');
        }
        $x = (string)($cose[-2] ?? '');
        $y = (string)($cose[-3] ?? '');
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Ungültiger EC-Schlüssel.');
        }
        // Fester Kopf für ecPublicKey + prime256v1, danach der unkomprimierte Punkt
        $kopf = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        return [pem((string)$kopf . "\x04" . $x . $y), $alg];
    }

    if ($kty === 3 && $alg === -257) {        // RSA / RS256
        $n = (string)($cose[-1] ?? '');
        $e = (string)($cose[-2] ?? '');
        if ($n === '' || $e === '') throw new RuntimeException('Ungültiger RSA-Schlüssel.');
        $rsa = der_seq(der_int($n) . der_int($e));
        // AlgorithmIdentifier: rsaEncryption + NULL
        $algId = der_seq(hex2bin('06092a864886f70d010101') . "\x05\x00");
        $bits = "\x03" . der_len(strlen($rsa) + 1) . "\x00" . $rsa;
        return [pem(der_seq($algId . $bits)), $alg];
    }

    throw new RuntimeException('Nicht unterstütztes Schlüsselverfahren (' . $alg . ').');
}

// ---- Angaben dieser Instanz ---------------------------------------------

/** Die Domain, an die Passkeys gebunden werden */
function webauthn_rp_id(): string
{
    $host = (string)parse_url(base_url(), PHP_URL_HOST);
    return $host !== '' ? $host : 'localhost';
}

/** Die vollständige Herkunft, gegen die geprüft wird */
function webauthn_origin(): string
{
    $u = parse_url(base_url());
    $o = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? 'localhost');
    if (isset($u['port'])) $o .= ':' . $u['port'];
    return $o;
}

/**
 * Ist WebAuthn hier überhaupt benutzbar?
 *
 * Browser geben Passkeys nur über HTTPS heraus – localhost ausgenommen. Auf
 * einer Instanz ohne TLS hat es keinen Zweck, den Knopf anzubieten.
 */
function webauthn_possible(): bool
{
    if (!function_exists('openssl_verify')) return false;
    $u = parse_url(base_url());
    $host = (string)($u['host'] ?? '');
    return ($u['scheme'] ?? '') === 'https' || $host === 'localhost' || $host === '127.0.0.1';
}

// ---- Ablage am Konto -----------------------------------------------------

/** @return array<int,array{id:string,pubkey:string,alg:int,sign_count:int,label:string,created:string,last_used:?string}> */
function passkeys_of(string $user): array
{
    return array_values((array)(users_all()[$user]['passkeys'] ?? []));
}

function passkeys_active(string $user): bool
{
    return passkeys_of($user) !== [];
}

/**
 * Die Kennung, unter der das Gerät das Konto ablegt.
 *
 * Zufällig und einmal erzeugt, statt aus dem Nutzernamen abgeleitet: Sie liegt
 * am Ende auf fremden Geräten und in Passwortverwaltungen, und sie soll dort
 * nichts über das Konto verraten.
 */
function passkey_user_handle(string $user): string
{
    $u = users_all()[$user] ?? [];
    $h = (string)($u['wa_handle'] ?? '');
    if ($h !== '') return $h;
    $h = b64u_encode(random_bytes(16));
    json_update(users_file(), function (array $users) use ($user, $h) {
        if (!isset($users[$user]) || ($users[$user]['wa_handle'] ?? '') !== '') return null;
        $users[$user]['wa_handle'] = $h;
        return $users;
    });
    return $h;
}

/** Vorgaben für die Einrichtung eines neuen Passkeys */
function passkey_create_options(array $user): array
{
    return [
        'challenge' => webauthn_challenge('reg'),
        'rp' => ['id' => webauthn_rp_id(), 'name' => (string)cfg('site_name')],
        'user' => [
            'id' => passkey_user_handle($user['name']),
            'name' => (string)($user['email'] ?? '') ?: $user['name'],
            'displayName' => $user['name'],
        ],
        // ES256 zuerst, RS256 als Rückfall für ältere Windows-Installationen
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],
            ['type' => 'public-key', 'alg' => -257],
        ],
        // Bereits hinterlegte ausschließen, damit dasselbe Gerät nicht zweimal
        // in der Liste landet
        'excludeCredentials' => array_map(
            fn($p) => ['type' => 'public-key', 'id' => $p['id']],
            passkeys_of($user['name'])
        ),
        'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
        'timeout' => 120000,
        'attestation' => 'none',
    ];
}

/** Vorgaben für eine Anmeldung */
function passkey_request_options(string $user): array
{
    return [
        'challenge' => webauthn_challenge('login'),
        'rpId' => webauthn_rp_id(),
        'allowCredentials' => array_map(
            fn($p) => ['type' => 'public-key', 'id' => $p['id']],
            passkeys_of($user)
        ),
        'userVerification' => 'preferred',
        'timeout' => 120000,
    ];
}

/** Antwort als JSON ausgeben und beenden */
function wa_json(array $daten, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Neue Aufgabe stellen und in der Sitzung hinterlegen */
function webauthn_challenge(string $zweck): string
{
    $c = random_bytes(32);
    $_SESSION['wa_' . $zweck] = ['c' => b64u_encode($c), 't' => time()];
    return b64u_encode($c);
}

/**
 * Gestellte Aufgabe abholen und verbrauchen.
 *
 * Sie gilt fünf Minuten und genau einmal. Ohne das Verbrauchen ließe sich eine
 * einmal abgehörte Antwort erneut einreichen.
 */
function webauthn_take_challenge(string $zweck): ?string
{
    $d = $_SESSION['wa_' . $zweck] ?? null;
    unset($_SESSION['wa_' . $zweck]);
    if (!is_array($d) || (int)($d['t'] ?? 0) < time() - 300) return null;
    return (string)$d['c'];
}

/**
 * Die Angaben des Browsers zur Aufgabe prüfen.
 *
 * Hier sitzen die Schritte 1 und 2: Aufgabe und Herkunft.
 *
 * @return ?string Fehlermeldung oder null
 */
function webauthn_check_clientdata(string $json, string $erwarteterTyp, string $challenge): ?string
{
    $d = json_decode($json, true);
    if (!is_array($d)) return 'Die Angaben des Browsers sind unlesbar.';
    if (($d['type'] ?? '') !== $erwarteterTyp) return 'Unerwarteter Vorgangstyp.';
    // (1) Die Aufgabe muss unsere sein
    if (!hash_equals($challenge, (string)($d['challenge'] ?? ''))) {
        return 'Die Aufgabe stimmt nicht – bitte den Vorgang neu starten.';
    }
    // (2) Die Herkunft muss unsere sein. DIESER Vergleich ist der Grund,
    //     warum ein Passkey auf einer nachgebauten Seite nutzlos ist.
    if (!hash_equals(webauthn_origin(), (string)($d['origin'] ?? ''))) {
        return 'Die Herkunft der Anfrage stimmt nicht.';
    }
    return null;
}

/**
 * Die Daten des Authenticators zerlegen.
 *
 * Aufbau: 32 Byte Abdruck der Domain, 1 Byte Merker, 4 Byte Zähler, danach
 * optional die Angaben zum frisch erzeugten Schlüssel.
 *
 * @return array{rpIdHash:string,flags:int,signCount:int,credId:?string,cose:?array}
 */
function webauthn_parse_authdata(string $bin): array
{
    if (strlen($bin) < 37) throw new RuntimeException('Authenticator-Daten zu kurz.');
    $out = [
        'rpIdHash' => substr($bin, 0, 32),
        'flags' => ord($bin[32]),
        'signCount' => unpack('N', substr($bin, 33, 4))[1],
        'credId' => null,
        'cose' => null,
    ];
    if (($out['flags'] & 0x40) === 0) return $out;   // kein neuer Schlüssel dabei

    if (strlen($bin) < 55) throw new RuntimeException('Authenticator-Daten unvollständig.');
    $len = unpack('n', substr($bin, 53, 2))[1];
    $out['credId'] = substr($bin, 55, $len);
    $pos = 55 + $len;
    $cose = cbor_decode($bin, $pos);
    if (!is_array($cose)) throw new RuntimeException('Schlüssel nicht lesbar.');
    $out['cose'] = $cose;
    return $out;
}

/**
 * Einen neuen Passkey eintragen.
 *
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function passkey_register(string $user, array $antwort, string $label): ?string
{
    $challenge = webauthn_take_challenge('reg');
    if ($challenge === null) return 'Der Vorgang ist abgelaufen – bitte neu beginnen.';

    $clientData = b64u_decode((string)($antwort['clientDataJSON'] ?? ''));
    $err = webauthn_check_clientdata($clientData, 'webauthn.create', $challenge);
    if ($err !== null) return $err;

    try {
        $att = cbor_decode(b64u_decode((string)($antwort['attestationObject'] ?? '')));
        if (!is_array($att) || !isset($att['authData'])) return 'Antwort des Geräts unlesbar.';
        $auth = webauthn_parse_authdata((string)$att['authData']);
        // (3) Der Abdruck der Domain muss zu unserer passen
        if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $auth['rpIdHash'])) {
            return 'Der Passkey gehört zu einer anderen Adresse.';
        }
        if (($auth['flags'] & 0x01) === 0) return 'Das Gerät hat die Anwesenheit nicht bestätigt.';
        if ($auth['credId'] === null || $auth['cose'] === null) return 'Es wurde kein Schlüssel geliefert.';
        [$pemKey, $alg] = cose_to_pem($auth['cose']);
    } catch (Throwable $e) {
        return 'Antwort des Geräts nicht verwertbar: ' . $e->getMessage();
    }

    $id = b64u_encode($auth['credId']);
    $doppelt = false;
    json_update(users_file(), function (array $users) use ($user, $id, $pemKey, $alg, $auth, $label, &$doppelt) {
        if (!isset($users[$user])) return null;
        $liste = (array)($users[$user]['passkeys'] ?? []);
        foreach ($liste as $p) {
            if (($p['id'] ?? '') === $id) { $doppelt = true; return null; }
        }
        if (count($liste) >= 10) { $doppelt = true; return null; }
        $liste[] = [
            'id' => $id,
            'pubkey' => $pemKey,
            'alg' => $alg,
            'sign_count' => (int)$auth['signCount'],
            'label' => mb_substr(trim($label), 0, 60) ?: 'Passkey',
            'created' => date('c'),
            'last_used' => null,
        ];
        $users[$user]['passkeys'] = array_values($liste);
        return $users;
    });
    return $doppelt ? 'Dieser Passkey ist bereits hinterlegt (oder es sind schon zehn).' : null;
}

/**
 * Eine Anmeldung mit Passkey prüfen.
 *
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function passkey_verify(string $user, array $antwort): ?string
{
    $challenge = webauthn_take_challenge('login');
    if ($challenge === null) return 'Der Vorgang ist abgelaufen – bitte neu anmelden.';

    $id = (string)($antwort['id'] ?? '');
    $treffer = null;
    foreach (passkeys_of($user) as $p) {
        if (hash_equals((string)$p['id'], $id)) { $treffer = $p; break; }
    }
    if ($treffer === null) return 'Dieser Passkey gehört nicht zu diesem Konto.';

    $clientData = b64u_decode((string)($antwort['clientDataJSON'] ?? ''));
    $err = webauthn_check_clientdata($clientData, 'webauthn.get', $challenge);
    if ($err !== null) return $err;

    $authRaw = b64u_decode((string)($antwort['authenticatorData'] ?? ''));
    try {
        $auth = webauthn_parse_authdata($authRaw);
    } catch (Throwable $e) {
        return 'Antwort des Geräts nicht verwertbar.';
    }
    // (3) Abdruck der Domain
    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $auth['rpIdHash'])) {
        return 'Der Passkey gehört zu einer anderen Adresse.';
    }
    if (($auth['flags'] & 0x01) === 0) return 'Das Gerät hat die Anwesenheit nicht bestätigt.';

    // (4) Die Unterschrift muss zum hinterlegten Schlüssel passen
    $signiert = $authRaw . hash('sha256', $clientData, true);
    $sig = b64u_decode((string)($antwort['signature'] ?? ''));
    $ok = openssl_verify($signiert, $sig, (string)$treffer['pubkey'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return 'Die Unterschrift stimmt nicht.';

    // Der Zähler darf nicht zurücklaufen: Täte er das, wäre der Schlüssel
    // vermutlich kopiert worden. Viele Geräte zählen gar nicht (dann bleibt er
    // bei 0) – nur ein echter Rückschritt ist verdächtig.
    $alt = (int)($treffer['sign_count'] ?? 0);
    $neu = (int)$auth['signCount'];
    if ($alt > 0 && $neu > 0 && $neu <= $alt) {
        return 'Der Passkey wurde möglicherweise kopiert – bitte einen neuen einrichten.';
    }

    json_update(users_file(), function (array $users) use ($user, $id, $neu) {
        if (!isset($users[$user]['passkeys'])) return null;
        foreach ($users[$user]['passkeys'] as $i => $p) {
            if (($p['id'] ?? '') === $id) {
                $users[$user]['passkeys'][$i]['sign_count'] = $neu;
                $users[$user]['passkeys'][$i]['last_used'] = date('c');
                return $users;
            }
        }
        return null;
    });
    return null;
}

/** Passkey entfernen */
function passkey_remove(string $user, string $id): bool
{
    $weg = false;
    json_update(users_file(), function (array $users) use ($user, $id, &$weg) {
        $liste = (array)($users[$user]['passkeys'] ?? []);
        $neu = array_values(array_filter($liste, fn($p) => ($p['id'] ?? '') !== $id));
        if (count($neu) === count($liste)) return null;
        $weg = true;
        if ($neu === []) unset($users[$user]['passkeys']); else $users[$user]['passkeys'] = $neu;
        return $users;
    });
    return $weg;
}

/** Alle Passkeys eines Kontos entfernen */
function passkeys_drop_user(string $user): void
{
    json_update(users_file(), function (array $users) use ($user) {
        if (!isset($users[$user]['passkeys'])) return null;
        unset($users[$user]['passkeys']);
        return $users;
    });
}
