<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/bio.php';

$code = $_GET['c'] ?? '';
if (!is_string($code) || $code === '') {
    // ErrorDocument-Fallback: Kurz-Code aus dem ursprünglich angefragten Pfad ableiten
    $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $code = trim($path, '/');
}
$link = lookup_code_ok($code) ? link_get($code) : null;

if ($link === null) {
    http_response_code(404);
    page_header('Nicht gefunden');
    echo '<div class="card center"><h1>404</h1><p>Diesen Kurzlink gibt es (noch) nicht.</p>'
        . '<p><a class="btn" href="./">Selbst einen anlegen</a></p></div>';
    page_footer();
    exit;
}

if (!empty($link['disabled'])) {
    http_response_code(410);
    page_header('Gesperrt');
    echo '<div class="card center"><h1>Gesperrt</h1><p>Dieser Kurzlink wurde wegen Missbrauchs gesperrt.</p>'
        . '<p><a class="btn" href="./">Zur Startseite</a></p></div>';
    page_footer();
    exit;
}

if (link_expired($link)) {
    http_response_code(410);
    page_header('Abgelaufen');
    echo '<div class="card center"><h1>Abgelaufen</h1><p>Dieser Kurzlink ist nicht mehr gültig.</p>'
        . '<p><a class="btn" href="./">Zur Startseite</a></p></div>';
    page_footer();
    exit;
}

// Link-in-Bio-Seiten leiten nicht weiter, sondern zeigen ihre Ziele. Die
// Abzweigung steht bewusst hinter Sperre und Ablauf – auch eine Seite kann
// gesperrt sein oder auslaufen – und vor dem Passwortschutz, damit sich auch
// eine ganze Seite schützen lässt.
if (bio_is($link) && empty($link['pass'])) {
    $i = $_GET['i'] ?? null;
    if (is_string($i) && $i !== '' && ctype_digit($i)) {
        bio_follow($code, $link, (int)$i);
    }
    bio_render($code, $link);
}

// Passwortgeschützte Links (Pro-Feature): erst nach richtigem Passwort weiterleiten
if (!empty($link['pass'])) {
    $fail = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $given = (string)($_POST['zugang'] ?? '');
        if (!bucket_rate_ok('golock', 20)) {
            $fail = 'Zu viele Versuche – bitte später erneut.';
        } elseif ($given !== '' && password_verify($given, (string)$link['pass'])) {
            // Auch eine geschützte Bio-Seite wird nach dem Passwort gezeigt,
            // statt weiterzuleiten – sie hat ja kein einzelnes Ziel.
            if (bio_is($link)) {
                $i = $_GET['i'] ?? null;
                if (is_string($i) && $i !== '' && ctype_digit($i)) bio_follow($code, $link, (int)$i);
                bio_render($code, $link);
            }
            clicks_bump($code);
            header('Location: ' . $link['url'], true, 302);
            exit;
        } else {
            sleep(1);
            $fail = 'Falsches Passwort.';
        }
    }
    page_header('Geschützter Link');
    echo '<div class="card narrow"><h1>Geschützter Link</h1>'
        . '<p class="muted">Dieser Kurzlink ist passwortgeschützt.</p>';
    if ($fail !== null) {
        echo '<div class="flash flash-err">' . e($fail) . '</div>';
    }
    echo '<form method="post" action="">'
        . '<label for="zugang">Passwort</label>'
        . '<input id="zugang" type="password" name="zugang" required autofocus>'
        . '<p><button class="btn btn-primary" type="submit">Öffnen</button></p>'
        . '</form></div>';
    page_footer();
    exit;
}

clicks_bump($code);
header('Location: ' . $link['url'], true, 302);
exit;
