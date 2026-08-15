/* QR-Serie – Live-Vorschau der Gestaltung am ersten Link der Liste.
 *
 * Das Archiv selbst entsteht klassisch per Formular-Abgabe auf dem Server;
 * dieses Skript zeigt nur vorab, wie die gewählte Gestaltung aussieht, und
 * holt die Lesbarkeits-Hinweise dazu. Die Optionen sammelt assets/qroptions.js
 * ein – dasselbe Panel wie im QR-Designer.
 *
 * Ohne Inline-Code, wie überall im Projekt (Content-Security-Policy). */
(function () {
    'use strict';
    var stage = document.getElementById('zip-stage');
    if (!stage || !window.QRGestaltung) return;
    var code = stage.getAttribute('data-code') || '';
    var basis = stage.getAttribute('data-base') || '../qr.php';
    if (code === '') return;

    function params(extra) {
        var p = new URLSearchParams({ c: code });
        QRGestaltung.sammle(function (k, v) { p.set(k, v); });
        for (var k2 in extra) p.set(k2, extra[k2]);
        return p.toString();
    }

    var laeuft = null;
    function refresh() {
        var img = document.getElementById('qr-preview');
        if (img) img.src = basis + '?' + params({ size: 280 });
        var box = document.getElementById('lesbarkeit');
        if (!box) return;
        if (laeuft) laeuft.abort();
        var c = new AbortController();
        laeuft = c;
        // Geprüft wird in der Größe, die das Archiv bekommt
        var groesse = document.getElementById('z-size');
        fetch(basis + '?' + params({ format: 'png', size: groesse ? groesse.value : 1024 }) + '&check=1',
            { signal: c.signal })
            .then(function (r) { return r.json(); })
            .then(function (d) { QRGestaltung.zeigeHinweise(box, d); })
            .catch(function () { /* abgebrochen oder nicht erreichbar */ });
    }

    QRGestaltung.bind(refresh);
    var groesse = document.getElementById('z-size');
    if (groesse) groesse.addEventListener('change', refresh);
    refresh();
})();
