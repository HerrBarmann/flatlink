Kurzlinks und QR-Codes, die ihre Besucher nicht vermessen. Reines PHP, keine
Datenbank, kein Composer, kein Build-Schritt – Dateien auf einen Webspace
kopieren, fertig.

Erste benannte Fassung. Die Software läuft seit Monaten unter
[1337.kiwi](https://1337.kiwi); was dort funktioniert, steht hier im Quelltext.

## Was drin ist

**Kurzlinks** mit Wunsch-Namen, Namen für die eigene Übersicht, Schlagworten
zum Filtern, Ablaufdatum und Passwortschutz. **Klick-Statistik als Zähler je
Tag** – kein Datensatz für einzelne Aufrufe, also keine IP-Adressen, keine
Geräte-Kennungen, keine Referrer. Der Weiterleitungspfad startet nicht einmal
eine Sitzung, solange kein Passwortschutz auf dem Link liegt.

**QR-Codes aus einem eigenen Encoder** nach ISO/IEC 18004, Versionen 1–40, alle
vier Fehlerkorrektur-Stufen. Sieben Modulformen, vier Augenformen mit getrennt
einfärbbarem Ring und Kern, Farbverläufe, Logo mit Freistellung, Rahmen mit
Text. Export als SVG, PNG, **Vektor-PDF und EPS**, wahlweise mit
**CMYK-Druckfarben**, die unverändert in der Datei landen. Serien als ZIP mit
Übersicht für die Druckerei.

**Statische Codes** für eine ungekürzte Adresse oder freien Text, WLAN, vCard,
iCalendar und **GS1 Digital Link** mit geprüfter Prüfziffer.

**Link-in-Bio-Seiten**: mehrere Ziele unter einem Kurzcode, gezählt wie alles
andere – je Tag, für die Seite und je Ziel, ohne Besucher-Datensatz.

**Für Organisationen**: Anmeldung über LDAP/Active Directory oder den
Webserver (Shibboleth, SAML, OpenID Connect), Gruppen als Rechte- oder
Arbeitsgruppen, Namensraum-Präfixe, mehrere Domains je Instanz.

**Konten** mit Double-Opt-In, Passwort-Reset, **Passkeys (WebAuthn)** oder
Einmalkennwörtern als zweitem Faktor, Datenexport als JSON und einem Knopf, der
Konto und Links wirklich entfernt.

Dazu CSV-Import (die Ausfuhren von Bitly und YOURLS lesen sich unverändert
ein), eine [Programmierschnittstelle](API.md) mit Zugangsschlüsseln,
UTM-Baukasten, Rate-Limits, Meldeformular und optional Google Safe Browsing.

## Zwei Dinge, die diese Fassung ausmachen

**Die Lesbarkeitsprüfung.** Je mehr sich gestalten lässt, desto leichter
entsteht ein Code, der auf dem Bildschirm gut aussieht und auf dem Aufsteller
versagt. Der Designer prüft deshalb mit: Kontrast, Ruhezone, Logo-Anteil gegen
die Kapazität der Fehlerkorrektur, Pixel je Modul, Millimeter je Modul. Zwei
Gestaltungsoptionen sind daran im Laufe der Entwicklung gescheitert und wurden
zurückgenommen – eine Augenform, die die Hälfte des Suchmusters wegschnitt, und
ein voller Kreis als Augenring, der bei zehn Prozent der Rastergrößen nicht las.
Beides fiel nur durch Messen auf.

**Gemessen statt behauptet.** Der Encoder ist über alle 160 Kombinationen aus
Version und Fehlerkorrektur randvoll gefüllt, gerendert und mit einem fremden
Decoder byteweise zurückgelesen worden. Die Gestaltung ist über 2856
Kombinationen aus Modulform, Augenform, Kernform, Inhalt und Rastergröße
geprüft. Wo Zahlen *nicht* gemessen sind – die Kontrast-Schwellen etwa –, steht
das im Quelltext dabei.

## Voraussetzungen

PHP 8.1 oder neuer, die Erweiterungen `json`, `mbstring`, `gd` und `fileinfo`.
Keine Datenbank, keine Fremdbibliothek.

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink
cp inc/config.example.php inc/config.php
php -S localhost:8080
```

Der erste Aufruf von `/admin/` legt das Admin-Konto an. Für den Dauerbetrieb:
[DEPLOYMENT.md](DEPLOYMENT.md).

## Lizenz

**GNU AGPL v3** mit einer Zusatzbedingung zur Namensnennung nach § 7(b) der
Lizenz. Benutzen, selbst betreiben, ändern, weitergeben, umbenennen und
einfärben ist erlaubt, auch kommerziell und ohne zu fragen. Zwei Bedingungen:
Die Herkunftszeile bleibt sichtbar, und wer eine *geänderte* Fassung als Dienst
im Netz anbietet, macht deren Quelltext seinen Nutzern zugänglich.

Für eine Fassung ohne Herkunftszeile gibt es eine schriftliche Freistellung –
eine kurze Mail genügt.

## Was noch fehlt

Die Oberfläche und die Kommentare sind auf Deutsch. Eine englische
Sprachebene ist der nächste Schritt.
