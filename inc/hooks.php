<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Webhooks: Bescheid sagen, wenn sich etwas ändert.
 *
 * Damit lässt sich anhängen, was flatlink selbst nie können soll – eine
 * Nachricht in den Teamchat, eine Zeile in einer Tabelle, ein Eintrag im
 * Ticketsystem. Über n8n, Zapier oder zehn Zeilen eigenes PHP.
 *
 * Zwei Entscheidungen, die den Rahmen setzen:
 *
 * **Nur Verwaltungsereignisse, nie Klicks.** Es gibt bewusst kein
 * `link.clicked`. Der Weiterleitungspfad ist der eine Ort, an dem dieses
 * Projekt nichts über Besucher tut – ein Webhook dort wäre genau die
 * Besucherverfolgung, die es nirgends gibt, nur ausgelagert an einen
 * Dritten. Nebenbei würde er jede Weiterleitung um die Antwortzeit des
 * Empfängers verlängern.
 *
 * **Ein Versuch, dann vorbei.** Kein Wiederholen, keine Warteschlange: Beides
 * bräuchte einen Hintergrundprozess, und den gibt es hier nicht (siehe
 * links_gc). Wer sichere Zustellung braucht, fragt die Schnittstelle ab. Ein
 * fehlgeschlagener Aufruf darf die auslösende Handlung nie kippen – wer einen
 * Link sperrt, hat ihn gesperrt, auch wenn der Chat gerade nicht erreichbar
 * ist.
 */

/** Die Ereignisse, die es gibt – Schlüssel und Beschreibung für die Oberfläche */
function hook_events(): array
{
    return [
        'link.created' => t('Kurzlink angelegt'),
        'link.updated' => t('Kurzlink geändert'),
        'link.deleted' => t('Kurzlink gelöscht'),
        'link.blocked' => t('Kurzlink gesperrt oder freigegeben'),
        'report.received' => t('Missbrauchs-Meldung eingegangen'),
        'user.pending' => t('Konto wartet auf Freischaltung'),
    ];
}

/**
 * Ein Ereignis melden.
 *
 * Läuft immer ins Leere, wenn keine Empfänger eingetragen sind – der Aufruf
 * kostet dann nichts und darf deshalb überall stehen.
 */
function hook_fire(string $ereignis, array $daten = []): void
{
    $ziele = array_values(array_filter(array_map('trim', (array)cfg('webhooks'))));
    if ($ziele === [] || !isset(hook_events()[$ereignis])) return;

    $body = json_encode([
        'event' => $ereignis,
        'at' => date('c'),
        'instance' => base_url(true) ?: cfg('site_name'),
        'actor' => hook_actor(),
        'data' => $daten,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) return;

    $kopf = "Content-Type: application/json\r\n"
        . "User-Agent: flatlink\r\n";
    // Signatur, damit der Empfänger prüfen kann, dass die Nachricht wirklich
    // von dieser Instanz kommt. Ohne Geheimnis keine Signatur – dann ist der
    // Empfänger selbst dafür zuständig, seine Adresse geheim zu halten.
    $secret = (string)cfg('webhook_secret');
    if ($secret !== '') {
        $kopf .= 'X-Flatlink-Signature: sha256=' . hash_hmac('sha256', $body, $secret) . "\r\n";
    }

    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $kopf,
        'content' => $body,
        'timeout' => 3,
        'ignore_errors' => true,
    ]]);
    foreach ($ziele as $url) {
        if (!preg_match('#^https?://#i', $url)) continue;
        // Fehler werden verschluckt: Der Empfänger ist nicht unser Problem,
        // die ausgelöste Handlung schon.
        @file_get_contents($url, false, $ctx);
    }
}

/** Wer die Handlung ausgelöst hat – oder null bei Systemvorgängen */
function hook_actor(): ?string
{
    if (!function_exists('auth_user')) return null;
    $u = auth_user();
    return is_array($u) ? ($u['name'] ?? null) : null;
}

/**
 * Die Felder eines Links, die in eine Meldung gehören.
 *
 * Bewusst knapp und ohne Zugangsdaten: kein Passwort-Hash, keine Zähler.
 * Wer mehr braucht, holt sich den Link über die Schnittstelle – die Meldung
 * sagt nur, dass und was passiert ist.
 */
function hook_link(string $code, ?array $l): array
{
    return [
        'code' => $code,
        'short_url' => short_url($code, (string)($l['domain'] ?? '')),
        'url' => $l['url'] ?? null,
        'title' => $l['title'] ?? null,
        'group' => $l['group'] ?? null,
        'owner' => $l['owner'] ?? null,
        'disabled' => (bool)($l['disabled'] ?? false),
    ];
}
