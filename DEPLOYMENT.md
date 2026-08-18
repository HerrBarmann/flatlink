# flatlink betreiben

🇬🇧 A condensed [English version](DEPLOYMENT.en.md) exists; this German guide
is the step-by-step reference.

Eine Anleitung von der leeren Maschine bis zur angebundenen Hochschul-Anmeldung.
Sie ist bewusst ausführlich – wer nur schnell etwas ausprobieren will, ist mit
den drei Zeilen im [README](README.de.md#installation) schneller.

**Inhalt**

1. [Vorüberlegungen](#1-vorüberlegungen)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Grundinstallation](#3-grundinstallation)
4. [Webserver einrichten](#4-webserver-einrichten)
5. [Erster Start](#5-erster-start)
6. [E-Mail-Versand](#6-e-mail-versand)
7. [LDAP und Active Directory](#7-ldap-und-active-directory)
8. [Shibboleth, SAML und OpenID Connect](#8-shibboleth-saml-und-openid-connect)
9. [Gruppen und Rechte in der Praxis](#9-gruppen-und-rechte-in-der-praxis)
10. [Betrieb](#10-betrieb)
11. [Wenn etwas nicht geht](#11-wenn-etwas-nicht-geht)

> **Zur Verlässlichkeit dieser Anleitung:** Die Teile zu flatlink selbst sind
> gegen den Code geprüft. Bei den Fremdkomponenten – Shibboleth, LDAP,
> Mailversand – ändern sich Pfade und Optionen zwischen Versionen und
> Distributionen. Diese Abschnitte beschreiben den üblichen Fall; im Zweifel
> gilt die Dokumentation deiner Version. Wo etwas besonders versionsabhängig
> ist, steht es dabei.

---

## 1. Vorüberlegungen

Zwei Entscheidungen bestimmen fast alles Weitere.

**Öffentlich oder intern?** Eine öffentliche Instanz, an der sich jeder
registrieren kann, braucht Missbrauchsschutz: Rate-Limits, ein Meldeformular,
enge Regeln für Wunsch-Namen, eventuell Safe Browsing. Eine interne Instanz für
eine Organisation braucht das meist nicht – dafür zentrale Anmeldung und
Gruppen. Beides lässt sich mischen, aber die Standardwerte zielen auf den
internen Fall: Selbst-Registrierung und öffentliches Kürzen lassen sich im
Admin-Bereich unter *Einstellungen* abschalten.

**Woher kommen die Konten?** Drei Wege, beliebig kombinierbar:

| Weg | Wann sinnvoll | Aufwand |
| --- | --- | --- |
| Lokale Konten | kleine Instanz, wenige Leute | keiner |
| LDAP / Active Directory | vorhandenes Verzeichnis im Haus | mittel |
| Shibboleth / SAML / OIDC | Hochschule, Föderation, echtes SSO | hoch |

Lokale Konten funktionieren immer parallel weiter. Lass mindestens ein lokales
Administrator-Konto bestehen – wenn das Verzeichnis oder der IdP ausfällt,
kommst du sonst nicht mehr in die Verwaltung.

---

## 2. Voraussetzungen

- **PHP 8.1** oder neuer
- **Erweiterungen:** `json`, `mbstring` (Kern), `gd` für PNG- und PDF-Export,
  `fileinfo` für Logo-Uploads, `openssl` für SMTP, `ldap` nur für die
  LDAP-Anmeldung
- **Webserver**, der Pfade umschreiben kann (Apache mit `mod_rewrite`, nginx,
  Caddy, …)
- **Schreibrechte** im Anwendungsverzeichnis für das Verzeichnis `data/`

Prüfen, was vorhanden ist:

```bash
php -v
php -m | grep -E '^(json|mbstring|gd|fileinfo|openssl|ldap)$'
```

Nachinstallieren, je nach System:

```bash
# Debian / Ubuntu
sudo apt install php-gd php-mbstring php-ldap

# RHEL / Rocky / Alma
sudo dnf install php-gd php-mbstring php-ldap
```

Nach dem Nachinstallieren PHP-FPM oder Apache neu starten, sonst greifen die
Erweiterungen nicht.

Keine Datenbank, kein Composer, kein Build-Schritt – das ist kein Zufall,
sondern der Kern des Projekts.

---

## 3. Grundinstallation

### Dateien ablegen

```bash
cd /var/www
sudo git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink
sudo cp inc/config.example.php inc/config.php
```

Ein Update ist damit später ein `git pull`. Wer kein Git auf dem Server möchte,
lädt das ZIP-Archiv von GitHub und entpackt es – dann sind Updates allerdings
Handarbeit.

### Rechte setzen

Der Webserver muss in `data/` schreiben dürfen, sonst nirgends. Die
Konfigurationsdatei enthält Zugangsdaten und geht niemanden sonst etwas an:

```bash
# Benutzer des Webservers: www-data (Debian/Ubuntu) oder apache (RHEL)
sudo chown -R root:www-data /var/www/flatlink
sudo find /var/www/flatlink -type d -exec chmod 750 {} \;
sudo find /var/www/flatlink -type f -exec chmod 640 {} \;

# Nur data/ ist beschreibbar
sudo mkdir -p /var/www/flatlink/data
sudo chown -R www-data:www-data /var/www/flatlink/data
sudo chmod 750 /var/www/flatlink/data

# Konfiguration mit Zugangsdaten enger fassen
sudo chmod 640 /var/www/flatlink/inc/config.php
```

`data/` legt sich beim ersten Aufruf selbst an, mit Rechten 0700 und einer
`.htaccess` – die aber nur Apache liest.

> **Besser: `data/` aus dem Webroot heraus.** Dort liegen Passwort-Hashes,
> gültige Reset-Token und im Mail-Modus `log` sämtliche Mails im Klartext.
> Wenn dein Hosting einen Pfad außerhalb zulässt, trag ihn ein:
>
> ```php
> 'data_dir' => '/var/lib/flatlink',
> ```
>
> ```bash
> sudo mkdir -p /var/lib/flatlink
> sudo chown www-data:www-data /var/lib/flatlink
> sudo chmod 700 /var/lib/flatlink
> ```
>
> Bleibt es im Webroot, sperr das Verzeichnis zusätzlich im Webserver (siehe
> nächster Abschnitt) – auf nginx, Caddy und LiteSpeed ist die `.htaccess`
> wirkungslos.

### Konfiguration anpassen

Alles steckt in `inc/config.php`. Für den Anfang reichen zwei Werte:

```php
'site_name' => 'Kurzlinks der Musterhochschule',
'base_url'  => 'https://kurz.example.org',
```

> **`base_url` gehört gesetzt – das ist keine Geschmacksfrage.** Bleibt der
> Wert leer, errät flatlink die Adresse aus dem `Host`-Header des Requests.
> Der ist eine Nutzereingabe: Wer eine Passwort-vergessen-Mail für ein fremdes
> Konto anstößt, könnte den Link darin sonst auf die eigene Domain zeigen
> lassen und den Token abgreifen. flatlink verschickt deshalb **gar keine**
> Mails mit Links, solange `base_url` fehlt – der Reset bliebe wirkungslos.
> Auch das `secure`-Flag des Sitzungs-Cookies wird daraus abgeleitet, was
> hinter einem TLS-terminierenden Proxy den Unterschied macht.

Für eine interne Instanz lohnt sich außerdem:

```php
'custom_code_quota'   => 0,        // Wunsch-Namen nicht kontingentieren
'custom_code_min_len' => 3,        // kurze Namen erlauben
'limits' => ['links' => 0, 'stats_days' => 365, 'logos' => 20],  // 0 = unbegrenzt
'link_gc_years' => 0,              // nichts automatisch aufräumen
```

---

## 4. Webserver einrichten

flatlink braucht eine einzige Umschreibung: Alles, was keine echte Datei ist,
geht an `go.php`, das den Kurzcode auflöst.

### Apache

Die mitgelieferte `.htaccess` erledigt das bereits – vorausgesetzt, sie wird
gelesen. Dafür muss `AllowOverride` gesetzt sein:

```apache
<VirtualHost *:443>
    ServerName kurz.example.org
    DocumentRoot /var/www/flatlink

    <Directory /var/www/flatlink>
        AllowOverride All
        Require all granted
    </Directory>

    # Interne Verzeichnisse sperren – doppelter Boden zur .htaccess
    <DirectoryMatch "^/var/www/flatlink/(inc|data)">
        Require all denied
    </DirectoryMatch>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/kurz.example.org/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/kurz.example.org/privkey.pem
</VirtualHost>
```

Wer `AllowOverride None` bevorzugt (schneller, weil Apache nicht in jedem
Verzeichnis nach `.htaccess` sucht), kopiert die Regeln aus der `.htaccess`
direkt in den `<Directory>`-Block.

`mod_rewrite` muss aktiv sein:

```bash
sudo a2enmod rewrite && sudo systemctl reload apache2
```

### nginx

nginx liest keine `.htaccess`. Die Regeln gehören in den Server-Block:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name kurz.example.org;
    root /var/www/flatlink;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/kurz.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/kurz.example.org/privkey.pem;

    # Interne Verzeichnisse sperren
    location ~ ^/(inc|data)/ {
        deny all;
        return 404;
    }

    # Echte Dateien zuerst, sonst als Kurzcode behandeln
    location / {
        try_files $uri $uri/ @shortcode;
    }

    location @shortcode {
        rewrite ^/([A-Za-z0-9_-]{1,64}(?:/[A-Za-z0-9_-]{1,64})?)/?$ /go.php?c=$1 last;
        return 404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;   # Pfad prüfen!
    }

    client_max_body_size 2m;   # Logo-Uploads
}
```

Den Socket-Pfad an die installierte PHP-Version anpassen –
`ls /run/php/` zeigt, was da ist.

### Hinter einem Reverse Proxy

Steht nginx, Traefik oder HAProxy davor, sieht flatlink für **alle** Besucher
dieselbe Adresse – Rate-Limit und Login-Sperre gelten dann versehentlich für
alle gemeinsam, und ein einzelner Nutzer kann den Dienst für die anderen
blockieren. Deshalb die Proxy-Adressen eintragen:

```php
'trusted_proxies' => ['127.0.0.1', '::1'],
```

Nur bei Anfragen von diesen Adressen wird `X-Forwarded-For` überhaupt
ausgewertet – und dann von rechts nach links, bis ein Eintrag kommt, der kein
bekannter Proxy ist. Alles andere wäre gefährlicher als das Problem: Ohne
diese Prüfung könnte sich jeder per Header eine beliebige Adresse geben und
sämtliche Limits umgehen.

Der Proxy muss `X-Forwarded-For` selbst setzen bzw. überschreiben. Bei nginx:

```nginx
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

### HTTPS

Ohne TLS gibt es kein sinnvolles flatlink: Sitzungs-Cookies, Passwörter und bei
LDAP auch fremde Zugangsdaten laufen sonst im Klartext. Mit Let's Encrypt:

```bash
sudo certbot --apache -d kurz.example.org      # oder --nginx
```

Das `secure`-Flag des Sitzungs-Cookies leitet flatlink aus der konfigurierten
`base_url` ab. Steht dort `https://`, wird es gesetzt – auch dann, wenn ein
Proxy TLS terminiert und intern per HTTP weiterreicht, wo PHP von sich aus
keine gesicherte Verbindung sähe.

---

## 5. Erster Start

Ruf die Seite im Browser auf. Es gibt zwei Wege zum ersten Konto:

**Über die Ersteinrichtung.** Solange keine Konten existieren, zeigt
`/admin/` statt des Logins ein Einrichtungsformular. Das dort angelegte Konto
wird Administrator.

**Über die Registrierung.** Ebenfalls möglich – auch hier wird das erste Konto
automatisch Administrator. Im Auslieferungszustand steht der Mailversand auf
`log`, die Bestätigungsmail landet also in `data/mail.log`; den Link von dort
kopieren und aufrufen.

Danach im Admin-Bereich unter *Einstellungen* festlegen, ob die öffentliche
Link-Erstellung und die Selbst-Registrierung offen bleiben sollen. Für eine
interne Instanz: beides aus.

---

## 6. E-Mail-Versand

Gebraucht wird er für Registrierungsbestätigungen, Passwort-Reset und die
Vorwarnung vor dem automatischen Aufräumen. Wer ausschließlich über LDAP oder
SSO anmeldet und das Aufräumen abgeschaltet lässt, braucht ihn gar nicht –
dann bleibt `'mode' => 'log'` stehen.

### Konfiguration

```php
'mail' => [
    'mode'      => 'smtp',
    'host'      => 'smtp.example.org',
    'port'      => 587,              // STARTTLS
    'user'      => 'kurzlinks@example.org',
    'pass'      => '…',
    'from'      => 'kurzlinks@example.org',
    'from_name' => 'Kurzlinks der Musterhochschule',
],
'contact_email' => 'it-support@example.org',   // erscheint in Systemmails
```

flatlink bringt einen minimalen SMTP-Client mit (`inc/mail.php`): Verbindung,
`STARTTLS`, `AUTH LOGIN`, fertig. Er kann bewusst wenig – kein OAuth2, keine
Anhänge, keine Warteschlange. Für ein paar Systemmails am Tag reicht das; wer
Massenversand braucht, ist hier falsch.

**Port 465 statt 587?** Dann `'port' => 465` – der Client erkennt implizites
TLS an der Portnummer. Wenn dein Anbieter nur 465 anbietet und es hakt, ist
587 mit STARTTLS fast immer ebenfalls verfügbar.

### Erst testen, dann scharf schalten

Mit `'mode' => 'log'` landet jede Mail vollständig in `data/mail.log`, ohne
verschickt zu werden. Ideal, um Registrierung und Reset durchzuspielen:

```bash
tail -f data/mail.log
```

### Damit die Mails ankommen

Systemmails landen gern im Spam. Drei DNS-Einträge verhindern das – sie gehören
zur **Domain der Absenderadresse**, nicht zur Domain der Instanz:

**SPF** – erlaubt dem Mailserver, für deine Domain zu senden:

```
example.org.  TXT  "v=spf1 include:_spf.example-provider.de -all"
```

**DKIM** – signiert ausgehende Mails. Selektor und Schlüssel liefert dein
Mailanbieter; der Eintrag sieht etwa so aus:

```
selektor._domainkey.example.org.  TXT  "v=DKIM1; k=rsa; p=MIGfMA0G…"
```

**DMARC** – sagt empfangenden Servern, was bei Fehlschlägen passieren soll.
Fang mit `p=none` an (nur Berichte, nichts wird abgewiesen) und zieh erst
nach ein paar Wochen fehlerfreier Berichte an:

```
_dmarc.example.org.  TXT  "v=DMARC1; p=none; rua=mailto:dmarc@example.org"
```

Zwei Erfahrungswerte aus dem Betrieb von 1337.kiwi:

- **DKIM braucht bei manchen Anbietern Zeit.** Nach dem Anlegen eines Postfachs
  kann es Stunden dauern, bis wirklich signiert wird. Wenn der Eintrag stimmt
  und trotzdem `dkim=none` in den Kopfzeilen steht: erst warten, dann den
  Support fragen.
- **Prüfen lässt sich das am schnellsten** über [mail-tester.com](https://www.mail-tester.com)
  (eine Testmail hinschicken, Punktzahl ablesen) oder direkt in den Kopfzeilen
  einer empfangenen Mail: Dort muss `spf=pass`, `dkim=pass` und `dmarc=pass`
  stehen.

---

## 7. LDAP und Active Directory

flatlink fragt hier selbst beim Verzeichnis nach. Kennung und Passwort werden
im gewohnten Login-Formular eingegeben; geprüft wird per Bind als der gefundene
Nutzer. Das Passwort wird nirgends gespeichert und mit keinem lokalen Hash
verglichen.

### Voraussetzung

```bash
php -m | grep ldap || sudo apt install php-ldap
```

### Erst außerhalb von flatlink prüfen

Bevor du irgendetwas konfigurierst: Klärt `ldapsearch`, ob Verbindung, Bind und
Filter überhaupt stimmen? Das erspart viel Rätselraten.

```bash
# OpenLDAP
ldapsearch -x -H ldaps://ldap.example.org \
  -D "cn=service,ou=system,dc=example,dc=org" -W \
  -b "ou=people,dc=example,dc=org" "(uid=mmuster)" dn mail memberOf

# Active Directory
ldapsearch -x -H ldaps://dc01.example.org \
  -D "service@example.org" -W \
  -b "OU=Benutzer,DC=example,DC=org" "(sAMAccountName=mmuster)" dn mail memberOf
```

Was hier zurückkommt, übernimmst du eins zu eins in die Konfiguration.

### Konfiguration

```php
'ldap' => [
    'enabled'   => true,
    'uri'       => 'ldaps://ldap.example.org:636',
    'start_tls' => false,        // bei ldap:// auf Port 389 unbedingt true!
    'timeout'   => 5,

    // Dienstkonto für die Suche; leer = anonyme Suche
    'bind_dn'   => 'cn=service,ou=system,dc=example,dc=org',
    'bind_pass' => '…',

    'base_dn'     => 'ou=people,dc=example,dc=org',
    'user_filter' => '(uid=%s)',       // AD: '(sAMAccountName=%s)'
    'mail_attr'   => 'mail',

    'group_mode'     => 'memberof',
    'group_base_dn'  => 'ou=groups,dc=example,dc=org',
    'group_filter'   => '(&(objectClass=groupOfNames)(member=%s))',
    'group_attr'     => 'cn',

    'group_map'      => ['rz-team' => 'it', 'bibliothek' => 'bib'],
    'default_groups' => [],
    'auto_create'    => true,
],
```

`%s` wird durch die Eingabe ersetzt – vorher escaped, LDAP-Injection ist also
nicht möglich. Der Filter muss **genau einen** Treffer liefern; mehrdeutige
Kennungen werden abgelehnt.

### Verschlüsselung

Nimm `ldaps://` auf Port 636. Wenn nur Port 389 zur Verfügung steht, setz
`'start_tls' => true` – sonst geht das Passwort deiner Nutzer im Klartext
durchs Netz.

Bei eigener Zertifizierungsstelle muss das Wurzelzertifikat systemseitig
bekannt sein, sonst schlägt der Bind ohne brauchbare Meldung fehl:

```
# /etc/ldap/ldap.conf
TLS_CACERT /etc/ssl/certs/unsere-ca.pem
```

### Gruppen aus dem Verzeichnis

Zwei Betriebsarten:

- **`memberof`** liest das Attribut `memberOf` am Nutzereintrag. Active
  Directory pflegt es von Haus aus. OpenLDAP braucht dafür das
  `memberof`-Overlay – ist es nicht geladen, kommt schlicht nichts zurück.
- **`search`** sucht stattdessen im Gruppenbaum nach Einträgen, die den
  Nutzer als Mitglied führen. Der Weg für klassische `groupOfNames` ohne
  Overlay.

`group_map` bildet Verzeichnisnamen auf flatlink-Gruppen ab. **Die Gruppen
müssen in flatlink vorher angelegt sein** – aus dem Verzeichnis kommende Namen
können nie neue Gruppen erfinden. Ist `group_map` leer, wird ein Name nur
übernommen, wenn es lokal eine gleichnamige Gruppe gibt.

### Reihenfolge beim Login

Erst wird das lokale Passwort geprüft, dann das Verzeichnis. Konten, die einmal
über LDAP angemeldet waren, sind als solche markiert und können sich **nicht**
mehr über ein lokales Passwort anmelden – sonst ließe sich die zentrale
Anmeldung über ein altes Passwort umgehen. Auch der Passwort-Reset ist für sie
gesperrt.

---

## 8. Shibboleth, SAML und OpenID Connect

Der Weg für Hochschulen. Hier authentifiziert **nicht flatlink**, sondern der
Webserver: Ein Servermodul spricht mit dem Identity Provider, und flatlink
liest nur noch, wen der Server durchgelassen hat.

Das Verfahren ist dasselbe für `mod_shib` (Shibboleth), `mod_auth_mellon`
(SAML) und `mod_auth_openidc` (OpenID Connect) – nur die Modulkonfiguration
unterscheidet sich. Ausführlich beschrieben ist Shibboleth, weil es der
komplizierteste Fall ist.

### 8.1 Wie die Teile zusammenhängen

```
Browser  →  Apache + mod_shib  →  IdP der Hochschule
                  ↓ (setzt REMOTE_USER, eppn, mail, …)
              flatlink
```

Du betreibst den **Service Provider** (SP). Der **Identity Provider** (IdP)
gehört dem Rechenzentrum – den richtest du nicht ein, du meldest dich dort an.
In Deutschland läuft das meist über die Föderation **DFN-AAI**: Beide Seiten
laden ihre Metadaten dort hoch und vertrauen sich darüber.

### 8.2 SP installieren

```bash
# Debian / Ubuntu
sudo apt install libapache2-mod-shib

# RHEL / Rocky (Repository der Shibboleth-Entwickler nötig)
sudo dnf install shibboleth
```

Der Dienst `shibd` läuft neben Apache und muss laufen:

```bash
sudo systemctl enable --now shibd
sudo systemctl status shibd
```

Schlüsselpaar für den SP erzeugen, falls das Paket das nicht schon getan hat:

```bash
cd /etc/shibboleth
sudo shib-keygen -u _shibd -h kurz.example.org
```

### 8.3 SP konfigurieren

In `/etc/shibboleth/shibboleth2.xml` sind drei Stellen wichtig.

**Die eigene Kennung (`entityID`)** – eine URL, die den SP eindeutig benennt.
Sie muss nicht erreichbar sein, aber stabil bleiben:

```xml
<ApplicationDefaults entityID="https://kurz.example.org/shibboleth"
                     REMOTE_USER="eppn subject-id pairwise-id persistent-id">
```

`REMOTE_USER` bestimmt, welches Attribut als Kennung durchgereicht wird. Der
erste vorhandene Wert gewinnt. `eppn` (eduPersonPrincipalName, etwa
`mmuster@example.org`) ist der übliche Fall.

**Der IdP oder die Föderation:**

```xml
<SSO discoveryProtocol="SAMLDS"
     discoveryURL="https://discovery.dfn.de/">
  SAML2
</SSO>

<MetadataProvider type="XML" validate="true"
    url="https://www.aai.dfn.de/metadata/dfn-aai-basic-metadata.xml"
    backingFilePath="dfn-metadata.xml" maxRefreshDelay="7200">
    <MetadataFilter type="Signature"
        certificate="dfn-aai.pem" verifyBackup="false"/>
    <MetadataFilter type="RequireValidUntil" maxValidityInterval="2419200"/>
</MetadataProvider>
```

Bei nur einem festen IdP entfällt die Discovery, dann reicht
`<SSO entityID="https://idp.example.org/idp/shibboleth">SAML2</SSO>`.

> Metadaten-URLs, Zertifikatsnamen und Filter unterscheiden sich je nach
> Föderation und Shibboleth-Version. Die aktuell gültigen Werte stehen in der
> Anleitung deiner Föderation – von dort kopieren, nicht von hier.

**Attribute freischalten.** In `/etc/shibboleth/attribute-map.xml` muss
entkommentiert sein, was du brauchst:

```xml
<Attribute name="urn:oid:1.3.6.1.4.1.5923.1.1.1.6"  id="eppn">
    <AttributeDecoder xsi:type="ScopedAttributeDecoder"/>
</Attribute>
<Attribute name="urn:oid:0.9.2342.19200300.100.1.3" id="mail"/>
<Attribute name="urn:oid:2.16.840.1.113730.3.1.241" id="displayName"/>
<Attribute name="urn:oid:1.3.6.1.4.1.5923.1.5.1.1"  id="isMemberOf"/>
<Attribute name="urn:oid:1.3.6.1.4.1.5923.1.1.1.7"  id="entitlement"/>
```

Was hier fehlt, kommt bei flatlink nicht an – auch wenn der IdP es sendet.

Danach neu laden:

```bash
sudo systemctl restart shibd && sudo systemctl reload apache2
```

### 8.4 Beim IdP anmelden

Deine SP-Metadaten stehen jetzt unter:

```
https://kurz.example.org/Shibboleth.sso/Metadata
```

Diese Datei geht ans Rechenzentrum bzw. in die Föderation. Dazu die Bitte,
**welche Attribute freigegeben werden sollen** – ohne Freigabe kommt nichts an,
selbst wenn alles andere stimmt. Für flatlink genügen:

- `eppn` – zwingend, das ist die Kennung
- `displayName` – **dringend empfohlen**, siehe unten
- `mail` – optional, füllt die E-Mail-Adresse des Kontos
- `isMemberOf` oder `entitlement` – optional, nur für die Gruppenzuordnung

> **Zum Anzeigenamen.** Viele Hochschulen geben aus Datenschutzgründen keine
> sprechende Kennung heraus, sondern eine undurchsichtige (`persistent-id`,
> `pairwise-id`) – etwa
> `https://idp.example.org!https://kurz.example.org!a7f3c9d21e8b4f6a`.
> Das ist richtig so, macht die Nutzerverwaltung aber unbedienbar: In der
> Liste stünden nur solche Zeichenketten. Mit einem freigegebenen
> `displayName` und `'name_var' => 'displayName'` zeigt flatlink stattdessen
> den Klarnamen und die Kennung nur klein darunter. Ist kein Klarname
> verfügbar, hilft die Suche in der Nutzerverwaltung, die auch Teile der
> Kennung und die E-Mail-Adresse findet.

Weniger zu verlangen ist hier die bessere Haltung: flatlink braucht weder Namen
noch Matrikelnummer noch Fakultät.

### 8.5 Apache: Was geschützt wird

Entscheidend ist, **nur den Login-Bereich** zu schützen. Wird die ganze Seite
geschützt, funktionieren die Kurzlinks selbst nicht mehr – jeder Aufruf würde
zum IdP umgeleitet.

```apache
# Der Anmeldebereich verlangt eine Sitzung
<Location /admin>
    AuthType shibboleth
    ShibRequestSetting requireSession 1
    Require valid-user
</Location>

# Die Handler des SP müssen frei erreichbar bleiben
<Location /Shibboleth.sso>
    AuthType None
    Require all granted
</Location>
```

Der Rest der Seite – Kurzlinks, QR-Endpunkt, Startseite – bleibt bewusst offen.

### 8.6 flatlink konfigurieren

```php
'sso' => [
    'enabled'  => true,

    'user_var'  => 'REMOTE_USER',   // von mod_shib gesetzt
    'mail_var'  => 'mail',          // wie in attribute-map.xml benannt
    'name_var'  => 'displayName',   // Klarname für die Nutzerverwaltung
    'group_var' => 'isMemberOf',
    'group_separator' => ';',

    'group_map' => [
        'urn:mace:example.org:group:rz'   => 'it',
        'urn:mace:example.org:group:bib'  => 'bib',
    ],
    'default_groups' => [],
    'auto_create'    => true,

    'trusted_proxies' => [],        // siehe Warnung unten

    'login_url'    => '/Shibboleth.sso/Login?target=/admin/',
    'logout_url'   => '/Shibboleth.sso/Logout',
    'button_label' => 'Mit Hochschulkennung anmelden',
],
```

Ist `sso.enabled` gesetzt, meldet flatlink jeden an, den der Webserver
durchlässt – der Knopf auf der Login-Seite ist nur die Bequemlichkeit für
Leute, die direkt auf `/admin/` landen.

### 8.7 Die eine Sache, die man nicht falsch machen darf

> **Servervariablen sind sicher, HTTP-Header sind es nicht.**
>
> `REMOTE_USER` und die Attribute von `mod_shib` setzt der Webserver selbst –
> denen kann flatlink vertrauen. Kommt die Kennung dagegen als **HTTP-Header**
> an (der Variablenname beginnt dann mit `HTTP_`), kann sie jeder Client frei
> erfinden und sich als beliebiger Nutzer ausgeben, Administratoren
> eingeschlossen.
>
> Deshalb akzeptiert flatlink `HTTP_`-Variablen nur, wenn unter
> `trusted_proxies` die IP-Adresse des Reverse Proxy steht, der diese Header
> nachweislich überschreibt. Ohne Eintrag werden sie verworfen und die
> Anmeldung schlägt fehl. Das ist Absicht.

Relevant wird das, wenn zwischen Apache und flatlink noch ein Proxy steht oder
`ShibUseHeaders On` gesetzt ist. **Setz `ShibUseHeaders` möglichst nicht** –
die Voreinstellung mit Umgebungsvariablen ist der sichere Weg.

Wenn du `trusted_proxies` brauchst: Trag die Adresse ein, mit der der Proxy
tatsächlich ankommt. Bei einem lokalen Proxy können das zwei sein, je nachdem
ob er über IPv4 oder IPv6 verbindet:

```php
'trusted_proxies' => ['127.0.0.1', '::1'],
```

Das ist ein beliebter Stolperstein: Die Anmeldung schlägt fehl, obwohl alles
richtig aussieht – weil der Proxy über `::1` kommt und nur `127.0.0.1`
eingetragen ist.

### 8.8 Prüfen

Der SP bringt eigene Diagnoseseiten mit:

```
https://kurz.example.org/Shibboleth.sso/Session   → welche Attribute angekommen sind
https://kurz.example.org/Shibboleth.sso/Status    → Zustand des SP
```

`/Session` ist der schnellste Weg zur Antwort auf „warum ist der Nutzer nicht
angemeldet". Stehen dort keine Attribute, liegt es am IdP oder an der
`attribute-map.xml` – nicht an flatlink.

Logdateien: `/var/log/shibboleth/shibd.log` und `transaction.log`.

### 8.9 Andere Module

**SAML mit `mod_auth_mellon`:** gleiche Struktur, Attribute kommen als
`MELLON_*`-Umgebungsvariablen. In flatlink dann etwa
`'user_var' => 'MELLON_eppn'`.

**OpenID Connect mit `mod_auth_openidc`:** Attribute als `OIDC_CLAIM_*`.
Beispiel: `'user_var' => 'OIDC_CLAIM_preferred_username'`,
`'mail_var' => 'OIDC_CLAIM_email'`, `'group_var' => 'OIDC_CLAIM_groups'`.
Der Trennzeichen-Eintrag muss zum Format des Claims passen.

---

## 9. Gruppen und Rechte in der Praxis

Gruppen leisten zweierlei: geteilten Zugriff auf Links und die Vergabe von
Rechten. Reihenfolge beim Einrichten:

1. **Gruppen anlegen** im Admin-Bereich unter *Gruppen* – mit einer Kennung
   (klein, kurz, unveränderlich) und den Rechten, die Mitglieder bekommen.
2. **Konten zuordnen** unter *Nutzer* – oder automatisch über `group_map`
   aus Verzeichnis bzw. IdP.
3. **Standardrechte festlegen** in `config.php` unter `default_perms` – das
   gilt für alle angemeldeten Konten, auch ohne Gruppe.

Verfügbare Rechte:

| Recht | Bedeutung |
| --- | --- |
| `custom_code` | darf Wunsch-Namen vergeben statt Zufallscodes |
| `csv_import` | darf viele Links auf einmal importieren |
| `logo_upload` | darf eigene Logos für QR-Codes hochladen |
| `qr_unbranded` | erzeugt QR-Codes ohne die Absenderzeile |
| `link_rules` | darf Weichen stellen (Ziel je nach Gerät, Sprache, Land) |
| `links_all` | sieht und verwaltet alle Links der Instanz |
| `reports_manage` | bearbeitet Meldungen und sperrt Links |

`links_all` und `reports_manage` zusammen ergeben eine Redaktion: volle Sicht
auf die Links und den Meldungs-Eingang, aber kein Zugriff auf Konten, Gruppen,
Einstellungen und Protokoll. Siehe [docs/gruppen.md](docs/gruppen.md).

### Wer darf sich überhaupt anmelden?

Die wichtigste Frage bei einer Föderation – und die am leichtesten übersehene.
Ein Verbund wie die DFN-AAI authentifiziert Angehörige **aller** beteiligten
Einrichtungen. Ohne Einschränkung bekommt also jedes Mitglied jeder deutschen
Hochschule auf deiner Instanz ein Konto. Drei Bremsen, beliebig kombinierbar:

```php
'allowed_scopes' => ['hfmt-hamburg.de'],   // nur die eigene Einrichtung
'require_group'  => true,                  // nur wer in einer Gruppe landet
'auto_create'    => false,                 // nur wer hier schon ein Konto hat
```

**`allowed_scopes`** greift bei Kennungen der Form `name@einrichtung.de`
(eppn) – der übliche Fall und meist schon ausreichend.

**`require_group`** ist der Weg, wenn die Kennung undurchsichtig ist
(`persistent-id`): Dann entscheidet die Gruppenzuordnung aus `group_map`, ob
jemand hereinkommt. `true` verlangt irgendeine Gruppe, eine Liste verlangt
eine bestimmte.

**`auto_create => false`** ist die strengste Stufe. Bei undurchsichtigen
Kennungen entsteht dabei ein Henne-Ei-Problem – niemand kann ein Konto vorab
anlegen, dessen Kennung er nicht kennt. Deshalb gibt es die
**Freischalt-Warteschlange** (`'approval_queue' => true`): Abgewiesene
Anmeldungen landen in der Nutzerverwaltung, mit Klarname, E-Mail und den
Gruppen aus dem Verzeichnis. Ein Klick auf *Freischalten*, und die nächste
Anmeldung derselben Person geht durch.

Dieselben drei Schalter gibt es im `ldap`-Block. Dort begrenzt schon
`base_dn` den Kreis; `require_group` verengt ihn auf bestimmte
Verzeichnis-Gruppen.

### Namensräume statt Namensstreit

Ist der Zugang bewusst offen, hilft die andere Richtung: Jede Gruppe bekommt
ein Präfix, und ihre Mitglieder legen nur darunter an.

| Gruppe | Präfix | Ergebnis |
| --- | --- | --- |
| Bibliothek | `bib` | `kurz.hochschule.de/bib/oeffnungszeiten` |
| Studierende | `stud` | `kurz.hochschule.de/stud/mensaplan` |
| (Verwaltung, ohne Präfix) | – | `kurz.hochschule.de/immatrikulation` |

So kann niemand versehentlich den kurzen, zentralen Namen belegen, und die
Bereiche kommen sich nicht in die Quere. Administratoren bleiben unbeschränkt.
Gesetzt wird das Präfix im Admin-Bereich unter *Gruppen*.

Ein bewährter Zuschnitt für eine Hochschule:

```php
'default_perms' => ['logo_upload'],     // darf jeder
```

…und dann eine Gruppe „Redaktion" mit `custom_code` und `csv_import` für die
Handvoll Leute, die Plakate und Aushänge verantworten. Der Grund: Der
Namensraum einer Instanz ist endlich. Wer sich `/studium` sichert, nimmt ihn
allen anderen weg – das gehört in wenige Hände.

**Geteilte Links** entstehen, indem beim Anlegen eine Gruppe gewählt wird. Alle
Mitglieder können sie dann bearbeiten, umziehen lassen und löschen. Genau
darum geht es: Ein gedruckter Aushang soll nicht davon abhängen, ob die
Kollegin, die ihn angelegt hat, noch im Haus ist.

---

## 10. Betrieb

### Eine Demo-Instanz betreiben

`'demo_mode' => true` macht aus einer Instanz eine öffentliche Spielwiese:
ein Hinweisband mit den Zugangsdaten (`demo / demo-1234`) über jeder Seite,
und der gesamte Bestand wird etwa alle `demo_reset_minutes` (Vorgabe 60)
verworfen und aus einem festen Demo-Bestand neu aufgebaut. Der Reset hängt
träge am Seitenaufbau – **kein Cron nötig**, das läuft auch auf Shared
Hosting ohne SSH.

In die Konfiguration der Demo gehören außerdem: `mail` auf `'mode' => 'log'`,
Selbstregistrierung aus, keine Webhooks, kein Safe-Browsing-Schlüssel – der
Modus erzwingt das nicht, er macht Band, Reset und Bestand.

### Sicherung

Alles Veränderliche liegt unter `data/`. Ein Backup ist ein Kopiervorgang:

```bash
sudo tar czf flatlink-$(date +%F).tar.gz -C /var/www/flatlink data inc/config.php
```

`inc/config.php` mitnehmen – sie enthält Zugangsdaten und steht bewusst nicht
im Repository. Und weil sie das tut: Das Backup gehört an einen Ort, an dem
nicht jeder mitliest.

**Für versionierte Sicherungen** – rsync, borg, ein Git-Repository – gibt es
einen Export, der den Bestand in ein Verzeichnis schreibt statt in ein Archiv:

```bash
php tools/backup-export.php /var/backups/flatlink
```

Die Datenbank kommt dabei als **SQL-Text** heraus, nicht als Datei. Das ist der
Unterschied, der ein Git-Repository benutzbar hält: Drei neue Kurzlinks sind
drei neue Zeilen statt einer neuen Kopie der ganzen Datenbank. Gleicher
Datenstand ergibt gleiche Bytes – keine Zeitstempel im Inhalt, feste
Reihenfolge –, sonst meldete jeder Lauf eine Änderung. Zurückgespielt wird mit
`sqlite3 data/<datenbank> < datenbank.sql`; der genaue Ablauf liegt als
`WIEDERHERSTELLEN.md` im Export.

`inc/config.php` bleibt ohne `--mit-config` draußen. Was drin ist –
Passwort-Hashes, E-Mail-Adressen, das Instanz-Geheimnis –, entscheidet über den
Ort: Ein Repository dafür muss privat sein, und seine Historie vergisst nichts,
auch ein gelöschtes Konto nicht.

### Aktualisieren

```bash
cd /var/www/flatlink
sudo git pull
```

`data/` und `inc/config.php` bleiben unangetastet. Nach einem Update lohnt ein
Blick in `inc/config.example.php`: Neue Optionen tauchen dort zuerst auf.
Fehlen sie in deiner `config.php`, greift automatisch der Vorgabewert aus der
Beispieldatei – die Instanz läuft also weiter, auch wenn du nichts tust.

### Was nicht ins Webroot gehört

`tests/`, `tools/` und `extension/` sind Werkzeuge für Kommandozeile und
Store-Bau – auf dem Server werden sie nicht gebraucht und gehören dort auch
nicht hin. Die mitgelieferte `.htaccess` sperrt sie ab, und die Skripte tragen
seit 2.9.5 zusätzlich einen eigenen CLI-Riegel; unter nginx (siehe oben) muss
die Sperre in der Server-Konfiguration nachgezogen werden:

```nginx
location ~ ^/(inc|data|tests|tools|extension)(/|$) { deny all; }
```

Der Grund ist konkret: `tests/einstellungen.php` legt für seinen Lauf ein
Admin-Konto an, dessen Passwort im Quelltext steht. Es räumt sich seit 2.9.5
selbst wieder ab und läuft nur noch auf der Kommandozeile – aber die sauberste
Fassung eines Werkzeugs, das auf dem Server nichts verloren hat, ist die, die
gar nicht erst dort liegt.

### Härten

- **Interne Instanz:** öffentliche Link-Erstellung und Selbst-Registrierung
  unter *Einstellungen* abschalten.
- **Öffentliche Instanz:** `public_rate_limit` prüfen und Google Safe Browsing
  erwägen (`safe_browsing_key`). Achtung: Dabei wird die Ziel-URL beim Anlegen
  an Google übertragen – das gehört in die Datenschutzerklärung.
- **Zugriffs-Logs.** flatlink speichert keine IP-Adressen, dein Webserver
  in aller Regel schon. Wer den Anspruch ernst meint, kürzt oder deaktiviert
  sie dort – sonst ist die Aussage nur die halbe Wahrheit.
- **Mindestens ein lokales Admin-Konto** behalten, damit ein Ausfall des IdP
  oder Verzeichnisses dich nicht aussperrt.

### Rechtliches

Wer eine öffentliche Instanz betreibt, braucht je nach Land eigene Angaben –
in Deutschland typischerweise Impressum und Datenschutzerklärung. flatlink
liefert dafür bewusst keine Vorlagen: Sie hängen von Betreiber, Zweck und
Nutzung ab, und eine mitgelieferte Vorlage würde mehr Schaden anrichten als
helfen. Eigene Seiten anlegen und in `page_footer()` in
[`inc/helpers.php`](inc/helpers.php) verlinken.

---

## 11. Wenn etwas nicht geht

| Symptom | Wahrscheinliche Ursache |
| --- | --- |
| „Konfiguration fehlt" | `inc/config.php` nicht angelegt – aus `config.example.php` kopieren |
| Kurzlinks führen zu 404 | Umschreibung greift nicht: `mod_rewrite` aus, `AllowOverride None`, oder bei nginx die `location`-Blöcke vergessen |
| Weiße Seite, keine Meldung | PHP-Fehler – ins Fehlerprotokoll des Webservers sehen |
| „Kein Zugriff" beim Speichern | `data/` gehört nicht dem Webserver-Benutzer |
| QR-Code als SVG ok, PNG kaputt | `gd` fehlt |
| Rahmentext im PNG grob gerastert | keine TrueType-Datei – eine `.ttf` nach `assets/fonts/` legen |
| Mails kommen nicht an | zuerst `'mode' => 'log'` und `data/mail.log` prüfen; danach SPF/DKIM/DMARC |
| Mails landen im Spam | DKIM fehlt oder ist noch nicht aktiv (kann nach Einrichtung dauern) |
| LDAP-Login schlägt immer fehl | Filter liefert keinen oder mehr als einen Treffer – mit `ldapsearch` gegenprüfen |
| LDAP: „Can't contact LDAP server" | TLS-Zertifikat nicht vertrauenswürdig – `TLS_CACERT` setzen |
| LDAP-Gruppen bleiben leer | `memberOf` nicht vorhanden → `group_mode` auf `search` stellen |
| SSO: Nutzer wird nicht angemeldet | `/Shibboleth.sso/Session` aufrufen – kommen dort Attribute an? |
| SSO: Attribute da, flatlink meldet nicht an | Name in `user_var` stimmt nicht, oder es ist eine `HTTP_`-Variable ohne `trusted_proxies` |
| SSO: funktioniert lokal, nicht über Proxy | `trusted_proxies` fehlt – auch `::1` eintragen |
| Nach Abmelden sofort wieder angemeldet | `logout_url` nicht gesetzt: Der IdP hält die Sitzung |
| Aussperrt: kein Admin mehr | `sqlite3 data/flatlink.sqlite "UPDATE users SET role='admin', data=json_set(data,'$.role','admin') WHERE name='DEINE-KENNUNG'"` |

Wenn nichts davon passt: [ein Issue aufmachen](https://github.com/HerrBarmann/flatlink/issues).
Hilfreich sind PHP-Version, Webserver, Anmeldeweg und der Auszug aus dem
Fehlerprotokoll – aber bitte **ohne** Zugangsdaten aus `config.php`.
