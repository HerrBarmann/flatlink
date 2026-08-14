<?php
declare(strict_types=1);
/**
 * Ein Logo aus der Bibliothek ausliefern.
 *
 * Die Bilder liegen in data/ und sind von außen gesperrt – zu Recht, dort
 * stehen auch Passwort-Hashes. Für Link-in-Bio-Seiten wird eines davon aber
 * öffentlich gebraucht. Statt das ganze Verzeichnis zu öffnen, gibt dieser
 * Endpunkt genau eine Datei heraus, und nur wenn ihre Kennung in der
 * Bibliothek verzeichnet ist.
 *
 * Als Daten-URI in die Seite einzubetten wäre die Alternative gewesen – bei bis
 * zu 512 KB je Bild wäre die Seite auf einem Mobilgerät aber unbrauchbar
 * langsam, und zwischenspeichern ließe sich nichts.
 */
require_once __DIR__ . '/inc/store.php';

$id = (string)($_GET['id'] ?? '');

// Streng gegen die Form prüfen, bevor irgendetwas mit dem Wert passiert: Die
// Kennungen sind maschinell vergeben und immer hexadezimal.
if (preg_match('/^[a-f0-9]{16,64}$/', $id) !== 1 || !isset(logos_meta()[$id])) {
    http_response_code(404);
    nosniff_header();
    exit('Unbekanntes Logo.');
}

$datei = data_path('logos') . '/' . $id;
if (!is_file($datei)) {
    http_response_code(404);
    nosniff_header();
    exit('Unbekanntes Logo.');
}

// Den Typ aus dem Bild selbst bestimmen, nicht aus dem Dateinamen – der trägt
// hier ohnehin keine Endung.
$info = @getimagesize($datei);
$typ = is_array($info) ? (string)($info['mime'] ?? '') : '';
if (!in_array($typ, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
    // SVG erkennt getimagesize nicht; es wird beim Hochladen bereits abgelehnt,
    // deshalb hier keine Ausnahme – was nicht als Bild erkannt wird, geht nicht raus.
    http_response_code(415);
    nosniff_header();
    exit('Kein auslieferbares Bildformat.');
}

$etag = '"' . substr(hash_file('sha256', $datei), 0, 32) . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $typ);
header('Content-Length: ' . (string)filesize($datei));
header('ETag: ' . $etag);
// Logos ändern sich unter derselben Kennung nie – eine neue Datei bekommt eine
// neue Kennung. Deshalb darf lange zwischengespeichert werden.
header('Cache-Control: public, max-age=604800, immutable');
header("Content-Security-Policy: default-src 'none'; sandbox");
nosniff_header();
readfile($datei);
