<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Zugangsschlüssel für die Programmierschnittstelle.
 *
 * Ein Schlüssel gehört immer zu einem Konto und kann nie mehr, als dieses Konto
 * selbst darf: Rechte, Limits und Gruppenzugehörigkeit gelten unverändert
 * weiter. Er ist ein zweiter Weg zur Anmeldung, keine zweite Berechtigung.
 *
 * Gespeichert wird nur der SHA-256-Abdruck, nie der Schlüssel selbst – wer die
 * Datei liest, kann sich damit nicht anmelden. Bewusst kein password_hash():
 * Zu einer eingehenden Anfrage muss der passende Eintrag *gefunden* werden, und
 * das geht nur mit einem gleichbleibenden Abdruck als Schlüssel. Der Grund für
 * langsame Passwort-Verfahren – dass Menschen kurze, erratbare Passwörter
 * wählen – entfällt hier: Ein Schlüssel besteht aus 160 zufälligen Bit.
 */
require_once __DIR__ . '/helpers.php';

/** Erkennungszeichen am Anfang jedes Schlüssels, damit er in Protokollen auffällt */
const TOKEN_PREFIX = 'flk_';

function tokens_file(): string
{
    return data_path() . '/tokens.json';
}

/**
 * Neuen Schlüssel erzeugen. Der Klartext wird genau einmal zurückgegeben und
 * danach nirgends mehr gespeichert.
 *
 * @return array{token:string,id:string}
 */
function token_create(string $user, string $label): array
{
    $plain = TOKEN_PREFIX . bin2hex(random_bytes(20));
    $abdruck = hash('sha256', $plain);
    $id = substr($abdruck, 0, 12);

    json_update(tokens_file(), function (array $t) use ($abdruck, $id, $user, $label, $plain) {
        $t[$abdruck] = [
            'id' => $id,
            'user' => $user,
            'label' => mb_substr(trim($label), 0, 60),
            // Die ersten Zeichen im Klartext, damit sich mehrere Schlüssel in
            // der Liste auseinanderhalten lassen. Zum Anmelden reicht das nicht.
            'hint' => substr($plain, 0, strlen(TOKEN_PREFIX) + 6),
            'created' => date('c'),
            'last_used' => null,
        ];
        return $t;
    });
    return ['token' => $plain, 'id' => $id];
}

/**
 * Schlüssel nachschlagen und den Zeitpunkt der Benutzung festhalten.
 *
 * @return ?array{id:string,user:string,label:string} Eintrag oder null
 */
function token_find(string $plain): ?array
{
    $plain = trim($plain);
    if ($plain === '' || !str_starts_with($plain, TOKEN_PREFIX)) return null;
    $abdruck = hash('sha256', $plain);
    $alle = json_read(tokens_file());
    $eintrag = $alle[$abdruck] ?? null;
    if (!is_array($eintrag)) return null;

    // Höchstens einmal pro Stunde zurückschreiben. Bei jedem Aufruf zu
    // schreiben hieße, dass sich sämtliche Anfragen an der Sperre dieser einen
    // Datei aufreihen – für eine Angabe, die auf die Stunde genau reicht.
    $letzte = (string)($eintrag['last_used'] ?? '');
    if ($letzte === '' || strtotime($letzte) < time() - 3600) {
        json_update(tokens_file(), function (array $t) use ($abdruck) {
            if (!isset($t[$abdruck])) return null;
            $t[$abdruck]['last_used'] = date('c');
            return $t;
        });
    }
    return $eintrag;
}

/** @return array<string,array> Schlüssel eines Kontos, neueste zuerst */
function tokens_of(string $user): array
{
    $out = array_filter(json_read(tokens_file()), fn($t) => ($t['user'] ?? null) === $user);
    uasort($out, fn($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));
    return $out;
}

/**
 * Schlüssel zurückziehen. Die Kennung des Kontos wird mitgeprüft, damit sich
 * über eine geratene Kennung nicht fremde Schlüssel entwerten lassen.
 */
function token_revoke(string $user, string $id): bool
{
    $weg = false;
    json_update(tokens_file(), function (array $t) use ($user, $id, &$weg) {
        foreach ($t as $abdruck => $e) {
            if (($e['id'] ?? '') === $id && ($e['user'] ?? '') === $user) {
                unset($t[$abdruck]);
                $weg = true;
            }
        }
        return $weg ? $t : null;
    });
    return $weg;
}

/** Alle Schlüssel eines Kontos entfernen – beim Löschen des Kontos */
function tokens_drop_user(string $user): void
{
    json_update(tokens_file(), function (array $t) use ($user) {
        $vorher = count($t);
        $t = array_filter($t, fn($e) => ($e['user'] ?? null) !== $user);
        return count($t) === $vorher ? null : $t;
    });
}
