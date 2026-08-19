<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Verzeichnisabgleich: Konten sperren, deren Person das Haus verlassen hat.
 *
 * Eingebunden von `tools/flatlink ldap:abgleich`, nicht eigenständig gedacht.
 *
 * Das Problem, das er löst: LDAP regelt die **Anmeldung**, nicht den
 * **Bestand**. Wer die Hochschule verlässt, kann sich nicht mehr anmelden –
 * aber sein Konto bleibt, seine Zugangsschlüssel bleiben gültig, und über die
 * Schnittstelle steht ihm alles offen wie zuvor. Für Verwaltungen und
 * Hochschulen ist genau das ein Beschaffungspunkt.
 *
 * Was er tut: Er holt alle Kennungen aus dem Verzeichnis, vergleicht sie mit
 * den Konten, die über LDAP angemeldet werden, und sperrt die übrigen. Sperren
 * heißt: keine Anmeldung, keine Schlüssel, laufende Sitzungen enden – und
 * **nichts wird gelöscht**. Links, Statistik und gedruckte QR-Codes bleiben.
 *
 * Was er NICHT tut, und warum das der wichtigere Teil ist:
 *
 *   * **Er handelt nie auf Verdacht.** Antwortet das Verzeichnis nicht, bricht
 *     er ab, ohne etwas zu ändern. Eine Zeitüberschreitung darf kein Haus
 *     aussperren.
 *   * **Er hat eine Schmerzgrenze.** Fehlen mehr als `--grenze` Prozent der
 *     Konten, bricht er ebenfalls ab. Wenn plötzlich achtzig Prozent
 *     verschwunden sind, ist wahrscheinlich der Suchzweig falsch und nicht
 *     eine Belegschaft entlassen worden.
 *   * **Er fasst lokale Konten nicht an.** Wer sich mit Passwort anmeldet,
 *     steht nicht im Verzeichnis und soll es auch nicht.
 *   * **Er schreibt nur mit `--anwenden`.** Ohne den Schalter zeigt er, was er
 *     täte. Das ist die richtige Voreinstellung für etwas, das per Cron läuft.
 *
 * Umgekehrt gilt dasselbe: Taucht eine Kennung wieder auf, hebt er die Sperre
 * auf – aber nur, wenn er sie selbst gesetzt hat. Eine von Hand verhängte
 * Sperre bleibt, wo sie ist.
 */

/** Woran der Abgleich seine eigenen Sperren wiedererkennt */
const ABGLEICH_GRUND = 'Verzeichnisabgleich: Kennung nicht mehr vorhanden';

$anwenden = hat($argv, 'anwenden');
$grenze = (float)(opt($argv, 'grenze') ?: 20);

if (!ldap_enabled()) {
    raus('LDAP ist auf dieser Instanz nicht eingeschaltet – es gibt nichts abzugleichen.');
}

sage($anwenden ? 'Verzeichnisabgleich (wird angewendet)' : 'Verzeichnisabgleich (Probelauf)');
sage('');

// ---- 1. Verzeichnis fragen ----------------------------------------------

[$fehler, $kennungen] = ldap_alle_kennungen();
if ($fehler !== null) {
    raus("  Das Verzeichnis antwortet nicht: $fehler\n"
       . '  Es wurde NICHTS geändert. Ein nicht erreichbares Verzeichnis ist '
       . "kein Grund,\n  Konten zu sperren.");
}
if ($kennungen === []) {
    raus("  Das Verzeichnis lieferte keine einzige Kennung.\n"
       . '  Das ist fast sicher ein Konfigurationsfehler (Suchzweig? Attribut?) '
       . "und nicht\n  ein leeres Haus. Es wurde nichts geändert.");
}
$imVerzeichnis = array_flip($kennungen);
sage('  Im Verzeichnis: ' . count($kennungen) . ' Kennungen');

// ---- 2. Betroffene Konten bestimmen -------------------------------------

$zentral = [];
foreach (users_all() as $name => $u) {
    // Nur, was auch über das Verzeichnis hereinkommt. Lokale Konten und
    // Shibboleth-Konten haben mit dieser Liste nichts zu tun.
    if (($u['auth'] ?? 'local') !== 'ldap') continue;
    $zentral[$name] = $u;
}
sage('  Konten über LDAP: ' . count($zentral));
if ($zentral === []) {
    sage('');
    sage('  Nichts zu tun.');
    return;
}

$fehlend = [];
$zurueck = [];
foreach ($zentral as $name => $u) {
    $da = isset($imVerzeichnis[mb_strtolower($name)]);
    if (!$da && !user_locked($u)) {
        $fehlend[$name] = $u;
    } elseif ($da && user_locked($u)
              && (string)($u['locked']['reason'] ?? '') === ABGLEICH_GRUND) {
        // Wieder aufgetaucht – aber nur aufmachen, was dieser Abgleich selbst
        // zugemacht hat. Eine von Hand verhängte Sperre geht ihn nichts an.
        $zurueck[$name] = $u;
    }
}

// ---- 3. Schmerzgrenze ----------------------------------------------------

$anteil = count($zentral) > 0 ? count($fehlend) / count($zentral) * 100 : 0.0;
sage(sprintf('  Nicht mehr im Verzeichnis: %d (%.1f %%)', count($fehlend), $anteil));
if ($zurueck !== []) sage('  Wieder aufgetaucht: ' . count($zurueck));
sage('');

if ($anteil > $grenze) {
    raus(sprintf(
        "  ABBRUCH: %.1f %% der Konten fehlen, erlaubt sind %.1f %%.\n"
      . "  Das sieht nach einem falschen Suchzweig aus, nicht nach einer\n"
      . "  entlassenen Belegschaft. Es wurde nichts geändert.\n\n"
      . "  Wenn es doch stimmt: --grenze=%d setzen.",
        $anteil, $grenze, (int)ceil($anteil) + 1));
}

// ---- 4. Handeln ----------------------------------------------------------

foreach ($fehlend as $name => $u) {
    $n = link_count($name);
    sage(sprintf('  %-28s sperren   (%d Link%s bleiben)', $name, $n, $n === 1 ? '' : 's'));
    if ($anwenden) user_set_locked($name, true, ABGLEICH_GRUND);
}
foreach ($zurueck as $name => $u) {
    sage(sprintf('  %-28s freigeben (war maschinell gesperrt)', $name));
    if ($anwenden) user_set_locked($name, false);
}

sage('');
if (!$anwenden) {
    sage('  Probelauf – es wurde nichts geändert. Mit --anwenden ausführen.');
} else {
    $summe = count($fehlend) + count($zurueck);
    sage("  $summe Konto/Konten geändert.");
    if ($fehlend !== []) {
        audit(sprintf('Verzeichnisabgleich: %d Konten gesperrt', count($fehlend)));
    }
    if ($zurueck !== []) {
        audit(sprintf('Verzeichnisabgleich: %d Konten freigegeben', count($zurueck)));
    }
}
