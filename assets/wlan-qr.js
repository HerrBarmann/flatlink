/* Live-Vorschau des WLAN-QR-Generators.
 * Ausgelagert für die Content-Security-Policy; die Eingaben gehen weiterhin
 * per POST an qr.php, damit nichts in Adresszeilen oder Logs landet.
 * Die Gestaltungsoptionen sammelt assets/qroptions.js ein – dasselbe Panel
 * wie im QR-Designer. */
(function () {
    var $ = function (id) { return document.getElementById(id); };
    if (!window.QRGestaltung) return;
    var timer = null, currentUrl = null, laeuft = null;

    function formData(extra) {
        var fd = new FormData();
        fd.set('t', 'wifi');
        fd.set('ssid', $('w-ssid').value);
        fd.set('enc', $('w-enc').value);
        fd.set('pw', $('w-pw').value);
        if ($('w-hidden').checked) fd.set('hidden', '1');
        QRGestaltung.sammle(function (k, v) { fd.set(k, v); });
        for (var k in extra) fd.set(k, extra[k]);
        return fd;
    }

    function refresh() {
        $('w-pw-row').style.display = $('w-enc').value === 'nopass' ? 'none' : '';
        if (!$('w-ssid').value) { $('w-preview').removeAttribute('src'); return; }
        fetch('qr.php', { method: 'POST', body: formData({ size: 300 }) })
            .then(function (r) { if (!r.ok) throw 0; return r.blob(); })
            .then(function (b) {
                if (currentUrl) URL.revokeObjectURL(currentUrl);
                currentUrl = URL.createObjectURL(b);
                $('w-preview').src = currentUrl;
            })
            .catch(function () {});
        pruefe();
    }

    /* Lesbarkeits-Hinweise wie im Designer – die Bewertung kommt vom Server */
    function pruefe() {
        var box = $('w-lesbarkeit');
        if (!box) return;
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
            .catch(function () { alert('Bitte zuerst einen Netzwerknamen eingeben.'); });
    }

    function angefasst() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 250);
    }

    ['w-ssid', 'w-enc', 'w-pw', 'w-hidden'].forEach(function (id) {
        $(id).addEventListener('input', angefasst);
        $(id).addEventListener('change', refresh);
    });
    QRGestaltung.bind(angefasst);
    $('w-svg').addEventListener('click', function () { download({ format: 'svg' }, 'wlan-qr.svg'); });
    $('w-png').addEventListener('click', function () { download({ format: 'png', size: 1024 }, 'wlan-qr.png'); });
    $('w-pdf').addEventListener('click', function () { download({ format: 'pdf' }, 'wlan-qr.pdf'); });
    if ($('w-eps')) $('w-eps').addEventListener('click', function () { download({ format: 'eps' }, 'wlan-qr.eps'); });
    refresh();
})();
