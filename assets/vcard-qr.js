/* Live-Vorschau des vCard-QR-Generators.
 * Ausgelagert für die Content-Security-Policy; die Eingaben gehen weiterhin
 * per POST an qr.php, damit nichts in Adresszeilen oder Logs landet.
 * Die Gestaltungsoptionen sammelt assets/qroptions.js ein – dasselbe Panel
 * wie im QR-Designer. */
(function () {
    var $ = function (id) { return document.getElementById(id); };
    if (!window.QRGestaltung) return;
    /* Übersetzungen wie in qroptions.js: page_footer() legt sie als
     * JSON-Datenblock in die Seite; fehlt er (deutsche Instanz), bleibt der
     * deutsche Text. */
    var UEB = {};
    try { UEB = JSON.parse(document.getElementById('lang-js').textContent); } catch (e) {}
    function t(s) { return UEB[s] || s; }
    var timer = null, currentUrl = null, laeuft = null;
    var felder = ['v-vorname', 'v-nachname', 'v-firma', 'v-tel', 'v-email', 'v-url'];

    function formData(extra) {
        var fd = new FormData();
        fd.set('t', 'vcard');
        fd.set('vorname', $('v-vorname').value);
        fd.set('nachname', $('v-nachname').value);
        fd.set('firma', $('v-firma').value);
        fd.set('tel', $('v-tel').value);
        fd.set('email', $('v-email').value);
        fd.set('url', $('v-url').value);
        QRGestaltung.sammle(function (k, v) { fd.set(k, v); });
        for (var k in extra) fd.set(k, extra[k]);
        return fd;
    }

    function refresh() {
        if (!$('v-vorname').value && !$('v-nachname').value) { $('v-preview').removeAttribute('src'); return; }
        fetch('qr.php', { method: 'POST', body: formData({ size: 300 }) })
            .then(function (r) {
                if (r.status === 400) { $('v-hint').textContent = t('Zu viele Zeichen für einen QR-Code – bitte Angaben kürzen.'); throw 0; }
                $('v-hint').textContent = t('Tipp: Weniger Felder = gröberes, leichter scannbares Raster.');
                return r.blob();
            })
            .then(function (b) {
                if (currentUrl) URL.revokeObjectURL(currentUrl);
                currentUrl = URL.createObjectURL(b);
                $('v-preview').src = currentUrl;
            })
            .catch(function () {});
        pruefe();
    }

    /* Lesbarkeits-Hinweise wie im Designer – die Bewertung kommt vom Server */
    function pruefe() {
        var box = $('v-lesbarkeit');
        if (!box || (!$('v-vorname').value && !$('v-nachname').value)) return;
        if (laeuft) laeuft.abort();
        var c = new AbortController();
        laeuft = c;
        var fd = formData({ format: 'png', size: 1024 });
        fd.set('check', '1');
        fetch('qr.php', { method: 'POST', body: fd, signal: c.signal })
            .then(function (r) { return r.json(); })
            .then(function (d) { QRGestaltung.zeigeHinweise(box, d); })
            .catch(function () {});
    }

    function download(extra, name) {
        fetch('qr.php', { method: 'POST', body: formData(extra) })
            .then(function (r) { if (!r.ok) throw 0; return r.blob(); })
            .then(function (b) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(b);
                a.download = name;
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
            })
            .catch(function () { alert(t('Bitte zuerst einen Namen eingeben (und ggf. Angaben kürzen).')); });
    }

    function angefasst() { clearTimeout(timer); timer = setTimeout(refresh, 250); }

    felder.forEach(function (id) {
        $(id).addEventListener('input', angefasst);
        $(id).addEventListener('change', refresh);
    });
    QRGestaltung.bind(angefasst);
    $('v-svg').addEventListener('click', function () { download({ format: 'svg' }, 'kontakt-qr.svg'); });
    $('v-png').addEventListener('click', function () { download({ format: 'png', size: 1024 }, 'kontakt-qr.png'); });
    $('v-pdf').addEventListener('click', function () { download({ format: 'pdf' }, 'kontakt-qr.pdf'); });
    if ($('v-eps')) $('v-eps').addEventListener('click', function () { download({ format: 'eps' }, 'kontakt-qr.eps'); });
})();
