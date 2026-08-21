<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Mehrere Domains je Instanz – für die Kurzlinks, nicht für die Verwaltung.
 *
 * Wer eine Agentur betreibt oder mehrere Marken bedient, will Codes unter
 * `kunde.link` ausgeben statt unter der eigenen Hausadresse. Alle Domains
 * zeigen auf dieselbe Installation; unterschieden wird nur, welche Adresse
 * unter einem Link steht und in einem QR-Code landet.
 *
 * **Jede Domain hat ihren eigenen Namensraum.** Ein Link wird seit 5.0 durch
 * (Domain, Code) bestimmt: `kunde-a.link/shop` und `kunde-b.link/shop` sind
 * zwei verschiedene Links, die nichts voneinander wissen. Das ist die eine
 * Entscheidung, an der hier alles hängt, deshalb die Begründung im Klartext:
 *
 * - **Wer eine zweite Domain einträgt, will einen zweiten Namensraum.** Genau
 *   dafür trägt man sie ein – für mehr Platz, oder weil ein Kunde seine
 *   eigene Adresse mitbringt. Bis 4.5 löste ein Code unter JEDER Domain auf;
 *   ein Kunde konnte unter seiner eigenen Adresse die Kurzlinks aller anderen
 *   Kunden abrufen. Das war kein Merkmal, das war ein Fehler.
 * - **Zwei Kunden können jetzt beide `/shop` haben.** Bis 4.5 half dagegen
 *   nur ein Namensraum-Präfix der Gruppe (`kunde-a/shop`) – eine Krücke für
 *   ein Problem, das die Domain schon gelöst hatte.
 *
 * Der Preis steht auf der anderen Seite und wird nicht verschwiegen: **Fällt
 * eine Domain weg, lösen ihre Links nicht mehr auf.** Bis 4.5 fingen die
 * übrigen Domains sie auf; heute hängt ein gedruckter Code an seiner Adresse.
 * Wer eine Domain austrägt, bekommt deshalb in den Einstellungen gesagt, wie
 * viele Links davon betroffen sind – gelöscht wird keiner, und ein
 * Wiedereintragen macht sie alle wieder erreichbar. Für den Umzug eines
 * einzelnen Links auf eine andere Domain gibt es `link_move()`.
 *
 * Eine Nebendomain liefert **nur Kurzlinks** aus – sonst nichts. Startseite,
 * QR-Generatoren, Meldeseite und Verwaltung leiten auf die Hauptdomain um.
 * Zwei Gründe:
 *
 * - Eine Marken-Domain zeigt die Links der Marke, nicht die Werbeseite des
 *   Kurzlink-Dienstes. Wer `kurz.beispiel.de` druckt, will dort keine
 *   Preisliste von jemand anderem.
 * - Für Suchmaschinen gäbe es sonst dieselbe Seite unter mehreren Adressen.
 *
 * Die Verwaltung bleibt aus einem weiteren Grund auf der Hauptdomain: eine
 * Sitzung, ein Cookie, eine Adresse für Passkeys.
 */
require_once __DIR__ . '/helpers.php';

/** Host der Hauptdomain – die aus base_url() */
function domain_main(): string
{
    $h = (string)parse_url(base_url(), PHP_URL_HOST);
    return $h !== '' ? $h : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Eingerichtete Nebendomains.
 *
 * @return array<int,array{host:string,group:string}> group leer = für alle
 */
function domains_extra(): array
{
    $roh = settings()['domains'];
    $out = [];
    foreach ((array)$roh as $eintrag) {
        // Zwei Schreibweisen: 'kunde.link' oder ['host' => …, 'group' => …]
        $host = is_array($eintrag) ? (string)($eintrag['host'] ?? '') : (string)$eintrag;
        $host = domain_clean($host);
        if ($host === '' || $host === domain_main()) continue;
        $out[$host] = ['host' => $host, 'group' => is_array($eintrag) ? (string)($eintrag['group'] ?? '') : ''];
    }
    return array_values($out);
}

/** Host säubern: Schema, Pfad, Port und www. fallen weg */
function domain_clean(string $roh): string
{
    $roh = trim($roh);
    if ($roh === '') return '';
    if (str_contains($roh, '://')) $roh = (string)parse_url($roh, PHP_URL_HOST);
    $roh = strtolower(trim(explode('/', $roh)[0]));
    $roh = explode(':', $roh)[0];
    if (str_starts_with($roh, 'www.')) $roh = substr($roh, 4);
    // Keine vollständige Namensprüfung, nur das, was in einer URL stehen darf
    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $roh) === 1
        ? $roh : '';
}

/** Alle Domains dieser Instanz, Hauptdomain zuerst @return string[] */
function domains_all(): array
{
    $out = [domain_main()];
    foreach (domains_extra() as $d) $out[] = $d['host'];
    return array_values(array_unique($out));
}

/** Gibt es überhaupt mehr als eine? */
function domains_multi(): bool
{
    return count(domains_all()) > 1;
}

/**
 * Welche Domains darf dieses Konto für einen Link wählen?
 *
 * Die Hauptdomain immer. Eine Nebendomain mit hinterlegter Gruppe nur, wer in
 * dieser Gruppe ist – so lässt sich `kunde.link` demselben Kunden vorbehalten,
 * dem auch das Namensraum-Präfix gehört.
 *
 * @return string[]
 */
function domains_for(?string $user): array
{
    require_once __DIR__ . '/groups.php';
    // Administratoren verwalten die Domains – ihnen eine davon vorzuenthalten,
    // wäre eine Hürde ohne Schutzwirkung: Sie könnten die Gruppe im selben
    // Atemzug ändern.
    if ($user !== null && (user_get($user)['role'] ?? '') === 'admin') return domains_all();
    $meine = $user === null ? [] : user_groups($user);
    $out = [domain_main()];
    foreach (domains_extra() as $d) {
        if ($d['group'] === '' || in_array($d['group'], $meine, true)) $out[] = $d['host'];
    }
    return array_values(array_unique($out));
}

/** Darf dieses Konto diese Domain benutzen? Leer heißt Hauptdomain. */
function domain_allowed(string $host, ?string $user): bool
{
    if ($host === '') return true;
    return in_array($host, domains_for($user), true);
}

/**
 * Vollständige Basis für eine Domain.
 *
 * Das Schema kommt von der Hauptdomain: Alle Domains zeigen auf dieselbe
 * Installation, also auf denselben Server mit derselben TLS-Einrichtung.
 */
function domain_url(string $host): string
{
    $basis = base_url();
    if ($host === '' || $host === domain_main()) return $basis;
    $u = parse_url($basis);
    $pfad = rtrim((string)($u['path'] ?? ''), '/');
    return ($u['scheme'] ?? 'https') . '://' . $host . $pfad;
}

/**
 * Läuft dieser Aufruf unter einer Nebendomain?
 *
 * Wird gebraucht, um die Verwaltung auf die Hauptdomain zu holen.
 */
function domain_current(): string
{
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $h = explode(':', $h)[0];
    if (str_starts_with($h, 'www.')) $h = substr($h, 4);
    return $h;
}

/**
 * Alles außer der Weiterleitung selbst gehört auf die Hauptdomain.
 *
 * Wird von page_header() für jede gezeichnete Seite gerufen – mit einer
 * Ausnahme: go.php. Dort entstehen die Seiten, die zu einem Kurzlink gehören
 * (Passwortabfrage, abgelaufen, gesperrt, nicht gefunden), und die müssen
 * unter der Adresse bleiben, unter der der Code gedruckt wurde. Eine
 * Passwortabfrage, die auf eine andere Domain springt, wäre ein Fehler.
 *
 * Zusätzlich rufen auth_require() und die Anmeldeseite die Umleitung selbst:
 * Dort muss sie greifen, *bevor* eine Sitzung entsteht, nicht erst wenn die
 * Seite gezeichnet wird.
 *
 * Dauerhaft (301) wäre falsch: Eine Domain kann später Hauptdomain werden,
 * und ein 301 klebt in Browsern und Suchmaschinen fest.
 */
function domain_force_main(): void
{
    if (!domains_multi()) return;
    $jetzt = domain_current();
    if ($jetzt === '' || $jetzt === domain_main()) return;
    // Nur umleiten, wenn die Domain uns überhaupt gehört – sonst würde ein
    // beliebiger Host-Kopf zu einer Weiterleitung führen.
    if (!in_array($jetzt, domains_all(), true)) return;
    $pfad = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . base_url() . $pfad, true, 302);
    exit;
}

/**
 * In welchem Namensraum löst dieser Aufruf auf?
 *
 * Seit 5.0 gehört ein Code nicht mehr der Instanz, sondern der Domain:
 * kunde-a.example/aktion und kunde-b.example/aktion sind zwei verschiedene
 * Links, die nichts voneinander wissen. Das ist der Grund, aus dem jemand
 * überhaupt eine zweite Domain einträgt – er will einen zweiten Namensraum.
 * Vorher konnte ein Kunde, der seine eigene Domain mitbrachte, unter ihr die
 * Kurzlinks aller anderen Kunden abrufen; das war ein Fehler, kein Merkmal.
 *
 * Die Hauptdomain ist der leere String, nicht ihr Name. So bleibt jeder
 * Datensatz aus der Zeit davor gültig, ohne angefasst zu werden – und eine
 * Instanz mit nur einer Domain merkt von der ganzen Trennung nichts.
 *
 * Ein fremder oder fehlender Host-Kopf landet ebenfalls im Hauptnamensraum:
 * Das ist der Fall „direkt über die IP aufgerufen" oder ein Proxy ohne
 * Host-Durchreichung, und dort ist die Hauptdomain die richtige Annahme.
 */
function domain_namensraum(): string
{
    if (!domains_multi()) return '';
    $jetzt = domain_current();
    if ($jetzt === '' || $jetzt === domain_main()) return '';
    return in_array($jetzt, domains_all(), true) ? $jetzt : '';
}

/**
 * Der Domain-Anhang für eine Verwaltungs-URL.
 *
 * Ein Link wird in der Verwaltung über `?c=` bzw. `?edit=` angesprochen –
 * seit die Namensräume getrennt sind, genügt der Code dafür nicht mehr. Die
 * Hauptdomain bleibt bewusst ohne Anhang: So funktioniert jeder gespeicherte
 * oder verschickte Verwaltungs-Link von früher unverändert weiter.
 */
function dom_param(string $domain, string $name = 'd'): string
{
    return $domain === '' ? '' : '&' . $name . '=' . urlencode($domain);
}

/**
 * Die Domain aus einer Verwaltungs-URL oder einem Formular – geprüft.
 *
 * Nur eingetragene Domains kommen durch; alles andere ist die Hauptdomain.
 * Damit kann ein geratener Parameter keinen Namensraum erfinden.
 */
function dom_param_lesen(mixed $roh): string
{
    $d = domain_clean((string)(is_string($roh) ? $roh : ''));
    if ($d === '' || $d === domain_main()) return '';
    return in_array($d, domains_all(), true) ? $d : '';
}

/**
 * Ist dies der Aufruf, der einen Kurzlink auflöst?
 *
 * Nur go.php – und zwar auch, wenn der Server es als 404-Behandlung aufruft.
 */
function domain_is_resolver(): bool
{
    return str_ends_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/go.php');
}
