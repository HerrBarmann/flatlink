# Änderungen

> 🇬🇧 English version: **[CHANGELOG.md](CHANGELOG.md)**

flatlink ist in elf Tagen und 63 Releases von der ersten Fassung auf 5.4
gekommen. Diese Datei verdichtet das auf die fünf Reihen und die wenigen
Releases, die für eine bestehende Installation etwas ändern. Die vollständigen
Notizen zu jeder einzelnen Version stehen auf
[der Releases-Seite](https://github.com/HerrBarmann/flatlink/releases).

## Zur Versionsvergabe

Ab 5.4.2 ändert sich die **erste Ziffer nur bei einem echten Bruch**: einer
Migration, von der man wissen muss, einer entfernten Schnittstelle, einer
geänderten Vorgabe. Ein Teil der früheren Hauptsprünge waren Meilensteine und
keine Brüche — 3.0 stand ausdrücklich unter „Dateien austauschen, kein
Migrationsschritt". So wird die erste Ziffer künftig nicht mehr ausgegeben.

Und nicht jeder Fix bekommt sein eigenes Release. Sie werden gesammelt.

## Beim Aktualisieren zu beachten

Alles, was hier nicht steht, wird durch Überschreiben der Dateien aktualisiert.

| Version | Was zu tun ist |
| --- | --- |
| **5.0.0** | Zeigt eine Domain weiter auf den Server, wird aber aus der Konfiguration genommen, lösen ihre Links nicht mehr auf. Gelöscht wird nichts — trägt man die Domain wieder ein, sind alle wieder da. |
| **4.0.0** | Alle melden sich einmal neu an. Bestehende Dateien werden automatisch übernommen und in `.uebernommen` umbenannt, nie gelöscht. Das Protokoll liegt jetzt hinter `tools/flatlink audit`. |
| **3.3.0** | Nur Container: Das Image lauscht auf **8080** statt auf 80. Die Portzuordnung wird zu `8080:8080`. |
| **3.1.0** | Steht in der Konfiguration `'api_doc_url' => 'API.md'`, gehört daraus `'docs/API.md'`. |

---

## 5.x — Jede Domain ein eigener Namensraum (21.08.2026 → 24.08.2026)

Bis 4.5 gehörte ein Kurzcode der *Instanz* und löste unter jeder eingetragenen
Adresse auf. Ein Kunde mit eigener Domain kam darüber an die Kurzlinks aller
anderen. Ein Link wird jetzt über `(Domain, Code)` bestimmt; die Hauptdomain
trägt die leere Zeichenkette, damit ein Server mit einer Domain gar nichts
merkt und jeder bestehende Datensatz gültig bleibt.

Die zweite Hälfte der Reihe hat den letzten Grund beseitigt, aus dem ein
angeklickter Link Inodes kostete — die eigentliche Grenze auf Shared Hosting —
und anschließend dafür gesorgt, dass dabei kein Besucher auf die Datenbank
wartet.

* **5.0.0** — Namensräume je Domain. Die Migration läuft von selbst und in
  einer Transaktion.
* **5.0.2** — Sicherheit: Drei `hook_fire()`-Aufrufe schlugen ihren Datensatz
  **ohne** Domain nach. Ein Webhook konnte damit Ziel und Besitzer eines
  fremden Links tragen. `link_get()` kennzeichnet jetzt, in welchem Namensraum
  ein Datensatz gefunden wurde.
* **5.1.0 / 5.2.0** — Der Zählstand ist in die Datenbank gezogen. Ein
  angeklickter Link ging von drei Dateien auf **keine**. Gemessen: 33.193
  Klicks/s gegen 34.169 auf dem alten Dateiweg — 2,9 % langsamer.
* **5.2.1 – 5.2.3** — Gezählt wird, nachdem die Antwort geschlossen ist. Mit
  absichtlich gehaltener Schreibsperre ging eine Weiterleitung von 3,92 s auf
  3,3 ms, die Belegung des Arbeiters von 5,04 s auf 0,23 s und die Bio-Seite
  von 5,04 s auf 1,0 ms. In diesem Band gehen Klicks verloren, statt erwartet
  zu werden — das steht so da, statt beschönigt zu werden.
* **5.2.4** — Die vier öffentlichen QR-Generatoren standen fest auf Deutsch,
  während die Seite `lang="en"` auswies. Alle Texte laufen über `t()`; ein
  Wächtertest baut jede öffentliche Seite auf Englisch und schlägt bei
  deutschen Resten fehl.
* **5.3.0** — `qr_public` (auto | on | off) gibt die statischen QR-Werkzeuge
  öffentlich frei, auch wenn das öffentliche Kürzen aus ist. Einzelne Logos
  lassen sich öffentlich stellen.
* **5.4.0 / 5.4.1** — Die Browser-Erweiterung steht im Chrome Web Store und bei
  AMO; beide Adressen sind Vorgabe.

## 4.x — Eine Ablage, und Größe (20.08.2026 → 21.08.2026)

Alles, was wächst oder geteilt werden muss, liegt in der SQLite-Datei hinter
der einen Naht in `inc/db.php`. Danach ging die Reihe für zwei Dinge drauf:
eine Weiterleitung billig zu machen und dafür zu sorgen, dass nichts im
Quelltext mit dem Bestand mitwächst. Lasttest bei 5 und 50 Millionen Links
unter 128 MB Speichergrenze.

Das Datenschutzversprechen wurde zweimal gegen den Quelltext geprüft — und
beide Male der Quelltext auf das Versprechen gehoben, nicht umgekehrt.

* **4.0.0** — Einstellungen, Gruppen, Logo-Angaben, die SSO-Warteschlange,
  offene Bestätigungen, das Protokoll und die PHP-Sitzungen werden je eine
  eigene Tabelle.
* **4.0.1** — Die Messung zeigte, dass ~90 % einer Weiterleitung der
  Verbindungsaufbau zu SQLite war. Dauerverbindungen drücken das auf 0,005 ms;
  die Zeit in PHP je Weiterleitung ging von 2,5–3,3 ms auf 0,13–0,24 ms.
* **4.2.0** — `links_each()`, ein Generator, der den Bestand bei gleichbleibendem
  Speicher durchläuft. Bei 500.000 Links ging die Verwaltungsliste von einem
  Absturz auf 45 ms.
* **4.3.0** — Sicherheit: Seit 4.1 hielt das Klick-Protokoll Herkunft, Gerät und
  Sprache **eines einzelnen** Besuchs in einer Zeile beieinander — im
  Widerspruch zum zentralen Versprechen der README. Das Tupel wurde aufgelöst.
* **4.4.0** — Die Folgeprüfung merkte an, dass die Auflösung die Verknüpfung nur
  von der Zeile in die *Nachbarschaft* der Zeilen verschoben hatte. Merkmale
  werden jetzt unmittelbar als Summen in `clickdims` gezählt; im Protokoll steht
  nur noch das Datum. Der Datenschutzsatz stimmt jetzt wörtlich.
* **4.4.2** — Die Datenschutzerklärung Zeile für Zeile gegen den Quelltext
  geprüft. Zeitstempel auf die angegebene Genauigkeit gekürzt; `audit_prune()`
  gibt dem Protokoll die Grenze, die die Erklärung verspricht.
* **4.5.0** — Vier Stellen, die mit dem Bestand wuchsen, davon unabhängig
  gemacht. Tiefes Blättern ging von 92,5 s auf 0,74 ms, der Bestandszähler von
  32 s auf 0,003 ms.
* **4.5.1** — Die ehrliche Grenze steht jetzt da: Auf Shared Hosting ist es das
  **Inode-Kontingent**, nicht die Datenbank. Zwei ältere veröffentlichte Zahlen
  mussten vorher neu gemessen werden, weil sie nicht trugen.

## 3.x — Gleichstand, dann Betrieb (18.08.2026 → 20.08.2026)

3.0 schloss die letzten Lücken zu Shlink und YOURLS-mit-Erweiterungen. Was den
kommerziellen Mitbewerbern bleibt, ist das, was dieses Projekt nicht bauen
will: Besucherprofile. Der Rest der Reihe hat flatlink für andere als den Autor
betreibbar gemacht — ein Container-Image, Kubernetes-Vorlagen, eine
Kommandozeile, Verzeichnisanmeldung, und nach den meisten Schritten eine
Sicherheitsprüfung.

* **3.0.0** — Zähler zählen *Menschen* (Bots, HEAD-Anfragen und der angemeldete
  Besitzer bleiben draußen, weiterhin ohne etwas zu speichern), Besuchsgrenzen,
  Import aus Shlink und Kutt, Vorführbetrieb, Impressum und Datenschutz auf
  Bio-Seiten. Die Dokumentation ist vollständig englisch, dazu eine
  Barrierefreiheitserklärung.
* **3.1.0** — Verwaltung der Merkmale über die Schnittstelle, `GET /health` ohne
  Anmeldung.
* **3.2.0 / 3.3.0** — Container-Image für amd64 und arm64 unter
  `ghcr.io/herrbarmann/flatlink`, danach ohne root, damit es unter dem
  `restricted`-Standard und auf OpenShift läuft.
* **3.2.1** — Sicherheit: `docker-compose.yml` wurde über HTTP ausgeliefert —
  genau die Datei, in die ein Betreiber SMTP- und LDAP-Zugangsdaten schreibt.
  Jetzt gesperrt, zusammen mit `.git`.
* **3.3.2** — Die Bremse gegen Rateversuche zählte **jede** Anfrage und setzte
  bei Erfolg nie zurück. Die Schnittstelle war damit bei 60 Aufrufen je Stunde
  gedeckelt, ganz gleich was `api_rate_limit` erlaubte.
* **3.5.0** — Die Browser-Erweiterung spricht Deutsch und Englisch.
* **3.5.2** — Sicherheit: Die Besuchsgrenze prüfte gegen den bot-gefilterten
  Zähler, `User-Agent: curl/8.0` ging also daran vorbei. Ein zweiter,
  ungefilterter Zähler trägt die Grenze jetzt.
* **3.5.3** — Zugangsschlüssel lassen sich mit eingeschränktem Umfang anlegen
  und an die eigenen Links binden.
* **3.6.0** — Konten lassen sich sperren, über jeden Weg einschließlich LDAP,
  SSO und Schnittstelle. Dazu Verzeichnisabgleich, `tools/flatlink` und
  `docs/openapi.yaml`.
* **3.6.1** — Sicherheit: Der Verzeichnisabgleich suchte in einem Zug und prüfte
  den Ergebniscode nie — Active Directory deckelt Antworten aber bei 1000
  Einträgen und liefert eine *Teilmenge* statt eines Fehlers. Bei 1200 Konten
  hätte das 200 echte Beschäftigte ausgesperrt. Jetzt wird geblättert und bei
  abgeschnittener Antwort abgebrochen.
* **3.7.0 / 3.8.0** — Anmeldung in zwei Schritten, denn das brauchte der
  Passkey: Er ersetzt das Passwort, statt ihm zu folgen. Konten ohne einen
  werden monatlich gefragt, gesteuert über `passkey_hint`.
* **3.9.0** — Das Aufräumen nie besuchter Links wurde einstellbar, samt der
  Fundstelle, die die Warnmail nennt — sie zitierte auf jeder Installation die
  AGB einer bestimmten Instanz.

## 2.x — Vom Kürzer zum Werkzeug (14.08.2026 → 18.08.2026)

Der QR-Generator wuchs von einer Beigabe zu dem Grund, flatlink zu nehmen, die
Oberfläche lernte Englisch, und die Ablage zog von JSON-Dateien nach SQLite. Am
Ende der Reihe konnte eine zweite Einrichtung es betreiben: Gruppen mit eigenen
Rechten, Verzeichnisanmeldung, und eine Logo-Bibliothek, die jemandem gehört.

* **2.1.0** — Encoder bis Version 40, Vektor-Export mit CMYK für den Satz,
  sieben Modulformen, vier Augenformen, Lesbarkeitsprüfung.
* **2.2.0** — 833 Texte übersetzt; README und Handbücher auch auf Englisch.
* **2.4.0** — Links und Konten ziehen nach SQLite. Die JSON-Ablage entfällt.
* **2.5.0** — Angemeldete Geräte, ein Protokoll der Verwaltungshandlungen,
  geplante Aktivierung, CSV-Export, Sicherung als Archiv.
* **2.6.0** — Woher die Klicks kamen (nur der Host, nur Summen, keine
  Zeitreihen je Merkmal), Weichen mit mehreren Zielen, Vorschau beim Teilen,
  Webhooks, Barrierefreiheit — und eine geschriebene Liste dessen, was flatlink
  nie tun wird.
* **2.8.0** — Alle QR-Typen im Kern, Logos für Gruppen freigeben, Rechte
  getrennt nach dem, was ein Konto selbst darf, und dem, was es für andere
  darf, brauchbare zentrale Anmeldung, Mailversand über hauseigene Relays.
* **2.9.0** — Besitz von Links, Konten aus dem Verzeichnis, Sicherungen, die zu
  rsync, borg und Git passen.
* **2.9.5** — Sicherheitsrelease nach einer externen Prüfung. Alle acht Funde
  der 2.5.0-Prüfung sind bestätigt behoben; Testskripte laufen nur noch auf der
  Kommandozeile, `.htaccess` sperrt `tests/`, `tools/` und `extension/`,
  Webhook-Ziele werden gegen interne Adressen geprüft.

## 1.0.0 — 13.08.2026

Erste Fassung.
