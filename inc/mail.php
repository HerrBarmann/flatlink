<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/helpers.php';

/**
 * Plaintext-Mail versenden gemäß cfg('mail').
 * mode 'log':  schreibt nach data/mail.log (Entwicklung – nichts verlässt den Server).
 * mode 'smtp': eigener Client. STARTTLS, sobald der Server es anbietet; AUTH
 *              nur mit hinterlegten Zugangsdaten. Damit laufen sowohl
 *              Anbieter-Relays (Brevo, Postmark, SES) als auch hausinterne
 *              Relays auf Port 25, die nach IP freigeschaltet sind.
 *
 * Gibt false zurück, wenn der Versand scheitert – Aufrufer entscheiden, was sie
 * dem Nutzer sagen (nie den Fehlertext des Servers durchreichen).
 */
/**
 * Absolute Adresse für einen Link in einer Mail.
 *
 * Nutzt ausschließlich die konfigurierte base_url. Grund: Ohne sie würde die
 * Adresse aus dem Host-Header des Auslösers gebaut – wer eine
 * Passwort-vergessen-Mail für ein fremdes Konto anstößt, könnte den Link
 * damit auf die eigene Domain zeigen lassen und den Token abgreifen.
 *
 * @return ?string null, wenn keine Basis-URL konfiguriert ist
 */
function mail_link(string $path = ''): ?string
{
    $base = base_url(true);
    if ($base === '') return null;
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function mail_send(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) return false;
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) $replyTo = null;
    $cfg = cfg('mail');

    if (($cfg['mode'] ?? 'log') !== 'smtp') {
        $entry = '=== ' . date('c') . " ===\nAn: $to\n" . ($replyTo !== null ? "Reply-To: $replyTo\n" : '')
            . "Betreff: $subject\n\n$body\n\n";
        file_put_contents(data_path() . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
        return true;
    }

    try {
        smtp_send($cfg, $to, $subject, $body, $replyTo);
        return true;
    } catch (Throwable $e) {
        error_log('mail_send an ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Minimaler SMTP-Client – bewusst ohne Composer-Abhängigkeit.
 *
 * Verschlüsselung und Anmeldung richten sich nach dem, was der Server
 * ankündigt und was konfiguriert ist:
 *
 * * **STARTTLS**, sobald der Server es anbietet. Ein Anbieter-Relay auf Port
 *   587 kann es immer; ein hausinternes Relay auf Port 25, das nach IP
 *   freigeschaltet ist, oft nicht. Beides muss funktionieren – deshalb wird
 *   die EHLO-Antwort gelesen, statt STARTTLS blind zu verlangen.
 * * **AUTH** nur, wenn Zugangsdaten hinterlegt sind. Ohne Passwort gibt es
 *   nichts anzumelden, und ein AUTH ins Leere beendet die Sitzung.
 *
 * Eine Ausnahme davon gibt es nicht: Sind Zugangsdaten gesetzt, der Server
 * kann aber kein STARTTLS, wird abgebrochen. Ein Passwort im Klartext über
 * das Netz zu schicken, ist kein Kompromiss, den ein Programm still eingehen
 * darf.
 */
function smtp_send(array $cfg, string $to, string $subject, string $body, ?string $replyTo = null): void
{
    $sock = @stream_socket_client('tcp://' . $cfg['host'] . ':' . (int)$cfg['port'], $errno, $errstr, 10);
    if ($sock === false) throw new RuntimeException("SMTP-Verbindung: $errstr ($errno)");
    stream_set_timeout($sock, 15);

    // Antwort lesen (Mehrzeiler "250-…" bis zur Zeile "250 …")
    $read = function () use ($sock): string {
        do {
            $line = fgets($sock, 1024);
            if ($line === false) throw new RuntimeException('SMTP: Verbindung abgerissen');
        } while (isset($line[3]) && $line[3] === '-');
        return $line;
    };
    $expect = function (string $resp, array $codes, string $ctx) {
        if (!in_array((int)substr($resp, 0, 3), $codes, true)) {
            throw new RuntimeException("SMTP $ctx: " . trim($resp));
        }
    };
    $cmd = function (string $c, array $codes, string $ctx) use ($sock, $read, $expect): void {
        fwrite($sock, $c . "\r\n");
        $expect($read(), $codes, $ctx);
    };
    // EHLO vollständig lesen: Die Fähigkeiten des Servers stehen in den
    // Zwischenzeilen, die $read() sonst verwirft.
    $ehlo = function (string $helo) use ($sock, $expect): string {
        fwrite($sock, 'EHLO ' . $helo . "\r\n");
        $alles = '';
        do {
            $line = fgets($sock, 1024);
            if ($line === false) throw new RuntimeException('SMTP: Verbindung abgerissen');
            $alles .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $expect($line, [250], 'EHLO');
        return strtoupper($alles);
    };

    $helo = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    $mitAnmeldung = (string)($cfg['user'] ?? '') !== '';
    $expect($read(), [220], 'Begrüßung');
    $faehig = $ehlo($helo);

    if (strpos($faehig, 'STARTTLS') !== false) {
        $cmd('STARTTLS', [220], 'STARTTLS');
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP: TLS-Handshake fehlgeschlagen');
        }
        // Nach dem Wechsel gilt die alte Fähigkeitenliste nicht mehr
        $faehig = $ehlo($helo);
    } elseif ($mitAnmeldung) {
        throw new RuntimeException(
            'SMTP: Der Server bietet kein STARTTLS an, es sind aber Zugangsdaten '
            . 'hinterlegt. Das Passwort ginge im Klartext über das Netz. Entweder '
            . 'einen Port mit Verschlüsselung nehmen (meist 587) oder – bei einem '
            . 'hausinternen Relay, das nach IP freigegeben ist – user und pass leer lassen.');
    }

    if ($mitAnmeldung) {
        if (strpos($faehig, 'AUTH') === false) {
            throw new RuntimeException('SMTP: Der Server kennt kein AUTH, es sind aber Zugangsdaten hinterlegt.');
        }
        $cmd('AUTH LOGIN', [334], 'AUTH');
        $cmd(base64_encode((string)$cfg['user']), [334], 'AUTH Nutzer');
        $cmd(base64_encode((string)($cfg['pass'] ?? '')), [235], 'AUTH Passwort');
    }
    $cmd('MAIL FROM:<' . $cfg['from'] . '>', [250], 'MAIL FROM');
    $cmd('RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
    $cmd('DATA', [354], 'DATA');

    // Betreff und Body Base64-kodiert: UTF-8-sicher und kein Dot-Stuffing nötig
    $headers = 'From: =?UTF-8?B?' . base64_encode($cfg['from_name']) . '?= <' . $cfg['from'] . ">\r\n"
        . "To: <$to>\r\n"
        . ($replyTo !== null ? "Reply-To: <$replyTo>\r\n" : '')
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $helo . ">\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n";
    fwrite($sock, $headers . "\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . ".\r\n");
    $expect($read(), [250], 'Zustellung');
    $cmd('QUIT', [221], 'QUIT');
    fclose($sock);
}
