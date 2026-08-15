# Gezählt statt gewartet

Ein externes Sicherheits-Review vom 15. August 2026 hat 2.5.0 durchgesehen –
Authentifizierung, Sitzungen, API-Schlüssel, Weiterleitung, WebAuthn, TOTP,
SSO/LDAP, Datei-Auslieferung, Routing, Sicherung – und **keine kritische, aus
der Ferne ausnutzbare Lücke** gefunden. Die acht Befunde sind ein
Datenschutz-Bug, mehrere DoS-Hebel und Härtung. Alle acht sind erledigt.

Diese Fassung ändert nichts an der Bedienung. Wer 2.5.0 einsetzt, sollte
trotzdem aktualisieren.

## Der eine echte Bug

Das öffentliche Rate-Limit hashte Besucher-Adressen mit blankem SHA-256 statt
mit dem instanzeigenen Schlüssel. Ausgerechnet die eine Stelle, an der
Adressen von **nicht angemeldeten** Menschen landen: Über IPv4 ist ein
ungekeyter SHA-256 keine Anonymisierung – der Adressraum ist klein genug, um
eine vollständige Tabelle in Minuten zu rechnen. Der Kommentar an `ip_hash()`
erklärte das seit Monaten korrekt; nur rief diese Stelle sie nicht auf.

Ein Einzeiler. Nach unserer eigenen Definition in SECURITY.md („speichert
mehr über Besucher als behauptet") aber ein Sicherheitsfehler und kein
Schönheitsfehler. Die betroffenen Dateien verfallen nach 24 Stunden von
selbst.

## Warten war der falsche Schutz

An acht Stellen verzögerte ein `sleep()` die Antwort nach Fehlversuchen –
Anmeldung, zweite Stufe, Link-Passwort, Nachweise im Profil. Das bremst den
Angreifer, belegt aber für seine Dauer einen PHP-Arbeitsprozess. Auf
Massenhosting mit einer Handvoll Prozessen ist das kein Schutz, sondern ein
Hebel: Wer genug Fehlversuche parallel abfeuert, legt die Instanz für alle
lahm – gerade **weil** die Bremse greift.

Gezählt wird jetzt, gewartet nicht mehr. Wer gesperrt ist, bekommt sofort 429
mit `Retry-After` statt einer hängenden Verbindung. Gemessen an einer
Testinstanz:

| | vorher | jetzt |
| --- | --- | --- |
| Fehlversuch | 1,25 s | 0,25 s (bcrypt, kein Warten) |
| Versuch während der Sperre | 2 s | 0,06 s |

Der instanzweite Zähler gegen verteiltes Ausprobieren verzögert ebenfalls
nicht mehr, sondern gibt jeder einzelnen Adresse ein enges Kontingent an
Fehlversuchen. Ein instanzweiter Riegel bleibt bewusst aus – er wäre ein
bequemer Weg, alle auszusperren.

## Rate-Limits zählen jetzt Anschlüsse, nicht Adressen

Hinter einem IPv6-Präfix wandert ein Anschluss durch Milliarden Adressen: Das
Limit war dort wirkungslos, und jede Adresse hinterließ eine eigene Datei –
bei einem Angriff Millionen davon. Gezählt wird jetzt je `/64`. Das ist auch
die ehrlichere Einheit: Dahinter steckt ein Anschluss, kein Gerät. IPv4 und
IPv4-in-IPv6 bleiben vollständig.

Dazu lief das Aufräumen der Zählerdateien bei **jeder** limitierten Anfrage
über das ganze Verzeichnis – genau unter Angriff die teuerste Stelle im
System. Jetzt stichprobenweise bei etwa jedem hundertsten Aufruf; abgelaufen
ist abgelaufen, jeder Lauf nimmt alles mit.

## Der Selbsttest fürs Datenverzeichnis

Die Warnung „Datenverzeichnis liegt im Webroot" prüfte bisher die
Konfiguration, nicht die Wirklichkeit. Sie sah bei Apache mit greifender
`.htaccess` genauso aus wie bei nginx, wo alles offenliegt – und schwieg,
wenn jemand die `.htaccess` beim Hochladen überschrieben hatte.

Jetzt wird gemessen: eine Kanarien-Datei ins Verzeichnis legen, über die
eigene `base_url` abrufen, wieder löschen. Drei Ergebnisse, und der
Unterschied zwischen den letzten beiden ist wichtig:

- **dicht** – der Abruf wurde abgewiesen,
- **offen** – der Inhalt kam zurück: rote Dauerwarnung, denn dann sind auch
  Passwort-Hashes, gültige Reset-Token und das Instanz-Geheimnis abrufbar,
- **unklar** – die Instanz konnte sich selbst nicht erreichen. Ausdrücklich
  keine Entwarnung, und sie wird auch nicht als solche angezeigt.

Beim Testen zeigte sich, warum der Test nicht beim Seitenaufbau laufen darf:
Die Instanz ruft sich selbst auf, während sie die laufende Anfrage bearbeitet
– bei nur einem Arbeitsprozess wartet sie auf sich selbst, bis der Timeout
greift. Er läuft deshalb auf Knopfdruck; die Seite zeigt das gespeicherte
Ergebnis.

## Weitere Härtung

- **`qr.php` hat eine Bremse** (`qr_rate_limit`, Vorgabe 60/Stunde). Es war
  der einzige öffentliche Weg ohne und zugleich der teuerste. Geprüft wird
  erst kurz vor der Erzeugung, damit Fehleingaben kein Kontingent kosten;
  angemeldete Konten sind ausgenommen. Eine Sitzung wird dabei nur gelesen,
  wenn schon ein Cookie da ist – ein Bildabruf soll niemandem eines setzen.
- **Ziele mit Namensteil und in privaten Bereichen sind gesperrt.**
  `https://sparkasse.de@boese.tld/` führt zu boese.tld und liest sich wie die
  Bank; `http://10.0.0.5/` macht den Kurzlink zur hübschen Verpackung für
  eine interne Adresse. Für den Server ist das kein SSRF – er ruft Ziele nie
  ab –, aber auf einer Instanz im Intranet, also genau unserer Zielgruppe,
  ist es eine Einladung. Namen werden dabei **nicht** aufgelöst: Das wäre
  eine Netzanfrage je Formularabsendung und damit selbst ein Hebel. Rein
  interne Instanzen setzen `'allow_private_targets' => true`.
- **`trusted_proxies` versteht CIDR-Bereiche.** Hinter Cloudflare war eine
  Liste einzelner Adressen nicht pflegbar – und wer sie nicht pflegen kann,
  trägt am Ende nichts ein, womit alle Limits auf die Proxy-Adresse zählen.
- **Ein Safe-Browsing-Ausfall ist nicht mehr lautlos.** Die Prüfung lässt
  bewusst durch, statt den Dienst anzuhalten; bei abgelaufenem Schlüssel
  sieht deshalb alles aus wie immer, nur geprüft wird nichts mehr.
  Fehlschläge werden gezählt und stehen, wenn es anhält, rot über den
  Meldungen.

## Beim Testen selbst gefunden

`ftp://x.de` wurde zu `https://ftp://x.de` ergänzt und als kaputter Link
gespeichert, statt abgelehnt zu werden. Ein vorhandenes Schema bleibt jetzt
stehen (`url_normalize`) – und die Ablehnung nennt den echten Grund, statt
„nur http/https" auf eine Adresse zu antworten, die mit `https://` beginnt.

## Aktualisieren

Dateien austauschen. Kein Migrationsschritt, keine neuen Pflichtangaben. Neu
ist die Datei `inc/probe.php`; zwei optionale Schalter kommen dazu
(`qr_rate_limit`, `allow_private_targets`), beide mit brauchbarer Vorgabe.

**Wer Kurzlinks auf interne Adressen betreibt**, setzt vor dem Aktualisieren
`'allow_private_targets' => true` – sonst lassen sich solche Links nicht mehr
anlegen und bestehende nicht mehr bearbeiten. Die Weiterleitung selbst bleibt
in jedem Fall unberührt.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
