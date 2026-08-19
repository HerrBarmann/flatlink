# flatlink im Container

Ein Image, ein Volume, fertig. Zurück zur [README](../README.de.md). – 🇬🇧
[English version](docker.en.md)

flatlink braucht keinen Container: Dateien auf einen Webspace kopieren tut
es auch, und genau dafür ist es gebaut. Wer aber ohnehin alles in Containern
betreibt, bekommt hier ein Image, das sich einfügt – mit Umgebungsvariablen
statt Konfigurationsdatei, einem Volume für die Daten und einem Endpunkt für
die Zustandsprüfung.

## In zwei Minuten

```bash
docker run -d --name flatlink -p 8080:8080 \
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

Ein leerer Wert zählt als „nicht gesetzt“. Als Wahrheitswert gelten `1`,
`true`, `yes` und `on`.

**`FLATLINK_BASE_URL` ist keine Geschmacksfrage.** Ohne sie errät flatlink
die Adresse anhand des `Host`-Kopfs der Anfrage – und der ist Nutzereingabe.
Wer eine Passwort-Mail für ein fremdes Konto auslöst, könnte den Link darin
auf die eigene Domain biegen und das Token abgreifen. flatlink verschickt
deshalb **gar keine** Mails mit Links, solange die Adresse fehlt; der
Container schreibt beim Start einen Hinweis ins Protokoll. Hinter einem
Proxy gehört hier die Adresse von **außen** hinein, nicht
`http://flatlink:80`.

### Lieber eine ausgeschriebene Konfiguration?

Dann häng deine eigene ein – sie hat Vorrang, die Variablen bleiben
unbenutzt:

```yaml
volumes:
  - ./config.php:/var/www/html/inc/config.php:ro
```

Beide Wege sind gleichberechtigt. Die Variablen sind die Abkürzung für den
Normalfall, die Datei die Ansage für alles Weitere – etwa für Shibboleth,
Webhooks oder mehrere Domains, die sich nicht sinnvoll in eine Zeile pressen
lassen.

## Daten

Alles Veränderliche liegt in **einem** Verzeichnis: `/var/lib/flatlink`.
Links, Konten, Klickzähler, Logos, die SQLite-Datei. Das ist das Einzige,
was ein neues Image überleben muss – und damit auch alles, was gesichert
werden muss:

```bash
docker run --rm -v flatlink-data:/daten -v "$PWD":/hier alpine \
  tar czf /hier/flatlink-sicherung.tar.gz -C /daten .
```

Für eine versionierbare Sicherung gibt es den Textexport – ein Betriebstag
sind ein paar geänderte Zeilen statt einer neuen Binärdatei. Der Ordner
`tools/` liegt dafür bewusst **nicht** im Image (Kommandozeilen-Werkzeuge
haben im Netz nichts zu suchen), also einhängen:

```yaml
volumes:
  - ./tools:/var/www/html/tools:ro
```

```bash
docker exec flatlink php /var/www/html/tools/backup-export.php /var/lib/flatlink/export
```

## Hinter einem Proxy

Der Regelfall: Traefik, Caddy oder nginx nehmen die TLS-Verbindung entgegen
und reichen weiter. Zwei Dinge müssen dann gesetzt sein:

```yaml
environment:
  FLATLINK_BASE_URL: "https://kurz.example.org"
  FLATLINK_TRUSTED_PROXIES: "172.16.0.0/12"
```

Ohne den zweiten Eintrag sieht flatlink für **alle** Besucher die Adresse
des Proxys. Rate-Limit und Anmeldesperre griffen dann für alle Besucher
zusammen – ein einzelner Nutzer könnte den Dienst für alle blockieren.

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
- **Keinen zweiten Container** für den Webserver: Apache und PHP stecken im
  Image, und die mitgelieferte `.htaccess` gilt damit unverändert – sie
  schreibt Kurzcodes um und sperrt, was verschlossen bleiben muss.

## Zustandsprüfung

`GET /api/health` antwortet ohne Schlüssel mit `{"status":"pass"}` – das
prüft auch der eingebaute `HEALTHCHECK` alle 30 Sekunden. `docker ps` zeigt
das Ergebnis als `healthy`; eine Überwachung von außen fragt dieselbe
Adresse.

## Kubernetes

Fertige Manifeste liegen unter
[`deploy/kubernetes/flatlink.yaml`](../deploy/kubernetes/flatlink.yaml) –
Namespace, ConfigMap, Secret, PVC, Deployment, Service und Ingress in einer
Datei. Anpassen muss man die Adresse (ConfigMap **und** Ingress) und
gegebenenfalls die `storageClassName`:

```bash
kubectl apply -f deploy/kubernetes/flatlink.yaml
```

**Ein Pod, nicht mehr.** Der Bestand liegt in einer SQLite-Datei auf einem
Volume, das genau ein Pod beschreiben darf. Deshalb stehen in den Manifesten
`replicas: 1` und `strategy: Recreate` – mit der Vorgabe `RollingUpdate`
liefen beim Ausrollen kurz zwei Pods auf derselben Datei, und genau dort ist
SQLite verwundbar. Das ist keine Übergangslösung, sondern die Bauweise:
flatlink ist für Instanzen gedacht, die auf einen Webspace passen. Wer
waagerecht skalieren muss, braucht etwas anderes.

### Was ein Pod trägt

Die Beschränkung auf einen Pod klingt nach einer Grenze, ist aber praktisch
keine. Gemessen an einem Container mit **einer** CPU und 512 MB:

| | |
| --- | --- |
| Weiterleitungen | **2300 je Sekunde** – das ist der Schreibpfad, jeder Aufruf zählt einen Klick |
| mit 20.000 Links im Bestand | unverändert 2400 je Sekunde |
| Nur lesen (`/api/health`) | 2900 je Sekunde |
| Neustart | nach **1,5 Sekunden** wieder erreichbar |

2300 je Sekunde sind über acht Millionen Weiterleitungen in der Stunde. Ein
Dienst mit einer Million Klicks im Monat liegt im Schnitt bei 0,4 je
Sekunde. Der Engpass ist also lange nicht die Instanz.

Wer trotzdem an die Grenze stößt, gibt dem Pod mehr CPU – das wirkt, weil
Apache mehrere Prozesse fährt. Waagerecht verteilen lässt sich flatlink
nicht: Das wäre ein zweiter Schreiber auf derselben SQLite-Datei. Dafür
bräuchte es ein anderes Ablage-Verfahren, und das würde das Versprechen
dieses Projekts kosten – kein Datenbankserver, Sicherung ist ein Ordner.

Weil es also nicht um Durchsatz geht, sondern um **Verfügbarkeit**, liegt
den Manifesten ein `PodDisruptionBudget` bei: Ohne das nähme ein Node-Drain
die Instanz einfach mit; mit ihm wartet der Cluster auf den Ersatz.

**Ohne root.** Das Image lauscht auf Port 8080 und läuft als `www-data` in
Gruppe 0. Damit erfüllt es den Pod-Security-Standard `restricted`
(`runAsNonRoot`, `allowPrivilegeEscalation: false`, alle Capabilities
abgelegt) und läuft auch dort, wo der Cluster eine eigene, zufällige
Benutzerkennung erzwingt – OpenShift etwa. Das Volume ordnet `fsGroup: 0`
zu; ein `chown` beim Start gibt es nicht mehr.

**Die Proben hängen an `/api/health`.** Beim allerersten Start legt die
Instanz ihre Datenbank an und meldet bis dahin ehrlich 503 – dafür ist die
`startupProbe` da.

**Zugangsdaten** gehören ins Secret, nicht in die ConfigMap:

```bash
kubectl -n flatlink create secret generic flatlink-secrets \
  --from-literal=FLATLINK_SMTP_PASS=… \
  --from-literal=FLATLINK_LDAP_BIND_PASS=…
```

## Aktualisieren

```bash
docker compose pull && docker compose up -d
```

Das Volume bleibt, die Anwendung wird ausgetauscht. Ein Migrationsschritt
ist nicht nötig – das Datenformat wächst mit und liest ältere Bestände
unverändert.

Feste Fassungen sind angenehmer als `latest`, wenn dir ein unbeabsichtigter
Sprung ungelegen käme: `ghcr.io/herrbarmann/flatlink:3.3` bleibt bei den
3.3er-Ausgaben, `:3.3.0` bei genau dieser.

## Selbst bauen

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink && docker build -t flatlink .
```

Ins Image kommt nur, was eine laufende Instanz braucht: `tests/`, `tools/`,
`extension/` und die Bildschirmfotos bleiben draußen (siehe
`.dockerignore`). Der Webserver besitzt keine einzige Datei der Anwendung –
er darf ausschließlich ins Datenverzeichnis schreiben. Ein Einbruch über PHP
kann die Anwendung damit nicht umschreiben.
