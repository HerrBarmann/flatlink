/* QR-Designer – Vorschau und Download-Adressen aktualisieren.
 *
 * Dieselbe Datei bedient Gäste und Angemeldete. Die Gestaltungsoptionen
 * selbst sammelt assets/qroptions.js ein – das Panel und seine Logik gehören
 * allen Generatoren gemeinsam. Hier steht nur, was der Designer zusätzlich
 * hat: den Kurz-Code (oder den statischen Inhalt), die Download-Knöpfe und
 * die Lesbarkeits-Anzeige. Der Kurz-Code und der Pfad zu qr.php kommen über
 * data-Attribute aus dem Markup, damit hier kein PHP eingebettet werden muss
 * und die CSP ohne Inline-Skripte auskommt.
 */
(function () {
    var stage = document.getElementById('qr-stage');
    if (!stage || !window.QRGestaltung) return;
    var code = stage.getAttribute('data-code') || '';
    var basis = stage.getAttribute('data-base') || 'qr.php';
    // 'url' = der Inhalt steht unmittelbar im Code, es gibt keinen Kurzlink
    var modus = stage.getAttribute('data-mode') || 'link';
    var $ = function (id) { return document.getElementById(id); };
    var wert = function (id) { var el = $(id); return el ? el.value : null; };

    function params(extra) {
        var p;
        if (modus === 'url') {
            p = new URLSearchParams({ t: 'url', u: (wert('opt-u') || '').trim() });
        } else {
            p = new URLSearchParams({ c: code });
        }
        QRGestaltung.sammle(function (k, v) { p.set(k, v); });
        for (var k2 in extra) p.set(k2, extra[k2]);
        return p.toString();
    }

    function setzeHref(id, extra) {
        var el = $(id);
        if (el) el.href = basis + '?' + params(extra);
    }

    var letzteVorschau = '';
    function refresh() {
        var vorschau = $('qr-preview');
        var url = basis + '?' + params({ size: 320 });
        // Nichts geändert (etwa `change` nach `input` desselben Werts)?
        // Dann auch keine neue Runde.
        if (url === letzteVorschau) return;
        letzteVorschau = url;
        if (vorschau) {
            // Erst laden, dann tauschen: Ein direktes src-Setzen ließe das
            // alte Bild verschwinden, bevor das neue da ist – genau das
            // Flackern, das sich wie Langsamkeit anfühlt.
            var lader = new Image();
            lader.onload = function () { if (letzteVorschau === url) vorschau.src = url; };
            lader.src = url;
        }
        setzeHref('dl-svg', { format: 'svg', download: 1 });
        setzeHref('dl-png', { format: 'png', size: wert('opt-size') || 1024, download: 1 });
        setzeHref('dl-pdf', { format: 'pdf', download: 1 });
        setzeHref('dl-eps', { format: 'eps', download: 1 });
        pruefe();
    }

    /* Lesbarkeit prüfen lassen und die Hinweise neben der Vorschau zeigen.
     * Läuft die Abfrage ins Leere, bleibt der Bereich still – eine Warnung,
     * die nicht kommt, ist besser als eine Fehlermeldung über eine Prüfung,
     * die niemand angefordert hat. */
    var laeuft = null;
    function pruefe() {
        var box = $('lesbarkeit');
        if (!box) return;
        if (laeuft) laeuft.abort();
        var c = new AbortController();
        laeuft = c;
        // Das Format mitgeben: Für PNG zählt die Pixelgröße, für PDF und EPS
        // die Breite auf dem Papier – sonst prüften wir die falsche Größe.
        var fmt = { format: 'png', size: wert('opt-size') || 1024 };
        fetch(basis + '?' + params(fmt) + '&check=1', { signal: c.signal })
            .then(function (r) { return r.json(); })
            .then(function (d) { QRGestaltung.zeigeHinweise(box, d); })
            .catch(function () { /* abgebrochen oder nicht erreichbar */ });
    }

    QRGestaltung.bind(refresh);
    ['opt-u', 'opt-size'].forEach(function (id) {
        var el = $(id);
        if (!el) return;
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
    });

    refresh();
})();
