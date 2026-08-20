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
 * Abgelegt in der Tabelle audit (bis 4.0: data/audit.log; die Übernahme in
 * inc/db.php nimmt den Bestand mit). Für ein zentrales Log (SIEM) liefert
 * `tools/flatlink audit` weiterhin JSON-Zeilen – dasselbe Format, das
 * vorher in der Datei stand, nur eben zum Abholen statt zum Mitlesen.
 *
 * Die Einträge entstehen in der Sprache der Instanz zum Zeitpunkt der
 * Handlung – ein Protokoll ist ein historisches Dokument, es wird nicht
 * nachträglich umformuliert.
 */

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
    db_audit_add(db(), $zeile['t'], $zeile);
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
    return db_audit_tail(db(), $anzahl);
}

