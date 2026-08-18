# flatlink im Container

Ein Bild, ein Volume, fertig. Zurück zum [README](../README.de.md). –
🇬🇧 [English version](docker.en.md)

flatlink braucht keinen Container: Dateien auf ein Webspace kopieren tut es
auch, und genau dafür ist es gebaut. Wer aber ohnehin alles in Containern
betreibt, bekommt hier ein Bild, das sich einfügt – mit Umgebungsvariablen
statt Konfigurationsdatei, einem Volume für die Daten und einem
Gesundheitsendpunkt für den Wächter.

## In zwei Minuten

```bash
docker run -d --name flatlink -p 8080:80 \
  -e FLATLINK_BASE_URL="http://localhost:8080" \
  -v flatlink-data:/var/lib/flatlink \
  ghcr.io/herrbarmann/flatlink:latest
```

Dann `http://localhost:8080/admin/` aufrufen – der erste Aufruf legt das
Administratorkonto an. Mit `docker-compose.yml` aus dem Projekt geht es
genauso: `docker compose up -d` (oder `docker-compose up -d`, je nachdem,
wie Compose bei dir installiert ist).

## Umgebungsvariablen

Gesetzt wird nur, was du angibst – alles andere behält die Vorgabe aus
`inc/config.example.php`. Die vollständige Liste der Optionen steht dort;
hier sind die, die sich über die Umgebung setzen lassen.

| Variable | Bedeutung |
| --- | --- |
| `FLATLINK_BASE_URL` | Adresse der Instanz, **ohne Schrägstrich am Ende**. Siehe unten. |
| `FLATLINK_SITE_NAME` | Name in Kopfzeile, Titel und Mails |
| `FLATLINK_LANGUAGE` | `de` oder `en` |
| `FLATLINK_DATA_DIR` | Ablage der Daten, Vorgabe `/var/lib/flatlink` |
| `FLATLINK_PUBLIC_MODE` | `on`, `prefix` oder `off` – darf jeder kürzen? |
| `FLATLINK_REGISTRATION` | `on` oder `off` – Selbstregistrierung |
| `FLATLINK_TRUSTED_PROXIES` | Adressen der Proxys davor, mit Komma getrennt |
| `FLATLINK_SMTP_HOST` … | Mailversand: `_PORT`, `_USER`, `_PASS`, dazu `FLATLINK_MAIL_FROM` |
| `FLATLINK_LDAP_URI` … | Verzeichnis: `_BASE_DN`, `_USER_FILTER`, `_BIND_DN`, `_BIND_PASS`, `_AUTO_CREATE` |
| `FLATLINK_CLICK_DIMS` | `false` schaltet Herkunft, Gerät und Sprache ab |
| `FLATLINK_DEMO_MODE` | `true` macht die Instanz zur Spielwiese mit Selbst-Reset |
| `FLATLINK_API_RATE_LIMIT` | Anfragen je Stunde und Schlüssel |

Ein leerer Wert zählt als „nicht gesetzt". Wahrheitswerte verstehen `1`,
`true`, `yes` und `on`.

**`FLATLINK_BASE_URL` ist keine Geschmacksfrage.** Ohne sie rät flatlink die
Adresse aus dem `Host`-Kopf der Anfrage – und der ist Nutzereingabe. Wer
eine Passwort-Mail für ein fremdes Konto auslöst, könnte den Link darin auf
die eigene Domain biegen und das Token abgreifen. flatlink verschickt
deshalb **gar keine** Mails mit Links, solange die Adresse fehlt; der
Container schreibt beim Start einen Hinweis ins Protokoll. Hinter einem
Proxy trägt hier die Adresse von **außen**, nicht `http://flatlink:80`.

### Lieber eine ausgeschriebene Konfiguration?

Dann häng deine eigene ein – sie hat Vorrang, die Variablen bleiben
unbenutzt:

```yaml
volumes:
  - ./config.php:/var/www/html/inc/config.php:ro
```

Beide Wege sind gleichberechtigt. Die Variablen sind die Abkürzung für den
Normalfall, die Datei die Ansage für alles Weitere – etwa für Shibboleth,
Webhooks oder mehrere Domains, die sich nicht sinnvoll in eine Zeile
pressen lassen.

## Daten

Alles Veränderliche liegt in **einem** Verzeichnis: `/var/lib/flatlink`.
Links, Konten, Klickzähler, Logos, die SQLite-Datei. Das ist das Einzige,
was ein neues Bild überleben muss – und damit auch alles, was gesichert
werden muss:

```bash
docker run --rm -v flatlink-data:/daten -v "$PWD":/hier alpine \
  tar czf /hier/flatlink-sicherung.tar.gz -C /daten .
```

Für eine versionierbare Sicherung gibt es den Textexport – ein Betriebstag
sind ein paar geänderte Zeilen statt einer neuen Binärdatei. Der Ordner
`tools/` liegt dafür bewusst **nicht** im Bild (Kommandozeilen-Werkzeuge
haben im Netz nichts zu suchen), also einhängen:

```yaml
volumes:
  - ./tools:/var/www/html/tools:ro
```

```bash
docker exec flatlink php /var/www/html/tools/backup-export.php /var/lib/flatlink/export
```

## Hinter einem Proxy

Der Regelfall: Traefik, Caddy oder nginx nehmen TLS an und reichen weiter.
Zwei Dinge gehören dann gesetzt:

```yaml
environment:
  FLATLINK_BASE_URL: "https://kurz.example.org"
  FLATLINK_TRUSTED_PROXIES: "172.16.0.0/12"
```

Ohne den zweiten Eintrag sieht flatlink für **alle** Besucher die Adresse
des Proxys. Rate-Limit und Anmeldesperre gälten dann versehentlich
gemeinsam – ein einzelner Nutzer könnte den Dienst für alle blockieren.

## Eigenes Aussehen

`assets/custom.css` wird nach dem Standard-Stylesheet geladen und
überschreibt es. Im Container heißt das: einhängen.

```yaml
volumes:
  - ./custom.css:/var/www/html/assets/custom.css:ro
```

Wie das aussieht, steht in der [Anpassungs-Anleitung](CUSTOMIZATION.md) –
Farben, Logo, Schrift, alles updatesicher über Variablen.

## Was der Container nicht braucht

- **Keinen Cron.** Aufräumen, Demo-Reset und Ablauf hängen am Seitenaufbau.
- **Keinen Datenbankdienst.** SQLite liegt im Volume.
- **Keinen zweiten Container** für den Webserver: Apache und PHP stecken
  im Bild, und die mitgelieferte `.htaccess` gilt damit unverändert – sie
  schreibt Kurzcodes um und sperrt, was gesperrt gehört.

## Gesundheit

`GET /api/health` antwortet ohne Schlüssel mit `{"status":"pass"}` – das
prüft auch der eingebaute `HEALTHCHECK` alle 30 Sekunden. `docker ps` zeigt
das Ergebnis als `healthy`; ein Wächter von außen fragt dieselbe Adresse.

## Aktualisieren

```bash
docker compose pull && docker compose up -d
```

Das Volume bleibt, die Anwendung wird ausgetauscht. Ein Migrationsschritt
ist nicht nötig – das Datenformat wächst mit und liest ältere Bestände
unverändert.

Feste Fassungen sind angenehmer als `latest`, wenn dir ein unbeabsichtigter
Sprung ungelegen käme: `ghcr.io/herrbarmann/flatlink:3.1` bleibt bei den
3.1er-Ausgaben, `:3.1.0` bei genau dieser.

## Selbst bauen

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink && docker build -t flatlink .
```

Im Bild landet nur, was eine laufende Instanz braucht: `tests/`, `tools/`,
`extension/` und die Bildschirmfotos bleiben draußen (siehe
`.dockerignore`). Der Webserver besitzt keine einzige Datei der Anwendung –
er darf ausschließlich ins Datenverzeichnis schreiben. Ein Einbruch über
PHP kann die Anwendung damit nicht umschreiben.
