Diese Fassung beantwortet die Frage „was, wenn wir eine Million Kurzlinks
haben?" – gemessen, nicht geschätzt, und ohne der Datei-Ablage untreu zu
werden.

## Der Anlass

Das Aufteilen der Ablage (2.1.0) hatte den Weiterleitungspfad gelöst: Ein
Scan liest wenige Kilobyte, egal wie groß der Bestand ist. Listen und
Zählungen lasen aber weiterhin alles. An einer Testinstanz mit einer Million
Links und hunderttausend Konten hieß das: 1,2 Sekunden für die Limit-Prüfung
beim Anlegen eines einzigen Links, 646 MB Speicher für eine Linkliste – und
eine Verwaltungsseite starb schlicht an PHPs üblicher 128-MB-Grenze.

## Besitzer- und Gruppen-Index

`data/links/owners/` beantwortet „welche Codes gehören diesem Konto?",
`data/links/groups-index.json` dasselbe je Arbeitsgruppe. Der Index ist eine
**Ableitung der Ablage, nie eine zweite Wahrheit**: Er nennt nur Codes samt
Typ, die Datensätze bleiben in den Ablagen. Er baut sich beim ersten Bedarf
selbst auf (2,4 s bei einer Million Links, einmalig – ausgelöst spätestens
vom nächsten angelegten Link), lässt sich durch Löschen der Markierung
`owners/fertig` jederzeit neu ableiten, und wo Index und Ablage sich
widersprechen, hat die Ablage recht.

Darüber laufen jetzt die Limit-Prüfungen, die Linkliste für
Nicht-Administratoren, Datenexport und Kontolöschung. Der Index liefert
dabei nur Kandidaten – ob ein Link sichtbar ist, entscheidet weiterhin
dieselbe Zugriffsprüfung wie zuvor.

Gemessen an derselben Millionen-Instanz:

| | vorher | nachher |
| --- | --- | --- |
| Limit-Prüfung beim Anlegen | 1225 ms | 0,5 ms |
| Linkliste, Konto mit 12 Links | Vollscan | 43 ms |
| Linkliste, Konto mit 2000 Links | Fatal Error (128 MB) | 1,0 s, läuft |
| Speicher-Spitze | 1244 MB | 152 MB |
| Weiterleitung | 7 ms | 7 ms (unberührt) |

## Konten-Ablage gekapselt

Jeder Zugriff auf die Konten läuft jetzt über drei Funktionen: `users_all()`
(liest je Anfrage nur noch einmal von der Platte – eine Seite fragte die
Liste leicht fünfmal an), `user_get()` für das einzelne Konto,
`users_update()` als einzige Schreibstelle unter Lock.

Das ist zugleich die Vorbereitung auf ein Datenbank-Backend, ohne eines zu
bauen: Die neuen Abfragen entsprechen genau dem, was dort ein Index
beantworten würde – `links_of_owner()` ist `WHERE owner = ?`, `link_count()`
ist `COUNT(*)`, `users_update()` die Transaktion. Ein Backend ersetzt diese
Handvoll Funktionen, nicht das Projekt.

Die README benennt die verbleibende Grenze ehrlich: `users.json` als eine
Datei trägt einige zehntausend Konten – darüber beginnt das Gebiet der
Datenbank. Die Testinstanz mit hunderttausend Konten starb am Ende nicht
mehr an den Links, sondern an dieser einen Datei.

## Änderungen am Verhalten

- Neu unter `data/`: `links/owners/` und `links/groups-index.json`. Beides
  entsteht zur Laufzeit von selbst – **es gibt nichts zu migrieren**, und
  ein Backup bleibt das Kopieren des `data/`-Ordners.
- Das erste Anlegen eines Links nach dem Update trägt die einmaligen Kosten
  des Index-Aufbaus (Sekunden nur bei sehr großen Beständen).
- Neue Funktionen der Ablage-Schnittstelle: `user_get()`, `users_update()`,
  `links_of_owner()`, `link_codes_of_group()`.

Bestehende Kurzlinks, Konten und Statistiken sind nicht betroffen.

## Getestet

Lebenszyklus mit Konsistenznachweis: Nach Anlegen, Gruppenwechsel, Löschen
und Kontolöschung ist der gepflegte Index identisch mit einem Neuaufbau aus
der Ablage. Dazu der Gesamttest gegen einen frischen Auschecken-Stand
(Ersteinrichtung, Anlegen samt Index-Aufbau, Weiterleitung, alle
Admin-Seiten, `tests/optionen.php` 21 von 21, keine PHP-Fehler) und die
Benchmarks oben an einer generierten Instanz mit einer Million Links und
hunderttausend Konten.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b),
seit 2.0.0.
