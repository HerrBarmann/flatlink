# flatlink im Container.
#
# Apache statt php-fpm mit eigenem Webserver davor: Die mitgelieferte
# .htaccess ist Teil der Anwendung – sie schreibt Kurzcodes auf go.php um
# und sperrt die Verzeichnisse, die nichts im Netz zu suchen haben. Mit
# Apache gilt sie unverändert, und ein Container weniger ist ein Container
# weniger.
FROM php:8.3-apache

# gd für PNG- und PDF-Ausgabe, ldap für die Anmeldung am Verzeichnis.
# pdo_sqlite und mbstring bringt das Basis-Image mit; wir prüfen es unten,
# damit ein Bau nicht still ohne sie durchgeht.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libldap2-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd ldap; \
    \
    # Die Entwicklungspakete fliegen wieder raus – aber die Bibliotheken, an
    # denen die frisch gebauten Erweiterungen hängen, müssen bleiben. Welche
    # das sind, sagt ldd; ohne diesen Umweg nimmt --auto-remove sie mit, und
    # gd lädt beim Start nicht mehr.
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -r 'foreach (["pdo_sqlite","mbstring","gd","fileinfo","openssl","ldap"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "fehlt: $e\n"); exit(1); } } echo "Erweiterungen vollständig\n";'

# Kurzcodes brauchen die Umschreibung, und die .htaccess braucht die
# Erlaubnis, überhaupt gelesen zu werden – ohne AllowOverride wäre sie
# eine stille Attrappe, und jeder Kurzlink liefe auf die Startseite.
# Apache lauscht auf 8080 statt 80. Ports unter 1024 darf nur root binden –
# und root will kein Cluster: Kubernetes mit dem Pod-Security-Standard
# 'restricted' lehnt solche Container ab, OpenShift ohnehin.
RUN set -eux; \
    sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    a2enmod rewrite; \
    printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/flatlink.conf; \
    a2enconf flatlink; \
    # Servername festlegen, sonst warnt Apache bei jedem Start
    printf 'ServerName flatlink\n' > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername

# Produktionsvorgaben von PHP übernehmen (Fehler nicht an Besucher ausgeben)
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    printf 'upload_max_filesize=8M\npost_max_size=10M\nexpose_php=Off\n' \
        > "$PHP_INI_DIR/conf.d/flatlink.ini"

# Wird der Container doch als root gestartet (Podman tut das im
# Benutzer-Namensraum), wechselt Apache für die Arbeitsprozesse auf
# www-data:www-data – Gruppe 33 statt 0, und dann sind ihm die Dateien
# nicht mehr lesbar. Gruppe 0 festschreiben, dann stimmt beides.
ENV APACHE_RUN_GROUP=root

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/

# Die erzeugte Konfiguration war bei jedem Start dieselbe – also gehört sie
# ins Image, nicht in den Startvorgang. Eine eingehängte eigene config.php
# überlagert sie weiterhin.
RUN printf '%s\n' '<?php' \
    '// Aus den FLATLINK_*-Umgebungsvariablen; siehe inc/config.docker.php.' \
    '// Eigene Konfiguration? Diese Datei einfach überhängen.' \
    "return require __DIR__ . '/config.docker.php';" \
    > /var/www/html/inc/config.php

# Der Webserver schreibt ausschließlich ins Datenverzeichnis. Alles andere
# gehört ihm nicht einmal – ein Einbruch über PHP kann die Anwendung damit
# nicht umschreiben.
# Der Container läuft als www-data, aber die Kennung darf beliebig sein:
# Cluster vergeben oft eine zufällige UID. Deshalb gehört alles der Gruppe 0
# und ist für sie lesbar – eine zufällige UID landet immer in Gruppe 0.
# Geschrieben wird weiterhin nur im Datenverzeichnis.
RUN set -eux; \
    chmod +x /usr/local/bin/docker-entrypoint.sh; \
    chgrp -R 0 /var/www/html; \
    find /var/www/html -type d -exec chmod 2750 {} \; ; \
    find /var/www/html -type f -exec chmod 640 {} \; ; \
    mkdir -p /var/lib/flatlink /var/run/apache2 /var/log/apache2; \
    chgrp -R 0 /var/lib/flatlink /var/run/apache2 /var/log/apache2; \
    chmod -R g=u /var/lib/flatlink /var/run/apache2 /var/log/apache2

# Links, Konten, Zähler und Logos – das Einzige, was ein neues Image
# überleben muss.
VOLUME /var/lib/flatlink

EXPOSE 8080

# Nicht-root von Haus aus. www-data in Gruppe 0 – so läuft es auch dort,
# wo der Cluster eine eigene Kennung erzwingt.
USER 33:0

# Derselbe Endpunkt, den auch ein Wächter von außen abfragt: Antwortet die
# Instanz, und ist ihre Ablage lesbar?
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r '$c = @file_get_contents("http://127.0.0.1:8080/api/health"); exit(is_string($c) && str_contains($c, "pass") ? 0 : 1);'

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

LABEL org.opencontainers.image.title="flatlink" \
      org.opencontainers.image.description="Selbst gehosteter Kurzlink-Dienst mit QR-Designer und Link-in-Bio-Seiten" \
      org.opencontainers.image.source="https://github.com/HerrBarmann/flatlink" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"
