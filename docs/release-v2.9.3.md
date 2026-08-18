# Was gehört in dieses Feld?

Dritter und letzter Nachtrag zum Thema Weichen. 2.9.1 machte sie auffindbar,
2.9.2 auch beim Anlegen verfügbar – blieb die Frage, die man vor dem leeren
Feld tatsächlich hat: *Was trage ich hier ein, damit es auch greift?*

Unter den Zeilen steht jetzt eine Tabelle statt eines Satzes im Fließtext –
man liest so etwas nicht, man schlägt darin nach:

| Merkmal | erlaubte Werte | |
| --- | --- | --- |
| Gerät | `mobile` · `tablet` · `desktop` | grob aus der Browser-Kennung |
| Sprache | `de` · `en` · `fr` … | immer zwei Buchstaben |
| Land | `at` · `ch` … | von einem vorgeschalteten Dienst |
| Anteil (A/B) | `1` – `99` | Prozent, ohne Zeichen |

Bei der Sprache steht dort auch, was sonst nur im Code zu finden war: `en`
trifft auch bei `en-GB`, und ein Zweitwunsch des Browsers zählt mit – wer
Deutsch an erster und Englisch an zweiter Stelle stehen hat, greift eine
`en`-Weiche ebenfalls ab.

Dazu richtet sich das Eingabefeld nach dem gewählten Merkmal: Bei *Anteil*
wird es ein Zahlenfeld mit Grenzen, sonst ein Textfeld mit der passenden
Vorschlagsliste. Geräte und Sprachen sind dabei getrennt; vorher standen
`mobile` und `de` in derselben Liste. Ohne JavaScript bleibt es beim Freitext –
geprüft wird ohnehin auf dem Server.

## Getestet

Die Prüfung war schon streng und bleibt es. Alle sechs Fälle nachgemessen:
`Handy` statt `mobile`, `deutsch` statt `de`, `150` und `30%` statt `30`
werden abgelehnt, jeweils mit einer Meldung, die den erlaubten Wert nennt; die
drei korrekten Werte gehen durch. `tests/optionen.php` 21 von 21,
`tests/einstellungen.php` bestanden.

Nebenbei ein schiefes Anführungszeichen in genau dieser Meldung gerade
gerückt – „en" schloss mit einem geraden Zeichen.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt, keine neuen Dateien. Betroffen
sind `admin/index.php`, `assets/app.js`, `assets/style.css`, `inc/routing.php`,
`inc/lang/en.php` und `docs/kurzlinks.md`.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
