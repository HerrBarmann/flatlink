# Browser-Erweiterung

Kürzt die geöffnete Seite auf **deiner eigenen** flatlink-Instanz – ein Klick
in der Werkzeugleiste, Kurzlink in der Zwischenablage.

Der Unterschied zu den Erweiterungen der bekannten Dienste: Sie redet mit
genau einer Adresse, nämlich der, die du einträgst. Es gibt keinen Anbieter
dahinter, der mitliest, welche Seiten du kürzt.

## Warum es keinen „Jetzt installieren"-Knopf gibt

Weil die Browser ihn abgeschafft haben, nicht weil er fehlt:

* **Chrome** erlaubt Installationen seit 2018 ausschließlich aus dem Web
  Store. Eine `.crx` von der eigenen Seite lässt sich nur per
  Unternehmens-Richtlinie ausrollen.
* **Firefox** installiert eine `.xpi` per Klick von jeder Seite – aber nur,
  wenn sie **von Mozilla signiert** ist. Das geht kostenlos und ohne
  Store-Eintrag („unlisted" bei addons.mozilla.org), müsste aber je Instanz
  gemacht werden, weil deren Adresse im Manifest steht.

Für den Weg über die Läden braucht es also eine **generische** Fassung, die
erst beim Einrichten erfährt, zu welcher Instanz sie gehört. Genau dafür gibt
es den Verbindungscode.

## Der schnellste Weg: Verbindungscode

Erweiterung installiert (aus einem Laden oder als Archiv), dann:

1. In der Instanz unter **Profil → Browser-Erweiterung** einen
   **Verbindungscode** erzeugen – ein Klick.
2. In den Einstellungen der Erweiterung einfügen, *Einlösen* – ein Klick.

Im Code stehen Adresse und ein frisch erzeugter Zugangsschlüssel. Er wird
geprüft, bevor etwas gespeichert wird. Weitergeben sollte man ihn nicht: Wer
ihn hat, kann im eigenen Namen Kurzlinks anlegen – zurückziehen lässt er sich
unter *Profil → Zugangsschlüssel*.

## Der bequeme Weg: fertig vorbereitet aus der Instanz

Wer ein Konto auf einer flatlink-Instanz hat, braucht diesen Ordner gar
nicht: Unter **Profil → Browser-Erweiterung** liegt ein Knopf, der ein Archiv
baut, in dem alles schon steht – Adresse, Name und Symbole der Instanz und
auf Wunsch ein eigens dafür erzeugter Zugangsschlüssel. Laden, fertig.

Die vorbereitete Fassung verlangt außerdem weniger: Weil die Adresse
feststeht, fragt sie nach Zugriff auf genau diese eine – nicht nach der
Möglichkeit, überhaupt eine anzugeben.

Was unten steht, ist der Weg für alle anderen: Entwickler, Instanzen ohne
Konto, oder wer lieber selbst einträgt.

## Einrichten

1. In deiner Instanz unter **Profil → Zugangsschlüssel** einen Schlüssel
   anlegen. Er wird nur einmal angezeigt.
2. Die Erweiterung laden (siehe unten) und in ihren Einstellungen die Adresse
   deiner Instanz und den Schlüssel eintragen.
3. **Prüfen und speichern** – dabei fragt der Browser die Berechtigung für
   genau diese Adresse ab. Erst wenn `/api/me` antwortet, wird gespeichert.

Das Konto braucht das Recht `api_access`; für Wunsch-Namen zusätzlich
`custom_code`.

## Laden

**Chrome, Edge, Brave, Vivaldi**

1. `chrome://extensions` öffnen
2. *Entwicklermodus* einschalten
3. *Entpackte Erweiterung laden* → diesen Ordner (`extension/`) auswählen

**Firefox**

1. `about:debugging#/runtime/this-firefox` öffnen
2. *Temporäres Add-on laden* → `manifest.json` in diesem Ordner auswählen

Temporär geladen heißt in Firefox: bis zum nächsten Neustart. Für den
Dauerbetrieb muss die Erweiterung signiert sein – über
[addons.mozilla.org](https://addons.mozilla.org/developers/) oder eine
Firefox-Fassung, die unsignierte Add-ons erlaubt (ESR, Developer Edition).

## Was sie kann

| | |
| --- | --- |
| Kürzen | Adresse des Tabs, Name (aus dem Seitentitel vorgeschlagen), Wunsch-Name |
| Domain | Auswahl, wenn die Instanz mehrere führt – sonst verborgen |
| Gruppe | Auswahl, wenn das Konto Arbeitsgruppen hat – sonst verborgen |
| Mehr | Schlagworte und Ablaufdatum, in einer Klappe |
| Schon gekürzt | Gibt es für diese Adresse bereits einen Kurzlink, steht er da – mit Kopieren-Knopf, statt einen zweiten anzulegen |
| Ergebnis | Kurzlink kopieren, oder weiter in die Instanz: QR-Designer und Linkverwaltung, beide direkt beim frisch angelegten Link |
| Rahmen | Hinweis, sobald das Link-Limit zu 80 % belegt ist |

Nicht dabei, und zwar mit Absicht: **UTM-Parameter** (fünf Felder – wer
Kampagnen baut, sitzt in der Verwaltung), **Passwortschutz** (selten, und ein
Passwortfeld im Popup lädt zum Missverständnis ein) und **Statistik**
(Auswerten ist nicht Aufgabe eines Werkzeugs zum Anlegen). Alles drei kann
die Schnittstelle – die Oberfläche der Instanz auch.

## Was sie darf – und was nicht

| | |
| --- | --- |
| `activeTab` | Die Adresse des Tabs, in dem du auf das Symbol klickst. Nur dann, nur diese. |
| `storage` | Adresse und Zugangsschlüssel, im **lokalen** Speicher des Browsers – nicht in der Synchronisierung, damit der Schlüssel nicht durch fremde Rechenzentren wandert. |
| Host-Zugriff | Wird erst beim Einrichten für **deine** Adresse angefragt (`optional_host_permissions`). Die Erweiterung verlangt keinen Zugriff auf „alle Seiten" im Voraus. |

Was sie **nicht** tut: keine Seiteninhalte lesen, kein Skript in Seiten
einspritzen, keine Hintergrundprozesse, keine Verbindung zu irgendeiner
anderen Adresse als deiner Instanz.

**Kein QR-Code im Popup.** Der QR-Designer der Instanz kann Farben, Formen,
Logo, Rahmen mit Text und Druckdateien (PDF, EPS, CMYK). Ein Knopf, der ein
512-Pixel-PNG einblendet, wäre daneben kein Angebot, sondern eine Ablenkung –
also führt ein Link direkt dorthin, mit dem eben angelegten Code.

Der Quelltext sind vier kleine Dateien: [`popup.js`](popup.js),
[`options.js`](options.js) und die beiden HTML-Seiten. Zusammen unter 200
Zeilen – nachlesbar an einem Nachmittag.

## Fehler und ihre Ursachen

| Meldung | Meist |
| --- | --- |
| „Die Instanz ist nicht erreichbar" | Adresse falsch, Instanz aus, oder die Berechtigung wurde nicht erteilt |
| „Der Zugangsschlüssel gilt nicht" | Schlüssel zurückgezogen, vertippt, oder er gehört zu einer anderen Instanz |
| „Diesem Konto fehlt die Berechtigung" | Recht `api_access` fehlt (oder `custom_code` beim Wunsch-Namen) |
| „Zu viele Anfragen" | Stundengrenze der Schnittstelle erreicht (`api_rate_limit`) |

## In die Läden bringen

Wer die Erweiterung im Chrome Web Store oder bei addons.mozilla.org anbieten
will – als generische Fassung oder gebrandet für die eigene Instanz –, findet
in [docs/store-einreichung.md](../docs/store-einreichung.md) alles dafür:
Bau-Befehle, Beschreibungstexte, die Begründungen zu jeder Berechtigung und
die Antworten auf die Datenschutz-Fragebögen.

## Version

1.0.0 – gebaut gegen die Schnittstelle von flatlink 2.6.0.
