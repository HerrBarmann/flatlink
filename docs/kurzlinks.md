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

