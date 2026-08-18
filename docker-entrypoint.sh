#!/bin/sh
# Start im Container: Konfiguration bereitstellen, Datenverzeichnis richten.
#
# Beides muss bei jedem Start passieren, nicht beim Bauen: Das Volume
# entsteht erst hier, und die Umgebungsvariablen kennt das Image nicht.
set -e

CONFIG=/var/www/html/inc/config.php
DATA="${FLATLINK_DATA_DIR:-/var/lib/flatlink}"

# Eigene Konfiguration eingehängt? Dann hat sie Vorrang – sie ist die
# ausdrückliche Ansage des Betreibers, die Umgebungsvariablen nur die
# bequeme Abkürzung.
if [ ! -f "$CONFIG" ]; then
    cat > "$CONFIG" <<'PHP'
<?php
// Erzeugt beim Start des Containers. Die Werte kommen aus den
// FLATLINK_*-Umgebungsvariablen; siehe inc/config.docker.php.
// Eigene Konfiguration? Diese Datei einfach überhängen.
return require __DIR__ . '/config.docker.php';
PHP
    chown root:www-data "$CONFIG"
    chmod 640 "$CONFIG"
fi

# Das Volume gehört beim ersten Start root. Ohne diesen Griff könnte der
# Webserver nicht schreiben – und die Einrichtung liefe ins Leere.
mkdir -p "$DATA"
chown -R www-data:www-data "$DATA"
chmod 700 "$DATA"

# base_url ist keine Kleinigkeit: Ohne sie rät flatlink die Adresse aus dem
# Host-Header, und weil der Nutzereingabe ist, verschickt es dann gar keine
# Mails mit Links. Im Container hinter einem Proxy ist das der Regelfall,
# deshalb hier ein sichtbarer Hinweis statt einer stillen Einschränkung.
if [ -z "$FLATLINK_BASE_URL" ] && ! grep -q "base_url" "$CONFIG" 2>/dev/null; then
    echo "flatlink: FLATLINK_BASE_URL ist nicht gesetzt – Mails mit Links (Registrierung," >&2
    echo "flatlink: Passwort zurücksetzen) unterbleiben, bis die Adresse feststeht." >&2
fi

exec "$@"
