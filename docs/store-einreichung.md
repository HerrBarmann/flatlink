# Erweiterung in die Läden bringen

Alles, was Chrome Web Store und addons.mozilla.org abfragen – zum Kopieren.
Zwei Fassungen, weil sie verschiedene Zwecke haben:

| | generisch | gebrandet |
| --- | --- | --- |
| Name | flatlink | z. B. 1337.kiwi |
| Für wen | jede Instanz | eine bestimmte |
| Adresse | wird beim Einrichten erfragt | steht fest |
| Berechtigung | „Zugriff auf Websites, die du angibst“ | „Zugriff auf 1337.kiwi“ |
| Einzurichten | Adresse **und** Schlüssel | nur der Schlüssel |
| Akzentfarbe | die des Systems | die der Instanz |
| Wortlaut | „deine Instanz“ | der Name der Instanz |

Die gebrandete Fassung verlangt **weniger** Rechte – das ist ihr
eigentlicher Vorteil, und es lohnt sich, das in der Beschreibung zu sagen.

## Pakete bauen

```bash
# generisch
php tools/store-build.php --out=./dist

# gebrandet (Beispiel 1337.kiwi)
php tools/store-build.php --out=./dist \
  --instanz=https://1337.kiwi --name="1337.kiwi" \
  --icon=/pfad/zu/icon-512.png --farbe="#7ABA1C" --farbetext="#101408"
```

Ein Zugangsschlüssel kommt **nie** ins Paket: Ein Paket im Laden bekommen
alle, ein Schlüssel gehört einem. Den gibt es weiterhin nur über das Profil
der eigenen Instanz.

**Die Fassungsnummer steht in `extension/manifest.json`** – dort wird sie vor
dem Einreichen hochgezählt, und das Paket übernimmt sie. `--version=` gibt es
weiterhin, um eine Nummer einmalig zu übersteuern (etwa für einen zweiten
Anlauf nach einem Formularfehler), aber die Wahrheit steht im Manifest.

Das ist keine Förmlichkeit: Wird sie nur beim Bauen mitgegeben, weiß das
Repository nicht, was im Laden liegt. Genau so stand hier eine Weile 1.0.0,
während die Läden bereits 1.2.1 führten – und ein Paket mit kleinerer Nummer
weisen beide Läden zurück.

Aktueller Stand: **1.3.0**. Vorher im Laden: 1.2.1.

---

## Chrome Web Store

Einmalig 5 USD Entwicklergebühr, Prüfung dauert meist zwei bis fünf Tage.

**Kurzbeschreibung** (max. 132 Zeichen)

> Kurzlinks auf deiner eigenen flatlink-Instanz anlegen – ein Klick in der
> Werkzeugleiste, ohne fremden Dienst.

**Ausführliche Beschreibung**

> Kürzt die Seite, auf der du gerade bist – mit einem Klick in der
> Werkzeugleiste.
>
> Der Unterschied zu den Erweiterungen der bekannten Anbieter ist nicht die
> Funktion, sondern wer mitliest: Diese Erweiterung spricht mit genau einer
> Adresse – deiner eigenen flatlink-Instanz. Es gibt keinen Anbieter
> dahinter, der erfährt, welche Seiten du kürzt.
>
> WAS SIE KANN
> • Die Adresse des aktuellen Tabs kürzen, Name und Wunsch-Adresse optional
> • Schlagwörter und Ablaufdatum gleich mitgeben
> • Erkennt, wenn du diese Seite schon einmal gekürzt hast, und zeigt den
>   vorhandenen Kurzlink statt einen zweiten anzulegen
> • Kurzlink mit einem Klick in die Zwischenablage
> • QR-Code zum Abscannen – der schnellste Weg vom Bildschirm aufs Handy;
>   auf Wunsch als PNG zu speichern
> • Tastenkürzel Alt+Shift+K, im Browser frei änderbar
> • Ein Klick weiter zum QR-Designer deiner Instanz – dort gibt es Farben,
>   Formen, Logo und Druckdateien (PDF, EPS, CMYK)
>
> WAS SIE NICHT TUT
> • Keine Seiteninhalte lesen, keine Skripte in Seiten einspritzen
> • Keine Verbindung zu irgendeiner anderen Adresse als deiner Instanz
> • Keine Analyse, keine Kennungen, kein Hintergrundprozess
>
> BERECHTIGUNGEN
> • „Aktiver Tab“: die Adresse des Tabs, in dem du auf das Symbol klickst –
>   nur dann, nur diese
> • „Speicher“: Adresse und Zugangsschlüssel, bewusst im lokalen Speicher
>   dieses Browsers statt in der Synchronisierung
>
> VORAUSSETZUNG
> Eine eigene flatlink-Instanz (github.com/HerrBarmann/flatlink) und darin
> ein Zugangsschlüssel aus deinem Profil. Am schnellsten geht das Einrichten
> mit einem Verbindungscode: in der Instanz erzeugen, hier einfügen.
>
> Der Quelltext der Erweiterung sind vier Dateien mit gut 500 Zeilen –
> nachlesbar an einem Nachmittag: github.com/HerrBarmann/flatlink

**Kategorie:** Produktivität (Productivity) **Sprache:** Deutsch

### Berechtigungen begründen

Chrome verlangt zu jeder Berechtigung einen Satz. Diese hier reichen:

| Feld | Text |
| --- | --- |
| `activeTab` | Die Erweiterung braucht die Adresse der Seite, die gekürzt werden soll. Sie wird ausschließlich beim Klick auf das Symbol gelesen und sofort an die vom Nutzer angegebene Instanz gesendet. |
| `storage` | Speichert die Adresse der Instanz und den Zugangsschlüssel des Nutzers lokal. Ohne beides kann die Erweiterung keine Kurzlinks anlegen. |
| Host-Zugriff | Die Erweiterung spricht mit der selbst gehosteten Instanz des Nutzers. Deren Adresse ist beim Bauen nicht bekannt, deshalb wird die Berechtigung erst beim Einrichten für genau diese eine Adresse angefragt. |
| Host-Zugriff (gebrandet) | Die Erweiterung spricht ausschließlich mit https://1337.kiwi, dem Dienst, zu dem sie gehört. |
| „Warum Remote Code?“ | Wird nicht verwendet. Sämtlicher Code liegt im Paket. |

### Datennutzung

Chrome fragt eine Liste ab. Die ehrlichen Antworten:

| Frage | Antwort |
| --- | --- |
| Personenidentifizierbare Informationen | **Nein** |
| Gesundheitsdaten, Finanzdaten, Zahlungsinformationen | **Nein** |
| Authentifizierungsdaten | **Ja** – der Zugangsschlüssel des Nutzers, gespeichert nur lokal, übertragen nur an dessen eigene Instanz |
| Persönliche Kommunikation, Standort | **Nein** |
| Website-Inhalte | **Nein** – nur die Adresse des Tabs, und nur auf Klick |
| Aktivitäten des Nutzers | **Nein** |
| Werden Daten verkauft oder an Dritte weitergegeben? | **Nein** |
| Werden Daten für fremde Zwecke verwendet? | **Nein** |
| Werden Daten für Bonitätsprüfung / Kreditvergabe genutzt? | **Nein** |

Die drei Bestätigungen am Ende („Ich verwende Daten nicht für nicht
offengelegte Zwecke“, „Ich verkaufe keine Daten“, „Ich nutze keine Daten für
Bonität“) lassen sich alle guten Gewissens anhaken.

**Datenschutz-Adresse:** eine Seite, die sagt, dass die Erweiterung nichts
sammelt – für 1337.kiwi passt `https://1337.kiwi/datenschutz.php`.

---

## addons.mozilla.org (Firefox)

Kostenlos. Zwei Wege:

* **Listed** – im Verzeichnis auffindbar, mit Prüfung.
* **Unlisted** – nur signiert, nicht im Verzeichnis. Das Paket kommt
  signiert zurück und lässt sich selbst ausliefern: Ein Link auf die `.xpi`
  ist dann ein echter Ein-Klick-Installer.

**Zusammenfassung** (bis 250 Zeichen)

> Kürzt die geöffnete Seite auf deiner eigenen flatlink-Instanz. Ein Klick in
> der Werkzeugleiste, Kurzlink in der Zwischenablage, QR-Code zum Abscannen.
> Spricht mit keiner anderen Adresse – kein Anbieter dazwischen, der mitliest.

**Beschreibung:** dieselbe wie bei Chrome (Firefox erlaubt einfaches HTML;
die Aufzählungen dürfen dort `<ul><li>` sein).

**Kategorien:** Lesezeichen, Produktivität **Schlagwörter:** kurzlink,
url-shortener, qr-code, selfhosted, datenschutz **Lizenz:**
AGPL-3.0-or-later (steht im Paket) **Support-Adresse:** die Issues des
Projekts

**Für die Prüfenden** (das Feld „Notes for reviewers“):

> Die Erweiterung spricht ausschließlich mit einer flatlink-Instanz, die der
> Nutzer selbst angibt (bzw. mit https://1337.kiwi in dieser Fassung). Zum
> Testen: eine Instanz unter github.com/HerrBarmann/flatlink aufsetzen, im
> Profil einen Zugangsschlüssel anlegen und in den Einstellungen der
> Erweiterung eintragen. Kein Build-Schritt – der Quelltext im Paket ist der
> ausgelieferte Code, es gibt keine Minifizierung und keine Bündelung.
>
> Zum QR-Code im Ergebnis: Das <img> zeigt auf /qr.php derselben Instanz, mit
> der die Erweiterung ohnehin spricht. Der Code wird dort erzeugt, nicht von
> einem Dienst Dritter geholt; es werden keine Kennungen mitgeschickt, und ein
> Zugangsschlüssel ist dafür nicht nötig, weil ein QR-Code zum Kurzlink gehört
> und nicht zum Konto.

Der letzte Satz ist wichtig: Firefox verlangt sonst die Quellen des
Build-Prozesses. Hier gibt es keinen.

### Die Angabe zur Datenerhebung

Seit 2025 verlangt Mozilla im Manifest ein Feld dazu; ohne es bricht der
Upload mit *„The data_collection_permissions property is missing“* ab. Es
steht unter `browser_specific_settings.gecko`:

```json
"data_collection_permissions": { "required": ["none"] }
```

`none` ist Mozillas Wert für „sammelt keine Daten“, und das ist hier die
zutreffende Angabe: Die Erweiterung überträgt die Adresse, die der Nutzer
kürzen will, an dessen **eigene** Instanz – so wie ein FTP-Programm Dateien
überträgt. An den Entwickler oder an Dritte geht nichts, gespeichert wird
nur, was der Nutzer selbst anlegt, und der Zugangsschlüssel bleibt im
lokalen Speicher seines Browsers.

Wer das für seine Instanz anders einschätzt – etwa weil sie die Adressen
auswertet –, gibt stattdessen die Kategorien des AMO-Formulars an
(`websiteActivity`, `technicalAndInteraction` …). Das Build-Werkzeug nimmt
sie kommagetrennt entgegen:

```bash
php tools/store-build.php … --daten=websiteActivity
```

Im AMO-Formular tauchen dieselben Angaben noch einmal zum Anklicken auf –
sie müssen zum Manifest passen, sonst kommt das Paket aus der Prüfung
zurück.

---

## Die Fassung für 1337.kiwi

Fertig zum Kopieren. Sie unterscheidet sich in der Sache an einer Stelle vom
generischen Text: Es gibt nichts einzurichten außer dem Schlüssel, und die
Erweiterung kann gar nicht woandershin sprechen.

**Name:** `1337.kiwi`

Kurz, weil er in der Werkzeugleiste und in der Erweiterungsliste steht. Wer
lieber gefunden werden will, baut mit `--name="1337.kiwi – Links kürzen"`;
der Name aus dem Manifest ist auch der Name im Laden.

**Kurzbeschreibung** (Chrome, max. 132 Zeichen – dieser Text steht schon im
Manifest, also gleich beim Bauen gesetzt)

> Die geöffnete Seite auf 1337.kiwi kürzen – ein Klick, fertig. Ohne fremden
> Dienst dazwischen.

**Zusammenfassung** (Firefox, max. 250 Zeichen)

> Kürzt die geöffnete Seite auf 1337.kiwi: ein Klick in der Werkzeugleiste,
> Kurzlink in der Zwischenablage, QR-Code zum Abscannen. Sie spricht mit
> keiner anderen Adresse als 1337.kiwi – niemand sitzt dazwischen und liest
> mit.

**Ausführliche Beschreibung**

> Ein Klick in der Werkzeugleiste, und die Seite, auf der du gerade bist,
> hat einen Kurzlink auf 1337.kiwi. Kein Tab-Wechsel, kein Einfügen, kein
> Formular.
>
> Der Unterschied zu den Erweiterungen der bekannten Anbieter ist nicht die
> Funktion, sondern wer mitliest. Diese hier kennt genau eine Adresse:
> 1337.kiwi. Sie kann technisch gar nicht woandershin sprechen – der Browser
> lässt sie nur an diesen einen Host. Was sie überträgt, ist die Adresse, die
> du kürzen willst, und nichts sonst.
>
> WAS SIE KANN
> • Die Adresse des aktuellen Tabs kürzen – Titel und Wunsch-Name optional
> • Schlagwörter und Ablaufdatum gleich mitgeben
> • Erkennt, wenn du diese Seite schon einmal gekürzt hast, und zeigt den
>   vorhandenen Kurzlink – statt einen zweiten anzulegen, der dieselbe Seite
>   noch einmal zählt
> • Kurzlink mit einem Klick in die Zwischenablage
> • QR-Code zum Abscannen, gleich im Fenster – der schnellste Weg vom
>   Bildschirm aufs Handy; auf Wunsch als PNG zu speichern
> • Tastenkürzel Alt+Shift+K, im Browser frei änderbar
> • Von dort weiter in den QR-Designer von 1337.kiwi: Farben, Formen, Logo,
>   Rahmen mit Text und Druckdateien in PDF und EPS
>
> WAS SIE NICHT TUT
> • Keine Seiteninhalte lesen, keine Skripte in Seiten einspritzen
> • Keine Verbindung zu irgendeiner anderen Adresse als 1337.kiwi
> • Keine Analyse, keine Kennungen, kein Hintergrundprozess
>
> BERECHTIGUNGEN
> • „Aktiver Tab“: die Adresse des Tabs, in dem du auf das Symbol klickst –
>   nur dann, nur diese
> • „Zugriff auf 1337.kiwi“: dorthin geht die Anfrage
> • „Speicher“: dein Zugangsschlüssel, bewusst im lokalen Speicher dieses
>   Browsers statt in der Browser-Synchronisierung – ein Schlüssel hat in
>   fremden Rechenzentren nichts verloren
>
> EINRICHTEN
> Ein Konto bei 1337.kiwi genügt. Unter Profil → Browser-Erweiterung einen
> Verbindungscode erzeugen, hier einfügen, fertig. Der Schlüssel wird dabei
> eigens für die Erweiterung angelegt und lässt sich einzeln zurückziehen,
> ohne dass andere Programme stehenbleiben.
>
> WAS DAHINTERSTECKT
> 1337.kiwi läuft auf flatlink, das quelloffen unter der AGPL steht:
> github.com/HerrBarmann/flatlink. Diese Erweiterung ist dort Teil des
> Quelltextes – vier Dateien, gut 300 Zeilen, nachlesbar an einem
> Nachmittag. Wer lieber selbst hostet, nimmt dieselbe Erweiterung in der
> neutralen Fassung und trägt seine eigene Adresse ein.

**Kategorie (Chrome):** Produktivität **Kategorien (Firefox):** Lesezeichen,
Produktivität **Schlagwörter:** kurzlink, url-shortener, qr-code, 1337.kiwi,
datenschutz **Sprache:** Deutsch **Lizenz:** AGPL-3.0-or-later
**Startseite:** `https://1337.kiwi` **Datenschutz:**
`https://1337.kiwi/datenschutz.php` **Support:**
`https://1337.kiwi/impressum.php`

**Für die Prüfenden**

> Diese Fassung gehört zum Dienst 1337.kiwi und spricht ausschließlich mit
> https://1337.kiwi – der Host-Zugriff im Manifest ist entsprechend fest
> gesetzt. Zum Testen: unter https://1337.kiwi ein kostenloses Konto anlegen,
> im Profil unter „Browser-Erweiterung“ einen Verbindungscode erzeugen und
> ihn in den Einstellungen der Erweiterung einfügen. Danach genügt ein Klick
> auf das Symbol, um die geöffnete Seite zu kürzen.
>
> Kein Build-Schritt: Der Quelltext im Paket ist der ausgelieferte Code, es
> gibt keine Minifizierung und keine Bündelung. Er ist quelloffen unter
> github.com/HerrBarmann/flatlink (Ordner `extension/`); diese Fassung
> entsteht daraus mit `tools/store-build.php`, das lediglich Name, Adresse,
> Symbole und Akzentfarbe einsetzt.

**Bildunterschriften** (Chrome zeigt keine, Firefox schon)

1. Ein Klick in der Werkzeugleiste – die Adresse steht schon da
2. Fertig: Kurzlink in der Zwischenablage, weiter zum QR-Designer
3. Kennt die Seite schon einen Kurzlink, zeigt sie ihn statt einen zweiten
   anzulegen
4. Einrichten mit einem Verbindungscode aus dem Profil

---

## Bildschirmfotos

Chrome verlangt mindestens eines, 1280×800 oder 640×400 (PNG oder JPEG).
Firefox nimmt beliebige Größen, empfiehlt aber dasselbe Maß.

`tools/screenshots.php` baut die Bühnen dafür. Es malt nichts nach: Es nimmt
`popup.html` und `popup.css` **aus einem entpackten Paket**, setzt daran
genau das, was sonst `popup.js` zur Laufzeit setzt – welcher Abschnitt
sichtbar ist, was in den Feldern steht –, und stellt das Ergebnis in eine
Bühne mit Fensterrahmen und Erklärtext. Was auf dem Bild steht, steht so
auch im Paket.

```bash
unzip -q dist/1337-kiwi-1.3.0.zip -d /tmp/paket   # oder flatlink-1.3.0.zip
php tools/screenshots.php --paket=/tmp/paket --out=/tmp/bilder \
  --name="1337.kiwi" --instanz=https://1337.kiwi \
  --logo=/pfad/zu/icon-512.png --mono=/pfad/zu/mono.ttf \
  --farbe="#7ABA1C" --farbetext="#101408" --farbetief="#507A14"
```

Heraus kommen vier HTML-Dateien in 1024×640 CSS-Pixeln. Gerendert wird mit
einem Browser – unter macOS reicht Quick Look, das WebKit benutzt:

```bash
cd /tmp/bilder
for f in *.html; do
  qlmanage -t -s 2560 -o . "$f"
  magick "$f.png" -crop 2560x1600+0+0 +repage -resize 1280x800 "${f%.html}.png"
  rm "$f.png"
done
```

Der Faktor dahinter: Quick Look rendert mit **1024** CSS-Pixeln Breite und
skaliert das Ergebnis auf die mit `-s` angegebene Kantenlänge. 2560 sind
also 2,5× – 640 CSS-Pixel Höhe landen bei 1600, der Rest des quadratischen
Bildes ist Füllung und wird weggeschnitten. Das Herunterrechnen auf 1280×800
ergibt saubere Kanten.

Zwei Fallen, beide beim ersten Anlauf zugeschnappt:

* **Farbe.** Die neutrale Fassung holt sich die Akzentfarbe des Systems
  (`AccentColor`). Im Browser ist das richtig, auf einem Bildschirmfoto aber
  Zufall – es zeigte die Systemfarbe des Rechners, auf dem gebaut wurde. Die
  Bühne setzt deshalb den dokumentierten Rückfall bzw. die Farbe der
  Instanz.
* **Zwei Stylesheets, ein Dokument.** Bühne und Popup benutzen dieselben
  Namen – beide haben ein `h1`, beide setzen Regeln auf `body`. Ungebunden
  gewinnt das spätere, und das Popup zog die ganze Bühne auf 22 rem
  zusammen. `css_binden()` hängt jeden Selektor an `.popup`.

---

## Nach der Veröffentlichung

Sobald die Adresse im Laden feststeht, gehört sie in die Instanz:
**Einstellungen → Browser-Erweiterung**. Dann zeigt das Profil einen Knopf
dorthin statt eines Archivs. Kein FTP nötig – der Wert liegt in
`data/settings.json`, nicht in `inc/config.php`.

Angenommen werden nur `https` und nur die Adressen der Läden selbst
(`chromewebstore.google.com`, `chrome.google.com`, `addons.mozilla.org`,
`microsoftedge.microsoft.com`). Ein Knopf „Installieren“ ist eine
Empfehlung, und die soll nicht irgendwohin zeigen können; wer sich vertippt,
bekommt eine Fehlermeldung statt eines stillen Knopfs ins Leere.

Im selben Formular steht der Schalter **„Archiv zum Selbstladen anbieten“**.
Für eine Instanz ohne Store-Eintrag ist das Archiv der einzige Weg – aber es
muss von Hand entpackt und im Entwicklermodus geladen werden, und es
aktualisiert sich nie. Wer im Laden steht, schaltet es ab. Der
Verbindungscode bleibt in jedem Fall: Er ist genau das, was eine aus dem
Laden installierte Erweiterung zum Einrichten braucht.

Die Voreinstellung für frische Installationen steht in `inc/config.php`
(`ext_stores`, `ext_download`); was in der Verwaltung geändert wird,
überschreibt sie. Bestehende Instanzen verlieren beim Update nichts: Fehlen
die Schlüssel in der Konfiguration, greift der Wert aus
`inc/config.example.php` – und dort ist das Archiv an.
