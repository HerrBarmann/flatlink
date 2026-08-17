# Die zweite Instanz

flatlink lief bisher an genau einer Stelle. Seit dieser Fassung läuft es an
zweien – die erste Einrichtung außerhalb des eigenen Hauses war eine
Hochschule mit LDAP, Arbeitsgruppen und einem Bestand aus YOURLS.

Das ist die ehrlichste Beschreibung dieser Version: Fast nichts darin ist eine
Idee am Schreibtisch. Es ist die Liste dessen, was beim ersten fremden
Einbau aufgefallen ist.

## Alle QR-Typen im Kern

WLAN-Zugang, Kontaktkarte, Termin und GS1 Digital Link gab es bisher nur auf
der Instanz des Projektbetreibers. Im Kern hatte der Designer genau einen Typ:
Kurzlink. Das war eine Trennung ohne Grund – der Encoder kann alles davon,
es fehlten nur die vier Seiten davor.

Sie liegen jetzt im Projekt (`wlan-qr.php`, `vcard-qr.php`, `termin-qr.php`,
`gs1-qr.php`), mit denselben Gestaltungsoptionen wie der Designer und einer
gemeinsamen Reiterzeile. **WLAN-Passwörter gehen per POST**, damit sie nicht
in Adresszeilen und Server-Protokollen stehen; gespeichert wird nichts.

## Logos für Gruppen freigeben

Die Logo-Bibliothek war streng persönlich: Wer ein Logo hochlud, war der
Einzige, der es verwenden konnte. In einer Einrichtung ist das die falsche
Grenze – das Logo der Hochschule lädt jemand einmal hoch, benutzen soll es
die ganze Abteilung.

Jedes eigene Logo lässt sich jetzt für Gruppen freigeben, mit dem Sonderwert
„alle angemeldeten Konten". Die Freigabe erlaubt ausschließlich das
**Verwenden**: Umbenennen und Löschen bleiben beim Eigentümer, und das Logo
zählt weiter auf dessen Kontingent. In der Auswahl steht bei fremden Logos,
wem sie gehören.

Dafür fiel eine Bedingung: Die Auswahl verlangte das Recht `logo_upload`. Das
regelt aber das Hochladen, nicht das Verwenden – und wer nichts hochladen
darf, ist gerade der typische Empfänger einer Freigabe.

## Rechte nach Sorten getrennt

Die Rechteliste war eine lange Reihe gleichrangiger Häkchen. Sie zerfällt aber
in zwei Sorten, die nichts miteinander zu tun haben: **was ein Konto selbst
darf** (eigene Wunsch-Namen, eigene Logos, eigener Schnittstellenzugang) und
**was jemand für andere darf** (Konten verwalten, Meldungen bearbeiten). Das
eine beschreibt einen Tarif, das andere eine Rolle. In der Oberfläche stehen
sie jetzt in getrennten Blöcken.

`api_access` gehört seither zur Grundausstattung. Eine Schnittstelle, die man
erst freischalten lassen muss, ist für ein Werkzeug dieser Größe eine Hürde
ohne Gegenwert.

## Zentrale Anmeldung, brauchbar gemacht

Drei Dinge, die sich erst im Betrieb zeigen:

**Das Protokoll sagt jetzt, woran es lag.** „Anmeldung fehlgeschlagen" hat
acht mögliche Ursachen, und im Browser sieht man keine davon – zu Recht, denn
wer sich anmeldet, soll nicht erfahren, ob eine Kennung existiert. Wer die
Instanz einrichtet, braucht aber genau diese Auskunft.

**Ein Werkzeug für die Kette davor.** `tools/ldap-check.php` geht Erweiterung,
Konfiguration, Verbindung, Bind, Suche und Passwortprüfung der Reihe nach
durch und hält an der ersten Stelle an, die nicht stimmt – mit einem konkreten
Rat statt einer Fehlernummer.

**Gruppen aus dem Verzeichnis löschen keine lokalen mehr.** Wer aus dem
Verzeichnis kommt, brachte bei jeder Anmeldung seine dortigen Gruppen mit –
und die von Hand vergebenen fielen dabei heraus. Die neue Einstellung
`group_sync` kennt `merge` (Vorgabe), `replace` und `off`.

## Mailversand gegen hauseigene Relays

Ein Hochschul-Relay nimmt auf Port 25 ohne Anmeldung an, verlangt aber
mitunter kein STARTTLS. Der Versand bot beides trotzdem an und brach ab. Jetzt
gilt: STARTTLS nur, wenn der Server es anbietet; AUTH nur mit Zugangsdaten –
und Zugangsdaten ohne Verschlüsselung sind ein Abbruch, kein Versuch.

## Behoben

**Die Einstellungen froren die ganze Konfiguration ein.** Beim Speichern wurde
der komplette laufende Zustand in die Datei geschrieben – einschließlich aller
Werte aus `inc/config.php`. Wer danach in der Konfiguration etwas änderte, sah
keine Wirkung mehr. Basis ist jetzt die Datei, nicht der Speicher.

**Die Freischaltung legte aus jeder Verzeichnis-Anfrage ein SSO-Konto an.**
In der Warteschlange stand nicht, woher die Anfrage kam.

**Ein Bio-Logo war nie zu sehen.** Der Upload benennt die Dateien
`a1b2….png`, `logo.php` und `bio_logo_url()` prüften aber noch gegen reine
Hex-Kennungen ohne Endung – die Adresse blieb dadurch immer leer.

**Bio-Logos wurden rund beschnitten.** Bei einem Porträt kleidet das gut, bei
einem Logo fallen die Ecken weg und der Schriftzug am Rand gleich mit. Jetzt
wird proportional eingepasst: höchstens 96 Pixel hoch, 240 breit.

**`bio_logo` wurde beim Speichern nicht geprüft.** Per POST ließ sich jede
fremde Logo-Kennung eintragen.

**Der CSV-Import kannte nur Kommas.** Tabulator und Semikolon werden jetzt
selbst erkannt – Tabellenprogramme im deutschen Sprachraum liefern
Semikolons.

**Die Reiterzeile der QR-Generatoren stand doppelt**, und ein Klick auf das
Logo führte angemeldete Nutzer auf die Startseite für Gäste.

## Getestet

Frischer Auscheck-Stand, Ersteinrichtung, alle Verwaltungsseiten und
öffentlichen Seiten abgerufen – keine PHP-Meldung im Protokoll. Kurzlink
angelegt, Weiterleitung geprüft (302 auf das Ziel), Statistik aufgerufen.

Alle fünf QR-Typen erzeugt und die Nutzlast **mit einem Lesegerät zurück
dekodiert**, nicht nur betrachtet: Kurzlink, `WIFI:T:WPA;S:…;P:…;;`, VCARD
mit Name, Firma und Adresse, VCALENDAR mit Ort und Zeitraum, GS1 Digital Link
mit Charge, Seriennummer und Haltbarkeit als Datenattribut.

Logo-Freigabe mit vier Konten und zwei Gruppen: Die Eigentümerin sieht alle
ihre Logos, ein Mitglied beider Gruppen drei, eines nur einer Gruppe zwei, das
private keiner außer ihr und dem Administrator. Der Versuch eines fremden
Kontos, die Freigabe zu ändern, wird abgewiesen. Bio-Kasten für ein Bild von
300 × 120 wird 240 × 96 – Seitenverhältnis unverändert.

`tests/optionen.php` 21 von 21, `tests/einstellungen.php` bestanden.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt. Neu sind `wlan-qr.php`,
`vcard-qr.php`, `termin-qr.php`, `gs1-qr.php`, die zugehörigen Dateien in
`assets/` sowie `tools/ldap-check.php` und `tools/screenshots.php` – **beim
Hochladen daran denken, dass ein Abgleich vorhandener Dateien neue Dateien
überspringt.**

Wer LDAP oder SSO benutzt, sollte `group_sync` bewusst setzen; ohne Eintrag
gilt `merge`, also „beides behalten". Bestehende Konfigurationen laufen
unverändert weiter.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
