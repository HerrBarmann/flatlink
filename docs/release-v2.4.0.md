# Eine Million Links, eine Datei

Diese Fassung baut flatlink für große Bestände um – gemessen an einer
Testinstanz mit einer Million Kurzlinks und hunderttausend Konten – und
zieht daraus die Konsequenz bei der Datenhaltung.

## Links und Konten liegen in SQLite

Die JSON-Datei-Ablage stieß an messbare Grenzen: Eine einzelne `users.json`
mit hunderttausend Konten sprengte PHPs übliche Speichergrenze, die
Limit-Prüfung beim Anlegen las den gesamten Bestand, Listen brauchten
Gigabytes. Links und Konten liegen deshalb jetzt in einer **SQLite-Datei**
(`data/flatlink.sqlite`).

Das ändert nichts am Charakter des Projekts: **kein Datenbank-Server, nichts
einzurichten, nichts zu warten.** Die Erweiterung `pdo_sqlite` bringt
praktisch jedes PHP mit, und das Backup bleibt das Kopieren des
`data/`-Ordners. Der vollständige Datensatz steht als JSON in einer
`data`-Spalte – dieselbe Wahrheit wie zuvor in den Dateien, nur anders
abgelegt; die übrigen Spalten sind daraus abgeleitete Kopien für die Suche.

Die Zahlen an der Millionen-Instanz, alles innerhalb von 128 MB
Speichergrenze:

| | Datei-Ablage | SQLite |
| --- | --- | --- |
| Anmeldeseite bei 100.000 Konten | Speicherfehler | 9 ms |
| Ein einzelnes Konto | 121 MB laden | 0,01 ms |
| Nachschlag einer Weiterleitung | 2,9 ms | 0,03 ms |
| Limit-Prüfung beim Anlegen | 1225 ms | 1 ms |
| Linkliste eines Kontos (2000 Links) | Speicherfehler | 170 ms |
| Speicher-Spitze | 1244 MB | 4 MB |

Klickzähler bleiben bewusst Einzeldateien: Der Weiterleitungspfad schreibt
sie bei jedem Scan, und genau dort soll kein gemeinsames Schreib-Lock
entstehen. Einstellungen, Gruppen, Logos, Rate-Limits und offene
Bestätigungen bleiben ebenfalls kleine Dateien – nichts davon wächst mit dem
Bestand.

Die Konten-Schnittstelle wurde dafür vollständig gekapselt (`users_all`,
`user_get`, `users_update` mit Ein-Konto-Pfad für die häufigen Schreiber wie
TOTP- und Passkey-Zähler oder den SSO-Login) und je Anfrage
zwischengespeichert; `user_resolve` löst E-Mail-Adressen per Abfrage statt
per Vollscan auf.

## Die Linkliste blättert

50 Links je Seite, neueste zuerst. Suchfeld, Gruppen- und Schlagwort-Filter
wandern beim Blättern mit; eine zu große Seitenzahl klemmt auf die letzte
Seite. Die Klickzähler werden nur noch für das aufgeschlagene Blatt gelesen;
die Klicksumme in der Kopfzeile bleibt bis 2000 sichtbare Links, darüber
steht die Linkzahl allein. Der Serien-Knopf meint weiterhin den ganzen
gefilterten Bestand – wer eine gefilterte Serie zieht, meint alle Treffer.

## Änderungen am Verhalten

- **Die JSON-Ablage für Links und Konten ist entfernt** – mitsamt der 256
  Link-Ablagen, des Besitzer-Index und des `storage`-Schalters. `data/`
  enthält für Links und Konten nur noch `flatlink.sqlite`.
- **Wer eine bestehende 2.3-Instanz mit Datei-Beständen betreibt**, migriert
  über den Zwischenstand (Commit `6998e91`, Knopf unter *Einstellungen →
  Ablage* oder `php migrate-sqlite.php`) und aktualisiert danach – 2.4.0
  selbst bringt keinen Umzugsweg mehr mit. Frische Instanzen legen die
  Datenbank beim ersten Aufruf selbst an.
- `migrate-links.php` und `migrate-sqlite.php` gibt es nicht mehr; wer sie
  auf dem Server liegen hat, kann sie löschen.
- Die Ablage-Karte in den Einstellungen ist weg. Was eine Handlung verlangt,
  bleibt: Liegt das Datenverzeichnis ungeschützt im Webroot, erscheint dort
  weiterhin die Warnung – sonst nichts.
- Neue Voraussetzung: die PHP-Erweiterung `pdo_sqlite` (Standardausstattung;
  fehlt sie, sagt flatlink es mit einem Satz statt eines Fatal Error).

## Getestet

Frischer Auschecken-Stand: Ersteinrichtung, 60 Links samt Blättern über zwei
Seiten, Weiterleitung, alle Admin-Seiten, Sprachumschaltung auf Englisch,
`tests/optionen.php` 21 von 21, keine PHP-Fehler. Identischer
Lebenszyklus-Test (Erstinstallation, Kollisionen, Gruppenwechsel,
Sichtbarkeit, Kontolöschung mit besitzerlos werdendem Gruppenlink,
E-Mail-Auflösung) und die Messungen oben an der generierten
Millionen-Instanz.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b),
seit 2.0.0.
