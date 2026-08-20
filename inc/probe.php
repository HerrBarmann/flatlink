<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Der Selbsttest: Ist das Datenverzeichnis wirklich dicht?
 *
 * Bisher warnte flatlink, wenn `data_dir` leer war – also wenn die Daten im
 * Webroot lagen. Das prüft aber nur die Konfiguration, nicht die Wirklichkeit.
 * Zwei Fälle gehen dabei durch:
 *
 *   1. Die Daten liegen im Webroot und der Webserver ignoriert die
 *      `.htaccess` (nginx, Caddy, LiteSpeed ohne passende Blöcke). Dann sind
 *      Passwort-Hashes, gültige Reset-Token und das Instanz-Geheimnis
 *      abrufbar – und die Oberfläche zeigt bloß denselben Hinweis wie bei
 *      Apache, wo alles in Ordnung wäre.
 *   2. Jemand hat die `.htaccess` beim Hochladen überschrieben oder gelöscht.
 *      Die Konfiguration sieht danach unverändert richtig aus.
 *
 * Deshalb wird gemessen statt vermutet: eine Kanarien-Datei ins
 * Datenverzeichnis legen, sie über die eigene Adresse abrufen, wieder
 * löschen. Kommt sie an, ist das Verzeichnis offen.
 *
 * Drei Ergebnisse, und der Unterschied zwischen den letzten beiden ist
 * wichtig: `dicht` (der Abruf wurde abgewiesen), `offen` (der Inhalt kam
 * zurück) und `unklar` (die Instanz konnte sich selbst nicht erreichen –
 * kein ausgehendes HTTP, DNS zeigt woandershin, Zertifikatsfehler). „Unklar"
 * ist ausdrücklich keine Entwarnung und wird auch nicht als solche angezeigt.
 *
 * Ausgelöst wird ausschließlich von Hand. Beim Aufbau der Seite zu prüfen
 * wäre bequemer, hat aber einen Haken, der beim Testen sofort auftrat: Die
 * Instanz ruft sich selbst auf, während sie die laufende Anfrage bearbeitet.
 * Wo nur ein Arbeitsprozess bereitsteht, wartet sie damit auf sich selbst,
 * bis der Timeout greift – die Einstellungsseite bräuchte fünf Sekunden und
 * meldete am Ende „unklar". Die Oberfläche zeigt deshalb das gespeicherte
 * Ergebnis und daneben einen Knopf.
 */

/**
 * Das gespeicherte Ergebnis, oder null, wenn noch nie geprüft wurde.
 *
 * @return array{stand:string,zeit:string,detail:string}|null
 */
function probe_last(): ?array
{
    $d = (array)state_get('probe');
    return isset($d['stand']) ? $d : null;
}

/**
 * Den Selbsttest ausführen und das Ergebnis speichern.
 *
 * @param bool $erzwingen Auch dann laufen, wenn erst kürzlich geprüft wurde
 * @return array{stand:string,zeit:string,detail:string}
 */
function probe_run(bool $erzwingen = false): array
{
    $alt = probe_last();
    if (!$erzwingen && $alt !== null && strtotime($alt['zeit']) > time() - 86400) {
        return $alt;
    }

    // Liegt das Verzeichnis außerhalb des Webroots, ist über HTTP ohnehin
    // nichts zu erreichen – dann ist der Test gegenstandslos.
    if (!data_dir_in_webroot()) {
        return probe_save('ausgelagert', t('Das Datenverzeichnis liegt außerhalb des Webroots.'));
    }

    $basis = base_url(true);
    if ($basis === '') {
        return probe_save('unklar', t('Ohne konfigurierte %s lässt sich der Abruf nicht durchführen.', 'base_url'));
    }

    // Die Kanarien-Datei trägt einen zufälligen Namen und einen zufälligen
    // Inhalt: Der Name verhindert, dass ein zwischengeschalteter Cache eine
    // alte Antwort liefert, der Inhalt beweist, dass die Antwort wirklich
    // aus dieser Datei stammt und nicht etwa eine Fehlerseite ist.
    $name = '.probe-' . bin2hex(random_bytes(8)) . '.txt';
    $inhalt = bin2hex(random_bytes(16));
    $pfad = data_path() . '/' . $name;
    if (@file_put_contents($pfad, $inhalt) === false) {
        return probe_save('unklar', t('Die Prüfdatei konnte nicht angelegt werden.'));
    }

    try {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,   // 403 ist hier das erwünschte Ergebnis
            'follow_location' => 0,
            'header' => "Cache-Control: no-cache\r\n",
        ]]);
        $antwort = @file_get_contents($basis . '/data/' . $name, false, $ctx);
        $kopf = $http_response_header ?? [];
    } finally {
        @unlink($pfad);
    }

    if ($antwort === false && $kopf === []) {
        return probe_save('unklar', t('Die Instanz konnte sich selbst nicht erreichen (kein ausgehendes HTTP, DNS oder Zertifikat).'));
    }
    if (is_string($antwort) && str_contains($antwort, $inhalt)) {
        return probe_save('offen', t('Der Inhalt der Prüfdatei kam über %s zurück.', 'HTTP'));
    }
    $code = probe_status($kopf);
    if ($code === 0) {
        return probe_save('unklar', t('Auf den Abruf kam keine auswertbare Antwort.'));
    }
    return probe_save('dicht', t('Der Abruf wurde mit %d abgewiesen.', $code));
}

/** Statuscode aus den Antwortkopfzeilen von file_get_contents ziehen */
function probe_status(array $kopf): int
{
    foreach ($kopf as $zeile) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$zeile, $m) === 1) return (int)$m[1];
    }
    return 0;
}

/** @return array{stand:string,zeit:string,detail:string} */
function probe_save(string $stand, string $detail): array
{
    $d = ['stand' => $stand, 'zeit' => date('c'), 'detail' => $detail];
    state_set('probe', $d);
    return $d;
}
