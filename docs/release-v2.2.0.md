flatlink spricht jetzt Englisch – und die Gestaltungsoptionen des QR-Designers
stehen in jedem Generator, nicht nur in einem.

## Englische Oberfläche

Deutsch bleibt die Quellsprache und steht weiterhin unmittelbar im Code:
`t('Anmelden')`. Eine weitere Sprache ist eine Datei unter `inc/lang/`, die
ein Wörterbuch zurückgibt. Eingestellt wird sie über `'language'` in der
Konfiguration oder unter *Einstellungen*, zur Laufzeit umschaltbar; sie gilt
für die ganze Instanz.

**Warum Deutsch als Schlüssel und keine Kennungen wie `login.title`:**
Kennungen zwingen jeden, der eine Vorlage liest, zum Nachschlagen in einer
zweiten Datei – und dieses Projekt lebt davon, dass man es lesen kann. Der
Preis ist bekannt: Ändert sich ein deutscher Satz, muss das Wörterbuch
mitziehen, sonst fällt die Stelle auf Deutsch zurück. Genau das ist als
Ausfallverhalten gewollt – ein deutscher Satz auf einer englischen Seite ist
sichtbar und damit auffindbar; eine leere Stelle wäre es nicht.

Das Wörterbuch `inc/lang/en.php` deckt **833 Texte** ab: öffentliche Seiten,
Verwaltung, Mails, Fehlermeldungen der Module und die menschenlesbaren
Meldungen der Schnittstelle. Die stabilen `error`-Codes der API bleiben
unverändert – Programme hängen an ihnen.

Auch die Skripte im Browser übersetzen mit, ohne die Content-Security-Policy
aufzuweichen: `page_footer()` legt die von ihnen benutzten Texte als
JSON-Datenblock in die Seite (ein Datenblock ist kein ausführbares Skript).
Auf einer deutschen Instanz entfällt er.

Geprüft wird die Abdeckung maschinell in beide Richtungen: Jeder `t()`-Aufruf
im Code hat einen Eintrag, kein Eintrag ist verwaist, und die
sprintf-Platzhalter stimmen zwischen Schlüssel und Übersetzung überein.

**README und Handbücher gibt es ebenfalls auf Englisch** – `README.en.md` und
`docs/*.en.md`.

## Gestaltung in jedem QR-Generator

Die vollen Gestaltungsoptionen gab es bisher nur im QR-Designer; WLAN,
Kontakt, Termin, GS1 und die QR-Serie boten zwei Farbfelder. `qr.php` nahm
alle Parameter längst für jeden Code-Typ an – es fehlte die Oberfläche davor.

Das Gestaltungs-Panel steht jetzt genau einmal (`inc/qrpanel.php`), die
Sammellogik dazu ebenso (`assets/qroptions.js`). Damit haben alle Generatoren
dieselben Möglichkeiten: sieben Modulformen, Augen-Form und -Kern mit eigenen
Farben, Farbverläufe mit Vorlagen, Fehlerkorrektur, Quiet-Zone, durchsichtiger
Hintergrund, Druckfarben in CMYK, Rahmentext, Logo – dazu **EPS-Export und die
Lesbarkeitsprüfung überall**.

Die **QR-Serie** nimmt die Gestaltung für das ganze Archiv an, samt
Fehlerkorrektur, Rahmentext und Logo, und zeigt eine Live-Vorschau am ersten
Link der Auswahl.

## Links-Seite

Die Seite für Angemeldete war ein Stapel grauer Kästen. Jetzt liegen Kopf und
Formular auf einer Bühne (`.stage` / `.hero` – dieselben Haken, an denen eine
Instanz die öffentlichen Seiten gestaltet), das Anlegen ist auf ein Feld und
einen Knopf gestrafft, der Rest steckt in einer Klappe. Nach dem Anlegen
erscheint eine Ergebnis-Karte mit Kurzadresse, QR-Code und den Wegen zu
Designer und Statistik.

Die Liste selbst zeigt eine Karte je Link statt einer Tabellenzeile: der
Kurz-Code als Anfasser mit Kopieren-Knopf, darunter Ziel und Schlagworte,
rechts die Klickzahl und die Werkzeuge. Ohne eigene Stile bleibt es eine
schlichte Seite – die Gestaltung liegt in `assets/style.css` und spricht die
vorhandenen Farb-Variablen.

## Wegweiser für den eingebauten Server

Die README versprach „zum Ausprobieren reicht der eingebaute Server" – aber
der kennt keine Rewrites, und ein Kurzlink führte damit zur Startseite statt
zum Ziel. Genau die Fünf-Minuten-Erfahrung, mit der das Projekt wirbt.

Neu ist deshalb `router.php`, das für `php -S` die vier Regeln der
`.htaccess` nachbildet: interne Verzeichnisse sperren, echte Dateien
durchreichen, `/api/…` an die Schnittstelle geben, alles Übrige als Kurzcode
behandeln.

```bash
php -S localhost:8080 router.php
```

Im Dauerbetrieb ändert sich nichts – dort arbeitet weiter die `.htaccess`.

## Änderungen am Verhalten

- **Der Anlege-Knopf der Links-Seite heißt jetzt „Kürzen"**, und die
  Zusatzfelder stecken in einer Klappe. Es fehlt nichts, es steht nur nicht
  mehr alles gleichzeitig da.
- **Nach dem Anlegen kommt keine Flash-Meldung mehr**, wenn die Ergebnis-Karte
  ohnehin dasselbe sagt. Bei Gruppen-Zuordnung oder Passwortschutz erscheint
  sie weiterhin, weil sie dann etwas hinzufügt.
- Neu: `inc/qrpanel.php`, `inc/lang.php`, `inc/lang/en.php`,
  `assets/qroptions.js`, `assets/qrzip.js`, `router.php`.
- `assets/qrdesign.js` ist auf das geschrumpft, was nur den Designer angeht;
  die Gestaltungslogik liegt jetzt in `qroptions.js`.

Bestehende Kurzlinks, Konten und Statistiken sind nicht betroffen; es gibt
nichts zu migrieren. Wer nichts umstellt, bekommt eine deutsche Instanz wie
zuvor.

## Getestet

Gegen einen frischen Auschecken-Stand, wie ihn ein Anwender bekommt:
Ersteinrichtung über die Oberfläche, Kurzlink mit Wunsch-Name, Schlagworten
und Kampagnen-Parametern, Weiterleitung samt Zähler, alle fünf statischen
Code-Typen, QR-Serie als ZIP, Link-in-Bio, Schnittstelle über beide Wege
(`/api/…` und `/api.php/…`), Sprachumschaltung über die Oberfläche und ein
Crawl aller fünfzehn Seiten auf Englisch ohne deutsche Reste. Dazu
`tests/optionen.php` mit 21 von 21.

```bash
php -S localhost:8080 router.php &
php tests/optionen.php http://localhost:8080
```

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b),
seit 2.0.0.
