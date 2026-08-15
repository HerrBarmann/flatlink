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
  Ob dieser Schutz wirklich greift, prüft die Instanz seit 2.5.1 selbst: Sie
  legt eine Kanarien-Datei ins Datenverzeichnis, ruft sie über die eigene
  `base_url` ab und löscht sie wieder. Das Ergebnis steht unter *Einstellungen*
  – „offen" ist eine rote Dauerwarnung, „unklar" (die Instanz erreicht sich
  selbst nicht) ausdrücklich keine Entwarnung.
- **Google Safe Browsing schlägt fail-open fehl:** Ist der Dienst nicht
  erreichbar, wird der Link angelegt statt abgelehnt. Verfügbarkeit geht hier
  vor Vollständigkeit der Prüfung. Damit dieser Zustand nicht lautlos bleibt,
  werden Fehlschläge gezählt und unter *Meldungen* angezeigt, sobald die
  Prüfung anhaltend ins Leere läuft.
- **Anmeldeversuche werden gezählt, nicht verzögert.** Bis 2.5.0 verzögerte
  ein `sleep()` die Antwort nach Fehlversuchen. Das bremst zwar, belegt aber
  für seine Dauer einen PHP-Prozess – auf Massenhosting mit einer Handvoll
  Prozessen ist genau das der wirksamere Angriff. Seit 2.5.1 antwortet die
  Instanz stattdessen sofort mit 429 und `Retry-After`.
- **Ziele in privaten Adressbereichen sind gesperrt** (10.x, 172.16–31.x,
  192.168.x, 127.x, `localhost`, `fc00::/7`, `fe80::/10`), ebenso Adressen mit
  Nutzerteil (`https://bank.de@boese.tld/`). Der Server ruft Ziele nie ab, es
  geht also nicht um SSRF, sondern um den Kurzlink als Verpackung für interne
  Adressen. Namen werden dabei nicht aufgelöst – das wäre eine Netzanfrage je
  Formularabsendung und damit selbst ein Hebel. Rein interne Instanzen setzen
  `'allow_private_targets' => true`.
- **IP-Hashes sind pseudonym, nicht anonym.** Sie werden mit einem
  instanzeigenen Geheimnis gebildet (`data/secret.key`) und sind damit ohne
  Serverzugriff nicht rückrechenbar – aber sie bleiben personenbezogene Daten
  im Sinne der DSGVO und gehören in die Datenschutzerklärung.
- **Links und Konten liegen in einer SQLite-Datei** (`data/flatlink.sqlite`,
  WAL-Modus). Lookup, Limit-Prüfung und Listen sind gezielte Abfragen; die
  Datei gehört wie der ganze `data/`-Ordner außerhalb des Webroots oder
  hinter die mitgelieferte Zugriffssperre.
- **Klick-Zeitstempel sind bewusst nur tagesgenau.** Der Zähler hält fest, wie
  oft ein Link insgesamt und je Kalendertag aufgerufen wurde, dazu den Tag des
  letzten Aufrufs – keine Uhrzeit, keine IP und keinen Datensatz je Aufruf.
- **Herkunft, Gerätegattung und Sprache werden als Summen gezählt.** Aus der
  Anfrage werden drei grobe Merkmale gebildet und je Link aufaddiert: der
  Hostname der verweisenden Seite (nie der Pfad – der kann eine Suchanfrage
  enthalten), „Handy/Tablet/Rechner" aus der Browser-Kennung und zwei
  Buchstaben aus der Sprachliste. Gespeichert wird ausschließlich die Summe je
  Wert; Referrer und Browser-Kennung selbst werden nicht abgelegt, und es
  entsteht weiterhin kein Datensatz je Aufruf. Je Merkmal höchstens 40
  verschiedene Werte, Weiteres sammelt sich unter „Übrige" – auch damit
  niemand die Zählerdatei über erfundene Herkünfte aufblähen kann. Ein sekundengenauer Zeitpunkt wäre bei einem
  selten aufgerufenen Link der einzige Wert im Bestand, über den sich ein
  einzelner Besuch zeitlich verorten ließe. Wer feinere Statistik braucht, baut
  sie über `inc/local.php` an – und passt die Datenschutzerklärung an.

- **Zwei-Faktor-Anmeldung** gibt es in zwei Formen, im Profil einzurichten und
  optional erzwingbar: Passkeys (WebAuthn) und Einmalkennwörter aus einer App
  (TOTP). Passkeys sind an die Domain gebunden und deshalb gegen nachgebaute
  Anmeldeseiten wirksam, wogegen ein abtippbarer Code nicht schützt. Beide
  schützen die Anmeldung mit Passwort – **nicht** die API: Ein Zugangsschlüssel
  ist ein eigener Nachweis und gilt für sich.

- **Zurücksetzen der zweiten Stufe** kann ein Administrator unter *Nutzer*.
  Für Passkeys gibt es keine Wiederherstellungscodes, also braucht es diesen
  Weg – er ist zugleich der schwächste Punkt der Kette. Wer ihn benutzt, muss
  wissen, mit wem er spricht.

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

Dazu kommt, was die betroffene Person selbst auslöst: Das Profil hat einen
Datenexport (Art. 15/20) und einen Löschknopf (Art. 17), der Konto, Links und
Klickzähler entfernt. Links mit Gruppenzuordnung bleiben und verlieren nur den
Besitzer. Abschaltbar über `'self_delete' => false`, wo Konten zentral
verwaltet werden.

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
| 15.08.2026 | N7: SVG-Logos ungeprüft gespeichert (latentes Stored-XSS) | behoben (Allowlist-Bereinigung beim Upload, `inc/svg.php`) |
| 13.08.2026 | Fehlendes `X-Content-Type-Options` auf `qr.php` | behoben |
| 13.08.2026 | Wettlauf beim Anlegen von `data/secret.key` | behoben (atomar) |
| 13.08.2026 | Leeres `data/links/` schaltet nicht migrierte Instanz um | behoben (Markierung) |
| 13.08.2026 | Sekundengenauer Zeitstempel des letzten Klicks | behoben (tagesgenau) |
| 13.08.2026 | `verified_ip` unbefristet gespeichert | behoben (12 Monate) |
| 13.08.2026 | Keine Selbstauskunft und keine Selbstlöschung im Profil | behoben |
| 15.08.2026 | F1: Öffentliches Rate-Limit hashte IPs ohne Instanz-Geheimnis | behoben (`ip_hash`) |
| 15.08.2026 | F2: `sleep()`-Throttling bindet PHP-Prozesse (DoS-Hebel) | behoben (Zähler + 429) |
| 15.08.2026 | F3: Rate-Limit je IPv6-Adresse statt je Präfix; GC bei jeder Anfrage | behoben (/64-Bündelung, GC nur stichprobenweise) |
| 15.08.2026 | F4: Webroot-Warnung prüfte die Konfiguration, nicht die Wirklichkeit | behoben (Selbsttest per HTTP-Abruf) |
| 15.08.2026 | F5: `qr.php` ohne Rate-Limit (CPU-DoS) | behoben (`qr_rate_limit`) |
| 15.08.2026 | F6: `valid_url()` erlaubt Userinfo und private Ziele | behoben |
| 15.08.2026 | F7: `trusted_proxies` ohne CIDR-Bereiche | behoben (`ip_in_list`) |
| 15.08.2026 | F8: Safe-Browsing-Ausfall bleibt unsichtbar | behoben (Zähler + Anzeige) |
