<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Zugangsschlüssel für die Programmierschnittstelle.
 *
 * Ein Schlüssel gehört immer zu einem Konto und kann nie MEHR, als dieses Konto
 * selbst darf: Rechte, Limits und Gruppenzugehörigkeit gelten unverändert
 * weiter. Er kann aber **weniger**.
 *
 * Das ist der Unterschied zu früher, als hier stand „keine zweite
 * Berechtigung". Ein Schlüssel wandert weiter als ein Passwort — er steckt im
 * Kassensystem, im Fertigungsauftrag einer Werkstatt, in einem
 * Verbindungscode für die Browser-Erweiterung. Wer ihn dort einsetzt, will
 * meist genau eine Sache erlauben und nicht das ganze Konto. Deshalb trägt
 * jeder Schlüssel einen Umfang:
 *
 *   TOKEN_VOLL   alles, was das Konto darf (Voreinstellung, wie bisher)
 *   TOKEN_SCHREIB anlegen und ändern, aber nicht löschen
 *   TOKEN_LESEN  nur lesen
 *
 * Dazu kommt die Herkunftsbindung `own_only`: Ein so gesetzter Schlüssel
 * sieht und ändert ausschließlich Links, die mit ihm selbst angelegt wurden.
 * Für ein Kassensystem, das täglich Bewertungs-Codes erzeugt, ist das die
 * eigentliche Absicherung — es kommt an den übrigen Bestand des Kontos gar
 * nicht heran.
 *
 * Gespeichert wird nur der SHA-256-Hash, nie der Schlüssel selbst – wer die
 * Datei liest, kann sich damit nicht anmelden. Bewusst kein password_hash():
 * Zu einer eingehenden Anfrage muss der passende Eintrag *gefunden* werden, und
 * das geht nur mit einem gleichbleibenden Hash als Schlüssel. Der Grund für
 * langsame Passwort-Verfahren – dass Menschen kurze, erratbare Passwörter
 * wählen – entfällt hier: Ein Schlüssel besteht aus 160 zufälligen Bit.
 */
require_once __DIR__ . '/helpers.php';

/** Erkennungszeichen am Anfang jedes Schlüssels, damit er in Protokollen auffällt */
const TOKEN_PREFIX = 'flk_';

/** Umfang eines Schlüssels – mehr als das Konto darf, wird daraus nie */
const TOKEN_VOLL = 'full';
const TOKEN_SCHREIB = 'write';
const TOKEN_LESEN = 'read';

/** Die drei Stufen mit ihrer Beschriftung, in absteigender Reichweite */
function token_umfaenge(): array
{
    return [
        TOKEN_VOLL => t('Voller Zugriff'),
        TOKEN_SCHREIB => t('Anlegen und ändern, nicht löschen'),
        TOKEN_LESEN => t('Nur lesen'),
    ];
}

/**
 * Darf ein Schlüssel dieses Verfahren?
 *
 * Alles, was den Bestand nicht anfasst, ist Lesen. DELETE braucht den vollen
 * Umfang; alles Übrige (POST, PATCH, PUT) gilt als Schreiben.
 *
 * Unbekannte Umfänge werden wie TOKEN_VOLL behandelt: Schlüssel aus der Zeit
 * vor diesem Feld haben gar keinen, und die sollen weiter funktionieren.
 */
function token_darf(array $eintrag, string $methode): bool
{
    $umfang = (string)($eintrag['scope'] ?? TOKEN_VOLL);
    $methode = strtoupper($methode);
    if ($umfang === TOKEN_LESEN) return in_array($methode, ['GET', 'HEAD'], true);
    if ($umfang === TOKEN_SCHREIB) return $methode !== 'DELETE';
    return true;
}

/** Sieht dieser Schlüssel nur, was mit ihm selbst angelegt wurde? */
function token_nur_eigene(array $eintrag): bool
{
    return !empty($eintrag['own_only']);
}

/**
 * Die Schlüssel liegen in der Datenbank, nicht in einer Datei: Nachgeschlagen
 * wird bei JEDEM API-Aufruf, und die Menge wächst mit der Zahl der Konten –
 * bei 50.000 Schlüsseln kostete das Lesen der Datei 34 ms und 86 MB je
 * Aufruf. Als Abfrage über den Primärschlüssel sind es Mikrosekunden.
 */

/**
 * Neuen Schlüssel erzeugen. Der Klartext wird genau einmal zurückgegeben und
 * danach nirgends mehr gespeichert.
 *
 * @return array{token:string,id:string}
 */
function token_create(string $user, string $label, string $umfang = TOKEN_VOLL,
                      bool $nurEigene = false): array
{
    if (!isset(token_umfaenge()[$umfang])) $umfang = TOKEN_VOLL;
    $plain = TOKEN_PREFIX . bin2hex(random_bytes(20));
    $abdruck = hash('sha256', $plain);
    $id = substr($abdruck, 0, 12);

    db_token_put(db(), $abdruck, [
        'id' => $id,
        'user' => $user,
        'label' => mb_substr(trim($label), 0, 60),
        // Die ersten Zeichen im Klartext, damit sich mehrere Schlüssel in
        // der Liste auseinanderhalten lassen. Zum Anmelden reicht das nicht.
        'hint' => substr($plain, 0, strlen(TOKEN_PREFIX) + 6),
        'created' => date('c'),
        'last_used' => null,
        'scope' => $umfang,
        // Nur setzen, wenn gewünscht – ein fehlendes Feld ist die Antwort
        // „nein" und hält den Datensatz klein.
        'own_only' => $nurEigene ?: null,
    ]);
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
    $eintrag = db_token_get(db(), $abdruck);
    if ($eintrag === null) return null;

    // Höchstens einmal pro Stunde zurückschreiben. Bei jedem Aufruf zu
    // schreiben hieße, dass sich sämtliche Anfragen an der Sperre dieser einen
    // Datei aufreihen – für eine Angabe, die auf die Stunde genau reicht.
    $letzte = (string)($eintrag['last_used'] ?? '');
    if ($letzte === '' || strtotime($letzte) < time() - 3600) {
        $eintrag['last_used'] = date('c');
        db_token_put(db(), $abdruck, $eintrag);
    }
    return $eintrag;
}

/** @return array<string,array> Schlüssel eines Kontos, neueste zuerst */
function tokens_of(string $user): array
{
    $st = db()->prepare('SELECT fingerprint, data FROM tokens WHERE owner = ? ORDER BY created DESC');
    $st->execute([$user]);
    $out = [];
    while (($zeile = $st->fetch()) !== false) {
        $d = json_decode((string)$zeile['data'], true);
        if (is_array($d)) $out[(string)$zeile['fingerprint']] = $d;
    }
    return $out;
}

/**
 * Schlüssel zurückziehen. Die Kennung des Kontos wird mitgeprüft, damit sich
 * über eine geratene Kennung nicht fremde Schlüssel entwerten lassen.
 */
function token_revoke(string $user, string $id): bool
{
    // Kennung UND Konto in der Bedingung: über eine geratene Kennung sollen
    // sich keine fremden Schlüssel entwerten lassen
    $st = db()->prepare('DELETE FROM tokens WHERE id = ? AND owner = ?');
    $st->execute([$id, $user]);
    return $st->rowCount() > 0;
}

/** Alle Schlüssel eines Kontos entfernen – beim Löschen des Kontos */
function tokens_drop_user(string $user): void
{
    $st = db()->prepare('DELETE FROM tokens WHERE owner = ?');
    $st->execute([$user]);
}

/**
 * Darf von dieser Adresse überhaupt noch ein Schlüssel geprüft werden?
 *
 * Gegenstück zu login_source_ok(): Es liest nur. Gezählt wird in api.php
 * ausschließlich dann, wenn ein Schlüssel sich als falsch erweist – sonst
 * verbrauchten rechtmäßige Aufrufe das Kontingent, das dem Durchprobieren
 * gilt.
 */
function api_source_ok(): bool
{
    $file = data_path('ratelimit') . '/apiauth-' . ip_hash() . '.json';
    $d = json_read($file, []);
    return ($d['hour'] ?? '') !== date('YmdH') || (int)($d['n'] ?? 0) < 60;
}
