<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Wegweiser für den eingebauten PHP-Server – nur zum Ausprobieren.
 *
 *     php -S localhost:8080 router.php
 *
 * Im Dauerbetrieb erledigt die `.htaccess` (oder eine gleichwertige
 * nginx-Regel) diese Arbeit; der eingebaute Server kennt keine Rewrites und
 * lieferte sonst für `/meincode` die Startseite aus statt weiterzuleiten.
 * Diese Datei bildet dieselben vier Regeln nach, in derselben Reihenfolge:
 * interne Verzeichnisse sperren, echte Dateien durchreichen, `/api/…` an die
 * Schnittstelle geben, alles Übrige als Kurzcode behandeln.
 *
 * Auf einem echten Webserver wird sie nie aufgerufen – dort ist sie eine
 * gewöhnliche PHP-Datei, die niemand anfragt. Sie mit hochzuladen schadet
 * nicht, nötig ist es nicht.
 */

$pfad = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Interne Verzeichnisse: hier liegen Konfiguration und Laufzeitdaten.
// Dazu, was die .htaccess ebenfalls sperrt – damit beim Ausprobieren nichts
// erreichbar ist, was auf dem echten Server verschlossen bleibt.
if (preg_match('#^/(inc|data|tests|tools|extension|\.git)(/|$)#', $pfad) === 1
    || preg_match('#^/(Dockerfile|docker-compose\.ya?ml|docker-entrypoint\.sh|\.dockerignore|\.gitignore)$#', $pfad) === 1) {
    http_response_code(403);
    exit('Forbidden');
}

// Vorhandene Dateien (Skripte, Bilder, Stylesheets) liefert der Server selbst.
// `false` heißt genau das – und nur auf oberster Ebene eines Routers.
if ($pfad !== '/' && is_file(__DIR__ . $pfad)) return false;

// Verzeichnisse ebenso: der Server sucht sich sein index.php
if (is_dir(__DIR__ . rtrim($pfad, '/'))) return false;

// Ein Skript mit angehängtem Pfad – `/api.php/links/abc123`. Ein Webserver mit
// PATH_INFO-Unterstützung ruft dann das Skript auf und legt den Rest dort ab;
// ohne diesen Zweig fiele die Adresse unten in die Kurzcode-Regel und
// beantwortete einen API-Aufruf mit einer HTML-Seite.
if (preg_match('#^(/.+\.php)(/.*)$#', $pfad, $t) === 1 && is_file(__DIR__ . $t[1])) {
    $_SERVER['PATH_INFO'] = $t[2];
    $_SERVER['SCRIPT_NAME'] = $t[1];
    require __DIR__ . $t[1];
    return true;
}

// Die Schnittstelle liegt unter /api/… – vor der Kurzcode-Regel, sonst hielte
// diese „api" für einen Kurzcode.
if (preg_match('#^/api(/(.*))?$#', $pfad, $t) === 1) {
    // api.php liest den Rest des Pfades aus PATH_INFO, wie es ein Webserver
    // mit PATH_INFO-Unterstützung liefern würde.
    $_SERVER['PATH_INFO'] = isset($t[2]) && $t[2] !== '' ? '/' . $t[2] : '';
    require __DIR__ . '/api.php';
    return true;
}

// Alles Übrige ist ein Kurzcode. go.php liest ihn selbst aus der Adresse,
// wenn kein Parameter `c` gesetzt ist – genau wie beim 404-Fallback der
// .htaccess.
require __DIR__ . '/go.php';
return true;
