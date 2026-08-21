<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
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
 *   3. Der Hash der Domain (rpIdHash) muss zu unserer Domain passen.
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
    return array_values((array)(user_get($user)['passkeys'] ?? []));
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
    $u = user_get($user) ?? [];
    $h = (string)($u['wa_handle'] ?? '');
    if ($h !== '') return $h;
    $h = b64u_encode(random_bytes(16));
    users_update(function (array $users) use ($user, $h) {
        if (!isset($users[$user]) || ($users[$user]['wa_handle'] ?? '') !== '') return null;
        $users[$user]['wa_handle'] = $h;
        return $users;
    }, $user);
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
        // „required" bei der Nutzerprüfung, seit der Passkey das Passwort
        // ersetzen darf: Ein Gerät, das nicht nachfragt, wer es gerade
        // benutzt, käme bei der Anmeldung ohnehin nicht durch — dann lieber
        // schon beim Einrichten sagen als hinterher.
        // Auffindbar („resident") nur bevorzugt: Sicherheitsschlüssel haben
        // dafür begrenzt Platz, und ohne Auffindbarkeit funktioniert alles
        // außer dem Vorschlag im Namensfeld weiterhin.
        'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
        'timeout' => 120000,
        'attestation' => 'none',
    ];
}

/** Vorgaben für eine Anmeldung */
function passkey_request_options(string $user, bool $alsPasswortersatz = false): array
{
    return [
        'challenge' => webauthn_challenge('login'),
        'rpId' => webauthn_rp_id(),
        'allowCredentials' => array_map(
            fn($p) => ['type' => 'public-key', 'id' => $p['id']],
            passkeys_of($user)
        ),
        // Als ZWEITE Stufe genügt „preferred": Das Passwort war der Nachweis
        // des Wissens, der Passkey belegt den Besitz.
        //
        // Als ERSATZ fürs Passwort muss der Passkey beides allein tragen, und
        // dafür ist die Nutzerprüfung Pflicht — sonst reicht der bloße Besitz
        // des entsperrten Geräts, und ein liegengelassenes Telefon wäre die
        // ganze Anmeldung. Genau hierin unterscheiden sich die beiden Wege.
        'userVerification' => $alsPasswortersatz ? 'required' : 'preferred',
        'timeout' => 120000,
    ];
}

/**
 * Vorgaben für eine Anmeldung, bei der die Kennung noch nicht feststeht.
 *
 * Das ist der Weg, den der Browser im Namensfeld anbietet („conditional
 * mediation"): Ohne `allowCredentials` sucht das Gerät selbst nach einem
 * passenden Passkey für diese Adresse. Wer das Konto ist, sagt uns hinterher
 * das Gerät über das `userHandle` in seiner Antwort.
 *
 * Voraussetzung ist ein auffindbarer Passkey. Gibt es keinen, passiert
 * schlicht nichts — der Weg über Kennung und Passwort bleibt daneben offen.
 */
function passkey_any_request_options(): array
{
    return [
        'challenge' => webauthn_challenge('login'),
        'rpId' => webauthn_rp_id(),
        'allowCredentials' => [],
        'userVerification' => 'required',
        'timeout' => 120000,
    ];
}

/**
 * Das Konto zu einem Geräte-Handle finden.
 *
 * Nur für den Weg oben. Das Handle ist eine Zufallsfolge, die wir selbst
 * vergeben haben — es zu kennen ist kein Nachweis. Der Nachweis ist die
 * Unterschrift, die passkey_verify() danach gegen den hinterlegten Schlüssel
 * dieses Kontos prüft.
 */
function passkey_user_by_handle(string $handle): ?string
{
    if ($handle === '') return null;
    foreach (users_all() as $name => $u) {
        $h = (string)($u['wa_handle'] ?? '');
        if ($h !== '' && hash_equals($h, $handle) && (array)($u['passkeys'] ?? []) !== []) {
            return (string)$name;
        }
    }
    return null;
}

// ---- Der Hinweis nach der Anmeldung --------------------------------------
//
// Ein Passkey nützt nur dem, der einen hat. Im Profil steht er seit je, aber
// dorthin geht selten jemand ohne Anlass — deshalb bietet ihn die Anmeldung
// von sich aus an. Einmal im Monat, nicht öfter: Ein Vorschlag, den man
// dreimal die Woche wegklickt, ist kein Vorschlag mehr, sondern eine Tür, die
// klemmt.
//
// Der Stand steht am Konto (`pk_hint`): ein Datum oder „nie".

/**
 * Ist es Zeit, diesem Konto einen Passkey anzubieten?
 *
 * Nimmt die Kennung, nicht den Datensatz: user_get() liefert das Konto ohne
 * sein eigenes `name`-Feld, auth_user() dagegen mit. Wer beides annimmt, prüft
 * je nach Aufrufer etwas anderes – und übersieht dann still, dass längst ein
 * Passkey hinterlegt ist.
 */
function passkey_hint_due(string $user): bool
{
    $u = user_get($user);
    if ($u === null) return false;

    // Leer heißt „nicht gesetzt" – etwa auf einer Instanz, deren
    // inc/config.php älter ist als diese Fassung.
    $modus = (string)(settings()['passkey_hint'] ?? '') ?: 'on';
    if ($modus === 'off') return false;
    // 'local': Zentral verwaltete Konten bleiben außen vor. Wo die Anmeldung
    // am Verzeichnis hängen soll, wäre ein Passkey ein zweiter Schlüssel,
    // den das Verzeichnis nicht kennt.
    if ($modus === 'local' && ($u['auth'] ?? 'local') !== 'local') return false;

    // Ohne HTTPS geht es technisch nicht – dann auch nichts versprechen.
    if (!webauthn_possible()) return false;
    if ((array)($u['passkeys'] ?? []) !== []) return false;

    $stand = (string)($u['pk_hint'] ?? '');
    if ($stand === 'nie') return false;
    if ($stand === '') return true;              // noch nie gefragt
    $zeit = strtotime($stand);
    return $zeit === false || $zeit < time() - 30 * 86400;
}

/**
 * Vermerken, dass gefragt wurde.
 *
 * Gesetzt wird beim ANZEIGEN, nicht beim Wegklicken: Wer die Seite schließt,
 * hat die Frage gesehen und soll morgen nicht wieder dieselbe bekommen.
 */
function passkey_hint_seen(string $user, bool $nie = false): void
{
    users_update(function (array $users) use ($user, $nie) {
        if (!isset($users[$user])) return null;
        $users[$user]['pk_hint'] = $nie ? 'nie' : date('c');
        return $users;
    }, $user);
}

/**
 * Wohin nach einer erfolgreichen Anmeldung?
 *
 * An einer Stelle, damit kein Anmeldeweg den Hinweis versehentlich
 * überspringt – und keiner ihn versehentlich doppelt zeigt.
 */
function login_ziel(string $user): string
{
    return passkey_hint_due($user) ? 'passkey.php' : 'index.php';
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
    if (!is_array($d)) return t('Die Angaben des Browsers sind unlesbar.');
    if (($d['type'] ?? '') !== $erwarteterTyp) return t('Unerwarteter Vorgangstyp.');
    // (1) Die Aufgabe muss unsere sein
    if (!hash_equals($challenge, (string)($d['challenge'] ?? ''))) {
        return t('Die Aufgabe stimmt nicht – bitte den Vorgang neu starten.');
    }
    // (2) Die Herkunft muss unsere sein. DIESER Vergleich ist der Grund,
    //     warum ein Passkey auf einer nachgebauten Seite nutzlos ist.
    if (!hash_equals(webauthn_origin(), (string)($d['origin'] ?? ''))) {
        return t('Die Herkunft der Anfrage stimmt nicht.');
    }
    return null;
}

/**
 * Die Daten des Authenticators zerlegen.
 *
 * Aufbau: 32 Byte Hash der Domain, 1 Byte Merker, 4 Byte Zähler, danach
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
    if ($challenge === null) return t('Der Vorgang ist abgelaufen – bitte neu beginnen.');

    $clientData = b64u_decode((string)($antwort['clientDataJSON'] ?? ''));
    $err = webauthn_check_clientdata($clientData, 'webauthn.create', $challenge);
    if ($err !== null) return $err;

    try {
        $att = cbor_decode(b64u_decode((string)($antwort['attestationObject'] ?? '')));
        if (!is_array($att) || !isset($att['authData'])) return t('Antwort des Geräts unlesbar.');
        $auth = webauthn_parse_authdata((string)$att['authData']);
        // (3) Der Hash der Domain muss zu unserer passen
        if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $auth['rpIdHash'])) {
            return t('Der Passkey gehört zu einer anderen Adresse.');
        }
        if (($auth['flags'] & 0x01) === 0) return t('Das Gerät hat die Anwesenheit nicht bestätigt.');
        if ($auth['credId'] === null || $auth['cose'] === null) return t('Es wurde kein Schlüssel geliefert.');
        [$pemKey, $alg] = cose_to_pem($auth['cose']);
    } catch (Throwable $e) {
        return t('Antwort des Geräts nicht verwertbar:') . ' ' . $e->getMessage();
    }

    $id = b64u_encode($auth['credId']);
    $doppelt = false;
    users_update(function (array $users) use ($user, $id, $pemKey, $alg, $auth, $label, &$doppelt) {
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
            // Tagesgenau bzw. stundengenau – siehe Datenschutzerklärung:
            // Es wird nicht feiner gespeichert, als dort zugesagt ist.
            'created' => date('Y-m-d'),
            'last_used' => null,
        ];
        $users[$user]['passkeys'] = array_values($liste);
        return $users;
    }, $user);
    return $doppelt ? 'Dieser Passkey ist bereits hinterlegt (oder es sind schon zehn).' : null;
}

/**
 * Eine Anmeldung mit Passkey prüfen.
 *
 * @return ?string Fehlermeldung oder null bei Erfolg
 */
function passkey_verify(string $user, array $antwort, bool $alsPasswortersatz = false): ?string
{
    $challenge = webauthn_take_challenge('login');
    if ($challenge === null) return t('Der Vorgang ist abgelaufen – bitte neu anmelden.');

    $id = (string)($antwort['id'] ?? '');
    $treffer = null;
    foreach (passkeys_of($user) as $p) {
        if (hash_equals((string)$p['id'], $id)) { $treffer = $p; break; }
    }
    if ($treffer === null) return t('Dieser Passkey gehört nicht zu diesem Konto.');

    $clientData = b64u_decode((string)($antwort['clientDataJSON'] ?? ''));
    $err = webauthn_check_clientdata($clientData, 'webauthn.get', $challenge);
    if ($err !== null) return $err;

    $authRaw = b64u_decode((string)($antwort['authenticatorData'] ?? ''));
    try {
        $auth = webauthn_parse_authdata($authRaw);
    } catch (Throwable $e) {
        return t('Antwort des Geräts nicht verwertbar.');
    }
    // (3) Hash der Domain
    if (!hash_equals(hash('sha256', webauthn_rp_id(), true), $auth['rpIdHash'])) {
        return t('Der Passkey gehört zu einer anderen Adresse.');
    }
    if (($auth['flags'] & 0x01) === 0) return t('Das Gerät hat die Anwesenheit nicht bestätigt.');
    // Bit 0x04 = User Verified: Das Gerät hat Fingerabdruck, Gesicht oder PIN
    // geprüft. Ohne diesen Nachweis ist ein Passkey nur ein Besitzfaktor —
    // als Passwortersatz zu wenig, als zweite Stufe genau richtig.
    if ($alsPasswortersatz && ($auth['flags'] & 0x04) === 0) {
        return t('Dieses Gerät hat nicht geprüft, wer es benutzt. Für die Anmeldung ohne Passwort ist das nötig – bitte Fingerabdruck, Gesichtserkennung oder Geräte-PIN einrichten.');
    }

    // (4) Die Unterschrift muss zum hinterlegten Schlüssel passen
    $signiert = $authRaw . hash('sha256', $clientData, true);
    $sig = b64u_decode((string)($antwort['signature'] ?? ''));
    $ok = openssl_verify($signiert, $sig, (string)$treffer['pubkey'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return t('Die Unterschrift stimmt nicht.');

    // Der Zähler darf nicht zurücklaufen: Täte er das, wäre der Schlüssel
    // vermutlich kopiert worden. Viele Geräte zählen gar nicht (dann bleibt er
    // bei 0) – nur ein echter Rückschritt ist verdächtig.
    $alt = (int)($treffer['sign_count'] ?? 0);
    $neu = (int)$auth['signCount'];
    if ($alt > 0 && $neu > 0 && $neu <= $alt) {
        return t('Der Passkey wurde möglicherweise kopiert – bitte einen neuen einrichten.');
    }

    users_update(function (array $users) use ($user, $id, $neu) {
        if (!isset($users[$user]['passkeys'])) return null;
        foreach ($users[$user]['passkeys'] as $i => $p) {
            if (($p['id'] ?? '') === $id) {
                $stunde = date('Y-m-d\TH:00:00P');
                // Gleicher Zähler, gleiche Stunde: nichts zu schreiben. Das
                // erspart nebenbei einen Konto-Schreibzugriff je Anmeldung.
                if ((int)($p['sign_count'] ?? 0) === $neu && ($p['last_used'] ?? '') === $stunde) {
                    return null;
                }
                $users[$user]['passkeys'][$i]['sign_count'] = $neu;
                $users[$user]['passkeys'][$i]['last_used'] = $stunde;
                return $users;
            }
        }
        return null;
    }, $user);
    return null;
}

/** Passkey entfernen */
function passkey_remove(string $user, string $id): bool
{
    $weg = false;
    users_update(function (array $users) use ($user, $id, &$weg) {
        $liste = (array)($users[$user]['passkeys'] ?? []);
        $neu = array_values(array_filter($liste, fn($p) => ($p['id'] ?? '') !== $id));
        if (count($neu) === count($liste)) return null;
        $weg = true;
        if ($neu === []) unset($users[$user]['passkeys']); else $users[$user]['passkeys'] = $neu;
        return $users;
    }, $user);
    return $weg;
}

/** Alle Passkeys eines Kontos entfernen */
function passkeys_drop_user(string $user): void
{
    users_update(function (array $users) use ($user) {
        if (!isset($users[$user]['passkeys'])) return null;
        unset($users[$user]['passkeys']);
        return $users;
    }, $user);
}
