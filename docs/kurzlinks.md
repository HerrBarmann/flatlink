# Kurzlinks im Alltag

Ordnung und Werkzeuge rund um die Links: Schlagworte, Kampagnen-Parameter,
Link-in-Bio-Seiten und der Umzug von einem anderen Dienst. Zurück zur
[README](../README.md). – 🇬🇧 [English version](kurzlinks.en.md).

## Schlagworte

Ab ein paar hundert Links reicht die Suche nicht mehr. Jeder Link nimmt bis zu
acht Schlagworte auf, mit Komma getrennt eingegeben. Über der Liste steht eine
Wolke aller vergebenen Schlagworte mit ihrer Häufigkeit; ein Klick filtert, ein
zweiter auf „alle anzeigen" hebt den Filter wieder auf. Filter und Suche lassen
sich verbinden.

Schlagworte werden **kleingeschrieben abgelegt**: „Kampagne" und „kampagne"
sollen dieselbe Schublade sein, sonst hat man nach einer Woche beide. Sie sind
Ordnung, keine Berechtigung – wer Zugriff regeln will, nimmt
[Gruppen](gruppen.md#zwei-arten-von-gruppen).

Verfügbar auch über die [Schnittstelle](../API.md) (Feld `tags`, Filter `?tag=`)
und im CSV-Import (Spalte `schlagworte` oder `tags`).

## Startdatum und Ablauf

Ein Link kann ein **Startdatum** tragen: Vorher gibt es ihn schon – der Code
ist vergeben, der QR-Code druckbar, die Adresse steht fest –, aber er führt
noch nicht weiter. Wer ihn zu früh scannt, bekommt eine Seite mit dem Datum
statt einer Weiterleitung (410, dieselbe Antwort wie bei einem abgelaufenen
Link: Der Code existiert, führt heute aber nicht).

Das ist der Fall, für den Plakate gedruckt werden, bevor die Kampagne läuft:
Semesterstart, Pressetermin, Produktvorstellung. Zusammen mit dem
Ablaufdatum lässt sich ein Zeitfenster abstecken; ein Startdatum nach dem
Ablauf wird abgelehnt.

Gültig ist der Link **ab** dem genannten Tag (wie der Ablauf **bis
einschließlich** seines Tages gilt). Ein leeres Feld heißt „sofort".
Verfügbar auch über die [Schnittstelle](../API.md) (Feld `starts`, dazu
`pending` in der Antwort) und im CSV-Export.

## Weichen: ein Link, mehrere Ziele

Ein Plakat hängt einmal, aber die Leute davor sind verschieden. Ein Link kann
deshalb **Weichen** tragen: Wer den Code mit einem Handy scannt, landet im App
Store; wer den Browser auf Englisch stehen hat, auf der englischen Seite; alle
anderen auf dem Hauptziel.

| Merkmal | Werte | Woher |
| --- | --- | --- |
| Gerät | `mobile`, `tablet`, `desktop` | grob aus der Browser-Kennung |
| Sprache | zwei Buchstaben (`en`, `fr`) | die **bevorzugte** Sprache des Browsers |
| Land | zwei Buchstaben (`at`, `ch`) | von einem vorgeschalteten Dienst (siehe unten) |
| Anteil (A/B) | eine Zahl von 1 bis 99 | ein Würfelwurf je Aufruf |

### Sprach-Weichen: die Zielsprache gehört dazu

Sprachen lassen sich nicht einzeln beantworten, sondern nur gegeneinander –
und dafür muss bekannt sein, **welche Sprache das Hauptziel spricht**. Dafür
gibt es das Feld *Sprache der Ziel-URL* direkt über den Weichen.

Ein Beispiel: Hauptziel deutsch, eine Weiche `en` auf die englische Fassung.

| Besucher | landet auf | warum |
| --- | --- | --- |
| Browser `de, en` | deutsche Seite | Deutsch steht vor Englisch und ist die Zielsprache |
| Browser `zh, en` | englische Seite | Chinesisch trifft nichts, dann greift Englisch |
| Browser `en` | englische Seite | Englisch trifft die Weiche |
| Browser `fr` | deutsche Seite | nichts passt |

Der zweite Fall ist der, an dem einfache Lösungen scheitern: Ein Student mit
chinesischem Browser und Englisch als Zweitsprache soll die englische Fassung
bekommen, ein Deutscher mit derselben Zweitsprache aber nicht.

**Ohne Angabe der Zielsprache** bleibt es bei der strengen Regel: Umgeleitet
wird nur, wer die Sprache der Weiche *bevorzugt*. Das ist der sichere Rückfall
– lieber bleibt jemand auf dem Hauptziel, als dass eine `en`-Weiche alle
einsammelt, weil Englisch bei fast jedem Browser als Zweitsprache steht.

Gekürzt wird auf zwei Buchstaben, `en` trifft also auch bei `en-GB`. Schickt
der Browser Gewichte mit (`q=0.8`), entscheiden die – nicht die Reihenfolge im
Kopf.

Die drei Felder einer Weiche sind immer dieselben: **Merkmal**, **Wert**,
**Ziel**. Welche Werte gelten, steht als Tabelle unter den Zeilen, und das
Eingabefeld richtet sich nach dem gewählten Merkmal – beim Anteil wird es ein
Zahlenfeld mit Grenzen, sonst bekommt es passende Vorschläge. Was trotzdem
nicht passt, lehnt der Server beim Speichern ab: „Handy" statt `mobile` oder
`30%` statt `30` wird nicht stillschweigend übernommen. Der Wert ist das, was zutreffen muss – `mobile`, `en`, `at`, oder
beim Anteil die Prozentzahl. `30` heißt: knapp jeder dritte Aufruf landet auf
diesem Ziel, der Rest geht weiter zur nächsten Weiche oder zum Hauptziel. Damit
lässt sich ein A/B-Test bauen, ohne dass jemand wiedererkannt wird: Der Würfel
fällt bei jedem Aufruf neu. Über viele Aufrufe stimmt das Verhältnis, und mehr
soll ein Split nicht leisten – eine Wiedererkennung wäre die sauberere
Statistik, kostet aber genau das, was dieses Projekt nicht ausgibt.

Weichen lassen sich **schon beim Anlegen** setzen (unter *Mehr Optionen*) und
später jederzeit ändern. Neben jeder gespeicherten Weiche steht, wie oft sie
gegriffen hat. Daran sieht
man, ob eine gestellte Weiche überhaupt je benutzt wird.

Die **erste zutreffende Weiche gewinnt**; trifft keine zu, gilt das Hauptziel.
Die Reihenfolge ist damit die ganze Logik – kein Und/Oder, keine
Verschachtelung. Wer mehr braucht, braucht kein Kurzlink-Werkzeug. Höchstens
acht Weichen je Link; das Recht dazu heißt `link_rules`.

**Es wird nichts gespeichert.** Die Merkmale werden bei der Anfrage geprüft
und danach vergessen – was ein einzelner Besucher für ein Gerät hatte oder aus
welchem Land er kam, steht nirgends. Genau das unterscheidet eine Weiche von
dem, was anderswo „Targeting" heißt: Dort ist sie der Anlass, ein Profil
anzulegen; hier ist sie eine Fallunterscheidung, so spurlos wie ein `if`.
Mitgezählt wird nur, **wie oft** jede Weiche gegriffen hat – das steht in der
Statistik des Links, damit sich sehen lässt, ob eine gestellte Weiche
überhaupt je benutzt wird.

**Zum Land:** flatlink bringt keine Geo-Datenbank mit und lädt auch keine –
eine IP-zu-Land-Tabelle wäre ein Vielfaches der ganzen Anwendung und passt
nicht zu einem Projekt, das man per FTP hochlädt. Wohl aber liefern viele
Vorschaltdienste das Land fertig mit (Cloudflare als `CF-IPCountry`, andere
als `X-Country-Code`). Gelesen wird es nur hinter einem als vertrauenswürdig
eingetragenen Proxy (`trusted_proxies`) – sonst könnte jeder Besucher sein
Land behaupten, indem er die Kopfzeile selbst mitschickt, und eine Weiche, die
sich von der Gegenseite stellen lässt, ist keine. Ohne diesen Eintrag steht
„Land" in der Oberfläche nicht zur Auswahl.

Über die [Schnittstelle](../API.md) heißt das Feld `rules` und nimmt eine
Liste aus `{wenn, ist, url}`.

## Kampagnen-Parameter (UTM)

Beim Anlegen und Ändern eines Links lassen sich `utm_source`, `utm_medium`,
`utm_campaign`, `utm_term` und `utm_content` eintragen. Sie werden an die
Ziel-Adresse gehängt; vorhandene Query-Parameter bleiben unangetastet, ein
Anker bleibt hinten.

**Ausgewertet wird das nicht hier.** Diese Parameter sind die einzige
Möglichkeit, der Statistik der *Zielseite* – Matomo, Plausible, Google
Analytics – mitzuteilen, woher jemand kam. Der Kurzlink selbst zählt weiterhin
nur Aufrufe je Tag: keine Herkunft, kein Gerät, kein Datensatz je Besuch. Wer
UTM-Parameter setzt, gibt die Herkunft absichtlich an die Zielseite weiter. Ein
Werkzeug, keine Empfehlung – deshalb ist der Block zugeklappt und leer, bis
jemand ihn benutzt.

**Keine eigene Datenhaltung.** Die Parameter stehen in der Ziel-URL und sonst
nirgends. Der Baukasten liest sie von dort und schreibt sie dorthin zurück –
sie zusätzlich am Link zu speichern hieße, zwei Wahrheiten zu pflegen. Wer die
Adresse von Hand ändert, ändert damit auch die Kampagne, und das Formular zeigt
beim nächsten Öffnen den neuen Stand.

Schon benutzte Werte erscheinen als Vorschlagsliste. Das ist der billigste
Schutz gegen den Tippfehler, der eine Auswertung in zwei Hälften zerlegt.

Verfügbar auch im CSV-Import (für den ganzen Vorgang, nicht je Zeile) und über
die [Schnittstelle](../API.md) (Feld `utm`).

## Link-in-Bio

Eine Seite mit mehreren Zielen unter einem Kurzcode – für das Profil im
sozialen Netz, den Aufkleber am Schaufenster, die Fußzeile einer Speisekarte.
Anzulegen unter *Link-in-Bio* im Verwaltungsbereich, sofern das Konto das Recht
`bio_page` hat.

Technisch ist eine solche Seite **ein Eintrag im Kurzlink-Bestand**, der statt
einer Zieladresse eine Liste davon trägt (`kind: "bio"`). Dadurch erbt sie
Code-Vergabe, Besitz, Gruppenzugehörigkeit, Zugriffsprüfung, Ablaufdatum,
Sperre, Löschung und QR-Code – es gibt keine zweite Ablage und kein zweites
Rechtemodell. Gehört sie einer Arbeitsgruppe, pflegen sie alle Mitglieder.

Die Ziele werden als Feldpaare gepflegt – Anzeigename und Adresse, weitere über
*Link hinzufügen*. Ohne JavaScript stehen immer drei leere Zeilen bereit, sodass
sich die Seite auch dann bedienen lässt.

Mit dem Recht `bio_style` kommen Logo und Farben hinzu. Zur Auswahl steht
dieselbe Bibliothek wie im QR-Designer: eigene Logos und die, die eine Gruppe
freigegeben hat (siehe [QR-Generator](qr-generator.md)). Hochladen zu dürfen ist
dafür nicht nötig – wer ein freigegebenes Logo verwenden darf, findet es hier.
Das Logo wird proportional eingepasst, nicht beschnitten: 96 Pixel hoch, höchstens
240 breit – ein Quadrat schöpft die Höhe aus, eine Wortmarke die Breite. Ein runder
Rahmen sähe bei einem Porträt gut aus, bei einem Logo fielen die Ecken weg und der
Schriftzug am Rand gleich mit.

Gesetzt werden kann nur, was in der eigenen Auswahl steht; ein bereits
gespeichertes Logo bleibt beim Bearbeiten unangetastet, damit es einem Vertreter
nicht unter der Hand verschwindet.

Gezählt wird wie überall: ein Zähler je Tag für die Seite und einer je Ziel.
Damit Letzteres möglich ist, zeigen die Schaltflächen auf den eigenen Code mit
einer laufenden Nummer (`/abc123?i=2`) statt unmittelbar auf die Zieladresse.
Ein Besucher-Datensatz entsteht dabei nicht — die Nummer sagt nur, *welches*
Ziel geklickt wurde, nicht *von wem*.

Die Seite wird **als eigenes Dokument ausgeliefert**, nicht im Theme der
Instanz: keine Kopfnavigation, kein Hinweis auf Anmeldung oder Tarife, und ab
Werk auch keine Absenderzeile im Fuß. Sie gehört dem, der sie angelegt hat –
wer den QR-Code am Schaufenster scannt, will die Speisekarte und nicht das
Menü des Kurzlink-Dienstes.

Wer eine öffentliche Instanz mit kostenlosen Konten betreibt, hat gute Gründe
für eine Herkunftszeile und setzt sie über `bio_footer_text` (Vorspann) und
`bio_footer_glyph` (Symbol); `''` lässt nur die Wortmarke stehen, `null`
– die Vorgabe – die ganze Zeile weg.

Die Reihenfolge der Ziele lässt sich mit Pfeiltasten je Zeile ändern – ohne
JavaScript bleibt sie die Reihenfolge der Felder, was ebenfalls funktioniert,
nur mühsamer. Wie eine Seite ohne eigene Gestaltung aussieht, bestimmt
`bio_default_colors`; steht dort nichts, bleibt ein neutrales Dunkelgrau. Wo
kein eigenes Logo hinterlegt ist, steht am Kopf die Wortmarke der Instanz,
aufgebaut wie in deren Seitenkopf.

Konten mit dem Recht `bio_style` wählen zusätzlich **ein Logo aus der
Logo-Bibliothek und vier Farben** (Hintergrund, Schrift, Schaltflächen und
deren Beschriftung). Die Farbwerte werden gegen `#rrggbb` geprüft, bevor sie in
den Stilblock gelangen; alles andere fällt auf die Vorgabe zurück. Das Logo
liefert `logo.php` aus – ein Endpunkt, der genau eine Datei herausgibt und nur
dann, wenn ihre Kennung in der Bibliothek verzeichnet ist. Wie viele Seiten ein
Konto anlegen darf, steht als Limit `bio` in den Grundregeln und lässt sich je
Gruppe erhöhen.

Suchmaschinen sind standardmäßig ausgeschlossen (`noindex`); wer die Seite
gefunden haben möchte, hakt das ausdrücklich an. Eine Seite, die als QR-Code an
einer Tür klebt, muss nicht auch im Index stehen.

## Umzug von einem anderen Dienst

Der CSV-Import unter *Links → CSV-Import* erkennt die Spalten an der Kopfzeile
statt an ihrer Reihenfolge. Der Export von **Bitly** (`Bitlink`, `Long URL`,
`Title`) und die von **YOURLS** (`keyword`, `url`, `title`) lassen sich damit
unverändert hochladen. Steht in der Code-Spalte eine vollständige Adresse wie
`bit.ly/3xYz9`, wird der letzte Teil übernommen – die Kurzcodes bleiben also
erhalten, und gedruckte Codes zeigen nach dem Umschalten der Domain weiter
dorthin, wo sie sollen.

Erkannt werden unter anderem `long url` / `url` / `ziel` für das Ziel,
`keyword` / `bitlink` / `slug` / `code` für den Kurzcode, `title` / `name` für
den Namen und `expires` / `ablaufdatum` für das Ablaufdatum. Fehlt eine
Kopfzeile, gilt die feste Reihenfolge `url;code;ablaufdatum;name`.

Der Import steht **jedem Konto** offen, damit ein Umzug nicht an einer
Berechtigung scheitert: Ohne das Recht `csv_import` reicht ein Durchgang so
weit, wie noch Platz im eigenen Link-Kontingent ist – das greift beim Anlegen
ohnehin. Erst der Massenbetrieb darüber hinaus hängt am Recht.

Wie viele Zeilen ein Durchgang dann annimmt, steht in `'import_max_rows'`
(Vorgabe 100). Wer einen größeren Bestand übernimmt, erhöht den Wert und lässt
den Import in Ruhe laufen.

