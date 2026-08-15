<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Das Protokoll der Verwaltungshandlungen.
 *
 * Wer hat wann einen Link gesperrt, ein Konto freigeschaltet, eine Domain
 * hinzugefügt? Institutionen brauchen diese Nachvollziehbarkeit – oft auch
 * für Zertifizierungen. Und sie ist datensparsam zu haben: Protokolliert
 * werden ausschließlich Handlungen der Verwaltung, nie Besucher. Keine
 * IP-Adressen, keine Klicks, keine Weiterleitungen.
 *
 * Abgelegt als eine JSON-Zeile je Ereignis in data/audit.log – lesbar mit
 * bloßem Auge, und zugleich das Format, das sich in ein zentrales Log
 * (SIEM) ziehen lässt, ohne dass flatlink dafür etwas wissen muss.
 *
 * Die Einträge entstehen in der Sprache der Instanz zum Zeitpunkt der
 * Handlung – ein Protokoll ist ein historisches Dokument, es wird nicht
 * nachträglich umformuliert.
 */

function audit_file(): string
{
    return data_path() . '/audit.log';
}

/**
 * Eine Verwaltungshandlung festhalten.
 *
 * $aktion ist der fertige, menschenlesbare Satz (durch t() gelaufen),
 * $objekt der betroffene Gegenstand (Code, Kennung, Domain …).
 */
function audit(string $aktion, string $objekt = ''): void
{
    $wer = function_exists('auth_user') ? (auth_user()['name'] ?? null) : null;
    $zeile = ['t' => date('c'), 'wer' => $wer ?? 'system', 'aktion' => $aktion];
    if ($objekt !== '') $zeile['objekt'] = $objekt;
    file_put_contents(audit_file(),
        json_encode($zeile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX);
}

/**
 * Die letzten Einträge, neueste zuerst.
 *
 * Gelesen wird vom Dateiende her in Blöcken – das Protokoll einer alten
 * Instanz kann lang sein, und die Anzeige will nur die jüngste Seite.
 *
 * @return array<int,array{t:string,wer:string,aktion:string,objekt?:string}>
 */
function audit_tail(int $anzahl = 200): array
{
    $datei = audit_file();
    if (!is_file($datei)) return [];
    $f = fopen($datei, 'r');
    if ($f === false) return [];
    try {
        $groesse = fstat($f)['size'] ?? 0;
        $puffer = '';
        $pos = $groesse;
        // Rückwärts blockweise lesen, bis genug Zeilen beisammen sind
        while ($pos > 0 && substr_count($puffer, "\n") <= $anzahl) {
            $schritt = min(65536, $pos);
            $pos -= $schritt;
            fseek($f, $pos);
            $puffer = fread($f, $schritt) . $puffer;
        }
    } finally {
        fclose($f);
    }
    $zeilen = array_filter(explode("\n", $puffer), fn($z) => trim($z) !== '');
    $zeilen = array_slice($zeilen, -$anzahl);
    $out = [];
    foreach (array_reverse($zeilen) as $z) {
        $d = json_decode($z, true);
        if (is_array($d) && isset($d['t'], $d['aktion'])) $out[] = $d;
    }
    return $out;
}
