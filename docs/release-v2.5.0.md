# Wer, wann, wohin

Diese Fassung dreht sich um eine Frage, die eine Instanz beantworten können
muss, sobald mehr als eine Person damit arbeitet: **Wer war das?** Wer ist
gerade angemeldet, wer hat einen Link gesperrt, wohin führte ein Kurzlink
gestern noch. Dazu kommt eine Runde Sicherheitsarbeit aus dem dritten
externen Review und eine vorkonfigurierte Zwischenstufe zwischen Nutzer und
Administrator.

## Angemeldete Geräte

Im Profil steht jetzt, wo das eigene Konto offen ist: seit wann, zuletzt
gesehen, und eine grobe Beschreibung des Geräts aus der Browser-Kennung
(„Firefox · macOS") – ohne Versionsnummern, ohne die vollständige Kennung.
Jede Anmeldung lässt sich einzeln beenden, oder alle auf einmal außer der
laufenden.

Die Sitzung selbst wird dabei nur als SHA-256-Hash am Konto vermerkt: Was
gespeichert wird, reicht zum Wiedererkennen und Widerrufen, aber nicht zum
Übernehmen. Der Widerruf greift sofort – `auth_user()` prüft ihn bei jeder
Anfrage und meldet eine zurückgezogene Sitzung ab. Höchstens zehn Einträge je
Konto; der Zeitstempel wird höchstens alle zehn Minuten geschrieben, damit die
Liste kein Schreibverkehr wird.

## Protokoll der Verwaltungshandlungen

Wer hat den Link gesperrt? Wer hat die Domain geändert? Unter *Protokoll*
stehen die letzten 200 Verwaltungshandlungen mit Zeitpunkt und Konto – Links
gesperrt, Konten freigeschaltet, Einstellungen gespeichert, Sicherungen
gezogen.

Was dort **nie** steht: Besucher. Keine Klicks, keine Adressen, keine
Weiterleitungen. Ein Protokoll, das den Weiterleitungspfad mitschreibt, wäre
genau das Besucher-Logbuch, das dieses Projekt nicht führt. Die Datei dahinter
ist `data/audit.log`, eine JSON-Zeile je Ereignis, blockweise von hinten
gelesen; die Anzeige kann nichts löschen – ein Protokoll, das sich aus der
Oberfläche kürzen ließe, wäre keines.

## Änderungen am Ziel

Ein gedruckter QR-Code lässt sich nicht zurückrufen – sein Ziel schon. Genau
das macht Kurzlinks nützlich und zugleich missbrauchbar: Das Plakat hängt
weiter, die Adresse ist dieselbe, nur die Landung ist eine andere.

Deshalb hält flatlink jetzt fest, wer wann von wo nach wo geändert hat, und
zeigt es in der Statistik des Links. Bewusst nur das Ziel: Titel, Schlagworte
und Gestaltung sind Ordnung, keine Zusage. Die letzten zwanzig Änderungen je
Link – genug, um eine stille Umleitung zu bemerken, wenig genug, dass der
Datensatz nicht wächst, der bei jeder Weiterleitung gelesen wird.

## Eine Redaktion, ohne eine dritte Rolle

Wer den Missbrauchs-Posteingang einer öffentlichen Instanz hütet, braucht
Zugriff auf jeden Link – aber nicht auf die SMTP-Zugangsdaten. Statt einer
starren dritten Rolle gibt es zwei neue Rechte:

| Recht | Bedeutung |
| --- | --- |
| `links_all` | sieht und verwaltet alle Links der Instanz |
| `reports_manage` | bearbeitet Meldungen und sperrt Links |

Eine Gruppe „Redaktion" mit beiden ergibt genau die Zwischenstufe; einzeln
vergeben ergeben sie eine Aufsicht, die alles sieht, oder eine
Beschwerdestelle, die nur die gemeldeten Links zu Gesicht bekommt. Konten,
Gruppen, Einstellungen und Protokoll bleiben in beiden Fällen dicht (403).

## Geplante Aktivierung

Das Ablaufdatum hatte kein Gegenstück: „geht erst am Datum X live" ließ sich
nicht hinterlegen. Genau das braucht, wer Plakate drucken lässt, bevor die
Kampagne läuft. Ein zu früh gescannter Code antwortet mit 410 und nennt das
Datum – dieselbe Antwort wie bei einem abgelaufenen Link, weil es dieselbe
Lage ist: Der Code existiert, führt heute nur nicht.

Durchgezogen bis in jede Oberfläche: Anlegen, Bearbeiten, Liste mit
„geplant"-Abzeichen, CSV in beide Richtungen, Schnittstelle als `starts` und
`pending`. Ein Startdatum nach dem Ablaufdatum wird abgelehnt.

## Mitnehmen und sichern

**Eigene Links als CSV**: der gefilterte Bestand als Datei, im Format des
eigenen Imports – wer exportiert, kann dieselbe Datei anderswo oder hier
wieder einlesen. Der Rückweg ist kein Zusatz, sondern das Gegenstück zum Umzug
hierher; niemand soll bleiben müssen, weil er seine Links nicht mitnehmen
kann.

**Sicherung als Archiv**: ein Knopf unter *Einstellungen*, der die Datenbank
(über `VACUUM INTO`, der von SQLite vorgesehene Weg im laufenden Betrieb),
die Zustandsdateien, Klickzähler, Logos und Meldungen als ZIP ausliefert –
samt Zettel `WIEDERHERSTELLEN.txt`. „Backup ist Ordner kopieren" bleibt der
beste Weg; auf Shared Hosting ohne Shell ist ein Knopf aber der Unterschied
zwischen „es gibt eine Sicherung" und „es gibt keine". `inc/config.php` liegt
bewusst nicht im Archiv: Sie enthält Zugangsdaten und gehört nicht in eine
Datei, die anschließend im Download-Ordner liegt.

**Auskunft nach Art. 15** war stillschweigend unvollständig geworden. Der
Export nennt jetzt auch angemeldete Geräte, den Stand der
Zwei-Faktor-Anmeldung samt Passkey-Bezeichnungen, die Zugangsschlüssel, das
Startdatum, die Änderungen am Ziel und den Inhalt von Link-in-Bio-Seiten – und
zählt am Ende auf, was er aus welchem Grund auslässt.

## Sicherheit

- **SVG-Logos werden beim Hochladen bereinigt** statt roh gespeichert. Eine
  Allowlist erlaubter Elemente, Attribute und Stil-Eigenschaften; alles andere
  fällt weg, `<!ENTITY>` führt zur Ablehnung, `url()` darf nur nach innen
  zeigen. Geprüft gegen elf Angriffsmuster.
- **Wiederholte Safe-Browsing-Prüfung**: Eine heute harmlose Seite kann morgen
  gekapert sein. Der Bestand wird in Blöcken erneut geprüft (`safety_recheck`,
  Vorgabe alle sieben Tage) und dabei gesperrt statt gelöscht – gedruckte
  Codes sollen reparierbar bleiben.
- **Zugangsschlüssel liegen in der Datenbank.** `tokens.json` wurde bei jedem
  API-Aufruf vollständig gelesen und wuchs mit der Zahl der Konten: bei 50.000
  Schlüsseln 34 ms und 86 MB je Anfrage, als Abfrage über den Primärschlüssel
  0,005 ms. Eine vorhandene Datei wird einmalig übernommen und danach
  umbenannt statt gelöscht.

## Behoben

- **Der CSV-Rückweg war an drei Stellen undicht** und fiel erst beim
  Gesamttest zu dieser Fassung auf: Die Kopfzeile kannte das Startdatum nicht
  (alle Spalten dahinter standen unter der falschen Überschrift – ein
  Startdatum wurde beim Zurückspielen zum Ablaufdatum); das BOM war keines,
  sondern die Zeichen `ï»¿` (Excel verstümmelte weiter die Umlaute, und der
  eigene Import erkannte die Code-Spalte nicht mehr, vergab also neue
  Zufallscodes – jeder gedruckte Code wäre tot gewesen); und das Startdatum
  fehlte im Import ganz.
- `str_getcsv()` bekommt das Escape-Zeichen ausdrücklich mit – ab PHP 8.4 sonst
  eine Deprecated-Meldung je Zeile. Der leere String ist zugleich der richtige
  Wert: CSV nach RFC 4180 kennt kein Backslash-Escape.
- Der Update-Zweig der Linkliste reichte das Startdatum nicht durch; das
  Bearbeiten-Formular hätte es stillschweigend verworfen.
- `admin/reports.php` band die Rechte-Schicht nicht ein und lief bei der
  Rechteprüfung in einen Fatal Error.

## Getestet

Frischer Auschecken-Stand: Ersteinrichtung, 60 Links mit Blättern über zwei
Seiten, Weiterleitung, geplanter Link (410), Zieländerung mit Historie,
CSV-Rundlauf (Export → Codes umbenennen → Import: Wunsch-Codes und Startdatum
kommen an), Sicherung ziehen und in eine leere Instanz zurückspielen (123
Links, Anmeldung mit altem Passwort), alle Admin-Seiten, Sprachumschaltung auf
Englisch, `tests/optionen.php` 21 von 21, keine PHP-Meldungen. Die
Redaktionsrechte gegen ein Testkonto: sieht alle Links, kommt in die
Meldungen, bekommt bei Nutzern, Gruppen, Einstellungen und Protokoll 403.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b),
seit 2.0.0.
