<?php
declare(strict_types=1);
/**
 * Mehrere Domains je Instanz – für die Kurzlinks, nicht für die Verwaltung.
 *
 * Wer eine Agentur betreibt oder mehrere Marken bedient, will Codes unter
 * `kunde.link` ausgeben statt unter der eigenen Hausadresse. Alle Domains
 * zeigen auf dieselbe Installation; unterschieden wird nur, welche Adresse
 * unter einem Link steht und in einem QR-Code landet.
 *
 * **Ein Namensraum für alle Domains.** Ein Code gehört der Instanz, nicht der
 * Domain: `shop` gibt es genau einmal, und er löst unter jeder eingerichteten
 * Domain auf. Das ist die eine Entscheidung, an der hier alles hängt, deshalb
 * die Begründung im Klartext:
 *
 * - **Ein gedruckter Code stirbt nicht, wenn eine Domain wegfällt.** Zieht ein
 *   Kunde um oder läuft eine Domain aus, funktionieren die Aufkleber weiter.
 *   Für einen Dienst, dessen ganzer Zweck „gedruckt ist gedruckt" lautet, wiegt
 *   das schwerer als Exklusivität.
 * - **Getrennte Namensräume wären eine andere Datenhaltung.** Ein Link wäre
 *   dann nicht mehr durch seinen Code bestimmt, sondern durch Domain *und*
 *   Code – das zöge sich durch Ablage, Schnittstelle, Import und jede
 *   Oberfläche. Der Preis dafür ist ein Zugeständnis: Zwei Kunden können
 *   nicht beide `/shop` haben. Dafür gibt es die Namensraum-Präfixe der
 *   Gruppen, die genau dieses Problem lösen.
 *
 * Die Verwaltung bleibt bewusst auf der Hauptdomain: eine Sitzung, ein Cookie,
 * eine Adresse für Passkeys. Aufrufe von /admin/ unter einer Nebendomain
 * werden dorthin umgeleitet.
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
    if ($user !== null && (users_all()[$user]['role'] ?? '') === 'admin') return domains_all();
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
 * Die Verwaltung gehört auf die Hauptdomain.
 *
 * Eine Sitzung, ein Cookie, eine Adresse für Passkeys: Ein Passkey, der unter
 * `kunde.link` eingerichtet wurde, ließe sich unter der Hauptdomain nicht mehr
 * benutzen. Statt das zu erklären, wird umgeleitet – bevor irgendetwas mit
 * einer Sitzung passiert.
 */
function domain_force_main(): void
{
    if (!domains_multi()) return;
    $jetzt = domain_current();
    if ($jetzt === '' || $jetzt === domain_main()) return;
    // Nur umleiten, wenn die Domain uns überhaupt gehört – sonst würde ein
    // beliebiger Host-Header zu einer Weiterleitung führen.
    if (!in_array($jetzt, domains_all(), true)) return;
    $pfad = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . base_url() . $pfad, true, 302);
    exit;
}
