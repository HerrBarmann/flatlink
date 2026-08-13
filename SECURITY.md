# Sicherheitslücken melden

Danke, dass du dir die Mühe machst. Meldungen sind ausdrücklich willkommen –
auch unfertige, auch solche, bei denen du dir nicht sicher bist.

## Wie

**Bitte kein öffentliches Issue** für Funde, die sich ausnutzen lassen, solange
sie nicht behoben sind. Stattdessen:

- **GitHub Security Advisory** – der bevorzugte Weg:
  [Report a vulnerability](https://github.com/HerrBarmann/flatlink/security/advisories/new)
- **E-Mail** an die im [Impressum von 1337.kiwi](https://1337.kiwi/impressum.php)
  genannte Adresse, gern mit `[flatlink]` im Betreff

Hilfreich sind: betroffene Datei und Zeile, wie sich der Fund reproduzieren
lässt, und deine Einschätzung der Auswirkung. Ein Proof of Concept ist schön,
aber keine Bedingung.

## Was du erwarten kannst

Dies ist ein Ein-Personen-Projekt, kein Unternehmen mit Bereitschaftsdienst.
Realistisch heißt das:

- Eingangsbestätigung innerhalb von **drei Tagen**
- Einschätzung, ob und wie schnell behoben wird, innerhalb von **zwei Wochen**
- Bei kritischen Funden versuche ich, deutlich schneller zu sein

Ein Bug-Bounty-Programm gibt es nicht – es gibt kein Budget dafür. Wer möchte,
wird in der Behebung namentlich genannt.

## Was in den Geltungsbereich fällt

Der Code in diesem Repository. Interessant sind vor allem:

- Kontoübernahme, Rechteausweitung, Umgehung der Zugangskontrolle
- Umgehung der Namensraum- oder Gruppentrennung
- Injection jeder Art, XSS, CSRF
- Alles, was dem Datenschutzversprechen widerspricht: Wenn flatlink mehr über
  Besucher speichert oder preisgibt, als in README und Code behauptet, ist das
  ein Sicherheitsfehler, kein Schönheitsfehler

**Nicht im Geltungsbereich:** die laufende Instanz 1337.kiwi als Ziel von
aktiven Tests. Bitte teste gegen eine eigene Installation – die ist in drei
Zeilen aufgesetzt. Ebenfalls außen vor: Fehlkonfigurationen einer fremden
Instanz und Befunde aus automatischen Scannern ohne nachvollziehbare
Auswirkung.

## Bekannte Grenzen

Manches ist keine Lücke, sondern eine bewusste Entscheidung. Der Vollständigkeit
halber:

- **`data/` liegt standardmäßig im Webroot.** Mit `'data_dir'` lässt es sich
  an einen beliebigen absoluten Pfad außerhalb legen – dringend empfohlen.
  Bleibt es im Webroot, schützt es bei Apache die `.htaccess`, bei nginx die
  Blöcke aus der [Deployment-Anleitung](DEPLOYMENT.md#4-webserver-einrichten).
- **Google Safe Browsing schlägt fail-open fehl:** Ist der Dienst nicht
  erreichbar, wird der Link angelegt statt abgelehnt. Verfügbarkeit geht hier
  vor Vollständigkeit der Prüfung.
- **IP-Hashes sind pseudonym, nicht anonym.** Sie werden mit einem
  instanzeigenen Geheimnis gebildet (`data/secret.key`) und sind damit ohne
  Serverzugriff nicht rückrechenbar – aber sie bleiben personenbezogene Daten
  im Sinne der DSGVO und gehören in die Datenschutzerklärung.
- **Die Ablage ist dateibasiert.** Kurzlinks liegen auf 256 Ablagen verteilt,
  der Lookup liest nur eine davon. Bei sehr vielen gleichzeitigen
  Schreibzugriffen bleibt eine Datenbank trotzdem die bessere Wahl.
- **Das Anlegen eines Kurzlinks liest alle 256 Ablagen.** Nur dort, wo Limits
  zu prüfen sind – der Weiterleitungspfad, auf den es ankommt, bleibt bei einer
  Datei. Bei sechsstelligen Beständen wird die Erstellung spürbar langsamer;
  wer dorthin kommt, sollte auf Zählerdateien je Konto umstellen.
- **Klick-Zeitstempel sind bewusst nur tagesgenau.** Der Zähler hält fest, wie
  oft ein Link insgesamt und je Kalendertag aufgerufen wurde, dazu den Tag des
  letzten Aufrufs – keine Uhrzeit, keine IP, keinen User-Agent, keinen Referrer
  und keinen Datensatz je Aufruf. Ein sekundengenauer Zeitpunkt wäre bei einem
  selten aufgerufenen Link der einzige Wert im Bestand, über den sich ein
  einzelner Besuch zeitlich verorten ließe. Wer feinere Statistik braucht, baut
  sie über `inc/local.php` an – und passt die Datenschutzerklärung an.

## Aufbewahrung

Was von selbst wieder verschwindet, ohne Cronjob – angestoßen von der
Link-Erstellung, höchstens einmal pro Woche:

| Daten | Frist |
| --- | --- |
| Tageswerte des Klickzählers | 400 Tage |
| IP-Hash des Double-Opt-In (`verified_ip`) | 12 Monate |
| Rate-Limit- und Login-Sperr-Einträge | 24 Stunden |
| Offene Registrierungen, E-Mail-Wechsel | 24 Stunden |
| Passwort-Reset-Vorgänge | 1 Stunde |
| Lange ungenutzte Kurzlinks | `link_gc_years`, aus (Vorwarnung per Mail) |

## Bisherige Meldungen

| Datum | Fund | Status |
| --- | --- | --- |
| 13.08.2026 | Host-Header-Poisoning im Passwort-Reset (Kontoübernahme) | behoben |
| 13.08.2026 | Sitzungs-Cookie ohne `secure` hinter TLS-terminierendem Proxy | behoben |
| 13.08.2026 | IP-Hashes ohne Schlüssel rückrechenbar | behoben |
| 13.08.2026 | Datenverlust bei vollem Datenträger (`json_write`) | behoben |
| 13.08.2026 | CPU-Last durch bcrypt bei jedem Login-Fehlversuch | behoben |
| 13.08.2026 | `data/` nicht aus dem Webroot verlagerbar | behoben (`data_dir`) |
| 13.08.2026 | Rate-Limit und Login-Sperre kollabieren hinter Reverse Proxy | behoben (`trusted_proxies`) |
| 13.08.2026 | Keine Sicherheits-Header, kein CSP | behoben |
| 13.08.2026 | Verteiltes Password-Spraying umgeht die Sperre | behoben (Instanz-Zähler) |
| 13.08.2026 | `qr.php` liest aus `$_REQUEST` | behoben |
| 13.08.2026 | Zu weite Dateirechte auf Shared Hosting | behoben (0700/0600) |
| 13.08.2026 | Vollständiger Parse von `links.json` bei jedem Redirect | behoben (256 Ablagen) |
| 13.08.2026 | Externe Anmeldung übernimmt gleichnamiges lokales Konto | behoben (Freigabe nötig) |
| 13.08.2026 | `migrate-links.php` ohne POST/CSRF auslösbar | behoben |
| 13.08.2026 | Fehlendes `X-Content-Type-Options` auf `qr.php` | behoben |
| 13.08.2026 | Wettlauf beim Anlegen von `data/secret.key` | behoben (atomar) |
| 13.08.2026 | Leeres `data/links/` schaltet nicht migrierte Instanz um | behoben (Markierung) |
| 13.08.2026 | Sekundengenauer Zeitstempel des letzten Klicks | behoben (tagesgenau) |
| 13.08.2026 | `verified_ip` unbefristet gespeichert | behoben (12 Monate) |
