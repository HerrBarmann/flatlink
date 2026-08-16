# Erweiterung in die Läden bringen

Alles, was Chrome Web Store und addons.mozilla.org abfragen – zum Kopieren.
Zwei Fassungen, weil sie verschiedene Zwecke haben:

| | generisch | gebrandet |
| --- | --- | --- |
| Name | flatlink | z. B. 1337.kiwi |
| Für wen | jede Instanz | eine bestimmte |
| Adresse | wird beim Einrichten erfragt | steht fest |
| Berechtigung | „Zugriff auf Websites, die du angibst" | „Zugriff auf 1337.kiwi" |
| Einzurichten | Adresse **und** Schlüssel | nur der Schlüssel |

Die gebrandete Fassung verlangt **weniger** Rechte – das ist ihr eigentlicher
Vorteil, und es lohnt sich, das in der Beschreibung zu sagen.

## Pakete bauen

```bash
# generisch
php tools/store-build.php --out=./dist --version=1.0.0

# gebrandet (Beispiel 1337.kiwi)
php tools/store-build.php --out=./dist --version=1.0.0 \
  --instanz=https://1337.kiwi --name="1337.kiwi" \
  --icon=/pfad/zu/icon-512.png --farbe="#7ABA1C" --farbetext="#101408"
```

Ein Zugangsschlüssel kommt **nie** ins Paket: Ein Paket im Laden bekommen
alle, ein Schlüssel gehört einem. Den gibt es weiterhin nur über das Profil
der eigenen Instanz.

---

## Chrome Web Store

Einmalig 5 USD Entwicklergebühr, Prüfung dauert meist zwei bis fünf Tage.

**Kurzbeschreibung** (max. 132 Zeichen)

> Kurzlinks auf deiner eigenen flatlink-Instanz anlegen – ein Klick in der
> Werkzeugleiste, ohne fremden Dienst.

gebrandet:

> Die geöffnete Seite auf 1337.kiwi kürzen – ein Klick, fertig. Ohne fremden
> Dienst dazwischen.

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
> • Kurzlink mit einem Klick in die Zwischenablage
> • QR-Code anzeigen – erzeugt von deiner Instanz, nicht von einem Dritten
>
> WAS SIE NICHT TUT
> • Keine Seiteninhalte lesen, keine Skripte in Seiten einspritzen
> • Keine Verbindung zu irgendeiner anderen Adresse als deiner Instanz
> • Keine Analyse, keine Kennungen, kein Hintergrundprozess
>
> BERECHTIGUNGEN
> • „Aktiver Tab": die Adresse des Tabs, in dem du auf das Symbol klickst –
>   nur dann, nur diese
> • „Speicher": Adresse und Zugangsschlüssel, bewusst im lokalen Speicher
>   dieses Browsers statt in der Synchronisierung
>
> VORAUSSETZUNG
> Eine eigene flatlink-Instanz (github.com/HerrBarmann/flatlink) und darin
> ein Zugangsschlüssel aus deinem Profil. Am schnellsten geht das Einrichten
> mit einem Verbindungscode: in der Instanz erzeugen, hier einfügen.
>
> Der Quelltext der Erweiterung sind vier Dateien mit gut 300 Zeilen –
> nachlesbar an einem Nachmittag: github.com/HerrBarmann/flatlink

**Kategorie:** Produktivität (Productivity)
**Sprache:** Deutsch

### Berechtigungen begründen

Chrome verlangt zu jeder Berechtigung einen Satz. Diese hier reichen:

| Feld | Text |
| --- | --- |
| `activeTab` | Die Erweiterung braucht die Adresse der Seite, die gekürzt werden soll. Sie wird ausschließlich beim Klick auf das Symbol gelesen und sofort an die vom Nutzer angegebene Instanz gesendet. |
| `storage` | Speichert die Adresse der Instanz und den Zugangsschlüssel des Nutzers lokal. Ohne beides kann die Erweiterung keine Kurzlinks anlegen. |
| Host-Zugriff | Die Erweiterung spricht mit der selbst gehosteten Instanz des Nutzers. Deren Adresse ist beim Bauen nicht bekannt, deshalb wird die Berechtigung erst beim Einrichten für genau diese eine Adresse angefragt. |
| Host-Zugriff (gebrandet) | Die Erweiterung spricht ausschließlich mit https://1337.kiwi, dem Dienst, zu dem sie gehört. |
| „Warum Remote Code?" | Wird nicht verwendet. Sämtlicher Code liegt im Paket. |

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
offengelegte Zwecke", „Ich verkaufe keine Daten", „Ich nutze keine Daten für
Bonität") lassen sich alle guten Gewissens anhaken.

**Datenschutz-Adresse:** eine Seite, die sagt, dass die Erweiterung nichts
sammelt – für 1337.kiwi passt `https://1337.kiwi/datenschutz.php`.

---

## addons.mozilla.org (Firefox)

Kostenlos. Zwei Wege:

* **Listed** – im Verzeichnis auffindbar, mit Prüfung.
* **Unlisted** – nur signiert, nicht im Verzeichnis. Das Paket kommt signiert
  zurück und lässt sich selbst ausliefern: Ein Link auf die `.xpi` ist dann
  ein echter Ein-Klick-Installer. **Das ist der Weg für die gebrandete
  Fassung** – sie taugt ohnehin nur für eine Instanz und hat im Verzeichnis
  wenig verloren.

**Zusammenfassung** (bis 250 Zeichen)

> Kürzt die geöffnete Seite auf deiner eigenen flatlink-Instanz. Ein Klick in
> der Werkzeugleiste, Kurzlink in der Zwischenablage, auf Wunsch mit QR-Code.
> Spricht mit keiner anderen Adresse – kein Anbieter dazwischen, der mitliest.

**Beschreibung:** dieselbe wie bei Chrome (Firefox erlaubt einfaches HTML;
die Aufzählungen dürfen dort `<ul><li>` sein).

**Kategorien:** Lesezeichen, Produktivität
**Schlagworte:** kurzlink, url-shortener, qr-code, selfhosted, datenschutz
**Lizenz:** AGPL-3.0-or-later (steht im Paket)
**Support-Adresse:** die Issues des Projekts

**Für die Prüfenden** (das Feld „Notes for reviewers"):

> Die Erweiterung spricht ausschließlich mit einer flatlink-Instanz, die der
> Nutzer selbst angibt (bzw. mit https://1337.kiwi in dieser Fassung). Zum
> Testen: eine Instanz unter github.com/HerrBarmann/flatlink aufsetzen, im
> Profil einen Zugangsschlüssel anlegen und in den Einstellungen der
> Erweiterung eintragen. Kein Build-Schritt – der Quelltext im Paket ist der
> ausgelieferte Code, es gibt keine Minifizierung und keine Bündelung.

Der letzte Satz ist wichtig: Firefox verlangt sonst die Quellen des
Build-Prozesses. Hier gibt es keinen.

---

## Bildschirmfotos

Chrome verlangt mindestens eines, 1280×800 oder 640×400 (PNG oder JPEG).
Firefox nimmt beliebige Größen, empfiehlt aber dasselbe Maß.

Die Vorlage dafür liegt bei: `tools/screenshot.html`. Sie zeigt das **echte**
Popup in einem Fensterrahmen neben einer kurzen Erklärung. Vorgehen:

1. Erweiterung im Browser laden und einrichten (sonst zeigt das Popup die
   Einrichtung statt der Eingabemaske).
2. `tools/screenshot.html` über einen lokalen Server öffnen – als `file://`
   lädt der Rahmen das Popup nicht.
3. Fenster auf 1280×800 stellen (Entwicklerwerkzeuge → Responsive) und einen
   Vollbild-Screenshot machen.

Drei Bilder reichen: die Eingabemaske, das Ergebnis mit Kurzlink, das
Ergebnis mit QR-Code.

---

## Nach der Veröffentlichung

Beide Läden zeigen eine feste Kennung an. Die gehört in die Instanz, sobald
sie feststeht – dann kann der Knopf im Profil künftig direkt in den Laden
verlinken, statt ein Archiv zu bauen.

Und die Versionsnummer: Sie steht im Manifest und wird beim Bauen gesetzt
(`--version=`). Die Läden nehmen keine zweite Einreichung mit derselben
Nummer an.
