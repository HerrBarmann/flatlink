# Der QR-Generator

Alles zu QR-Codes in flatlink: die zwei Arten von Codes, der eigene Encoder,
Gestaltung, Lesbarkeit, der Export für den Druck, Serien und der GS1 Digital
Link. Zurück zur [README](../README.de.md). – 🇬🇧 [English version](qr-generator.en.md).

## Zwei Arten von QR-Code

Der Designer bietet beide Wege, und der Unterschied ist die eine Entscheidung,
die vor dem Drucken zu treffen ist:

**Mit Kurzlink.** Der Code zeigt auf die eigene Instanz. Das Ziel lässt sich
jederzeit ändern, ohne den gedruckten Code auszutauschen, und es gibt eine
Klickzahl. Der Code braucht die Instanz, solange er im Umlauf ist.

**Ohne Kürzen** (`qr-designer.php?m=statisch`). Die Adresse steht unmittelbar
im Code. Gespeichert wird nichts, der Code läuft über niemanden und
funktioniert auch dann noch, wenn es die Instanz nicht mehr gibt. Dafür steht
das Ziel fest.

Der statische Weg nimmt auch `mailto:`, `tel:` oder schlicht einen Text. Fehlt
bei etwas Domain-Förmigem das Schema, wird `https://` ergänzt – sonst bleibt
die Eingabe unangetastet.

## Fünf Typen, ein Generator

Ein QR-Code enthält Text – was dieser Text bedeutet, entscheidet die
Anwendung, die ihn liest. Deshalb kann derselbe Encoder mehr als Adressen:

| Reiter | Datei | was im Code steht |
| --- | --- | --- |
| Link | `qr-designer.php` | eine Adresse, ein Kurzlink oder freier Text |
| WLAN | `wlan-qr.php` | `WIFI:` – Netzwerkname, Verschlüsselung, Passwort |
| Kontakt | `vcard-qr.php` | eine vCard 3.0 |
| Termin | `termin-qr.php` | ein iCalendar-Eintrag (`VEVENT`) |
| GS1 | `gs1-qr.php` | ein GS1 Digital Link (siehe unten) |

Die Reiter stehen auf jeder dieser Seiten (`qr_type_nav()`); angemeldet führt
der erste in den Designer im Login-Bereich, wo Logos und die Zuordnung zu
einem Kurzlink dazukommen.

Alle vier Zusatztypen erzeugen **statische** Codes: Die Daten stehen im Code
selbst, es wird nichts gespeichert, und sie funktionieren auch dann noch,
wenn es diese Instanz nicht mehr gibt. Der Preis ist, dass sie sich nicht
mehr ändern lassen – wer das braucht, nimmt einen Kurzlink.

Die Eingaben gehen per POST an `qr.php`, nicht als Parameter in der Adresse:
Ein WLAN-Passwort hat in Server-Protokollen und im Verlauf des Browsers
nichts verloren.

## Die Logo-Bibliothek

Sie hat eine **eigene Seite** unter *Logos* im Verwaltungsbereich
(`admin/logos.php`). Das ist keine Kosmetik: Die Auswahl im Designer gehört zum
Gestalten eines einzelnen Codes, die Bibliothek ist ein Bestand, den man
unabhängig davon pflegt – wer ein Logo hochlädt, will in diesem Moment selten
einen QR-Code bauen. Im Designer steht deshalb nur noch das Auswahlfeld und ein
Verweis hierher.

Auf der Seite liegt jedes Logo als Karte: Vorschau auf kariertem Grund (damit
sich bei freigestellten Bildern sehen lässt, wo die Transparenz sitzt), Name,
Eigentümer, Freigabe und Löschknopf. Fremde, über eine Gruppe freigegebene
Logos erscheinen mit, aber ohne Verwaltung – benutzen ja, ändern nein.

Wer das Recht `logo_upload` hat, kann eigene Logos hochladen; wie viele,
begrenzt das Limit `logos`. Ein Logo gehört dem, der es hochgeladen hat.

**Teilen.** Im Designer lässt sich jedes eigene Logo für Gruppen freigeben
(*Logo für andere freigeben*). Die Mitglieder dieser Gruppen finden es danach
in ihrer Auswahl, gekennzeichnet mit dem Konto, dem es gehört. Der Sonderwert
„alle angemeldeten Konten" gibt es allen frei.

Freigeben heißt **verwenden dürfen**, nicht verwalten: Umbenennen und Löschen
bleibt beim Eigentümer (und bei Administratoren), und das Logo zählt weiter
auf dessen Kontingent. Wer ein geteiltes Logo in seiner Liste sieht, kann es
also benutzen, aber niemandem wegnehmen.

Technisch steht die Freigabe in `data/logos.json` als Liste von
Gruppen-Kennungen (`shared`), der Stern `*` steht für alle Konten. Gruppen,
die es nicht mehr gibt, werden beim Speichern verworfen.

## Der Encoder

Reines PHP nach ISO/IEC 18004, Byte-Mode, Versionen 1–40, alle vier
Fehlerkorrektur-Stufen, Maskenwahl über den Penalty-Score der Norm.

Aus der Norm abgetippt sind nur **zwei Zahlenreihen je Stufe** – ECC-Codewörter
je Block und Anzahl Blöcke aus Tabelle 9. Alles andere ergibt sich daraus
rechnerisch: die Gesamtzahl der Codewörter aus der Geometrie der Matrix, die
Aufteilung in kurze und lange Blöcke aus einer Division mit Rest, die Lage der
Ausrichtungsmuster aus der Schrittweiten-Regel. Eine Tabelle mit 320
handgetippten Werten wäre die wahrscheinlichere Fehlerquelle gewesen.

Geprüft wird das nicht durch Hinsehen: Alle **160 Kombinationen** aus Version
und Fehlerkorrektur werden randvoll gefüllt, gerendert und mit einem fremden
Decoder (`zbarimg`) byteweise zurückgelesen. Die Höchstlängen, die dabei
herauskommen – 2953 / 2331 / 1663 / 1273 Byte für L/M/Q/H – sind genau die der
Norm.

## Modulformen und Hintergrund

Sieben Formen für die Datenmodule: quadratisch, abgerundet, stark abgerundet,
Punkte, Raute sowie senkrechte und waagerechte **Balken**. Die Balken sind der
einzige Fall, in dem eine Form über ein Modul hinausreicht: Aufeinanderfolgende
dunkle Module verschmelzen zu einem durchgehenden Strich mit runden Enden. Die
Läufe berechnet [`moduleRuns()`](../inc/qrlib.php) einmal für alle drei
Zeichenwege, damit SVG, PNG und Vektor nicht auseinanderlaufen.

Der **Hintergrund lässt sich durchsichtig** schalten (`bg=none`). Im PNG wird
die Fläche wirklich transparent, im SVG bleibt das Grundrechteck weg, in PDF
und EPS scheint das Papier durch – was dasselbe ist. Die Lesbarkeitsprüfung
sagt dazu, was sie sagen kann: Ob der Code liest, entscheidet dann die Fläche
darunter, und das lässt sich von hier aus nicht prüfen.

## Augen

Der äußere Ring und der innere Kern lassen sich getrennt formen (quadratisch,
abgerundet, rund, Blatt) und getrennt einfärben. Leer heißt jeweils „wie das
darüber": Der Kern nimmt Form und Farbe des Rings, der Ring die Farbe der
Datenmodule – die Vorgabe bleibt damit genau das, was sie vorher war.

**Der runde Ring ist bewusst kein voller Kreis**, sondern ein sehr stark
abgerundetes Quadrat (Radius 3,0 statt 3,5 Module). Gemessen über 1224
Kombinationen aus Modulform, Augenform, Inhalt und Rastergröße: Mit vollem
Kreis lasen sich 90 % der erzeugten Bilder, mit 3,0 sind es 100 %. Der Grund
steht in der Norm – ein Scanner sucht Linien, auf denen das Suchmuster im
Verhältnis 1:1:3:1:1 liegt; beim Quadrat stimmt das auf jeder der sieben
Zeilen, beim vollen Kreis nur nahe der Mitte. Am Aussehen ändern die 0,5
Module wenig, an der Verlässlichkeit alles.

**Zur Blattform eine Anmerkung, die den Umgang mit Gestaltung hier zeigt.**
Sie hatte zunächst einen Radius von 3,5 Modulen, also eine halb weggeschnittene
Ecke – hübsch, aber der Code fiel bei mehreren Rastergrößen durch, während die
übrigen Formen bei denselben Größen sauber lasen. Das Suchmuster muss entlang
jeder Abtastlinie durch seine Mitte das Verhältnis 1:1:3:1:1 halten; wer die
Hälfte davon wegschneidet, verlässt den Bereich, in dem sich ein Scanner
auskennt. Der Radius ist deshalb auf 2,0 zurückgenommen. Gestaltung darf einen
Code nicht unlesbar machen.

## Farbverläufe

Linear mit frei wählbarer Richtung oder radial von innen nach außen, dazu vier
Vorlagen. Der Verlauf liegt über den Datenmodulen und den Augen; der
Hintergrund bleibt einfarbig.

**Gefärbt wird modulweise, nicht mit dem Verlaufs-Werkzeug des jeweiligen
Formats.** SVG und PDF könnten einen glatten Verlauf, PNG und EPS in Level 2
nicht – vier Formate mit zwei Verfahren wären vier Ergebnisse, die sich im
Detail unterscheiden. Ausgerechnet beim Druckexport will niemand herausfinden,
warum die Datei anders aussieht als die Vorschau. Ein QR-Code besteht ohnehin
aus Kacheln; eine Farbe je Kachel ist bei jeder vernünftigen Größe von einem
glatten Verlauf nicht zu unterscheiden.

**Mit CMYK verträgt sich das nicht**, und deshalb gewinnt dort die Druckfarbe:
Ein Verlauf im Vierfarbdruck ist eine Entscheidung für sich – Rasterung,
Farbauftrag, Papier –, und ein stillschweigend umgerechneter Verlauf wäre keine
gute Antwort darauf. Die Oberfläche sagt das, statt es geschehen zu lassen.

## Lesbarkeit

Je mehr sich gestalten lässt, desto leichter entsteht ein Code, der auf dem
Bildschirm gut aussieht und auf dem Aufsteller versagt. Der Designer prüft
deshalb bei jeder Änderung mit und zeigt Hinweise neben der Vorschau:

- **Kontrast** zwischen Vordergrund und Hintergrund, einzeln auch für die
  zweite Verlaufsfarbe und die Augenfarben – ein Verlauf ist an einem Ende oft
  kräftig und am anderen zu blass. Warnung auch, wenn der Code heller als sein
  Grund ist.
- **Ruhezone** unter den vier Modulen der Norm.
- **Logo-Anteil** gegen das, was die eingestellte Fehlerkorrektur trägt.
- **Ausgabegröße**: Pixel je Modul beim PNG, Millimeter je Modul bei PDF und
  EPS.

Geprüft wird **auf dem Server** ([`inc/qrcheck.php`](../inc/qrcheck.php)), nicht
im Browser: Die Schwellen gehören zu den Regeln des Dienstes und sollen nicht
davon abhängen, was ein Browser gerade ausführt – und beim Serien-Download gibt
es gar kein Skript.

**Woher die Zahlen kommen, und woher nicht.** Rand, Logo-Anteil und Modulgröße
folgen der Norm beziehungsweise der Kapazität der Fehlerkorrektur; die sind
nachrechenbar. Die Kontrast-Schwellen sind es nicht: Ein Software-Decoder liest
auf einem sauberen PNG noch hellgrau auf weiß (1,3:1) fehlerfrei und kann sie
gar nicht belegen. Was einen Code scheitern lässt, ist die Kamera – Rauschen,
schiefes Licht, Papier, in das die Farbe läuft. Die Werte orientieren sich am
Symbolkontrast der Prüfnormen für gedruckte Codes und liegen bewusst auf der
vorsichtigen Seite.

Hinter dem Logo liegt eine **freigestellte Fläche** (abgerundet, eckig, rund
oder keine, Abstand einstellbar). Sie ist kein Zierrat: Ein Logo, das Module
nur halb verdeckt, verwirrt die Erkennung mehr als eine sauber ausgesparte
Fläche, die die Fehlerkorrektur wegsteckt. Im Vektor-Export fehlte sie bis
zuletzt – dort saß das Logo unmittelbar auf den Modulen.

## Export für den Druck

Fünf Formate aus derselben Vorlage:

| Format | Wofür |
| --- | --- |
| SVG | Web und Weiterverarbeitung, mit eingebettetem Logo |
| PNG | Bildschirm, Office, alles Pixelige |
| **PDF** | echte Vektoren, eine Seite in der gewünschten Größe |
| **EPS** | Satz und Belichtung – das Format, nach dem Druckereien fragen |

PDF und EPS enthalten **keine Pixelgrafik**: Der Code besteht aus Pfaden und
lässt sich auf Plakatgröße ziehen, ohne weich zu werden. Das PDF eines
gewöhnlichen Codes ist dabei rund 4 kB groß – ein Bruchteil der eingebetteten
Grafik, die vorher darin steckte.

**CMYK.** Wer die vier Druckfarben angibt, bekommt sie *unverändert* in PDF und
EPS. Umgerechnet wird nur in die andere Richtung: SVG, PNG und die Vorschau
zeigen eine Näherung, weil ein Bildschirm kein CMYK kann. Ohne Farbprofil gibt
es dafür keine richtige Antwort – verbindlich ist die Druckdatei, und die
Oberfläche sagt das auch.

Beide Formate holen ihre Geometrie aus derselben Quelle wie das SVG
([`QrRenderer::vectorOps()`](../inc/qrlib.php)); der Text nutzt Courier aus dem
Standardvorrat beider Formate, also ohne eingebettete Schriftdatei und ohne
Lizenzfrage.

Nachgewiesen wird das ohne Ghostscript: Ein Prüfprogramm liest die erzeugten
Dateien zurück, zeichnet die enthaltenen Pfade und lässt `zbarimg` sie scannen –
über alle Modul- und Augenformen, mit Rahmen, mit Absenderzeile und in CMYK.

## Beschriftung im PNG

Rahmen- und Absendertexte werden im SVG sauber gesetzt. Für PNG und PDF braucht
GD eine TrueType-Datei: eine beliebige `.ttf` nach `assets/fonts/` legen, die
erste gefundene wird genommen. Ohne Datei greift ein grober GD-Systemfont.
Es ist bewusst keine Schrift mitgeliefert, damit dem Projekt keine fremde
Font-Lizenz anhängt.

## QR-Serien als ZIP

Zwanzig Tischaufsteller, eine Ausstellung, eine Aufkleberserie: *QR-Serie* in
der Kopfzeile packt die QR-Codes mehrerer Links in ein Archiv. Das volle
Gestaltungs-Panel gilt für die ganze Serie – Formen, Augen, Farben, Verläufe,
Fehlerkorrektur, Rahmentext und Logo –, mit einer Live-Vorschau am ersten
Link der Liste. Höchstens 200 Codes je Archiv.

Der Weg führt über die Liste: nach Schlagwort oder Gruppe filtern, dann den
Knopf über der Liste – die Auswahl steht schon.

Im ZIP liegt **eine Übersicht als CSV**. Wer eine Serie an eine Druckerei gibt,
braucht die Zuordnung von Datei zu Ziel, nicht nur die Bilder; und die
Dateinamen tragen zusätzlich den Namen des Links, damit sie auf einem fremden
Schreibtisch noch etwas bedeuten.

Geschrieben wird das Archiv von [`inc/zip.php`](../inc/zip.php) – **ohne die
PHP-Erweiterung `zip`**. Sie ist nicht überall eingeschaltet und will eine
echte Datei auf der Platte: erst schreiben, dann ausliefern, dann aufräumen.
Genau der Fall, der auf günstigem Hosting scheitert und beim Entwickler nie.
Das Format selbst ist überschaubar, wenn man weglässt, was hier ohnehin
niemand braucht: keine Verschlüsselung, keine geteilten Archive, kein ZIP64.
Verdichtet wird mit `gzdeflate()`, wo es etwas bringt – sonst wird gespeichert;
beides ist im Format vorgesehen.

## GS1 Digital Link

`qr.php` erzeugt neben Kurzlink-, WLAN-, vCard- und Termin-Codes auch
**GS1 Digital Links** – die Adressform, die ab „Sunrise 2027" auf Verpackungen
neben oder statt des Strichcodes stehen soll:

```
POST qr.php
  t=gs1
  gtin=4006381333931       Artikelnummer, 8/12/13/14 Ziffern
  lot=LOT-42               Charge (optional)
  serial=SN-0001           Seriennummer (optional)
  mhd=2027-12-31           Haltbarkeitsdatum (optional)
  resolver=https://…       eigener Auflösungsdienst (optional)
```

Daraus wird `https://id.gs1.org/01/04006381333931/10/LOT-42?17=271231`. Die
Reihenfolge der Bestandteile ist in der GS1-Syntax festgelegt und keine
Geschmackssache; Lesegeräte verlassen sich darauf. Die **Prüfziffer der GTIN
wird nachgerechnet** – stimmt sie nicht, kommt eine Fehlermeldung statt eines
Codes, der auf einer Palette auffällt.

Was flatlink **nicht** tut: einen Resolver betreiben. Was beim Scannen
erscheint, entscheidet der Betreiber der eingetragenen Adresse; ohne Angabe
zeigt der Code auf den Dienst von GS1 selbst. Die Logik steht in
[`inc/gs1.php`](../inc/gs1.php), eine Bedienoberfläche dafür bringt flatlink nicht
mit – sie ist als eigene Seite schnell gebaut.

