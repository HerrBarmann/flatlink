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

- **`data/` liegt standardmäßig im Webroot.** Der Schutz kommt bei Apache aus
  `.htaccess`, bei nginx aus den Blöcken in der
  [Deployment-Anleitung](DEPLOYMENT.md#4-webserver-einrichten). Wer das
  Verzeichnis verschieben kann, sollte es tun.
- **Google Safe Browsing schlägt fail-open fehl:** Ist der Dienst nicht
  erreichbar, wird der Link angelegt statt abgelehnt. Verfügbarkeit geht hier
  vor Vollständigkeit der Prüfung.
- **IP-Hashes sind pseudonym, nicht anonym.** Sie werden mit einem
  instanzeigenen Geheimnis gebildet (`data/secret.key`) und sind damit ohne
  Serverzugriff nicht rückrechenbar – aber sie bleiben personenbezogene Daten
  im Sinne der DSGVO und gehören in die Datenschutzerklärung.
- **Der Link-Lookup liest die vollständige `links.json`.** Bei sehr großen
  Instanzen ist das die Leistungsgrenze; siehe README.

## Bisherige Meldungen

| Datum | Fund | Status |
| --- | --- | --- |
| 13.08.2026 | Host-Header-Poisoning im Passwort-Reset (Kontoübernahme) | behoben |
| 13.08.2026 | Sitzungs-Cookie ohne `secure` hinter TLS-terminierendem Proxy | behoben |
| 13.08.2026 | IP-Hashes ohne Schlüssel rückrechenbar | behoben |
| 13.08.2026 | Datenverlust bei vollem Datenträger (`json_write`) | behoben |
| 13.08.2026 | CPU-Last durch bcrypt bei jedem Login-Fehlversuch | behoben |
