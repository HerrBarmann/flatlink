<h1 align="center">flatlink</h1>

<p align="center">
  <strong>Der Kurzlink-Dienst zum Selbstbetreiben – mit QR-Designer und Link-in-Bio.</strong><br>
  Reines PHP. Kein Datenbank-Server, kein Composer, kein Build-Schritt –<br>
  Dateien auf einen Webspace kopieren, fertig.
</p>

<p align="center">
  <a href="LICENSE"><img alt="AGPL-3.0-Lizenz" src="https://img.shields.io/badge/Lizenz-AGPL--3.0-1a7f37"></a>
  <img alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4">
  <img alt="Keine Abhängigkeiten" src="https://img.shields.io/badge/Abh%C3%A4ngigkeiten-0-0a7ea4">
  <img alt="Kein Datenbank-Server" src="https://img.shields.io/badge/Datenbank--Server-keiner-555">
</p>

<p align="center">
  <img src="docs/screenshots/linkliste.png" alt="Die Linkliste mit Schlagworten, Gruppen und Klickzahlen" width="820">
</p>

> 🇬🇧 English version: **[README.en.md](README.en.md)** – the manual chapters
> are translated as well.

---

## Der Punkt

flatlink will der beste quelloffene Kurzlink-Dienst zum Selbstbetreiben sein –
mit einem QR-Generator, der bis zur Druckerei reicht, und Link-in-Bio-Seiten.
Gebaut ist er für die Orte, an denen so etwas am dringendsten fehlt:
Hochschulen, Bibliotheken und Verwaltungen, die ihre Links nicht an einen
Dienst außerhalb des Hauses geben wollen oder dürfen. Anmeldung über LDAP und
Shibboleth, Gruppen mit Rechten und Limits, Namensräume je Abteilung und
mehrere Domains sind deshalb keine Anbauten, sondern der Kern.

Datenschutz ist bei diesem Einsatzzweck keine eigene Funktion – er geht mit
einher, als Bauweise. Wo praktisch jeder Kurzlink-Dienst protokolliert,
**wer** klickt, speichert flatlink je Link genau das hier – vollständig,
nicht gekürzt:

```json
{ "n": 1840, "last": "2026-08-14", "days": { "2026-08-14": 72 } }
```

Ein Zähler pro Tag. Kein Datensatz für einzelne Aufrufe, also auch keine
IP-Adressen, keine Geräte- oder Browser-Kennungen, keine Referrer. Aus diesen
Daten lässt sich kein einzelner Besucher rekonstruieren, weil nie ein einzelner
Besucher gespeichert wird.

Auch der letzte Aufruf steht nur tagesgenau da. Bei einem Link mit einer
Handvoll Aufrufe wäre eine Uhrzeit sonst der einzige Wert im ganzen Bestand,
über den sich ein einzelner Besuch zeitlich verorten – und mit anderen Quellen
zusammenführen – ließe.

Das ist keine Absichtserklärung, sondern in [`inc/store.php`](inc/store.php) in
etwa zehn Zeilen nachlesbar (`clicks_bump()`). Prüf es nach – genau dafür liegt
der Code offen. Der Weiterleitungspfad (`go.php`) startet nicht einmal eine
Session, solange kein Passwortschutz auf dem Link liegt.

<p align="center">
  <img src="docs/screenshots/statistik.png" alt="Statistik eines Links: Tageswerte, Monatsübersicht, CSV-Export" width="760">
</p>

## Wie es aussieht

<table>
<tr>
<td width="50%" valign="top">
<a href="docs/screenshots/qr-designer.png"><img src="docs/screenshots/qr-designer.png" alt="QR-Designer mit Modul- und Augenformen, Farben und Live-Vorschau"></a>
<p><strong>QR-Designer.</strong> Modul- und Augenformen, freie Farben, Logo in
der Mitte, Rahmen mit Text. Export als SVG, PNG, Vektor-PDF und EPS, wahlweise in
CMYK – aus einem eigenen Encoder, ohne Fremdbibliothek.</p>
</td>
<td width="50%" valign="top">
<a href="docs/screenshots/qr-serie.png"><img src="docs/screenshots/qr-serie.png" alt="QR-Serie: mehrere Links auswählen und als ZIP herunterladen"></a>
<p><strong>QR-Serien.</strong> Zwanzig Tischaufsteller in einem Archiv, mit
Übersicht als CSV für die Druckerei. Das ZIP schreibt flatlink selbst – auch
ohne die PHP-Erweiterung <code>zip</code>.</p>
</td>
</tr>
<tr>
<td width="50%" valign="top">
<a href="docs/screenshots/neuer-link.png"><img src="docs/screenshots/neuer-link.png" alt="Formular für einen neuen Kurzlink mit Name, Schlagworten und UTM-Baukasten"></a>
<p><strong>Anlegen.</strong> Wunsch-Name, Name für die eigene Übersicht,
Schlagworte zum Filtern, Ablaufdatum, Passwortschutz und ein Baukasten für
Kampagnen-Parameter.</p>
</td>
<td width="50%" valign="top" align="center">
<a href="docs/screenshots/link-in-bio.png"><img src="docs/screenshots/link-in-bio.png" alt="Link-in-Bio-Seite mit fünf Zielen" width="260"></a>
<p align="left"><strong>Link-in-Bio.</strong> Eine Seite mit mehreren Zielen
unter einem Kurzcode. Gezählt wie alles andere: je Tag, für die Seite und je
Ziel, ohne Besucher-Datensatz.</p>
</td>
</tr>
</table>

## In fünf Minuten läuft es

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink
cp inc/config.example.php inc/config.php
php -S localhost:8080 router.php
```

Im Browser `http://localhost:8080/admin/` öffnen – der erste Aufruf legt das
Admin-Konto an. (`router.php` bildet für den eingebauten Server nach, was im
Betrieb die `.htaccess` erledigt – ohne ihn führt ein Kurzlink zur Startseite
statt zum Ziel.) Für den Dauerbetrieb: Dateien auf den Webspace kopieren,
`data/` beschreibbar machen, `base_url` in der Konfiguration eintragen.
Ausführlich unter [Installation](#installation).

## Für wen das gedacht ist

- **Hochschulen, Bibliotheken, Schulen, Verwaltungen**, die Kurzlinks nicht an
  einen Dienst außerhalb Europas geben dürfen. Anmeldung über LDAP oder
  Shibboleth, Gruppen mit eigenen Rechten und Limits, Namensräume je Abteilung.
- **Vereine, Praxen, Restaurants, kleine Betriebe**, die einen QR-Code drucken
  und das Ziel später ändern wollen, ohne den Aufkleber zu tauschen.
- **Agenturen**, die mehrere Marken bedienen: eigene Domains je Kunde,
  gemeinsame Arbeitsgruppen, Schnittstelle für die Automatisierung.
- **Alle, die einen Satz belegen wollen statt ihn zu behaupten.** „Wir tracken
  nicht" ist auf einer Website eine Behauptung. Mit dem Quelltext daneben wird
  sie überprüfbar.

## Wo es im Einsatz ist

Dass die Software den Alltag aushält, lässt sich nachsehen: Auf derselben
technischen Basis läuft der öffentliche Dienst [1337.kiwi](https://1337.kiwi)
– ein Nebeneffekt des Projekts, mit eigenem Design und den Inhalten, die ein
öffentliches Angebot braucht. Was sich dort im Betrieb bewährt, steht hier im
Quelltext; was hier für Organisationen dazukommt (zentrale Anmeldung, Gruppen,
Rechte), braucht der öffentliche Dienst nicht.

Wer flatlink installiert, bekommt **kein Imitat davon**: ein neutrales Theme,
das eigene Kürzel, die eigene Domain. Was bleibt, ist eine dezente
Herkunftszeile im Seitenfuß – die verlangt die [Lizenz](#lizenz), und sie ist
auch alles, was sie verlangt.

## Was drin ist

- **Kurzlinks** mit zufälligem oder selbst gewähltem Code, optionalem Namen,
  Schlagworten zum Filtern, optionalem Ablaufdatum und optionalem Passwortschutz
- **QR-Codes** aus einem eigenen Encoder (ISO/IEC 18004, Byte-Mode,
  **Versionen 1–40**, Fehlerkorrektur L/M/Q/H) – ohne jede Fremdbibliothek.
  Bis zu 2953 Zeichen, also auch lange Adressen mit Kampagnen-Parametern
- **QR-Designer** unter `qr-designer.php`: Modul- und Augenformen, freie
  Farben, **Farbverläufe**, **Druckfarben in CMYK**, Export als SVG, PNG,
  **Vektor-PDF und EPS**.
  Angemeldete bekommen auf derselben Seite zusätzlich eigenes Logo, Rahmen mit
  Text und die Auswahl ihrer Links – ein Kurzlink lässt sich dort auch gleich
  anlegen
- **Link-in-Bio-Seiten**: eine Seite mit mehreren Zielen unter einem Kurzcode,
  gezählt wie alles andere – je Tag, für die Seite und je Ziel, ohne
  Besucher-Datensatz
- **Statische QR-Codes** für eine **ungekürzte Adresse oder freien Text**,
  WLAN-Zugänge, Kontakte (vCard), Termine (iCalendar) und **GS1 Digital Link** – die Eingaben werden nirgends
  gespeichert, sondern direkt in den Code kodiert, sodass diese Grafiken völlig
  unabhängig vom Dienst funktionieren
- **Englische Oberfläche**: Deutsch ist die Quellsprache, die Sprache gilt je
  Instanz (`'language'` in der Konfiguration oder unter *Einstellungen*, zur
  Laufzeit). Eine weitere Sprache ist eine Datei unter `inc/lang/`; was einer
  Übersetzung fehlt, bleibt sichtbar deutsch statt leer
- **Konten** mit Selbstregistrierung per Double-Opt-In, Passwort-Reset und
  Rollen (Nutzer/Admin), inklusive Nutzungs-Limits pro Konto
- **QR-Codes einzeln oder als Serie im ZIP**, mit Übersicht als CSV
- **Zwei-Faktor-Anmeldung**: Passkeys (WebAuthn) oder Einmalkennwörter aus
  einer App, mit Wiederherstellungscodes, optional für
  die ganze Instanz erzwingbar
- **Auskunft und Löschung im Profil**: Datenexport als JSON und ein Knopf, der
  Konto und Links wirklich entfernt – Art. 15, 17 und 20 DSGVO ohne
  Ticketsystem
- **Zentrale Anmeldung** über LDAP/Active Directory oder über den Webserver
  (Shibboleth, SAML, OpenID Connect) – siehe [Konten und Anmeldung](docs/konten.md)
- **Gruppen** in zwei Betriebsarten: als Rechtegruppe (Berechtigungen und
  Limits, Links bleiben privat) oder als Arbeitsgruppe, deren Links das ganze
  Team gemeinsam verwaltet
- **CSV-Import** für viele Links auf einmal – die Exporte von Bitly und
  YOURLS lassen sich unverändert einlesen
- **Programmierschnittstelle** mit Zugangsschlüsseln je Konto, siehe
  [API.md](API.md)
- **Missbrauchsschutz**: Rate-Limits pro IP (gespeichert wird nur ein
  Schlüssel-Hash, kein Klartext), Meldeformular, Sperrfunktion, optional
  Google Safe Browsing
- **Automatisches Aufräumen** nie aufgerufener Links, mit Vorwarnung per Mail
  (standardmäßig deaktiviert)
- **Ablage ohne Betrieb**: Links und Konten in einer SQLite-Datei, alles
  Übrige in kleinen JSON-Dateien – kein Datenbank-Server, Backup = Ordner
  kopieren, siehe [Wie die Daten liegen](#wie-die-daten-liegen)

## Voraussetzungen

- PHP 8.1 oder neuer
- Erweiterungen: `json`, `mbstring`, `pdo_sqlite` (Ablage), `gd` (für
  PNG/PDF), `fileinfo` (Logo-Upload), `openssl` (nur für SMTP-Versand),
  `ldap` (nur für die LDAP-Anmeldung)
- Ein Webserver mit `mod_rewrite` oder gleichwertiger Umschreibung.
  Die mitgelieferte `.htaccess` bringt zusätzlich einen Fallback über
  `ErrorDocument 404`, falls Rewrites beim Hoster nicht greifen.

Kein Datenbank-Server, kein Composer, kein Build-Schritt.

## Installation

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink
cp inc/config.example.php inc/config.php
```

Danach `inc/config.php` anpassen (mindestens `site_name`), die Dateien in den
Webroot legen und sicherstellen, dass der Webserver in das Verzeichnis
schreiben darf – `data/` wird beim ersten Aufruf selbst angelegt.

Zum Ausprobieren reicht der eingebaute Server. Er kennt keine Rewrites,
deshalb das mitgelieferte Wegweiser-Skript dazu – es bildet die Regeln der
`.htaccess` nach, damit auch Kurzlinks und `/api/…` funktionieren:

```bash
php -S localhost:8080 router.php
```

**Erstes Konto:** Über `register.php` registrieren. Im Standard steht der
Mailversand auf `log`, die Bestätigungsmail landet also in `data/mail.log` –
dort den Link herauskopieren und aufrufen. Das erste angelegte Konto bekommt
automatisch die Admin-Rolle.

> **Für den echten Betrieb** gibt es eine ausführliche
> **[Deployment-Anleitung](DEPLOYMENT.md)**: Rechte und Webserver-Konfiguration
> für Apache und nginx, Mailversand samt SPF/DKIM/DMARC, LDAP und Active
> Directory, die komplette Shibboleth-Einrichtung inklusive Apache und
> Attributfreigabe – dazu Betrieb, Sicherung und eine Tabelle mit den
> häufigsten Stolpersteinen.
>
> **Eigene Farben, eigenes Logo?** Das beschreibt die
> **[Anpassungs-Anleitung](CUSTOMIZATION.md)** – updatesicher über
> `assets/custom.css`, ohne den Quelltext anzufassen.

## Konfiguration

Alles steckt in `inc/config.php`; die kommentierte Vorlage ist
[`inc/config.example.php`](inc/config.example.php). Die wichtigsten Schalter:

| Option | Bedeutung |
| --- | --- |
| `site_name` | Anzeigename in Titel, Kopfzeile und Mails |
| `base_url` | Feste Basis-URL; leer = automatische Erkennung |
| `sqlite_file` | Pfad der Ablage-Datei; leer = `data/flatlink.sqlite` |
| `language` | Sprache der Oberfläche (`de` ist die Quellsprache, `en` liegt bei) |
| `limits` | Links, Statistik-Tiefe und Logos pro Konto (`0` = unbegrenzt) |
| `default_perms` | Rechte, die jedes angemeldete Konto ohne Gruppe hat |
| `sso` | Zentrale Anmeldung über den Webserver (Shibboleth/SAML/OIDC) |
| `ldap` | Anmeldung gegen LDAP oder Active Directory |
| `qr_brand_text` | Optionale Absenderzeile unter erzeugten QR-Codes |
| `custom_code_min_len` / `custom_code_quota` | Bremsen gegen Namensraum-Squatting auf öffentlichen Instanzen |
| `mail` | `log` schreibt nach `data/mail.log`, `smtp` versendet echt |
| `safe_browsing_key` | Leer = aus. Siehe Warnung unten |
| `link_gc_years` | `0` = kein automatisches Aufräumen |
| `data_dir` | Laufzeitdaten außerhalb des Webroots ablegen – empfohlen |
| `trusted_proxies` | Adressen vorgelagerter Proxys; nötig für korrekte Rate-Limits |

Zur Laufzeit lassen sich im Admin-Bereich außerdem die öffentliche
Link-Erstellung und die Selbstregistrierung abschalten – praktisch, wenn die
Instanz nur intern genutzt werden soll.

## Handbuch

Die README ist der Überblick; die Tiefe steht in eigenen Dokumenten.
Die vier Handbücher gibt es auch auf Englisch (`.en.md` daneben):

| Dokument | Inhalt |
| --- | --- |
| [Der QR-Generator](docs/qr-generator.md) | Encoder, Gestaltung, Lesbarkeitsprüfung, Druck-Export (PDF, EPS, CMYK), Serien, GS1 Digital Link |
| [Kurzlinks im Alltag](docs/kurzlinks.md) | Schlagworte, Kampagnen-Parameter, Link-in-Bio, Umzug von Bitly oder YOURLS |
| [Konten und Anmeldung](docs/konten.md) | Passkeys und Einmalkennwörter, LDAP, Shibboleth/SAML/OIDC, Auskunft und Löschung |
| [Gruppen, Rechte und Domains](docs/gruppen.md) | Rechte- und Arbeitsgruppen, Limits, Namensräume, mehrere Domains je Instanz |
| [API.md](API.md) | die Programmierschnittstelle |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Installation für den Dauerbetrieb, von Dateirechten bis Shibboleth |
| [CUSTOMIZATION.md](CUSTOMIZATION.md) | eigenes Aussehen ohne Änderungen am Kern |
| [SECURITY.md](SECURITY.md) | was gespeichert wird, was nicht, und wie sich Lücken melden lassen |

## Wie die Daten liegen

**Links und Konten liegen in einer SQLite-Datei** (`data/flatlink.sqlite`).
Das ist keine Infrastruktur: kein Server, nichts einzurichten, nichts zu
warten – die Erweiterung `pdo_sqlite` bringt praktisch jedes PHP mit. Der
vollständige Datensatz steht als JSON in einer `data`-Spalte; die übrigen
Spalten sind daraus abgeleitete Kopien für die Suche. Gemessen an einer
Instanz mit einer Million Links und hunderttausend Konten: Anmeldeseite
9 ms, ein einzelnes Konto 0,01 ms, der Nachschlag einer Weiterleitung
0,01 ms – alles innerhalb der üblichen PHP-Speichergrenze.

Alles Übrige sind kleine JSON-Dateien unter `data/`, mit `flock` gegen
gleichzeitige Schreibzugriffe und atomarem Schreiben über Tempdatei plus
`rename`:

| Datei | Inhalt |
| --- | --- |
| `flatlink.sqlite` | Kurzlinks und Konten, siehe oben |
| `clicks/<code>.json` | Klickzähler – bewusst eine Mini-Datei je Code: Der Weiterleitungspfad schreibt sie bei jedem Scan, ohne gemeinsames Schreib-Lock |
| `groups.json` | Gruppen: Anzeigename und Rechte |
| `settings.json` | Zur Laufzeit änderbare Einstellungen |
| `logos/` | Hochgeladene Logos für QR-Codes |
| `ratelimit/` | Zähler je IP-Hash (HMAC mit Instanz-Geheimnis), nach 24 h gelöscht |
| `secret.key` | Geheimnis dieser Instanz für die IP-Hashes – wie ein Passwort behandeln |
| `pending/` | Offene Bestätigungs-Token (Registrierung, Reset) |

Ein Backup ist damit weiterhin ein simples Kopieren des `data/`-Ordners.

Eine ehrliche Grenze bleibt: Die Admin-Gesamtliste über *Millionen* Links
lädt auch mit Datenbank den ganzen Bestand in den Speicher – wer wirklich
dort ankommt, hebt `memory_limit` an. Die gezielte Abfrage je Seite ist der
nächste Schritt, wenn ihn jemand braucht.

## Was nicht drin ist

Damit niemand danach sucht: keine Statistik nach Ländern oder Geräten – das
liegt in der Natur der Sache. Gruppen teilen Links und Rechte, trennen aber
keine Mandanten voneinander: Administratoren sehen immer alles.

Ebenfalls nicht enthalten sind **Impressum, Datenschutzerklärung und AGB**.
Wer eine öffentliche Instanz betreibt, ist in Deutschland und weiten Teilen
der EU dazu verpflichtet, solche Angaben selbst bereitzustellen – sie hängen
von Betreiber, Land und Nutzung ab und lassen sich nicht sinnvoll mitliefern.
Eigene Seiten anlegen und in `page_footer()` in
[`inc/helpers.php`](inc/helpers.php) verlinken.

## Tests

Keine Test-Bibliothek, keine Konfiguration – zwei PHP-Dateien, die man mit dem
eingebauten Server laufen lässt:

```bash
php -S localhost:8080 router.php &
php tests/optionen.php http://localhost:8080
```

[`tests/optionen.php`](tests/optionen.php) prüft, ob jede Gestaltungsoption bei
`qr.php` auch ankommt. Der Anlass war ein Fehler, den ein anderer Test nicht
finden konnte: Vier Modulformen waren im Renderer gebaut und im Designer
angeboten, aber die Prüfliste in `qr.php` kannte sie nicht – und ein unbekannter
Wert wird dort stillschweigend auf die Vorgabe zurückgesetzt. Wer „Raute"
wählte, bekam ein Quadrat, ohne ein Wort dazu.

Der frühere Test fragte nur, ob sich das Ergebnis **scannen** lässt. Ein Code,
dessen Form unterwegs verworfen wurde, lässt sich ebenfalls scannen – die Frage
war falsch gestellt. Jetzt wird dasselbe Bild zweimal erzeugt, einmal über den
Renderer und einmal über die Adresse, und Byte für Byte verglichen.

## Mitmachen

Fehlerberichte und Pull Requests sind willkommen. Eine Bitte vorab: Die
Abhängigkeitsfreiheit ist kein Zufall, sondern der Kern des Projekts. Ein
Patch, der Composer, einen Build-Schritt oder einen Datenbank-*Server*
voraussetzt, wird nicht übernommen – auch wenn er die Sache eleganter
macht. (SQLite besteht diese Prüfung: eine Datei unter `data/`, keine
Infrastruktur.)

## Lizenz

**[GNU AGPL v3](LICENSE)** mit einer Zusatzbedingung zur Namensnennung nach
§ 7(b) der Lizenz. Was das praktisch heißt:

**Erlaubt, ohne zu fragen** – auch kommerziell, auch für zahlende Kundschaft:
benutzen, selbst betreiben, ändern, weitergeben, umbenennen, einfärben, für
eigene Zwecke erweitern.

**Zwei Bedingungen:**

1. **Die Herkunftszeile bleibt sichtbar.** Jede Oberfläche muss auf „flatlink"
   hinweisen und auf <https://1337.kiwi/flatlink> verlinken. Übersetzen,
   umformulieren, klein und dezent setzen – alles erlaubt. Verstecken oder
   weglassen nicht. Der Bezugspunkt ist `origin_note()` in
   [`inc/helpers.php`](inc/helpers.php).
2. **Änderungen bleiben offen.** Wer eine *geänderte* Fassung als Dienst im
   Netz anbietet, muss seinen Nutzern den Quelltext dieser Fassung zugänglich
   machen (AGPL § 13). Wer unverändert betreibt, muss nichts veröffentlichen.

Warum nicht MIT: Weil MIT erlaubt, den Quelltext zu schließen und daraus einen
Dienst zu machen, bei dem niemand mehr nachsehen kann, was mit den Klickdaten
passiert. Der ganze Punkt dieses Projekts ist, dass man das nachsehen kann.

Für eine Fassung ohne Herkunftszeile – etwa als White-Label – gibt es eine
schriftliche Freistellung: <dennis@1337.hamburg>.
