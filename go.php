<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/inc/store.php';
require_once __DIR__ . '/inc/routing.php';

$code = $_GET['c'] ?? '';
if (!is_string($code) || $code === '') {
    // ErrorDocument-Fallback: Kurzcode aus dem ursprünglich angefragten Pfad ableiten
    $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $code = trim($path, '/');
}
// Unter WELCHER Domain gefragt wird, entscheidet mit, welcher Link gemeint
// ist: Jede eingetragene Domain hat ihren eigenen Namensraum (siehe
// domain_namensraum()).
//
// inc/domains.php wird dafür nur geladen, wenn es überhaupt eine zweite
// Domain gibt – so wie short_url() es hält. go.php ist der heißeste Pfad des
// Systems, und die allermeisten Instanzen haben genau eine Domain; die sollen
// für die Trennung kein einziges geparstes Byte bezahlen.
$namensraum = '';
if ((array)cfg('domains') !== []) {
    require_once __DIR__ . '/inc/domains.php';
    $namensraum = domain_namensraum();
}
$link = lookup_code_ok($code) ? link_get($code, $namensraum) : null;

if ($link === null) {
    http_response_code(404);
    page_header(t('Nicht gefunden'));
    echo '<div class="card center"><h1>404</h1><p>' . t('Diesen Kurzlink gibt es (noch) nicht.') . '</p>'
        . '<p><a class="btn" href="./">' . t('Selbst einen anlegen') . '</a></p></div>';
    page_footer();
    exit;
}

if (!empty($link['disabled'])) {
    http_response_code(410);
    page_header(t('Gesperrt'));
    echo '<div class="card center"><h1>' . t('Gesperrt') . '</h1><p>' . t('Dieser Kurzlink wurde wegen Missbrauchs gesperrt.') . '</p>'
        . '<p><a class="btn" href="./">' . t('Zur Startseite') . '</a></p></div>';
    page_footer();
    exit;
}

// Noch nicht aktiv: dieselbe Antwort wie „abgelaufen" (410) – der Code
// existiert, führt aber heute nicht weiter. Das Datum steht dabei, damit
// niemand rätselt, ob der Aufkleber kaputt ist.
if (link_pending($link)) {
    http_response_code(410);
    page_header(t('Noch nicht aktiv'));
    echo '<div class="card center"><h1>' . t('Noch nicht aktiv') . '</h1><p>'
        . t('Dieser Kurzlink führt ab dem %s weiter.', e(date('d.m.Y', strtotime((string)$link['starts'])))) . '</p>'
        . '<p><a class="btn" href="./">' . t('Zur Startseite') . '</a></p></div>';
    page_footer();
    exit;
}

if (link_expired($link)) {
    http_response_code(410);
    page_header(t('Abgelaufen'));
    echo '<div class="card center"><h1>' . t('Abgelaufen') . '</h1><p>' . t('Dieser Kurzlink ist nicht mehr gültig.') . '</p>'
        . '<p><a class="btn" href="./">' . t('Zur Startseite') . '</a></p></div>';
    page_footer();
    exit;
}

// Aufruf-Limit: „nur die ersten 50“ – danach dieselbe Antwort wie beim
// Ablauf. Geprüft wird gegen den Zähler, der ohnehin geführt wird; ein
// Besucher-Datensatz entsteht dafür nicht.
if (link_ausgeschoepft($code, $link, $namensraum)) {
    http_response_code(410);
    page_header(t('Limit erreicht'));
    echo '<div class="card center"><h1>' . t('Limit erreicht') . '</h1><p>'
        . t('Dieser Kurzlink war auf %d Aufrufe begrenzt und hat sie erreicht.', (int)$link['max_visits']) . '</p>'
        . '<p><a class="btn" href="./">' . t('Zur Startseite') . '</a></p></div>';
    page_footer();
    exit;
}

// Einmal je Anfrage entschieden, an allen Zählstellen benutzt – auch von den
// Bio-Seiten (inc/bio.php liest dieselbe Funktion).
$zaehlbar = click_zaehlbar($link);
// Nur für Links MIT Aufruflimit wird auch das Nichtzählbare mitgeschrieben –
// sonst ginge die Grenze am Bot-Filter vorbei. Bei allen anderen bleibt eine
// Bot-Anfrage ein Aufruf, der keine Datei anfasst.
$limitiert = !$zaehlbar && (int)($link['max_visits'] ?? 0) > 0;

// Link-in-Bio-Seiten leiten nicht weiter, sondern zeigen ihre Ziele. Die
// Abzweigung steht bewusst hinter Sperre und Ablauf – auch eine Seite kann
// gesperrt sein oder auslaufen – und vor dem Passwortschutz, damit sich auch
// eine ganze Seite schützen lässt.
if (($link['kind'] ?? '') === 'bio' && empty($link['pass'])) {
    // Erst jetzt, wo feststehend eine Bio-Seite gerendert wird – die
    // gewöhnliche Weiterleitung soll die 440 Zeilen nicht mitparsen.
    require_once __DIR__ . '/inc/bio.php';
    $i = $_GET['i'] ?? null;
    if (is_string($i) && $i !== '' && ctype_digit($i)) {
        bio_follow($code, $link, (int)$i, $namensraum);
    }
    bio_render($code, $link, $namensraum);
}

// Passwortgeschützte Links (Pro-Feature): erst nach richtigem Passwort weiterleiten
if (!empty($link['pass'])) {
    $fail = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $given = (string)($_POST['zugang'] ?? '');
        if (!bucket_rate_ok('golock', 20)) {
            // Der Zähler war schon da, die Antwort log ihn bisher nicht mit:
            // Wer abgewiesen wird, soll das auch am Status sehen – und ein
            // automatisierter Versucher soll es auswerten können, statt
            // weiterzuprobieren.
            http_response_code(429);
            header('Retry-After: 600');
            $fail = t('Zu viele Versuche – bitte später erneut.');
        } elseif ($given !== '' && password_verify($given, (string)$link['pass'])) {
            // Auch eine geschützte Bio-Seite wird nach dem Passwort gezeigt,
            // statt weiterzuleiten – sie hat ja kein einzelnes Ziel.
            if (($link['kind'] ?? '') === 'bio') {
                require_once __DIR__ . '/inc/bio.php';
                $i = $_GET['i'] ?? null;
                if (is_string($i) && $i !== '' && ctype_digit($i)) bio_follow($code, $link, (int)$i, $namensraum);
                bio_render($code, $link, $namensraum);
            }
            [$ziel, $weiche] = route_target($link);
            if ($zaehlbar) clicks_bump($code, null, $weiche, $namensraum);
            elseif ($limitiert) clicks_roh_bump($code, $namensraum);
            header('Location: ' . $ziel, true, 302);
            exit;
        } else {
            // Kein Warten mehr an dieser Stelle: Der golock-Zähler oben
            // bremst bereits, und ein sleep() hätte für seine Dauer einen
            // PHP-Prozess belegt – auf kleinen Instanzen der wirksamere
            // Angriff (siehe inc/auth.php).
            $fail = t('Falsches Passwort.');
        }
    }
    page_header(t('Geschützter Link'));
    echo '<div class="card narrow"><h1>' . t('Geschützter Link') . '</h1>'
        . '<p class="muted">' . t('Dieser Kurzlink ist passwortgeschützt.') . '</p>';
    if ($fail !== null) {
        echo '<div class="flash flash-err">' . e($fail) . '</div>';
    }
    echo '<form method="post" action="">'
        . '<label for="zugang">' . t('Passwort') . '</label>'
        // Kein Konto-Passwort: Es gehört zum Link, nicht zu einer Anmeldung –
        // eine Passwortverwaltung soll es nicht als Zugangsdatum einsammeln.
        . '<input id="zugang" type="password" name="zugang" required autofocus'
        . ' autocomplete="off" data-lpignore="true" data-1p-ignore>'
        . '<p><button class="btn btn-primary" type="submit">' . t('Öffnen') . '</button></p>'
        . '</form></div>';
    page_footer();
    exit;
}

// Eine Weiche kann ein anderes Ziel bestimmen – ausgewertet in dieser
// Anfrage, gespeichert wird davon nichts (siehe inc/routing.php).
[$ziel, $weiche] = route_target($link);

// Vorschau für Chats und soziale Netze: nur wenn der Link eigene Angaben
// trägt und der Abruf von einem Vorschau-Dienst kommt. Nicht gezählt – ein
// Vorschau-Abruf ist kein Besuch, und ihn mitzuzählen würde jede geteilte
// Nachricht als Klick ausweisen.
if (($link['og_title'] ?? '') !== '' && route_ist_vorschau()) {
    preview_render($code, $link, $ziel);
}
if ($zaehlbar) clicks_bump($code, null, $weiche, $namensraum);
elseif ($limitiert) clicks_roh_bump($code, $namensraum);
header('Location: ' . $ziel, true, 302);
exit;
