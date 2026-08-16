# Ein Klick in der Werkzeugleiste

Diese Fassung bringt eine **Browser-Erweiterung** mit: die geöffnete Seite
kürzen, ohne den Umweg über die Verwaltung – und ohne den Umweg über einen
fremden Dienst.

Der Unterschied zu den Erweiterungen der bekannten Anbieter ist nicht die
Funktion, sondern wer mitliest. Diese redet mit genau einer Adresse: der
eigenen Instanz. Es gibt niemanden dahinter, der erfährt, welche Seiten
jemand kürzt.

## Die Erweiterung

Ein Klick auf das Symbol, die Adresse des Tabs steht schon da, der Seitentitel
ist als Name vorgeschlagen. Kürzen, kopieren, fertig – auf Wunsch mit
QR-Code, der ebenfalls aus der eigenen Instanz kommt.

**302 Zeilen in vier Dateien.** Keine Abhängigkeiten, kein Baukasten, kein
Hintergrundprozess. Wer wissen will, was sie tut, liest sie an einem
Nachmittag – und genau das ist bei einem Werkzeug, das jede besuchte Adresse
sehen könnte, der Punkt.

Berechtigungen so eng, wie es geht:

| | |
| --- | --- |
| `activeTab` | die Adresse des Tabs, in dem man auf das Symbol klickt – nur dann, nur diese |
| `storage` | Adresse und Schlüssel, bewusst im **lokalen** Speicher: Ein Zugangsschlüssel hat in der Browser-Synchronisierung nichts verloren |
| Host-Zugriff | wird nicht im Voraus für „alle Seiten" verlangt, sondern beim Einrichten für die eine eingetragene Instanz |

Was sie nicht tut: keine Seiteninhalte lesen, keine Skripte in Seiten
einspritzen, keine Verbindung zu irgendeiner anderen Adresse.

## Zwei Wege zum eingerichteten Zustand

**Fertig vorbereitet aus der Instanz.** Unter *Profil → Browser-Erweiterung*
baut die Instanz ein Archiv, in dem alles schon steht: ihre Adresse, ihr Name,
ihre Symbole (aus dem eigenen Logo skaliert) und auf Wunsch ein
Zugangsschlüssel. Laden, fertig – für die Nutzenden entfällt jede Einrichtung.

Der Schlüssel wird dabei **neu** angelegt und trägt die Bezeichnung
„Browser-Erweiterung" mit Datum: So lässt er sich einzeln zurückziehen, ohne
dass andere Programme stehenbleiben. Deshalb ist er ein Häkchen beim
Herunterladen und keine stille Voreinstellung – das Archiv enthält damit ein
Zugangsmittel, und das muss dabeistehen.

Die vorbereitete Fassung verlangt nebenbei **weniger** Rechte als die zum
Selbsteinrichten: Weil die Adresse feststeht, wird der Host-Zugriff fest auf
diese eine gesetzt, statt optional nach beliebigen zu fragen.

**Verbindungscode.** Für eine Erweiterung, die schon installiert ist – etwa
aus einem der Läden. Ein Klick erzeugt einen Code mit Adresse und frischem
Schlüssel, ein Klick löst ihn in den Einstellungen ein. Geprüft wird gegen
`/api/me`, bevor etwas gespeichert wird: Ein halb kopierter Code soll nicht
still danebengehen.

## Warum es keinen „Jetzt installieren"-Knopf gibt

Weil die Browser ihn abgeschafft haben. **Chrome** erlaubt Installationen seit
2018 ausschließlich aus dem Web Store. **Firefox** installiert ein `.xpi` per
Klick von jeder Seite, aber nur signiert – das geht kostenlos und ohne
Store-Eintrag über addons.mozilla.org, müsste aber je Instanz gemacht werden,
weil deren Adresse im Manifest steht.

Für den Weg über die Läden braucht es eine generische Fassung, die erst beim
Einrichten erfährt, wohin sie gehört. Genau dafür ist der Verbindungscode da.

## Behoben

- **Die Adresse wird aufgeräumt.** Wer seine Links verwaltet, hat `/admin` in
  der Adresszeile und trägt genau das ein – das ergab `/admin/api/me` und
  damit 404. `/admin`, `/index.php`, `/api` und `/api.php` am Ende fallen
  jetzt weg, auch bei Instanzen in einem Unterverzeichnis
  (`example.org/links/admin` → `example.org/links`).
- **Zweiter Weg zur Schnittstelle.** Antwortet `/api/…` mit 404, wird
  `/api.php/…` versucht – für Instanzen, deren Hoster keine Umschreibungen
  erlaubt. Der gefundene Weg wird gemerkt.

Beim Bauen selbst gefunden und behoben: Die Einstellungen lasen `daten.user`,
während die Schnittstelle das Feld `account` nennt. Das Popup hatte einen
Rückfall `short_url || url` – der zweite Wert ist die **lange** Zieladresse,
die im Fehlerfall als Kurzlink angezeigt und kopiert worden wäre. `AccentColor`
gibt es erst in neueren Browsern, ohne Rückfallfarbe war der Hauptknopf
durchsichtig. Und eine `display`-Regel überstimmte das `hidden`-Attribut,
weshalb das leere QR-Bild von Anfang an als kaputtes Symbol dastand.

## Getestet

Frischer Auschecken-Stand: Ersteinrichtung, Verbindungscode erzeugt und der
darin enthaltene Schlüssel gegen `/api/me` geprüft (200), Archiv mit und ohne
Schlüssel gebaut (11 Dateien, gültiges Manifest, lauffähige Skripte), alle
Admin-Seiten, `tests/optionen.php` 21 von 21, keine PHP-Meldungen.

Die Erweiterung selbst gegen eine laufende Instanz durchgeklickt – mit einer
Attrappe der `chrome.*`-Schnittstellen: einrichten, kürzen, Ergebnis,
QR-Code, und die Fehlerfälle (falscher Schlüssel 401, ungültige Adresse,
vergebener Wunsch-Name, vier Arten kaputter Verbindungscodes). Die
vorbereitete Fassung zusätzlich mit **leerem** Speicher: Sie zeigt sofort das
Kürzen-Formular statt der Einrichtung und legt auf Klick einen Kurzlink an.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt. Neu sind der Ordner
`extension/` und `inc/extbuild.php` – ohne sie erscheint der Abschnitt im
Profil nicht, alles andere läuft unverändert.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
