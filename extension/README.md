# Browser-Erweiterung

Kürzt die geöffnete Seite auf **deinem eigenen** flatlink-Server – ein
Klick in der Werkzeugleiste, Kurzlink in der Zwischenablage.

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
  Store-Eintrag („unlisted" bei addons.mozilla.org), müsste aber je Server
  gemacht werden, weil deren Adresse im Manifest steht.

Für den Weg über die Läden braucht es also eine **generische** Fassung, die
erst beim Einrichten erfährt, zu welchem Server sie gehört. Genau dafür
gibt es den Verbindungscode.

## Der schnellste Weg: Verbindungscode

Erweiterung aus einem Laden installiert, dann:

1. Auf dem Server unter **Profil → Browser-Erweiterung** einen
   **Verbindungscode** erzeugen – ein Klick.
2. In den Einstellungen der Erweiterung einfügen, *Verbinden* – ein Klick.

Im Code stehen Adresse und ein frisch erzeugter Zugangsschlüssel. Er wird
geprüft, bevor etwas gespeichert wird. Weitergeben sollte man ihn nicht: Wer
ihn hat, kann im eigenen Namen Kurzlinks anlegen – zurückziehen lässt er
sich unter *Profil → Zugangsschlüssel*.

Wer ein Konto auf einem flatlink-Server hat, braucht diesen Ordner gar
nicht. Was unten steht, ist der Weg für alle anderen: Entwickler, oder wer
lieber selbst einträgt.

Bis August 2026 konnte ein Server auch selbst ein fertig eingerichtetes
Archiv packen. Das ist entfallen: Die neutrale Fassung steht inzwischen in
den Läden, fragt beim ersten Öffnen nach der Adresse, und der
Verbindungscode trägt sie samt Schlüssel in einem Zug ein – ohne die
Nachteile eines Archivs, das man von Hand entpackt, im Entwicklermodus lädt
und das sich nie aktualisiert.

## Einrichten

1. Auf deinem flatlink-Server unter **Profil → Zugangsschlüssel** einen Schlüssel
   anlegen. Er wird nur einmal angezeigt.
2. Die Erweiterung laden (siehe unten) und in ihren Einstellungen die
   Adresse deines flatlink-Servers und den Schlüssel eintragen.
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
| Domain | Auswahl, wenn der Server mehrere führt – sonst verborgen |
| Gruppe | Auswahl, wenn das Konto Arbeitsgruppen hat – sonst verborgen |
| Mehr | Schlagwörter und Ablaufdatum, in einer Klappe |
| Schon gekürzt | Gibt es für diese Adresse bereits einen Kurzlink, steht er da – mit Kopieren-Knopf, statt einen zweiten anzulegen |
| Ergebnis | Kurzlink kopieren, QR-Code zum Abscannen (auch als PNG zu sichern), oder weiter zum Server: QR-Designer und Linkverwaltung, beide direkt beim frisch angelegten Link |
| Tastenkürzel | `Alt+Shift+K` öffnet das Popup – im Browser frei änderbar |
| Rahmen | Hinweis, sobald das Link-Limit zu 80 % belegt ist |

Nicht dabei, und zwar mit Absicht: **UTM-Parameter** (fünf Felder – wer
Kampagnen baut, sitzt in der Verwaltung), **Passwortschutz** (selten, und
ein Passwortfeld im Popup lädt zum Missverständnis ein) und **Statistik**
(Auswerten ist nicht Aufgabe eines Werkzeugs zum Anlegen). Alles drei kann
die Schnittstelle – die Oberfläche des Servers auch.

## Was sie darf – und was nicht

| | |
| --- | --- |
| `activeTab` | Die Adresse des Tabs, in dem du auf das Symbol klickst. Nur dann, nur diese. |
| `storage` | Adresse und Zugangsschlüssel, im **lokalen** Speicher des Browsers – nicht in der Synchronisierung, damit der Schlüssel nicht durch fremde Rechenzentren wandert. |
| Host-Zugriff | Wird erst beim Einrichten für **deine** Adresse angefragt (`optional_host_permissions`). Die Erweiterung verlangt keinen Zugriff auf „alle Seiten" im Voraus. |

Was sie **nicht** tut: keine Seiteninhalte lesen, kein Skript in Seiten
einspritzen, keine Hintergrundprozesse, keine Verbindung zu irgendeiner
anderen Adresse als deinem Server.

**Der QR-Code im Popup ist bewusst schmucklos.** Er beantwortet eine Frage,
die im Browser oft ansteht: den Link vom Bildschirm aufs Handy bekommen.
Dafür genügt der Code, wie der Server ihn ausgibt – samt Absenderzeile,
falls dort eine eingestellt ist. Alles Weitere – Farben, Formen, Logo,
Rahmen mit Text und Druckdateien in PDF, EPS und CMYK – kann der Designer
des Servers, und dorthin führt ein Link mit dem eben angelegten Code.

Das Bild kommt von `qr.php` und braucht **keinen Zugangsschlüssel**: Ein
QR-Code gehört zum Link, nicht zum Konto. Die Erweiterung hängt dafür also
kein `<img>` an fremde Adressen und holt nichts über die Schnittstelle.

Der Quelltext sind fünf kleine Dateien: [`popup.js`](popup.js),
[`options.js`](options.js), [`i18n.js`](i18n.js) und die beiden HTML-Seiten.
Zusammen gut 600 Zeilen – nachlesbar an einem Nachmittag.

## Deutsch und Englisch

Die Oberfläche folgt der Sprache des Browsers. Die Texte stehen in
[`_locales/`](_locales/) – `de` und `en`, gleich viele Schlüssel in beiden.

Der Weg dorthin ist etwas umständlicher als erwartet: Browser lösen
`__MSG_…__` nur im Manifest und in CSS auf, **nicht in HTML**. Die Elemente
tragen deshalb ein `data-i18n`-Attribut, und [`i18n.js`](i18n.js) füllt sie
beim Laden – mit `textContent`, nie mit `innerHTML`. Wer eine Sprache
hinzufügt, legt einen Ordner unter `_locales/` an und ergänzt in
[`tools/store-build.php`](../tools/store-build.php) eine Regel in
`$markenTexte`; ohne sie bricht der Bau einer gebrandeten Fassung ab, statt
in der neuen Sprache weiter „dein Server" zu sagen.

Im Englischen heißt die eigene Installation **flatlink server**, nicht
„instance": Wer die Erweiterung einrichtet, trägt eine Serveradresse ein und
hat den Server oft nicht selbst aufgesetzt.

## Fehler und ihre Ursachen

| Meldung | Meist |
| --- | --- |
| „Der Server ist nicht erreichbar" | Adresse falsch, Server aus, oder die Berechtigung wurde nicht erteilt |
| „Der Zugangsschlüssel gilt nicht" | Schlüssel zurückgezogen, vertippt, oder er gehört zu einem anderen Server |
| „Diesem Konto fehlt die Berechtigung" | Recht `api_access` fehlt (oder `custom_code` beim Wunsch-Namen) |
| „Zu viele Anfragen" | Stundengrenze der Schnittstelle erreicht (`api_rate_limit`) |

## In die Läden bringen

Wer die Erweiterung im Chrome Web Store oder bei addons.mozilla.org anbieten
will – als generische Fassung oder gebrandet für den eigenen Server –, baut
sich die Pakete mit [`tools/store-build.php`](../tools/store-build.php):

```bash
php tools/store-build.php --out=./dist
php tools/store-build.php --out=./dist --instanz=https://kurz.example.org \
  --name="Kurzlinks" --icon=/pfad/zu/icon-512.png
```

Die Fassungsnummer steht in `extension/manifest.json` und wandert von dort
ins Paket; `--version=` übersteuert sie einmalig. Vor dem Hochladen lohnt
Mozillas eigene Prüfung – sie findet, was die Läden später bemängeln:

```bash
cd extension && npx web-ext lint
```

Bildschirmfotos für die Ladenseiten baut
[`tools/screenshots.php`](../tools/screenshots.php) aus einem entpackten
Paket: 1280×800, wie Chrome es verlangt.

## Version

1.3.3 – gebaut gegen die Schnittstelle von flatlink 3.5.0.
