Der QR-Generator ist in dieser Fassung von Grund auf ausgebaut worden – und mit
ihm eine Prüfung, die verhindern soll, dass Gestaltung einen Code unlesbar
macht.

## QR-Codes ohne Kürzen

Der Designer zwang bisher zu einem Kurzlink. Wer nur einen Code für eine
bestehende Adresse wollte, brauchte den Umweg über einen Dienst, den er gar
nicht braucht. Jetzt gibt es beide Wege nebeneinander:

- **Mit Kurzlink** – das Ziel bleibt änderbar, es gibt eine Klickzahl.
- **Ohne Kürzen** – die Adresse steht unmittelbar im Code. Nichts wird
  gespeichert, nichts läuft über die Instanz, der Code funktioniert auch dann
  noch, wenn es sie nicht mehr gibt. Dafür steht das Ziel fest.

Auch `mailto:`, `tel:` oder schlicht ein Text.

## Encoder bis Version 40

Bisher endete er bei Version 10 – 213 Zeichen bei mittlerer Fehlerkorrektur.
Eine Produktadresse mit Kampagnen-Parametern passt da nicht hinein, und ohne
sie wäre der Punkt oben wertlos. Jetzt bis **2953 Zeichen**, die Grenze der
Norm.

Aus ISO/IEC 18004 abgetippt sind nur zwei Zahlenreihen je Fehlerkorrektur-Stufe;
alles andere ist gerechnet. Geprüft über alle **160 Kombinationen** aus Version
und Stufe: randvoll gefüllt, gerendert und mit einem fremden Decoder byteweise
zurückgelesen.

## Vektor-Export mit CMYK

Das PDF trug bisher ein eingebettetes JPEG. Auf Papier fällt das erst auf, wenn
jemand den Code groß zieht – dann aber deutlich. Jetzt **echte Vektoren**, dazu
**EPS** für Satz und Belichtung und **CMYK-Druckfarben**, die unverändert in der
Datei landen. Das Vektor-PDF eines gewöhnlichen Codes wiegt rund 4 kB.

Alle Formate holen ihre Geometrie aus derselben Quelle; eine Form kann nicht in
einem Format erscheinen und im anderen fehlen.

## Gestaltung

**Farbverläufe**, linear mit freier Richtung oder radial, mit vier Vorlagen.
**Sieben Modulformen**: quadratisch, abgerundet, stark abgerundet, Punkte,
Raute sowie senkrechte und waagerechte Balken. **Vier Augenformen**, Ring und
Kern getrennt formbar und getrennt einfärbbar. **Freistellung hinter dem Logo**
in vier Varianten. **Durchsichtiger Hintergrund** für SVG und PNG.

## Lesbarkeitsprüfung

Je mehr sich gestalten lässt, desto leichter entsteht ein Code, der auf dem
Bildschirm gut aussieht und auf dem Aufsteller versagt. Der Designer prüft
deshalb bei jeder Änderung mit und warnt bei zu wenig Kontrast, zu schmaler
Ruhezone, zu großem Logo oder zu kleiner Ausgabe. Geprüft wird auf dem Server,
nicht im Browser.

Zwei Gestaltungsoptionen sind an dieser Prüfung gescheitert und wurden
zurückgenommen: eine Augenform, die die Hälfte des Suchmusters wegschnitt, und
ein voller Kreis als Augenring, der bei zehn Prozent der Rastergrößen nicht las.
Beides fiel nur durch Messen auf – der Kreis liegt jetzt bei Radius 3,0 statt
3,5, und über 1224 geprüfte Kombinationen liest sich jede.

Wo Zahlen **nicht** gemessen sind – die Kontrast-Schwellen etwa, weil ein
Software-Decoder auf einem sauberen Bild noch hellgrau auf weiß liest –, steht
das im Quelltext dabei.

## Änderungen am Verhalten

- **Das PDF ist jetzt Vektor statt Rastergrafik.** Es sieht in jeder Größe
  besser aus und ist kleiner; wer die alte Datei erwartet, bekommt eine andere.
- **Runde Augen sind minimal weniger rund** (Radius 3,0 statt 3,5). Optisch
  kaum sichtbar, für die Erkennung entscheidend.
- `inc/pdf.php` entfällt – der Vektor-Schreiber hat es abgelöst.
- Neu: `inc/vector.php`, `inc/qrcheck.php`, `tests/optionen.php`.

Bestehende Kurzlinks, Konten und Statistiken sind nicht betroffen; es gibt
nichts zu migrieren.

## Tests

Zwei PHP-Dateien gegen den eingebauten Server, kein Testrahmen:

```bash
php -S localhost:8080 -t . &
php tests/optionen.php http://localhost:8080
```

`tests/optionen.php` prüft, ob jede Gestaltungsoption bei `qr.php` auch ankommt.
Anlass war ein Fehler, den kein anderer Test finden konnte: Vier Modulformen
waren gebaut und angeboten, aber die Prüfliste in `qr.php` kannte sie nicht –
wer „Raute" wählte, bekam ein Quadrat. Der frühere Test fragte nur, ob sich das
Ergebnis scannen lässt, und ein Quadrat scannt eben auch.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b),
seit 2.0.0.
