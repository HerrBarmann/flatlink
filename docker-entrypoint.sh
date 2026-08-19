#!/bin/sh
# Start im Container: Datenverzeichnis bereitstellen, Lage prüfen.
#
# Der Container läuft als Nicht-root – hier darf deshalb nichts stehen, was
# root-Rechte braucht. Die Konfiguration liegt bereits im Image; das
# Datenverzeichnis richtet der Betreiber ein (Docker über das Volume,
# Kubernetes über fsGroup).
set -e

DATA="${FLATLINK_DATA_DIR:-/var/lib/flatlink}"

# Falls wir doch als root laufen (docker run --user root, ältere Aufrufe):
# dann räumen wir die Rechte selbst, statt uns darauf zu verlassen.
if [ "$(id -u)" = "0" ]; then
    mkdir -p "$DATA"
    chown -R www-data:0 "$DATA"
    chmod -R g=u "$DATA"
fi

mkdir -p "$DATA" 2>/dev/null || true

# Schreibbar? Wenn nicht, hilft eine klare Ansage mehr als ein PHP-Fehler
# auf der ersten Seite. In Kubernetes ist fsGroup die übliche Antwort.
if [ ! -w "$DATA" ]; then
    echo "flatlink: '$DATA' ist für Kennung $(id -u):$(id -g) nicht beschreibbar." >&2
    echo "flatlink: Docker: -v flatlink-data:$DATA genügt." >&2
    echo "flatlink: Kubernetes: securityContext.fsGroup auf 0 setzen." >&2
fi

# base_url ist keine Kleinigkeit: Ohne sie rät flatlink die Adresse aus dem
# Host-Header, und weil der Nutzereingabe ist, verschickt es dann gar keine
# Mails mit Links. Hinter einem Proxy ist das der Regelfall, deshalb hier
# ein sichtbarer Hinweis statt einer stillen Einschränkung.
if [ -z "$FLATLINK_BASE_URL" ]; then
    echo "flatlink: FLATLINK_BASE_URL ist nicht gesetzt – Mails mit Links (Registrierung," >&2
    echo "flatlink: Passwort zurücksetzen) unterbleiben, bis die Adresse feststeht." >&2
fi

exec "$@"
