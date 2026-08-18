# Nichts mehr, das die anderen können

> **English summary:** flatlink 3.0 closes the remaining feature gaps to
> Shlink and YOURLS-with-plugins: counters now count *people* (known bots,
> HEAD requests and the signed-in owner are excluded – still without storing
> anything), links can carry a visit cap, the importer reads Shlink and Kutt
> exports, a self-resetting demo mode needs no cron, link-in-bio pages can
> carry imprint/privacy links (your own or the instance's), and logos no
> longer clip QR modules. Documentation is now complete in English – manuals,
> API and a condensed deployment guide – plus an accessibility statement with
> a fill-in template for public bodies. What remains exclusive to commercial
> competitors is what this project refuses to build: visitor profiles.

Der Anlass für diese Fassung war eine Frage: *Was können Shlink oder YOURLS –
auch mit Plugins –, was wir nicht können?* Die Antwort war eine kurze Liste,
und diese Fassung arbeitet sie ab. Was den kommerziellen Anbietern danach
exklusiv bleibt, ist genau das, was dieses Projekt nicht baut:
Besucherprofile.

## Zähler zählen Besuche

Bisher zählte jeder Treffer – jede in einen Chat geworfene Nachricht löste
über den Vorschau-Abruf einen „Klick" aus, ein Uptime-Check ergab 1440
Besucher am Tag, und wer seinen frisch gedruckten Code fünfmal testete, hob
seine Kampagne um fünf. Jetzt bleiben draußen, ohne einen Krümel Speicherung:

- **bekannte Bots** – Vorschau-Dienste, Suchmaschinen, Monitoring, `curl`;
  weitergeleitet wird selbstverständlich trotzdem,
- **HEAD-Anfragen** – so fragt Werkzeug, nicht Publikum,
- **der Besitzer selbst** samt Arbeitsgruppe, sofern angemeldet; für anonyme
  Besucher startet die Weiterleitung weiterhin keine Session.

## Aufruf-Limit

„Nur die ersten 50 bekommen den Rabatt": `max_visits` je Link, danach 410
mit Begründung. Geprüft gegen den Zähler, der ohnehin geführt wird – und weil
Bots nicht zählen, meint das Limit echte Besuche. In beiden Formularen und
der Schnittstelle.

## Umzug von Shlink und Kutt

Der CSV-Import versteht jetzt auch die Exporte von **Shlink** (Web-Client;
die Pipe-getrennten Schlagworte werden zu unseren Kommas) und **Kutt** –
zusätzlich zu Bitly und YOURLS. Kurzcodes bleiben beim Umzug erhalten.

## Demo-Modus

`demo_mode` macht aus einer Instanz eine öffentliche Spielwiese: Hinweisband
mit Zugangsdaten, und der Bestand wird etwa stündlich verworfen und aus einem
festen Demo-Bestand neu aufgebaut – träge beim Seitenaufbau, **ohne Cron**,
also auch auf Shared Hosting ohne SSH.

## Link-in-Bio: Impressum und Datenschutz

Der Fuß jeder Bio-Seite kann beide Pflichtlinks tragen. Die Instanz gibt ihre
Seiten vor (`bio_legal_defaults`); jede Seite kann sie durch eigene Adressen
ersetzen – wer seine Seite geschäftlich betreibt, ist selbst verantwortlich
und verlinkt **sein** Impressum, nicht das des Dienstes.

## QR-Logo ohne angeschnittene Module

Module, die die Logo-Freifläche berühren, werden gar nicht erst gezeichnet –
vorher wurde die Fläche über die fertigen Module gelegt, und aus runden
Punkten wurden Halbmonde. Nachgewiesen mit Lesegerät über alle sieben
Modulformen und alle Ausgabeformate; ohne Logo ist die Ausgabe byte-identisch
zur alten Fassung.

## Dokumentation, vollständig und zweisprachig

Jede Funktion ist jetzt auf Deutsch **und** Englisch beschrieben: die vier
Handbücher strukturgleich, `API.en.md` und eine gestraffte
`DEPLOYMENT.en.md` neu. Dazu die Barrierefreiheits-Selbsteinschätzung in
beiden Sprachen, erweitert um eine **Muster-Erklärung zum Ausfüllen** für
öffentliche Stellen – das Beschaffungskriterium, das kein Konkurrent bedient.

## Getestet

Frischer Auscheck-Stand: alle 20 Seiten ohne Fehler und ohne PHP-Meldung.
Am Stück geprüft: Sprachverhandlung (chinesischer Browser mit Englisch als
Zweitsprache bekommt die englische Fassung), Bot-Aufruf zählt nicht,
Aufruf-Limit antwortet mit 410, QR-Code dekodiert. `tests/optionen.php`
21 von 21, `tests/einstellungen.php` und `tests/weichen.php` bestanden.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt. **Neue Dateien**, die ein
Abgleich vorhandener Dateien überspringt: `inc/demo.php`, `API.en.md`,
`DEPLOYMENT.en.md`, `docs/barrierefreiheit.en.md`, `docs/kurzlinks.en.md`
(ersetzt), `docs/release-v3.0.0.md`.

Die Zähler verhalten sich ab sofort anders – ehrlicher: Zahlen sinken dort,
wo bisher Bots mitzählten. Das ist kein Fehler, sondern der Punkt.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
