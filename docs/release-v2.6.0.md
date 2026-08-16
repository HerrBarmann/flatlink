# Woher, wohin, und was nie

Diese Fassung beantwortet die zwei Fragen, die bei einem Kurzlink am
häufigsten gestellt werden – **woher kommen meine Klicks?** und **kann der
Link je nach Gerät woandershin?** – und zwar so, dass die Antwort das
Versprechen des Projekts nicht bricht. Dazu kommt eine dritte Frage, die
seltener gestellt und öfter gebraucht wird: **was wird hier nie passieren?**

## Woher die Klicks kamen

Neben Gesamtzahl und Tageskurve zählt flatlink jetzt drei Merkmale je Link:
den Hostname der verweisenden Seite, die Gerätegattung und die bevorzugte
Sprache.

```json
{ "n": 1840, "last": "2026-08-14", "days": { "2026-08-14": 72 },
  "refs": { "google.com": 210, "-": 1630 },
  "devs": { "mobile": 1402, "desktop": 438 },
  "langs": { "de": 1701, "en": 139 } }
```

Drei Entscheidungen machen den Unterschied:

- **Vom Verweis bleibt nur der Host.** `google.com` ist eine Herkunft,
  `google.com/search?q=…` wäre eine Spur – im Pfad einer verweisenden Seite
  kann eine Suchanfrage oder eine Kennung stehen.
- **Summen, keine Zeitreihen je Merkmal.** Eine Aufschlüsselung nach Tag *und*
  Herkunft käme einzelnen Besuchen zu nahe. Und die Zählerdatei wird bei jeder
  Weiterleitung geschrieben.
- **Höchstens 40 Werte je Merkmal**, danach ein Sammeleintrag – sonst ließe
  sich die Datei über erfundene Herkünfte beliebig aufblähen.

Was unverändert bleibt: kein Datensatz je Aufruf, keine IP-Adresse, keine
gespeicherte Browser-Kennung, keine Uhrzeit. Wem selbst das zu viel ist,
schaltet es ab (`'click_dims' => false`) und hat wieder nichts als Zähler.

## Weichen: ein Link, mehrere Ziele

Ein Plakat hängt einmal, aber die Leute davor sind verschieden. Ein Link kann
jetzt Weichen tragen – nach **Gerät**, **Sprache**, **Land** oder als
**A/B-Anteil**:

| Merkmal | Werte | Woher |
| --- | --- | --- |
| Gerät | `mobile`, `tablet`, `desktop` | grob aus der Browser-Kennung |
| Sprache | zwei Buchstaben | aus der Sprachliste, in ihrer Reihenfolge |
| Land | zwei Buchstaben | von einem vorgeschalteten Dienst |
| Anteil | 1–99 | Zufall, je Aufruf neu |

Die erste zutreffende Weiche gewinnt, sonst gilt das Hauptziel. Die
Reihenfolge ist die ganze Logik – kein Und/Oder, keine Verschachtelung,
höchstens acht je Link. Wer mehr braucht, braucht kein Kurzlink-Werkzeug.

**Es wird nichts gespeichert.** Die Merkmale werden bei der Anfrage geprüft
und danach vergessen. Genau das unterscheidet eine Weiche von dem, was
anderswo „Targeting" heißt: Dort ist sie der Anlass, ein Profil anzulegen –
hier ist sie eine Fallunterscheidung, so spurlos wie ein `if`. Gezählt wird
allein, *wie oft* jede Weiche gegriffen hat; ohne diese Zahl wüsste niemand,
ob eine gestellte Weiche je benutzt wird.

Beim **Land** bringt flatlink bewusst keine Geo-Datenbank mit – die wäre ein
Vielfaches der ganzen Anwendung und passt nicht zu einem Projekt, das man per
FTP hochlädt. Stattdessen wird das Land von einem Vorschaltdienst gelesen
(Cloudflare `CF-IPCountry`), und zwar nur hinter einem eingetragenen
`trusted_proxies`: Sonst könnte jeder Besucher sein Land behaupten, indem er
die Kopfzeile selbst mitschickt – und eine Weiche, die sich von der Gegenseite
stellen lässt, ist keine.

Beim **A/B-Anteil** fällt der Würfel je Aufruf neu. Wiedererkennung wäre die
sauberere Statistik, weil derselbe Mensch immer dieselbe Variante sähe, kostet
aber genau die Markierung, die es hier nicht gibt.

## Vorschau beim Teilen

Titel, Beschreibung und Bild je Link. Wer den Kurzlink in einen Chat klebt,
sieht diese Angaben statt dessen, was die Zielseite hergibt – nützlich, wenn
das Ziel keine eigenen Angaben mitbringt.

Ausgeliefert nur an Vorschau-Dienste und nur, wenn der Link eigene Angaben
trägt; sonst wird auch ein Vorschau-Abruf ganz normal weitergeleitet. Das ist
**kein Cloaking**: Die Seite nennt dasselbe Ziel, auf das auch jeder Mensch
geleitet wird, sichtbar und klickbar. Und ein Vorschau-Abruf zählt nicht als
Klick – sonst wiese jede geteilte Nachricht einen Besuch aus.

## Webhooks

POST mit JSON bei Verwaltungsereignissen: Link angelegt, geändert, gelöscht,
gesperrt, Meldung eingegangen, Konto wartet auf Freischaltung. Optional
signiert (`X-Flatlink-Signature`, HMAC über den Rumpf).

Zwei Grenzen sind Absicht. Es gibt **kein Ereignis für Klicks** – der
Weiterleitungspfad ist der eine Ort, an dem über Besucher nichts passiert, und
ein Webhook dort wäre Besucherverfolgung durch die Hintertür, nur ausgelagert
an einen Dritten. Und es gibt **keine Wiederholung**: kein Hintergrundprozess,
keine Warteschlange. Ein toter Empfänger kostet 0,1 s und bricht nichts.

## Barrierefreiheit

Erst geprüft, dann geschrieben – und dabei zwei echte Verstöße gefunden:

- **Links, Knöpfe und Klappen hatten keinen sichtbaren Fokus** (WCAG 2.4.7);
  nur Eingabefelder hatten einen. Jetzt `:focus-visible` mit 2 px Rahmen, auf
  dunklen Bändern in der hellen Papierfarbe.
- **Es fehlte ein Sprunglink** (WCAG 2.4.1). „Zum Inhalt springen" ist jetzt
  die erste Tab-Station.

Die Kontraste wurden gerechnet, nicht geschätzt: 42 Textelemente gegen ihren
tatsächlichen Hintergrund, keines unter der Schwelle. Die Selbsteinschätzung
steht in [docs/barrierefreiheit.md](barrierefreiheit.md) – samt dem, was
**nicht** geprüft ist: kein Screenreader-Test durch Menschen, die sie täglich
benutzen, kein Audit einer Prüfstelle, und der QR-Designer als schwächste
Stelle.

## Was flatlink nie tun wird

Neu als eigene Seite: [docs/niemals.md](niemals.md). Keine Wiedererkennung,
keine minutengenauen Klickzeitpunkte, kein Conversion-Tracking, keine
Retargeting-Pixel, kein Cloaking, kein Klick-Webhook, keine Abo-Falle mit den
Links anderer Leute.

Eine Funktion, die dort steht, ist keine offene Aufgabe, sondern eine
Entscheidung. Mit dem Unterschied, auf den es ankommt: Eine Eigenschaft der
Anfrage zu prüfen und zu vergessen ist etwas anderes, als sie einer Person
zuzuordnen und aufzuheben.

## Behoben

- **Passwortverwaltungen fragten nach jeder Anmeldung nach dem
  Benutzernamen** – mit leerem Feld, jedes Mal aufs Neue. Das Anmeldeformular
  war nie schuld; es sind die Formulare danach, allen voran die zweite Stufe:
  Weil die Anmeldung erst nach ihr als geglückt gilt, schaut die Verwaltung
  auf dieses Formular, und dort stand kein Benutzername. Fünf Stellen tragen
  jetzt ein ausgeblendetes Kennungsfeld. Umgekehrt bei geschützten Links: Das
  Link-Passwort ist kein Zugangsdatum und wird nicht mehr angeboten.
- **iOS zoomte beim Antippen von Eingabefeldern selbsttätig hinein** und ließ
  die Seite danach quer scrollen. Ursache waren Felder mit weniger als 16 px
  Schrift – die Schwelle, ab der Safari eingreift. Auf Touch-Geräten stehen
  sie jetzt auf 16 px. Den Zoom zu sperren wäre die falsche Antwort gewesen:
  ein Barrierefreiheitsverstoß, den Safari ohnehin ignoriert.
- **`report.php` hashte die Adresse des Meldenden mit blankem SHA-256** –
  derselbe Fehler, den das Sicherheits-Review nur im Rate-Limit gefunden
  hatte. Der übrige Bestand wurde durchsucht; weitere Stellen gibt es nicht.
- Der **Datenexport nach Art. 15** nennt jetzt auch Weichen, Vorschau-Angaben
  und die Herkunfts-Summen.

## Getestet

Frischer Auschecken-Stand: Ersteinrichtung, Link anlegen, Weiche und
Vorschau über das Formular setzen, Weiterleitung mit Handy (Weiche greift),
Rechner (Hauptziel) und Vorschau-Dienst (Seite statt Umleitung, nicht
gezählt), alle Admin- und öffentlichen Seiten, `tests/optionen.php` 21 von 21,
keine PHP-Meldungen. A/B-Anteil über 200 Aufrufe: bei 30 % kamen 60 auf der
zweiten Adresse an. Sicherung gezogen und in eine leere Instanz
zurückgespielt – Weichen, Vorschau-Angaben und Herkunfts-Summen überstehen
den Weg.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt. Neu sind `inc/routing.php` und
`inc/hooks.php`. Neue optionale Schalter: `click_dims` (Vorgabe an),
`webhooks` und `webhook_secret` (Vorgabe leer, es passiert also nichts). Das
Recht für Weichen heißt `link_rules` und muss den Gruppen zugeteilt werden,
die es bekommen sollen.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
