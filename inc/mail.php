<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/helpers.php';

/**
 * Plaintext-Mail versenden gemäß cfg('mail').
 * mode 'log':  schreibt nach data/mail.log (Entwicklung – nichts verlässt den Server).
 * mode 'smtp': STARTTLS + AUTH LOGIN, funktioniert mit Brevo, Postmark, SES & Co.
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

/** Minimaler SMTP-Client (STARTTLS, AUTH LOGIN) – bewusst ohne Composer-Abhängigkeit. */
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

    $helo = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
    $expect($read(), [220], 'Begrüßung');
    $cmd('EHLO ' . $helo, [250], 'EHLO');
    $cmd('STARTTLS', [220], 'STARTTLS');
    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        throw new RuntimeException('SMTP: TLS-Handshake fehlgeschlagen');
    }
    $cmd('EHLO ' . $helo, [250], 'EHLO (TLS)');
    $cmd('AUTH LOGIN', [334], 'AUTH');
    $cmd(base64_encode($cfg['user']), [334], 'AUTH Nutzer');
    $cmd(base64_encode($cfg['pass']), [235], 'AUTH Passwort');
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
