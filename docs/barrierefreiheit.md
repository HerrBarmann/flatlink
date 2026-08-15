# Barrierefreiheit

Diese Seite sagt, was geprüft wurde, was dabei herauskam und was noch fehlt.
Sie ist eine **Selbsteinschätzung**, kein Prüfsiegel: Es gab kein Audit durch
eine Prüfstelle und keinen Test mit Screenreader-Nutzenden. Wer sie für eine
Beschaffung braucht, kann sie als Grundlage nehmen und das Fehlende selbst
prüfen lassen.

Maßstab ist **WCAG 2.1 Level AA**, auf den sich BITV 2.0 und EN 301 549
beziehen.

## Was eingebaut ist

**Textkontraste.** Alle Textfarben des mitgelieferten Themes wurden gegen
ihren tatsächlichen Hintergrund gerechnet (nicht geschätzt): 42 Textelemente
der Startseite, keines unter der geforderten Schwelle von 4,5:1 für normalen
und 3:1 für großen Text. Auch die gedämpfte Schrift (`.muted`, `.small`) und
die hellen Texte auf den dunklen Bändern liegen darüber. Wer eigene Farben
setzt (`assets/custom.css`), muss selbst nachrechnen – die Prüfung gilt für
die Auslieferung.

**Tastaturbedienung.** Jede Funktion ist ohne Maus erreichbar. Der Fokus ist
sichtbar: `:focus-visible` legt einen 2 px starken Rahmen in der Akzentfarbe
um Links, Knöpfe, Klappen und Felder, auf dunklen Bändern in der hellen
Papierfarbe. `:focus-visible` statt `:focus` heißt: Mausklicks hinterlassen
keinen Rahmen, Tastaturbedienung schon.

**Sprunglink.** Erste Station beim Tabben ist „Zum Inhalt springen"; er wird
sichtbar, sobald er den Fokus hat, und führt an der Kopfzeile vorbei zu
`<main id="inhalt">`.

**Struktur.** Eine `<h1>` je Seite, Überschriften ohne Sprünge in der Ebene,
Landmarken (`header`, `nav`, `main`, `footer`) auf jeder Seite, `lang` am
`<html>`-Element aus der eingestellten Sprache.

**Formulare.** Jedes Feld hat ein verknüpftes `<label>` (oder ein
`aria-label`, wo kein sichtbarer Text sinnvoll ist). Fehler stehen als Text
über dem Formular, nicht nur als Farbe. Pflichtfelder sind mit `required`
ausgezeichnet, Eingabearten mit `type` und `inputmode` – die Tastatur eines
Handys passt sich damit an.

**Bilder.** Jedes `<img>` hat ein `alt`; rein schmückende Grafiken tragen ein
leeres. Der QR-Code in der Vorschau trägt eine Beschreibung, der
Datensatz-Beleg auf der Startseite ist als `role="img"` mit `aria-label`
ausgezeichnet, damit ein Screenreader nicht Zeichen für Zeichen vorliest.

**Bewegung.** Es gibt keine automatischen Animationen, kein Karussell, nichts
Blinkendes. Übergänge sind kurz und rein dekorativ.

**Zoom.** Die Seite lässt sich vergrößern; es gibt kein `user-scalable=no`.
Eingabefelder tragen auf Touch-Geräten mindestens 16 px, damit iOS beim
Antippen nicht selbsttätig hineinzoomt. Bei 200 % Vergrößerung bleibt der
Inhalt lesbar; breite Blöcke (Tabellen, Codebeispiele) scrollen in sich
selbst, statt die Seite quer zu schieben.

**Ohne JavaScript.** Anlegen, Bearbeiten, Löschen, Anmelden und die
Weiterleitung funktionieren ohne Skripte. JavaScript verbessert nur den
QR-Designer (Live-Vorschau) und das Kopieren in die Zwischenablage.

## Was nicht geprüft ist

- **Kein Test mit Screenreadern** (NVDA, JAWS, VoiceOver) durch Menschen, die
  sie täglich benutzen. Die Auszeichnung ist nach bestem Wissen gesetzt, aber
  ob sie sich gut anhört, hat niemand gehört.
- **Kein Audit durch eine Prüfstelle**, also keine BITV-Prüfung nach dem
  offiziellen Verfahren.
- **Der QR-Designer** ist die schwächste Stelle: Er ist ein Werkzeug mit
  vielen Reglern und einer Live-Vorschau. Die Regler sind bedienbar und
  beschriftet, aber ob sich damit ohne Blick auf die Vorschau sinnvoll
  gestalten lässt, ist zweifelhaft. Die erzeugten Dateien sind davon
  unberührt.
- **Farbwahl bei eigenen Themes**: Wer `custom.css` benutzt, verlässt den
  geprüften Stand.

## Wenn etwas nicht geht

Barrieren sind Fehler wie andere auch. Sie gehören in die
[Issues](https://github.com/HerrBarmann/flatlink/issues) – mit dem
verwendeten Hilfsmittel, der Seite und dem, was nicht ging. Betreiber einer
Instanz sind rechtlich selbst verantwortlich; für sie ist diese Seite die
Grundlage ihrer eigenen Erklärung, nicht deren Ersatz.

## Für die eigene Erklärung

Öffentliche Stellen brauchen eine eigene Erklärung zur Barrierefreiheit auf
ihrer Instanz. Was dort hineingehört und hier steht: der Maßstab (WCAG 2.1
AA), der Stand der Umsetzung, die bekannten Ausnahmen (siehe oben) und ein
Kontaktweg für Meldungen. Was jede Stelle selbst ergänzen muss: Datum der
Erstellung, Verfahren der Prüfung, die eigene Meldestelle und der Verweis auf
das Schlichtungsverfahren nach § 16 BGG.
