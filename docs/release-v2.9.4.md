# Welche Sprache spricht das Ziel?

Sprach-Weichen taten nicht, was man von ihnen erwartet – und beide Anläufe,
das zu beheben, scheiterten am selben fehlenden Wissen: **Welche Sprache
spricht eigentlich das Hauptziel?**

## Zwei Fehlversuche

Bis 2.9.3 traf eine Weiche auf `en`, sobald Englisch **irgendwo** in der
Sprachliste des Browsers stand. Dort steht es bei fast jedem:
`de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7` ist die Voreinstellung eines deutschen
Chrome. Wer Deutsch bevorzugte, landete trotzdem auf der englischen Seite – die
Weiche fing praktisch alle ab, statt die englischsprachigen auszusortieren.

Der naheliegende Gegenzug war, nur noch die *bevorzugte* Sprache zu prüfen.
Damit landete dann ein Student mit chinesischem Browser und Englisch als
Zweitsprache auf der **deutschen** Seite, obwohl die englische für ihn die
bessere gewesen wäre. Ein Fehler, ersetzt durch den anderen.

## Die Zielsprache gehört dazu

Ein Link trägt jetzt die Sprache seines Hauptziels – ein Feld direkt über den
Weichen. Damit wird verhandelt statt geraten: Die Sprachwünsche des Browsers
werden der Reihe nach durchgegangen, und der erste, der entweder das Hauptziel
oder eine Weiche trifft, gewinnt.

Hauptziel deutsch, eine Weiche `en` auf die englische Fassung:

| Besucher | landet auf | warum |
| --- | --- | --- |
| Browser `de, en` | deutsche Seite | Deutsch steht vor Englisch und ist die Zielsprache |
| Browser `zh, en` | **englische Seite** | Chinesisch trifft nichts, dann greift Englisch |
| Browser `en` | englische Seite | Englisch trifft die Weiche |
| Browser `fr` | deutsche Seite | nichts passt |

**Ohne Angabe der Zielsprache** bleibt es bei der strengen Regel: Umgeleitet
wird nur, wer die Sprache der Weiche bevorzugt. Das ist der sichere Rückfall –
lieber bleibt jemand auf dem Hauptziel, als dass eine Weiche alle einsammelt.
Bestehende Links verhalten sich also unverändert, bis jemand die Zielsprache
einträgt.

Nebenbei: Die **Gewichte** im Header wurden bisher ignoriert, gelesen wurde nur
die Reihenfolge. Die ist üblicherweise absteigend sortiert, verlangt ist es
aber nicht – `de;q=0.7,en;q=0.9` hätte Deutsch als bevorzugt gemeldet.

## Was in das Wertfeld gehört

Unter den Weichen steht jetzt eine Tabelle statt eines Satzes im Fließtext –
man liest so etwas nicht, man schlägt darin nach:

| Merkmal | erlaubte Werte | |
| --- | --- | --- |
| Gerät | `mobile` · `tablet` · `desktop` | grob aus der Browser-Kennung |
| Sprache | `de` · `en` · `fr` … | die bevorzugte Sprache des Browsers |
| Land | `at` · `ch` … | von einem vorgeschalteten Dienst |
| Anteil (A/B) | `1` – `99` | Prozent, ohne Zeichen |

Dazu richtet sich das Eingabefeld nach dem gewählten Merkmal: Bei *Anteil* wird
es ein Zahlenfeld mit Grenzen, sonst ein Textfeld mit passender
Vorschlagsliste. Ohne JavaScript bleibt es beim Freitext – geprüft wird ohnehin
auf dem Server, und zwar streng: `Handy` statt `mobile`, `deutsch` statt `de`
oder `30%` statt `30` werden abgelehnt.

## Getestet

Die Sprachverhandlung über den ganzen Weg – Formular, Ablage, echte
Weiterleitung mit gesetztem `Accept-Language` – mit vier Besuchern; alle vier
landen dort, wo sie hingehören. Sechs Accept-Language-Werte gegen die
Sprachauswahl geprüft, darunter umgekehrte Gewichte und ein leerer Kopf. Sechs
Eingaben gegen die Wertprüfung, drei gültige und drei ungültige.
`tests/optionen.php` 21 von 21, `tests/einstellungen.php` bestanden.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt, keine neuen Dateien. Betroffen
sind `admin/index.php`, `assets/app.js`, `assets/style.css`, `inc/routing.php`,
`inc/store.php`, `inc/linkrules.php`, `inc/lang/en.php` und
`docs/kurzlinks.md`.

Wer Sprach-Weichen benutzt, sollte danach einmal die Zielsprache eintragen –
ohne sie bleibt es beim vorsichtigen Verhalten.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
