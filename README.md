# flatlink

**Der offene Kern von [1337.kiwi](https://1337.kiwi).**

Ein Kurzlink-Dienst mit QR-Code-Generator, der seine Besucher nicht vermisst.
Reines PHP, keine Datenbank, keine Abhängigkeiten – läuft auf jedem
Feld-Wald-und-Wiesen-Webspace.

📦 Projektseite: **[flatlink.1337.kiwi](https://flatlink.1337.kiwi)** ·
🥝 Öffentliche Instanz: **[1337.kiwi](https://1337.kiwi)**

> **In English:** a self-hosted URL shortener and QR code generator written in
> dependency-free PHP. No database, no Composer, no build step – just copy the
> files onto any PHP 8.1 web space. Click statistics are a per-day counter and
> nothing else: no IP addresses, no user agents, no referrers, no per-visit
> records. This is the open core of the German service
> [1337.kiwi](https://1337.kiwi), run by its author. Code comments and UI are
> in German. MIT licensed.

## Warum noch ein Shortener?

Weil praktisch jeder andere protokolliert, wer klickt. flatlink speichert pro
Kurzlink genau das hier – vollständig, nicht gekürzt:

```json
{ "n": 42, "last": "2026-08-13T10:22:00+02:00", "days": { "2026-08-13": 7 } }
```

Ein Zähler pro Tag. Kein Datensatz für einzelne Aufrufe, also auch keine
IP-Adressen, keine Geräte- oder Browser-Kennungen und keine Referrer. Aus
diesen Daten lässt sich kein einzelner Besucher rekonstruieren, weil nie ein
einzelner Besucher gespeichert wird.

Das ist keine Absichtserklärung, sondern in
[`inc/store.php`](inc/store.php) in etwa zehn Zeilen nachlesbar
(`clicks_bump()`). Prüf es nach – genau dafür liegt der Code offen.

Der Weiterleitungspfad (`go.php`) startet nicht einmal eine Session, solange
kein Passwortschutz auf dem Link liegt.

## Herkunft: Woher flatlink kommt

flatlink ist kein Nebenprojekt, sondern der Motor eines laufenden Dienstes.
Unter **[1337.kiwi](https://1337.kiwi)** betreibt der Autor damit einen
öffentlichen Kurzlink- und QR-Dienst; was dort funktioniert, steht hier im
Quelltext.

Der Unterschied zwischen beiden ist bewusst gezogen:

| | 1337.kiwi | flatlink |
| --- | --- | --- |
| Was es ist | die öffentliche Instanz | die Software dahinter |
| Aussehen | eigenes Design, Kiwi-Logo | neutrales Standard-Theme zum Überschreiben |
| Inhalte | Ratgeber-Seiten, Rechtstexte, Tarife | nichts davon – nur das Werkzeug |
| Konten | Selbstregistrierung per E-Mail | zusätzlich LDAP, Shibboleth, Gruppen |

Marke und Marketing bleiben also draußen: Wer flatlink installiert, bekommt
kein 1337.kiwi-Imitat, sondern eine leere Instanz, die er selbst benennt und
einfärbt. Umgekehrt sind die Funktionen für Organisationen – zentrale
Anmeldung, Gruppen, Rechte – nur hier zu finden; der öffentliche Dienst
braucht sie nicht.

Warum das offenliegt: Der Satz „wir tracken nicht" ist auf einer Website
bloß eine Behauptung. Mit dem Quelltext daneben wird er überprüfbar – und das
ist der einzige Weg, ihn ernsthaft zu belegen.

Im Seitenfuß jeder Instanz steht dafür eine dezente Herkunftszeile mit
Kiwi-Zeichen. Sie ist reine Höflichkeit, keine Lizenzbedingung: `show_origin`
in der Konfiguration schaltet sie ab.

## Was drin ist

- **Kurzlinks** mit zufälligem oder selbst gewähltem Code, optionalem
  Ablaufdatum und optionalem Passwortschutz
- **QR-Codes** aus einem eigenen Encoder (ISO/IEC 18004, Byte-Mode,
  Versionen 1–10, Fehlerkorrektur L/M/Q/H) – ohne jede Fremdbibliothek
- **QR-Designer**: Modul- und Augenformen, freie Farben, eigenes Logo in der
  Mitte, Rahmen mit frei wählbarem Text, Export als SVG, PNG und druckfertiges
  PDF
- **Statische QR-Codes** für WLAN-Zugänge, Kontakte (vCard) und Termine
  (iCalendar) – die Eingaben werden nirgends gespeichert, sondern direkt in den
  Code kodiert, sodass diese Grafiken völlig unabhängig vom Dienst funktionieren
- **Konten** mit Selbstregistrierung per Double-Opt-In, Passwort-Reset und
  Rollen (Nutzer/Admin), inklusive Nutzungs-Limits pro Konto
- **Zentrale Anmeldung** über LDAP/Active Directory oder über den Webserver
  (Shibboleth, SAML, OpenID Connect) – siehe unten
- **Gruppen** für geteilte Links und für Rechte: Ein Link kann einer Gruppe
  gehören, dann verwaltet ihn das ganze Team
- **CSV-Import** für viele Links auf einmal
- **Missbrauchsschutz**: Rate-Limits pro IP (nur als Hash gespeichert),
  Meldeformular, Sperrfunktion, optional Google Safe Browsing
- **Automatisches Aufräumen** nie aufgerufener Links, mit Vorwarnung per Mail
  (standardmäßig deaktiviert)

## Voraussetzungen

- PHP 8.1 oder neuer
- Erweiterungen: `json`, `mbstring`, `gd` (für PNG/PDF), `fileinfo` (Logo-Upload),
  `openssl` (nur für SMTP-Versand), `ldap` (nur für die LDAP-Anmeldung)
- Ein Webserver mit `mod_rewrite` oder gleichwertiger Umschreibung.
  Die mitgelieferte `.htaccess` bringt zusätzlich einen Fallback über
  `ErrorDocument 404`, falls Rewrites beim Hoster nicht greifen.

Keine Datenbank, kein Composer, kein Build-Schritt.

## Installation

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink
cp inc/config.example.php inc/config.php
```

Danach `inc/config.php` anpassen (mindestens `site_name`), die Dateien in den
Webroot legen und sicherstellen, dass der Webserver in das Verzeichnis
schreiben darf – `data/` wird beim ersten Aufruf selbst angelegt.

Zum Ausprobieren reicht der eingebaute Server:

```bash
php -S localhost:8080
```

**Erstes Konto:** Über `register.php` registrieren. Im Standard steht der
Mailversand auf `log`, die Bestätigungsmail landet also in `data/mail.log` –
dort den Link herauskopieren und aufrufen. Das erste angelegte Konto bekommt
automatisch die Admin-Rolle.

> **Für den echten Betrieb** gibt es eine ausführliche
> **[Deployment-Anleitung](DEPLOYMENT.md)**: Rechte und Webserver-Konfiguration
> für Apache und nginx, Mailversand samt SPF/DKIM/DMARC, LDAP und Active
> Directory, die komplette Shibboleth-Einrichtung inklusive Apache und
> Attributfreigabe – dazu Betrieb, Sicherung und eine Tabelle mit den
> häufigsten Stolpersteinen.
>
> **Eigene Farben, eigenes Logo?** Das beschreibt die
> **[Anpassungs-Anleitung](CUSTOMIZATION.md)** – updatesicher über
> `assets/custom.css`, ohne den Quelltext anzufassen.

## Konfiguration

Alles steckt in `inc/config.php`; die kommentierte Vorlage ist
[`inc/config.example.php`](inc/config.example.php). Die wichtigsten Schalter:

| Option | Bedeutung |
| --- | --- |
| `site_name` | Anzeigename in Titel, Kopfzeile und Mails |
| `base_url` | Feste Basis-URL; leer = automatische Erkennung |
| `limits` | Links, Statistik-Tiefe und Logos pro Konto (`0` = unbegrenzt) |
| `default_perms` | Rechte, die jedes angemeldete Konto ohne Gruppe hat |
| `sso` | Zentrale Anmeldung über den Webserver (Shibboleth/SAML/OIDC) |
| `ldap` | Anmeldung gegen LDAP oder Active Directory |
| `qr_brand_text` | Optionale Absenderzeile unter erzeugten QR-Codes |
| `custom_code_min_len` / `custom_code_quota` | Bremsen gegen Namensraum-Squatting auf öffentlichen Instanzen |
| `mail` | `log` schreibt nach `data/mail.log`, `smtp` versendet echt |
| `safe_browsing_key` | Leer = aus. Siehe Warnung unten |
| `link_gc_years` | `0` = kein automatisches Aufräumen |

Zur Laufzeit lassen sich im Admin-Bereich außerdem die öffentliche
Link-Erstellung und die Selbstregistrierung abschalten – praktisch, wenn die
Instanz nur intern genutzt werden soll.

## Gruppen und Rechte

Ohne Gruppen verhält sich flatlink wie ein Einzelplatz-Werkzeug: Jedes Konto
sieht nur seine eigenen Links. Gruppen ändern zwei Dinge.

**Geteilte Links.** Beim Anlegen eines Links lässt sich eine Gruppe wählen.
Der Link gehört dann dem ganzen Team: Jedes Mitglied sieht ihn, kann sein Ziel
ändern, den QR-Code gestalten, die Klickzahlen ansehen und ihn löschen. Das ist
der eigentliche Zweck – ein gedruckter Code soll nicht davon abhängen, ob die
Kollegin, die ihn angelegt hat, noch im Haus ist. Wer den Link ursprünglich
angelegt hat, behält ihn unabhängig von der Gruppe.

**Rechte.** Jede Gruppe trägt eine Menge von Rechten, die ihre Mitglieder
bekommen. Ein Konto in mehreren Gruppen hat die Summe aller Rechte. Verfügbar
sind:

| Recht | Bedeutung |
| --- | --- |
| `custom_code` | darf Wunsch-Namen vergeben statt Zufallscodes |
| `csv_import` | darf viele Links auf einmal importieren |
| `logo_upload` | darf eigene Logos für QR-Codes hochladen |
| `qr_unbranded` | erzeugt QR-Codes ohne die Absenderzeile |

Gruppen können außerdem **eigene Limits** mitbringen, die die globalen aus
`config.php` anheben – wer in mehreren ist, bekommt jeweils den höchsten Wert.
Und eine Mitgliedschaft lässt sich **befristen**: Nach dem Stichtag zählt sie
nicht mehr, ganz ohne Cronjob. Damit lässt sich ein gestaffeltes Angebot
abbilden, ohne dass die Software einen Tarifbegriff kennen müsste.

In `config.php` legt `default_perms` fest, was **jedes** angemeldete Konto
zusätzlich darf – auch ohne Gruppe. Administratoren dürfen immer alles.

Gerade Wunsch-Namen sind ein gutes Beispiel dafür, warum das an Gruppen hängt:
Der Namensraum einer Instanz ist endlich, und wer sich `/team` sichert, nimmt
ihn allen anderen weg. Als Gruppenrecht lässt sich das vergeben, statt es
entweder allen oder niemandem zu erlauben.

Angelegt werden Gruppen im Admin-Bereich unter **Gruppen**, zugeordnet werden
Konten unter **Nutzer**. Bei zentraler Anmeldung kann die Zuordnung auch aus
dem Verzeichnis kommen (siehe unten).

## Zentrale Anmeldung

Beide Wege sind optional, stehen standardmäßig auf `false` und lassen sich
parallel zu lokalen Konten betreiben. Hier steht das Prinzip – die
Schritt-für-Schritt-Einrichtung samt Apache-Konfiguration, SP-Metadaten und
Attributfreigabe steht in der [Deployment-Anleitung](DEPLOYMENT.md#8-shibboleth-saml-und-openid-connect).

### Über den Webserver (Shibboleth, SAML, OpenID Connect)

Der empfohlene Weg für einen Shibboleth-IdP. Die eigentliche Anmeldung erledigt
ein Servermodul – `mod_shib`, `mod_auth_mellon` oder `mod_auth_openidc` –, das
den Admin-Bereich schützt. flatlink liest nur, wen der Server bereits
authentifiziert hat. Für Apache:

```apache
<Location /admin>
    AuthType shibboleth
    ShibRequestSetting requireSession 1
    Require valid-user
</Location>
```

Dann in `config.php` unter `sso` die Variable benennen, in der die Kennung
steht (meist `REMOTE_USER`), optional die für E-Mail-Adresse und
Gruppenzugehörigkeit, und `login_url` auf `/Shibboleth.sso/Login` setzen.
Konten entstehen beim ersten Login automatisch.

> **Sicherheitshinweis, bitte nicht überlesen.** Variablen, die der Webserver
> selbst setzt (`REMOTE_USER`, die Attribute von `mod_shib`), sind
> vertrauenswürdig. Ein Wert, der als **HTTP-Header** ankommt – der
> Variablenname beginnt dann mit `HTTP_` –, ist es nicht: Den kann jeder
> Client frei erfinden und sich damit als beliebiger Nutzer ausgeben, inklusive
> Administrator. flatlink akzeptiert solche Variablen deshalb nur, wenn unter
> `trusted_proxies` die IP-Adresse des Reverse Proxy steht, der diese Header
> nachweislich überschreibt. Ohne diesen Eintrag werden sie verworfen und die
> Anmeldung schlägt fehl. Das ist Absicht.

### Über LDAP oder Active Directory

Hier fragt flatlink selbst beim Verzeichnis nach; Kennung und Passwort werden
im gewohnten Login-Formular eingegeben. Braucht die PHP-Erweiterung `ldap`.

Geprüft wird per Bind als der gefundene Nutzer – das Passwort wird nirgends
gespeichert und nicht mit einem lokalen Hash verglichen. Eingaben werden vor
dem Einsetzen in den Suchfilter escaped, LDAP-Injection ist damit nicht
möglich; leere Passwörter werden abgelehnt, bevor sie als „unauthenticated
bind" fälschlich als Erfolg durchgehen könnten.

Reihenfolge beim Login: erst das lokale Passwort, dann das Verzeichnis. Lokale
Konten funktionieren also weiter – wichtig, damit man sich nicht aussperrt,
wenn der LDAP-Server einmal nicht erreichbar ist.

Bei `ldap://` unbedingt `start_tls` einschalten, sonst geht das Passwort im
Klartext über das Netz. Besser gleich `ldaps://`.

### Gruppen aus dem Verzeichnis

Beide Wege können Gruppenzugehörigkeiten übernehmen: bei SSO aus einem
Attribut wie `isMemberOf` oder `entitlement`, bei LDAP aus `memberOf` oder per
Suche im Gruppenbaum. Die Zuordnungstabelle `group_map` bildet externe Namen
auf lokale Gruppen ab:

```php
'group_map' => [
    'urn:mace:example.org:group:marketing' => 'marketing',
    'cn=it,ou=groups,dc=example,dc=org'    => ['it', 'technik'],
],
```

Ist die Tabelle leer, wird ein externer Name nur übernommen, wenn es lokal
eine gleichnamige Gruppe gibt. Aus dem Verzeichnis kommende Namen können nie
neue Gruppen anlegen und nie Rechte erfinden – welche Rechte an einer Gruppe
hängen, entscheidet immer die lokale Konfiguration.

Die Rolle bleibt beim erneuten Login unangetastet: Wer hier zum Administrator
gemacht wurde, bleibt es. Und ein Konto, das zentral verwaltet wird, kann sich
nicht mehr über das lokale Passwortformular anmelden – sonst wäre die zentrale
Anmeldung über ein altes Passwort umgehbar.

### Zwei Hinweise zum Datenschutz

**Google Safe Browsing** ist standardmäßig **aus**. Wer es aktiviert, schickt
beim Anlegen eines Links dessen Ziel-URL an Google. Für eine öffentliche
Instanz ist das ein wirksamer Schutz gegen Phishing-Missbrauch, für eine interne
meist überflüssig. Wer es einschaltet, sollte es in seiner
Datenschutzerklärung angeben.

**Der Webserver protokolliert weiter.** flatlink speichert keine IP-Adressen,
die Zugriffs-Logs von Apache oder nginx tun es in aller Regel schon. Wer den
Anspruch ernst nimmt, kürzt oder deaktiviert sie dort.

## Wie die Daten liegen

Alles unter `data/`, als JSON, mit `flock` gegen gleichzeitige Schreibzugriffe
und atomarem Schreiben über Tempdatei plus `rename`:

| Datei | Inhalt |
| --- | --- |
| `links.json` | Kurzlinks: Ziel, Besitzer, Gruppe, Typ, Ablauf, Passwort-Hash |
| `users.json` | Konten: Passwort-Hash, Rolle, E-Mail, Gruppen, Anmeldequelle |
| `groups.json` | Gruppen: Anzeigename und Rechte |
| `clicks/<code>.json` | Klickzähler, siehe oben |
| `settings.json` | Zur Laufzeit änderbare Einstellungen |
| `logos/` | Hochgeladene Logos für QR-Codes |
| `ratelimit/` | Zähler je gehashter IP, nach 24 Stunden gelöscht |
| `pending/` | Offene Bestätigungs-Token (Registrierung, Reset) |

Ein Backup ist damit ein simples Kopieren des `data/`-Ordners.

Diese Bauweise ist bewusst für kleine bis mittlere Instanzen gedacht. Bei
sehr vielen gleichzeitigen Schreibzugriffen ist eine Datenbank die bessere
Wahl – dafür braucht flatlink weder Einrichtung noch Wartung noch Migration.

## Was nicht drin ist

Damit niemand danach sucht: keine API mit Token-Authentifizierung, keine
mehrsprachige Oberfläche (die Texte sind deutsch), keine Statistik nach Ländern
oder Geräten – Letzteres liegt in der Natur der Sache. Gruppen teilen Links und
Rechte, trennen aber keine Mandanten voneinander: Administratoren sehen immer
alles.

Ebenfalls nicht enthalten sind **Impressum, Datenschutzerklärung und AGB**.
Wer eine öffentliche Instanz betreibt, ist in Deutschland und weiten Teilen
der EU dazu verpflichtet, solche Angaben selbst bereitzustellen – sie hängen
von Betreiber, Land und Nutzung ab und lassen sich nicht sinnvoll mitliefern.
Eigene Seiten anlegen und in `page_footer()` in
[`inc/helpers.php`](inc/helpers.php) verlinken.

## Beschriftung im PNG

Rahmen- und Absendertexte werden im SVG sauber gesetzt. Für PNG und PDF braucht
GD eine TrueType-Datei: eine beliebige `.ttf` nach `assets/fonts/` legen, die
erste gefundene wird genommen. Ohne Datei greift ein grober GD-Systemfont.
Es ist bewusst keine Schrift mitgeliefert, damit dem Projekt keine fremde
Font-Lizenz anhängt.

## Mitmachen

Fehlerberichte und Pull Requests sind willkommen. Eine Bitte vorab: Die
Abhängigkeitsfreiheit ist kein Zufall, sondern der Kern des Projekts. Ein
Patch, der Composer, einen Build-Schritt oder eine Datenbank einführt, wird
nicht übernommen – auch wenn er die Sache eleganter macht.

## Lizenz

[MIT](LICENSE) – frei verwendbar, auch kommerziell.
