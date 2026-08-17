/* Live-Vorschau des GS1-Digital-Link-Generators.
 * Ausgelagert für die Content-Security-Policy; die Eingaben gehen per POST an
 * qr.php. Die fertige Adresse zeigen wir zusätzlich im Klartext an – bei einem
 * Code, der auf eine Verpackung geht, will man vorher sehen, was drinsteht.
 * Die Gestaltungsoptionen sammelt assets/qroptions.js ein – dasselbe Panel
 * wie im QR-Designer. */
(function () {
    var $ = function (id) { return document.getElementById(id); };
    if (!window.QRGestaltung) return;
    var timer = null, currentUrl = null, laeuft = null;

    function formData(extra) {
        var fd = new FormData();
        fd.set('t', 'gs1');
        fd.set('gtin', $('g-gtin').value);
        fd.set('lot', $('g-lot').value);
        fd.set('serial', $('g-serial').value);
        fd.set('mhd', $('g-mhd').value);
        fd.set('resolver', $('g-resolver').value);
        QRGestaltung.sammle(function (k, v) { fd.set(k, v); });
        for (var k in extra) fd.set(k, extra[k]);
        return fd;
    }

    function melde(text, fehler) {
        var box = $('g-status');
        box.textContent = text;
        box.className = 'flash ' + (fehler ? 'flash-err' : 'flash-ok') + (text ? '' : ' hidden');
        box.style.display = text ? '' : 'none';
    }

    function update() {
        if (!$('g-gtin').value.trim()) {
            melde('', false);
            $('g-preview').removeAttribute('src');
            return;
        }
        fetch('qr.php', { method: 'POST', body: formData({ size: 300 }) })
            .then(function (r) {
                if (!r.ok) return r.text().then(function (t) { throw new Error(t); });
                return r.blob();
            })
            .then(function (b) {
                if (currentUrl) URL.revokeObjectURL(currentUrl);
                currentUrl = URL.createObjectURL(b);
                $('g-preview').src = currentUrl;
                melde('', false);
                zeigeAdresse();
            })
            .catch(function (e) {
                $('g-preview').removeAttribute('src');
                melde(String(e.message || e).slice(0, 200), true);
                $('g-url').textContent = '';
            });
        pruefe();
    }

    /* Lesbarkeits-Hinweise wie im Designer – die Bewertung kommt vom Server */
    function pruefe() {
        var box = $('g-lesbarkeit');
        if (!box || !$('g-gtin').value.trim()) return;
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

    /* Die Adresse noch einmal im Browser bauen – rein zur Anzeige. Verbindlich
       ist immer, was der Server in den Code geschrieben hat. */
    function zeigeAdresse() {
        var g = $('g-gtin').value.replace(/[^0-9]/g, '');
        if (!g) { $('g-url').textContent = ''; return; }
        var basis = ($('g-resolver').value.trim() || 'https://id.gs1.org').replace(/\/+$/, '');
        if (!/^https?:\/\//.test(basis)) basis = 'https://' + basis;
        var url = basis + '/01/' + g.padStart(14, '0');
        if ($('g-lot').value.trim()) url += '/10/' + encodeURIComponent($('g-lot').value.trim());
        if ($('g-serial').value.trim()) url += '/21/' + encodeURIComponent($('g-serial').value.trim());
        if ($('g-mhd').value) url += '?17=' + $('g-mhd').value.slice(2).replace(/-/g, '');
        $('g-url').textContent = url;
    }

    function download(extra) {
        fetch('qr.php', { method: 'POST', body: formData(extra) })
            .then(function (r) { if (!r.ok) throw new Error('Fehler'); return r.blob(); })
            .then(function (b) {
                var endung = { png: 'png', pdf: 'pdf', eps: 'eps' }[extra.format] || 'svg';
                var a = document.createElement('a');
                a.href = URL.createObjectURL(b);
                a.download = 'gs1-' + ($('g-gtin').value.replace(/[^0-9]/g, '') || 'code') + '.' + endung;
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
            })
            .catch(function () { melde('Der Download hat nicht geklappt.', true); });
    }

    function angefasst() {
        clearTimeout(timer);
        timer = setTimeout(update, 350);
    }

    ['g-gtin', 'g-lot', 'g-serial', 'g-mhd', 'g-resolver'].forEach(function (id) {
        var el = $(id);
        if (!el) return;
        el.addEventListener('input', angefasst);
    });
    QRGestaltung.bind(angefasst);
    $('g-svg').addEventListener('click', function () { download({ format: 'svg', download: 1 }); });
    $('g-png').addEventListener('click', function () { download({ format: 'png', size: 1024, download: 1 }); });
    $('g-pdf').addEventListener('click', function () { download({ format: 'pdf', download: 1 }); });
    if ($('g-eps')) $('g-eps').addEventListener('click', function () { download({ format: 'eps', download: 1 }); });
})();
