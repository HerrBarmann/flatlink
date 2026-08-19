# Programmierschnittstelle

Kurzlinks anlegen, ändern, löschen und Klickzahlen abrufen – aus einem
Skript, einem Kassensystem, einem Redaktionswerkzeug. 🇬🇧 [English
version](API.en.md).

Die Schnittstelle kann **nichts, was das Konto nicht auch über die
Oberfläche könnte**. Rechte, Limits, Namensräume und Gruppenzugehörigkeit
gelten unverändert: Ein Schlüssel ist ein zweiter Weg zur Anmeldung, keine
zweite Berechtigung. Sämtliche Regeln stammen aus derselben Quelle wie die
der Verwaltungsoberfläche ([`inc/linkrules.php`](../inc/linkrules.php)).

---

**Maschinenlesbar:** Dieselbe Schnittstelle als OpenAPI 3.1 in
[`openapi.yaml`](openapi.yaml) – für erzeugte Clients, Postman, Insomnia oder
einen Eintrag in einem Werkzeugkatalog. Was hier steht, sind die Begründungen;
dort stehen die Formen.

## Voraussetzungen

Das Konto braucht das Recht **`api_access`**. Es hängt wie alle Rechte an
einer Gruppe; ein Administrator schaltet es frei. Soll es auf einer Instanz
für alle gelten, gehört es in `'default_perms'` in der Konfiguration.

## Schlüssel anlegen

*Profil → Programmierschnittstelle → Anlegen.* Der Schlüssel wird **einmal**
angezeigt und danach nirgends mehr – gespeichert ist nur sein Hash. Geht er
verloren, wird er zurückgezogen und ein neuer angelegt.

Ein Schlüssel beginnt mit `flk_`. Das ist Absicht: Taucht er versehentlich
in einem Protokoll oder einem öffentlichen Repository auf, ist er als
solcher zu erkennen und lässt sich gezielt suchen.

### Umfang: weniger dürfen als das Konto

Ein Schlüssel kann nie **mehr**, als sein Konto darf – Rechte, Limits und
Gruppen gelten unverändert. Er kann aber **weniger**, und das ist der Sinn:
Ein Schlüssel wandert weiter als ein Passwort. Er steckt im Kassensystem, im
Auftrag einer Werkstatt, in einem Verbindungscode, den jemand per
Zwischenablage weiterreicht.

| Umfang | Erlaubt |
| --- | --- |
| **Voller Zugriff** | alles, was das Konto darf (Voreinstellung) |
| **Anlegen und ändern** | alles außer `DELETE` |
| **Nur lesen** | nur `GET` |

Ein Aufruf jenseits des Umfangs endet mit **403** und `scope_exceeded`,
bevor er das Stundenkontingent berührt.

Dazu kommt **nur eigene Links**: Ein so gesetzter Schlüssel sieht und ändert
ausschließlich, was mit ihm selbst angelegt wurde – auch nicht das, was
dasselbe Konto über die Oberfläche erzeugt. Alles Übrige beantwortet die
Schnittstelle wie einen fremden Link, also mit 404. Für ein Kassensystem, das
täglich Bewertungs-Codes erzeugt, ist das die eigentliche Absicherung: Ein
gestohlener Schlüssel kommt an den restlichen Bestand nicht heran.

Schlüssel aus der Zeit vor dieser Fassung tragen keinen Umfang und behalten
den vollen – sie hören nicht plötzlich auf zu funktionieren. Der
Verbindungscode für die Browser-Erweiterung erzeugt seit 3.5.3 einen Schlüssel
**ohne Löschrecht**; die Erweiterung braucht keines.

## Anmeldung

```
Authorization: Bearer flk_…
```

Entfernt der Server den Kopf `Authorization`, bevor PHP ihn sieht – auf
manchem Shared Hosting der Fall —, geht auch:

```
X-Api-Key: flk_…
```

Die mitgelieferte `.htaccess` reicht `Authorization` zusätzlich als
Umgebungsvariable durch, sodass der übliche Weg in aller Regel funktioniert.

**Sitzungs-Cookies werden bewusst nicht akzeptiert.** Andernfalls könnte
eine fremde Seite im Browser eines angemeldeten Nutzers Anfragen stellen und
dessen Links ändern. Ohne Cookie gibt es diese Angriffsfläche nicht – und
deshalb braucht die Schnittstelle auch kein CSRF-Token.

## Adressen

| Form | Wann |
| --- | --- |
| `/api/links/abc123` | mit der Regel aus der mitgelieferten `.htaccess` |
| `/api.php/links/abc123` | wenn der Server `PATH_INFO` liefert |
| `/api.php?p=/links/abc123` | Ausweichweg, wenn nicht |

Anfragekörper: JSON (`Content-Type: application/json`) oder Formularfelder –
beides wird gelesen.

---

> Für den Alltag gibt es die [Browser-Erweiterung](../extension/README.md): Sie
> nutzt genau diese Schnittstelle, um die geöffnete Seite mit einem Klick zu
> kürzen.

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
| `tags` | nein | Schlagwörter zum Ordnen – Liste oder Zeichenkette mit Kommas; höchstens acht, je 24 Zeichen, werden kleingeschrieben |
| `domain` | nein | Adresse, unter der der Link stehen soll; leer oder unbekannt = Hauptdomain |
| `utm` | nein | Objekt mit `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`; wird an `url` gehängt |
| `expires` | nein | `JJJJ-MM-TT`, frühestens heute |
| `group` | nein | nur Arbeitsgruppen des Kontos |
| `password` | nein | Zugriffsschutz vor der Weiterleitung |

Antwort **201** mit dem angelegten Link, dazu ein `Location`-Kopf.

### `GET /links/{code}`

Ein einzelner Link.

### `PATCH /links/{code}`

Ändert **nur die übergebenen Felder**. Ein Aufruf, der bloß das Ziel setzt,
lässt den Namen unangetastet – anders als ein Formular, das seine Felder
immer vollständig mitschickt. Um einen Namen zu entfernen, wird `"title":
""` ausdrücklich übergeben.

Zusätzlich zu den Feldern von `POST`: `disabled` (`true`/`false`) sperrt den
Link, `"password": ""` hebt den Zugriffsschutz auf.

`utm` verhält sich innerhalb seines Objekts genauso: Nur die übergebenen
Parameter werden angefasst. `{"utm": {"utm_campaign": "winter"}}` tauscht
die Kampagne und lässt `utm_source` stehen; ein leerer Wert entfernt einen
einzelnen Parameter. Die Parameter werden **nicht getrennt gespeichert** –
sie stehen in `url`, und `utm` in der Antwort ist ausgelesen, nicht
abgelegt.

### `DELETE /links/{code}`

Löscht Link und Klickzähler. Antwort `{"deleted": "abc123"}`.

### Weichen (`rules`)

```json
{ "rules": [
    { "wenn": "device", "ist": "mobile", "url": "https://apps.apple.com/…" },
    { "wenn": "lang",   "ist": "en",     "url": "https://example.com/en" }
] }
```

`GET` liefert die Liste mit, `PATCH` setzt sie (eine leere Liste löscht alle
Weichen). Merkmale: `device` (`mobile`/`tablet`/`desktop`), `lang` und
`country` (je zwei Buchstaben) sowie `split` (Anteil von 1 bis 99). Die
erste zutreffende Weiche gewinnt, sonst gilt `url`. Braucht das Recht
`link_rules`; höchstens acht je Link. Ausgewertet wird bei jeder Anfrage –
gespeichert wird davon nichts, gezählt nur, wie oft jede Weiche gegriffen
hat.

### `GET /links/{code}/stats`

```json
{ "code": "abc123", "total": 42, "last": "2026-08-14", "days": { "2026-08-14": 7 },
  "refs": { "google.com": 12, "-": 30 },
  "devices": { "mobile": 28, "desktop": 14 },
  "languages": { "de": 35, "en": 7 } }
```

`refs`, `devices` und `languages` sind Summen über alle Aufrufe – keine
Zeitreihe und kein Datensatz je Aufruf. Sie fehlen, wenn die Instanz
`'click_dims' => false` gesetzt hat. `-` steht für Aufrufe ohne erkennbare
Herkunft (getippt, QR-Code, App).

`days` reicht nur so weit zurück, wie das Konto Statistik sehen darf.
**`last` ist tagesgenau, nicht sekundengenau** – es gibt keine feinere
Angabe, weil keine gespeichert wird. Einzelne Aufrufe existieren nicht, also
auch nicht in der Schnittstelle: Es gibt keinen Endpunkt, der einen
einzelnen Klick, seine Uhrzeit oder seine Adresse liefert, weil so etwas
nirgends steht.

---

### Zwei Felder seit 3.0

| Feld | Bedeutung |
| --- | --- |
| `lang` | Sprache der Ziel-URL, zwei Buchstaben. Grundlage der Sprachauswahl der Weichen: Nur mit ihr kann ein Besucher mit passender Zweitsprache richtig verteilt werden. |
| `max_visits` | Aufruf-Limit. Ganze Zahl ab 1; ist sie erreicht, antwortet der Link mit 410. Leer oder 0 = unbegrenzt. Dagegen zählt **jede ausgelieferte Weiterleitung**, auch die von Bots und HEAD-Anfragen – anders als die Statistik, die beides auslässt. Steuert den Kurzlink, schützt nicht das Ziel (siehe [Kurzlinks](kurzlinks.md#aufruf-limit)). |

Beide gelten für `POST /links` und `PATCH /links/{code}` und stehen in jeder
Link-Antwort.

### Schlagwörter (`/tags`)

Schlagwörter hängen an den Links; hier lassen sie sich über den ganzen
erreichbaren Bestand auf einmal verwalten – „erreichbar“ heißt dieselbe
Menge, die auch je Link gilt: eigene Links plus die der Arbeitsgruppen, für
Administratoren alle.

| Aufruf | Wirkung |
| --- | --- |
| `GET /tags` | alle vergebenen Schlagwörter mit Anzahl der Links |
| `PATCH /tags/{tag}` | umbenennen, Feld `name`; trägt ein Link beide, verschmelzen sie |
| `DELETE /tags/{tag}` | von allen Links entfernen |

```bash
curl -X PATCH https://example.org/api/tags/kampanien \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"name":"kampagne"}'
```

```json
{ "tag": "kampagne", "renamed_from": "kampanien", "links": 12 }
```

Der neue Name durchläuft dieselbe Aufbereitung wie beim Link:
kleingeschrieben, höchstens 24 Zeichen. Ein Schlagwort, das keiner der
eigenen Links trägt, antwortet mit 404. So wird aus dem Tippfehler in
dreißig Links ein Aufruf statt dreißig `PATCH`-Aufrufen.

### `GET /health`

Der eine Endpunkt **ohne Schlüssel** – für Monitoring gedacht: Ein Wächter
soll keinen Zugangsschlüssel hinterlegen müssen. Antwortet die Instanz und
ist ihre Ablage lesbar, kommt 200:

```json
{ "status": "pass" }
```

Sonst 503 mit `"fail"`. Mehr steht da absichtlich nicht – ein anonym
abrufbarer Endpunkt verrät weder Version noch Zahlen.

## QR-Code abrufen

Der QR-Code eines Kurzlinks ist keine eigene Ressource der Schnittstelle,
sondern derselbe öffentliche Weg, den auch die Oberfläche nutzt – ein Code
gehört zum Link, nicht zum Konto, und braucht deshalb keinen Schlüssel:

```bash
curl -o code.svg "https://example.org/qr.php?c=abc123&format=svg"
```

`format` kann `svg`, `png`, `pdf` oder `eps` sein; dazu kommen die
Gestaltungsparameter des Designers (`style`, `eye`, `fg`, `bg`, `size`,
`margin` …) – die vollständige Liste steht gleich unten. Unbekannte Codes
antworten mit 404, das Rate-Limit ist `qr_rate_limit` (Vorgabe 600 je Stunde
und Adresse, die schweren Druckformate enger).

### Gestaltung des QR-Codes

Alles, was der Designer kann, steht auch als Parameter an `qr.php` – er ist
derselbe Generator, nicht eine zweite Fassung davon.

| Parameter | Werte | Vorgabe |
| --- | --- | --- |
| `format` | `svg`, `png`, `pdf`, `eps` | `svg` |
| `size` | Kantenlänge in Pixeln (PDF rastert mindestens 2048) | 512 |
| `margin` | Ruhezone in Modulen, 0–10 | 4 |
| `ecc` | Fehlerkorrektur `L`, `M`, `Q`, `H` | `M` |
| `style` | `square`, `rounded`, `smooth`, `dot`, `diamond`, `bars-v`, `bars-h` | `square` |
| `eye` | `square`, `rounded`, `circle`, `leaf` | `square` |
| `eyecore` | dieselben Werte; leer = wie der Ring | leer |
| `fg`, `bg` | `#rrggbb`; `bg=none` macht den Grund durchsichtig | `#16181D`, `#ffffff` |
| `eyefg`, `eyecorefg` | Augenfarben; leer = wie die Module | leer |
| `grad`, `fg2`, `ga` | Verlauf `linear`/`radial`, Zweitfarbe, Winkel | aus |
| `logo`, `ls`, `lpad` | Logo aus der Bibliothek, Größe in Prozent, Abstand | – |
| `ftext` | Rahmentext, höchstens 24 Zeichen | leer |
| `mm` | Zielbreite in Millimetern für PDF und EPS | – |
| `download` | gesetzt = als Datei statt im Browser | aus |

Zwei Dinge lassen sich **nicht** über Parameter steuern, und das ist
Absicht:

- Die **Absenderzeile** unter dem Code (`qr_brand_text`) hängt am Recht
  `qr_unbranded` des **Besitzers** des Links, nicht am Aufrufer. Sonst
  könnte sie jeder mit einem Parameter abstreifen.
- Die **Logo-Bibliothek**: `logo` nimmt nur Kennungen, die der Instanz
  bekannt sind – keine fremden Adressen.

Das Rate-Limit ist `qr_rate_limit` (Vorgabe 600 je Stunde und Adresse), für
die schweren Druckformate zusätzlich `qr_rate_limit_print` (60). Angemeldete
Konten sind von beidem ausgenommen.

## Fehler

```json
{ "error": { "code": "rejected", "message": "Ungültige Ziel-URL (nur http/https)." } }
```

| Status | `code` | Bedeutung |
| --- | --- | --- |
| 401 | `no_key`, `bad_key`, `no_account` | Schlüssel fehlt, ist unbekannt oder zurückgezogen |
| 403 | `no_permission` | Konto hat `api_access` nicht |
| 403 | `scope_exceeded` | Schlüssel darf dieses Verfahren nicht (siehe Umfang) |
| 403 | `account_locked` | Konto ist gesperrt – der Schlüssel bleibt gültig und greift wieder, sobald die Sperre fällt |
| 404 | `not_found` | Link oder Endpunkt gibt es nicht |
| 405 | `method_not_allowed` | Methode hier nicht vorgesehen |
| 409 | `not_created` | Code bereits vergeben |
| 422 | `rejected` | Eingabe verstößt gegen eine Regel |
| 429 | `rate_limited`, `too_many_attempts` | Stundengrenze erreicht |

**404 statt 403 für fremde Links** ist Absicht: Andernfalls ließe sich über
die Schnittstelle herausfinden, welche Kurzcodes bereits vergeben sind.

## Grenzen

`'api_rate_limit'` in der Konfiguration, Vorgabe 300 Anfragen je Stunde und
Schlüssel. Gezählt wird nach Schlüssel, nicht nach IP – ein Server, der die
Schnittstelle nutzt, kommt immer von derselben Adresse. Fehlgeschlagene
Anmeldungen zählen getrennt nach IP – höchstens 60 je Stunde, damit sich
Schlüssel nicht durchprobieren lassen. Rechtmäßige Aufrufe rühren dieses
Kontingent nicht an; für sie gilt allein die Stundengrenze oben.

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

Für einen einmaligen Umzug von einem anderen Dienst ist der CSV-Import in
der Oberfläche der kürzere Weg – er versteht die Exporte von Bitly, YOURLS,
Shlink und Kutt unmittelbar.
