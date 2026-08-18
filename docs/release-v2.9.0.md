# Wem gehört was

Diese Fassung dreht sich um Besitz und Zuständigkeit. Wer darf ein Logo
benutzen, das jemand anderes hochgeladen hat? Wem gehört ein Link, dessen
Anleger das Haus verlassen hat? Und wie kommt jemand zu einem Konto, ohne sich
vorher erfolglos anmelden zu müssen?

Wie schon bei 2.8.0 stammen die Antworten nicht vom Schreibtisch, sondern aus
dem Betrieb an zwei Instanzen.

## Die Logo-Bibliothek steht für sich

Auswahl und Verwaltung steckten im selben Kasten des QR-Designers: das
Auswahlfeld, darunter Hochladen, ein Aufklapper zum Freigeben und ein zweites
Auswahlfeld zum Löschen – zwischen Modulformen und Farbverläufen. Das sind
zwei verschiedene Dinge. Die Auswahl gehört zum Gestalten eines einzelnen
Codes; die Bibliothek ist ein Bestand, den man unabhängig davon pflegt.

Sie liegt jetzt unter *Logos* im Verwaltungsbereich. Jedes Logo als Karte:
Vorschau auf kariertem Grund – bei freigestellten Bildern sieht man sonst
nicht, wo die Transparenz sitzt –, Name, Eigentümer, Freigabe, Löschknopf.
Fremde, über eine Gruppe freigegebene Logos stehen mit dabei, aber ohne
Verwaltung. Im Designer bleiben Auswahlfeld und ein Verweis.

**Umbenennen ging bisher überhaupt nicht.** Die Funktion dafür gab es, aber
keine Oberfläche rief sie auf; ein vertippter Name blieb, bis man das Logo
löschte und neu hochlud. Und die Freigabe-Häkchen waren nie vorbelegt – ein
unbedachtes Speichern nahm die Freigabe zurück, weil die leere Auswahl die
alte ersetzt.

## Konten aus dem Verzeichnis anlegen

Bisher entstand ein Konto erst, **nachdem** sich jemand einmal vergeblich
angemeldet hatte: Der Versuch legte einen Eintrag in der Warteschlange an, den
ein Administrator freischaltete. Das funktioniert, mutet den Leuten aber einen
Fehlschlag zu, den sie nicht einordnen können – und wer ein Konto vorbereiten
will, bevor jemand anfängt, konnte es gar nicht.

Die Nutzerverwaltung durchsucht das Verzeichnis jetzt selbst. Suchen, Treffer
anklicken, Konto steht. Der Suchfilter entsteht dabei **aus der
Konfiguration**: aus `uid_attr`, `name_attr` und `mail_attr`, ergänzt um `cn`,
`sn`, `givenName` und `mail`. Ein fest verdrahtetes `(cn=*%s*)` findet an einem
Verzeichnis nichts, das seinen Anzeigenamen in einem eigenen Feld führt – und
niemand sollte dafür einen LDAP-Filter schreiben müssen.

Mehrere Wörter werden UND-verknüpft, jedes für sich über alle Attribute:
„Dennis Bormann" trifft damit auch einen Eintrag „Bormann, Dennis".

## Besitz von Links

**Den Besitzer ändern** geht im Bearbeiten-Formular, für Administratoren und
Konten mit `links_all`. Neben allen Konten steht „niemand, gehört nur der
Gruppe" zur Wahl. Ohne Gruppe wird das abgelehnt – sonst fände den Link außer
der Verwaltung niemand mehr.

**Ein Konto zu löschen** räumte auf zwei Wegen verschieden auf. Wer sich selbst
löschte, hinterließ saubere Verhältnisse; wer von der Verwaltung gelöscht
wurde, hinterließ seinen Namen im Besitzerfeld jedes Links, dazu herrenlose
Links ohne Gruppe, die außer Administratoren niemand mehr fand, gültige
Zugangsschlüssel und offene Bestätigungen.

Beide Wege gehen jetzt durch dieselbe Stelle. Links einer Arbeitsgruppe
verlieren den Besitzer und bleiben der Gruppe; für die Links ohne Gruppe fragt
die Verwaltung vor dem Löschen, mit den Zahlen vor Augen: *„3 Links dieses
Kontos gehören einer Arbeitsgruppe … 2 Links hängen an keiner Gruppe. Was soll
damit geschehen?"* Vorgabe ist übertragen – ein gedruckter Code, dessen Ziel
verschwindet, führt ins Leere.

## Sicherung für rsync, borg und Git

Der Knopf in der Verwaltung baut ein Archiv. Für alles, was Versionen
verwaltet, ist das das falsche Format: ein Binärklumpen, von dem jeder Lauf
einen neuen erzeugt.

`tools/backup-export.php` schreibt denselben Bestand in ein Verzeichnis – und
die Datenbank als **SQL-Text**. Im Versuch mit 40 Links: drei neue Kurzlinks
ergeben `datenbank.sql | 3 +++` gegen 64 KB für die SQLite-Datei. Gleicher
Datenstand ergibt gleiche Bytes, sonst meldete jeder Lauf eine Änderung.

## Behoben

**Ein Update konnte eine Instanz lahmlegen.** `inc/local.php` ist der
Erweiterungspunkt einer Instanz und wird vor allem anderen geladen. Wandert
eine Funktion von dort in den Kern – wie `qr_type_nav()` in 2.8.0 –, treffen
zwei Fassungen aufeinander, und PHP bricht mit „Cannot redeclare" ab. Nicht auf
der Seite, wo die Funktion benutzt wird, sondern auf jeder, die beide Dateien
lädt. Die Kern-Fassung steht jetzt in einem `if (!function_exists(...))`: Die
eigene gewinnt, was der Sinn eines Erweiterungspunkts ist.

**Ein Bio-Logo war nie zu sehen.** Der Upload benennt die Dateien `a1b2….png`,
`logo.php` und `bio_logo_url()` prüften aber gegen reine Hex-Kennungen ohne
Endung – die Adresse blieb immer leer.

**Bio-Logos wurden rund beschnitten.** Bei einem Porträt kleidet das gut, bei
einem Logo fallen die Ecken weg und der Schriftzug am Rand gleich mit. Jetzt
wird proportional eingepasst: höchstens 96 Pixel hoch, 240 breit.

**`bio_logo` wurde ungeprüft gespeichert.** Per POST ließ sich jede fremde
Logo-Kennung eintragen.

**Die Logo-Auswahl verlangte das Recht `logo_upload`.** Das regelt aber das
Hochladen, nicht das Verwenden – und wer nichts hochladen darf, ist gerade der
typische Empfänger einer Freigabe. Für ihn war sie damit wirkungslos.

**Ein abgelehnter Besitzerwechsel scheiterte stumm.** `flash()` behält nur die
letzte Meldung, und die war „Kurzlink aktualisiert".

## Getestet

Frischer Auscheck-Stand, Ersteinrichtung, alle elf Verwaltungsseiten und neun
öffentlichen Seiten abgerufen – keine PHP-Meldung im Protokoll. Kurzlink
angelegt, Weiterleitung geprüft, QR-Code dazu erzeugt und **mit einem Lesegerät
zurück dekodiert**; dasselbe für WLAN, Kontaktkarte, Termin und GS1.

Logo-Freigabe über eine Gruppe, Besitzer eines Links entfernt (bleibt bei der
Gruppe), Konto mit Übertragung gelöscht. Die Verzeichnissuche gegen ein echtes
LDAP geprüft: „Albert", „Einstein", „Albert Einstein" und „Einstein Albert"
finden alle denselben Eintrag – vorher fand nur die Kennung und genau eine
Wortreihenfolge. Ein Einschleusversuch `)(objectClass=*` landet vollständig
escaped im Filter.

`tests/optionen.php` 21 von 21, `tests/einstellungen.php` bestanden.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt. Neu sind `admin/logos.php` und
`tools/backup-export.php` – **beim Hochladen daran denken, dass ein Abgleich
vorhandener Dateien neue Dateien überspringt.**

Wer eine eigene `inc/local.php` betreibt, sollte sie einmal gegen den Kern
prüfen: Funktionen, die dort und hier denselben Namen tragen, gehören
entfernt. Ab dieser Fassung führt das nicht mehr zum Ausfall, doppelt gepflegt
werden sollten sie trotzdem nicht.

Für die Verzeichnissuche genügt die vorhandene LDAP-Konfiguration; `search_filter`
bleibt am besten leer.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
