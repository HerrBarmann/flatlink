/* Live-Vorschau des Termin-QR-Generators.
 * Ausgelagert für die Content-Security-Policy; die Eingaben gehen weiterhin
 * per POST an qr.php, damit nichts in Adresszeilen oder Logs landet.
 * Die Gestaltungsoptionen sammelt assets/qroptions.js ein – dasselbe Panel
 * wie im QR-Designer. */
(function () {
    var $ = function (id) { return document.getElementById(id); };
    if (!window.QRGestaltung) return;
    var timer = null, currentUrl = null, laeuft = null;
    var felder = ['t-titel', 't-ort', 't-start', 't-ende'];

    function formData(extra) {
        var fd = new FormData();
        fd.set('t', 'event');
        fd.set('titel', $('t-titel').value);
        fd.set('ort', $('t-ort').value);
        fd.set('start', $('t-start').value);
        fd.set('ende', $('t-ende').value);
        QRGestaltung.sammle(function (k, v) { fd.set(k, v); });
        for (var k in extra) fd.set(k, extra[k]);
        return fd;
    }

    function refresh() {
        if (!$('t-titel').value || !$('t-start').value) { $('t-preview').removeAttribute('src'); return; }
        fetch('qr.php', { method: 'POST', body: formData({ size: 300 }) })
            .then(function (r) { if (!r.ok) throw 0; return r.blob(); })
            .then(function (b) {
                if (currentUrl) URL.revokeObjectURL(currentUrl);
                currentUrl = URL.createObjectURL(b);
                $('t-preview').src = currentUrl;
            })
            .catch(function () {});
        pruefe();
    }

    /* Lesbarkeits-Hinweise wie im Designer – die Bewertung kommt vom Server */
    function pruefe() {
        var box = $('t-lesbarkeit');
        if (!box || !$('t-titel').value || !$('t-start').value) return;
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
            .catch(function () { alert('Bitte zuerst Titel und Beginn angeben.'); });
    }

    function angefasst() { clearTimeout(timer); timer = setTimeout(refresh, 250); }

    felder.forEach(function (id) {
        $(id).addEventListener('input', angefasst);
        $(id).addEventListener('change', refresh);
    });
    QRGestaltung.bind(angefasst);
    $('t-svg').addEventListener('click', function () { download({ format: 'svg' }, 'termin-qr.svg'); });
    $('t-png').addEventListener('click', function () { download({ format: 'png', size: 1024 }, 'termin-qr.png'); });
    $('t-pdf').addEventListener('click', function () { download({ format: 'pdf' }, 'termin-qr.pdf'); });
    if ($('t-eps')) $('t-eps').addEventListener('click', function () { download({ format: 'eps' }, 'termin-qr.eps'); });
})();
