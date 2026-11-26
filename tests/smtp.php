<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft den SMTP-Client gegen nachgestellte Server.
 *
 * Der Anlass: Der Client verlangte früher STARTTLS und AUTH LOGIN
 * bedingungslos. Gegen die Relays der Anbieter geht das gut – gegen ein
 * hausinternes Relay auf Port 25, das nach IP freigeschaltet ist und weder
 * das eine noch das andere kennt, scheiterte jeder Versand. Genau so eine
 * Instanz stand an, als das auffiel.
 *
 * Getestet wird deshalb nicht der Versand als solcher, sondern das
 * Aushandeln: Wird STARTTLS genommen, wenn es angeboten wird? Wird es
 * ausgelassen, wenn nicht? Bleibt AUTH weg, wenn keine Zugangsdaten
 * hinterlegt sind? Und – der Fall, der wirklich zählt – bricht der Client ab,
 * bevor er ein Passwort unverschlüsselt über das Netz schickt?
 *
 * Der nachgestellte Server spricht nur so viel SMTP, wie dafür nötig ist.
 * TLS wird nicht wirklich ausgehandelt: Ein Testfall, der bis zum Handshake
 * kommt, gilt als bestanden – ab dort ist es die Sache von OpenSSL.
 *
 * Aufruf:
 *   php tests/smtp.php
 */

// Nur auf der Kommandozeile – wie bei tools/*.php. Ohne diesen Riegel ließe
// sich das Skript über den Webserver anstoßen, wenn tests/ versehentlich mit
// ins Webroot geladen wurde. Bei dieser Datei hieße das: Jemand legt von außen
// ein Admin-Konto an, dessen Passwort im Quelltext steht.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur auf der Kommandozeile.\n");
}
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/mail.php';

$fehler = 0;

/**
 * Startet einen nachgestellten SMTP-Server in einem Unterprozess.
 *
 * Bis 5.6.1 schlief der Test nach dem Start fest 400 ms, nahm einen
 * zufälligen Port und las das Protokoll, sobald der Client fertig war. Das
 * ging etwa jedes vierte Mal schief: Der Server schreibt sein Protokoll erst
 * nach dem letzten Befehl, und der Test las es, bevor der Prozess so weit
 * war. Jetzt sucht sich der Server einen freien Port, meldet „BEREIT <port>",
 * sobald er lauscht, und server_protokoll() wartet, bis er beendet ist.
 *
 * @param bool $starttls Bietet der Server STARTTLS an?
 * @param bool $auth     Bietet er AUTH an?
 * @return array{0:int,1:array} Port und Server (für server_protokoll())
 */
function server_start(bool $starttls, bool $auth): array
{
    $log = tempnam(sys_get_temp_dir(), 'smtplog');
    $code = <<<'PHPEOF'
$starttls = $argv[1] === '1'; $auth = $argv[2] === '1'; $log = $argv[3];
// Port 0: Das Betriebssystem vergibt einen freien – kein Zusammenstoß mit
// einem anderen Prozess, der zufällig denselben Port hält.
$srv = @stream_socket_server('tcp://127.0.0.1:0', $e, $s);
if ($srv === false) { file_put_contents($log, "LISTEN-FEHLER $s"); exit(1); }
$name = (string)stream_socket_get_name($srv, false);
fwrite(STDOUT, 'BEREIT ' . substr($name, strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);
$c = @stream_socket_accept($srv, 10);
if ($c === false) { file_put_contents($log, 'KEINE VERBINDUNG'); exit(1); }
$mit = []; $imBody = false;
fwrite($c, "220 testserver bereit\r\n");
while (($line = fgets($c, 2048)) !== false) {
    // Nach DATA schweigt der Server, bis die Zeile mit dem einzelnen Punkt
    // kommt – sonst beantwortet er jede Body-Zeile und alles gerät aus dem Tritt.
    if ($imBody) {
        if (rtrim($line) === '.') { $imBody = false; fwrite($c, "250 angenommen\r\n"); }
        continue;
    }
    $mit[] = rtrim($line);
    $b = strtoupper(substr($line, 0, 4));
    if ($b === 'EHLO') {
        $z = ['250-testserver'];
        if ($starttls) $z[] = '250-STARTTLS';
        if ($auth) $z[] = '250-AUTH LOGIN PLAIN';
        $z[] = '250 8BITMIME';
        fwrite($c, implode("\r\n", $z) . "\r\n");
    } elseif ($b === 'STAR') {
        fwrite($c, "220 los\r\n");
        break;   // ab hier spräche echtes TLS – für den Test genügt das
    } elseif ($b === 'AUTH') { fwrite($c, "334 VXNlcm5hbWU6\r\n");
    } elseif ($b === 'MAIL' || $b === 'RCPT') { fwrite($c, "250 ok\r\n");
    } elseif ($b === 'DATA') { $imBody = true; fwrite($c, "354 los\r\n");
    } elseif ($b === 'QUIT') { fwrite($c, "221 tschuess\r\n"); break;
    } else { fwrite($c, "250 ok\r\n"); }
}
file_put_contents($log, implode("\n", $mit));
PHPEOF;
    $datei = tempnam(sys_get_temp_dir(), 'smtpsrv') . '.php';
    file_put_contents($datei, "<?php\n" . $code);
    $proc = proc_open([PHP_BINARY, $datei, $starttls ? '1' : '0', $auth ? '1' : '0', $log],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) exit("Testserver ließ sich nicht starten.\n");
    // Warten, bis er lauscht – höchstens fünf Sekunden, dann ist etwas faul.
    $lesen = [$pipes[1]]; $nichts = null;
    $zeile = stream_select($lesen, $nichts, $nichts, 5) === 1 ? (string)fgets($pipes[1]) : '';
    if (!preg_match('/^BEREIT (\d+)/', $zeile, $m)) {
        exit("Testserver meldet sich nicht bereit: " . trim($zeile . ' ' . (string)@file_get_contents($log)) . "\n");
    }
    return [(int)$m[1], ['proc' => $proc, 'pipe' => $pipes[1], 'log' => $log, 'datei' => $datei]];
}

/**
 * Das Protokoll des Testservers – erst, wenn er fertig ist. Er schreibt es
 * nach dem letzten Befehl (QUIT, STARTTLS oder Verbindungsende) und beendet
 * sich dann; wer vorher liest, findet eine leere Datei.
 */
function server_protokoll(array $server): string
{
    $bis = microtime(true) + 5;
    while (proc_get_status($server['proc'])['running'] && microtime(true) < $bis) usleep(20000);
    if (proc_get_status($server['proc'])['running']) proc_terminate($server['proc']);
    fclose($server['pipe']);
    proc_close($server['proc']);
    $mit = (string)file_get_contents($server['log']);
    @unlink($server['log']);
    @unlink($server['datei']);
    return $mit;
}

function pruefe(string $was, bool $ok, string $detail = ''): void
{
    global $fehler;
    if (!$ok) $fehler++;
    printf("  %s %s%s\n", $ok ? '✓' : '✗', $was, $detail !== '' ? "\n      $detail" : '');
}

echo "SMTP-Client: handelt er Verschlüsselung und Anmeldung richtig aus?\n\n";

// ---- 1. Hausinternes Relay: kein STARTTLS, kein AUTH, keine Zugangsdaten ----
[$port, $server] = server_start(false, false);
$fall = 'Relay ohne TLS und ohne Anmeldung';
try {
    smtp_send(['host' => '127.0.0.1', 'port' => $port, 'user' => '', 'pass' => '',
        'from' => 'admin@example.org', 'from_name' => 'Test'], 'wer@example.org', 'Betreff', 'Text');
    $mit = server_protokoll($server);
    pruefe($fall, strpos($mit, 'AUTH') === false && strpos($mit, 'STARTTLS') === false
        && strpos($mit, 'MAIL FROM') !== false,
        'Gesendet: ' . str_replace("\n", ' | ', $mit));
} catch (Throwable $e) {
    pruefe($fall, false, $e->getMessage() . ' | Gesendet: ' . str_replace("\n", ' | ', server_protokoll($server)));
}

// ---- 2. Anbieter-Relay: STARTTLS wird angeboten, also genommen ----
[$port, $server] = server_start(true, true);
$fall = 'Server bietet STARTTLS an – Client nimmt es';
try {
    smtp_send(['host' => '127.0.0.1', 'port' => $port, 'user' => '', 'pass' => '',
        'from' => 'admin@example.org', 'from_name' => 'Test'], 'wer@example.org', 'Betreff', 'Text');
    pruefe($fall, false, 'Kein TLS-Fehler – der Client hat STARTTLS übersprungen. Gesendet: '
        . str_replace("\n", ' | ', server_protokoll($server)));
} catch (Throwable $e) {
    // Der Testserver bricht nach 220 ab; ein Handshake-Fehler ist hier der Beweis
    $mit = server_protokoll($server);
    pruefe($fall, strpos($mit, 'STARTTLS') !== false,
        strpos($mit, 'STARTTLS') !== false ? '' : 'Gesendet: ' . str_replace("\n", ' | ', $mit));
}

// ---- 3. Zugangsdaten ohne Verschlüsselung: muss abbrechen ----
[$port, $server] = server_start(false, true);
$fall = 'Zugangsdaten ohne STARTTLS – Abbruch statt Klartext-Passwort';
try {
    smtp_send(['host' => '127.0.0.1', 'port' => $port, 'user' => 'konto', 'pass' => 'geheim',
        'from' => 'admin@example.org', 'from_name' => 'Test'], 'wer@example.org', 'Betreff', 'Text');
    pruefe($fall, false, 'Kein Abbruch – das Passwort wäre im Klartext gegangen. Gesendet: '
        . str_replace("\n", ' | ', server_protokoll($server)));
} catch (Throwable $e) {
    $mit = server_protokoll($server);
    pruefe($fall, strpos($mit, 'AUTH') === false && strpos($e->getMessage(), 'STARTTLS') !== false,
        strpos($mit, 'AUTH') === false ? '' : 'AUTH wurde trotzdem gesendet!');
}

echo "\n", $fehler === 0
    ? "Alle Prüfungen bestanden.\n"
    : "$fehler Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
