# Programmierschnittstelle

Kurzlinks anlegen, ändern, löschen und Klickzahlen abrufen — aus einem
Skript, einem Kassensystem, einem Redaktionswerkzeug.

Die Schnittstelle kann **nichts, was das Konto nicht auch über die Oberfläche
könnte**. Rechte, Limits, Namensräume und Gruppenzugehörigkeit gelten
unverändert: Ein Schlüssel ist ein zweiter Weg zur Anmeldung, keine zweite
Berechtigung. Sämtliche Regeln kommen aus derselben Fassung wie die der
Verwaltungsoberfläche ([`inc/linkrules.php`](inc/linkrules.php)).

---

## Voraussetzungen

Das Konto braucht das Recht **`api_access`**. Es hängt wie alle Rechte an einer
Gruppe; ein Administrator schaltet es frei. Soll es auf einer Instanz für alle
gelten, gehört es in `'default_perms'` in der Konfiguration.

## Schlüssel anlegen

*Profil → Programmierschnittstelle → Anlegen.* Der Schlüssel wird **einmal**
angezeigt und danach nirgends mehr — gespeichert ist nur sein Abdruck. Geht er
verloren, wird er zurückgezogen und ein neuer angelegt.

Ein Schlüssel beginnt mit `flk_`. Das ist Absicht: Taucht er versehentlich in
einem Protokoll oder einem öffentlichen Repository auf, ist er als solcher zu
erkennen und lässt sich gezielt suchen.

## Anmeldung

```
Authorization: Bearer flk_…
```

Nimmt der Server den Kopf `Authorization` vor PHP weg — auf manchem Shared
Hosting der Fall —, geht auch:

```
X-Api-Key: flk_…
```

Die mitgelieferte `.htaccess` reicht `Authorization` zusätzlich als
Umgebungsvariable durch, sodass der übliche Weg in aller Regel funktioniert.

**Sitzungs-Cookies werden bewusst nicht akzeptiert.** Andernfalls könnte eine
fremde Seite im Browser eines angemeldeten Nutzers Anfragen stellen und dessen
Links ändern. Ohne Cookie gibt es diese Angriffsfläche nicht — und deshalb
braucht die Schnittstelle auch kein CSRF-Token.

## Adressen

| Form | Wann |
| --- | --- |
| `/api/links/abc123` | mit der Regel aus der mitgelieferten `.htaccess` |
| `/api.php/links/abc123` | wenn der Server `PATH_INFO` liefert |
| `/api.php?p=/links/abc123` | Rückfall, wenn nicht |

Anfragekörper: JSON (`Content-Type: application/json`) oder
Formularfelder — beides wird gelesen.

---

## Endpunkte

### `GET /me`

Konto, Rechte, Limits, verbleibender Rahmen.

```bash
curl -H "Authorization: Bearer $KEY" https://example.org/api/me
```

```json
{
  "account": "anna",
  "role": "user",
  "groups": ["pro"],
  "permissions": ["logo_upload", "custom_code", "api_access"],
  "limits": { "links": "500", "links_used": 42, "stats_days": "365", "logos": "100" },
  "domains": ["example.org", "kunde.link"],
  "assignable_groups": [],
  "rate_limit_per_hour": 300
}
```

### `GET /links`

Alle Links, auf die das Konto Zugriff hat.

| Parameter | Bedeutung |
| --- | --- |
| `q` | sucht in Code, Ziel und Name |
| `group` | nur Links dieser Gruppe |
| `tag` | nur Links mit diesem Schlagwort |
| `limit` | 1–200, Vorgabe 50 |
| `offset` | zum Blättern |

```json
{ "total": 42, "limit": 50, "offset": 0, "links": [ … ] }
```

### `POST /links`

```bash
curl -X POST https://example.org/api/links \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.org/eine/lange/adresse","title":"Speisekarte"}'
```

| Feld | Pflicht | Bemerkung |
| --- | --- | --- |
| `url` | ja | http/https; fehlt das Schema, wird `https://` ergänzt |
| `code` | nein | Wunsch-Name; braucht das Recht `custom_code` |
| `title` | nein | Name für die eigene Übersicht |
| `tags` | nein | Schlagworte zum Ordnen – Liste oder Zeichenkette mit Kommas; höchstens acht, je 24 Zeichen, werden kleingeschrieben |
| `domain` | nein | Adresse, unter der der Link stehen soll; leer oder unbekannt = Hauptdomain |
| `expires` | nein | `JJJJ-MM-TT`, frühestens heute |
| `group` | nein | nur Arbeitsgruppen des Kontos |
| `password` | nein | Zugriffsschutz vor der Weiterleitung |

Antwort **201** mit dem angelegten Link, dazu ein `Location`-Kopf.

### `GET /links/{code}`

Ein einzelner Link.

### `PATCH /links/{code}`

Ändert **nur die übergebenen Felder**. Ein Aufruf, der bloß das Ziel setzt,
lässt den Namen unangetastet — anders als ein Formular, das seine Felder immer
vollständig mitschickt. Um einen Namen zu entfernen, wird `"title": ""`
ausdrücklich übergeben.

Zusätzlich zu den Feldern von `POST`: `disabled` (`true`/`false`) sperrt den
Link, `"password": ""` hebt den Zugriffsschutz auf.

### `DELETE /links/{code}`

Löscht Link und Klickzähler. Antwort `{"deleted": "abc123"}`.

### `GET /links/{code}/stats`

```json
{ "code": "abc123", "total": 42, "last": "2026-08-14", "days": { "2026-08-14": 7 } }
```

Nur so weit zurück, wie das Konto Statistik sehen darf. **`last` ist
tagesgenau, nicht sekundengenau** — es gibt keine feinere Angabe, weil keine
gespeichert wird. Einzelne Aufrufe existieren nicht, also auch nicht in der
Schnittstelle: keine Herkunft, kein Gerät, kein Land.

---

## Fehler

```json
{ "error": { "code": "rejected", "message": "Ungültige Ziel-URL (nur http/https)." } }
```

| Status | `code` | Bedeutung |
| --- | --- | --- |
| 401 | `no_key`, `bad_key`, `no_account` | Schlüssel fehlt, ist unbekannt oder zurückgezogen |
| 403 | `no_permission` | Konto hat `api_access` nicht |
| 404 | `not_found` | Link oder Endpunkt gibt es nicht |
| 405 | `method_not_allowed` | Methode hier nicht vorgesehen |
| 409 | `not_created` | Code bereits vergeben |
| 422 | `rejected` | Eingabe verstößt gegen eine Regel |
| 429 | `rate_limited`, `too_many_attempts` | Stundengrenze erreicht |

**404 statt 403 für fremde Links** ist Absicht: Andernfalls ließe sich über die
Schnittstelle herausfinden, welche Kurzcodes bereits vergeben sind.

## Grenzen

`'api_rate_limit'` in der Konfiguration, Vorgabe 300 Anfragen je Stunde und
Schlüssel. Gezählt wird nach Schlüssel, nicht nach IP — ein Server, der die
Schnittstelle bedient, kommt immer von derselben Adresse. Fehlgeschlagene
Anmeldungen zählen getrennt nach IP, damit sich Schlüssel nicht durchprobieren
lassen.

Höchstens zehn Schlüssel je Konto.

## Beispiel: Serie anlegen

```bash
#!/bin/sh
KEY="flk_…"
BASE="https://example.org/api"

while IFS=';' read -r url name; do
  curl -s -X POST "$BASE/links" \
    -H "Authorization: Bearer $KEY" \
    -H "Content-Type: application/json" \
    -d "$(printf '{"url":"%s","title":"%s"}' "$url" "$name")" \
  | sed -n 's/.*"short_url": "\([^"]*\)".*/\1/p'
done < liste.csv
```

Für einen einmaligen Umzug von einem anderen Dienst ist der CSV-Import in der
Oberfläche der kürzere Weg — er versteht die Ausfuhren von Bitly und YOURLS
unmittelbar.
