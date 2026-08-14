/* QR-Designer – Vorschau und Download-Adressen aktualisieren.
 *
 * Dieselbe Datei bedient Gäste und Angemeldete. Nicht jedes Bedienelement ist
 * immer da – Logo, Rahmentext, PDF und Größenwahl gibt es nur mit Konto –,
 * deshalb wird überall geprüft, bevor gelesen wird. Der Kurz-Code und der Pfad
 * zu qr.php kommen über data-Attribute aus dem Markup, damit hier kein PHP
 * eingebettet werden muss und die CSP ohne Inline-Skripte auskommt.
 */
(function () {
    var stage = document.getElementById('qr-stage');
    if (!stage) return;
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
        ['style', 'eye', 'eyecore', 'fg', 'bg', 'ecc', 'margin', 'ls'].forEach(function (k) {
            var v = wert('opt-' + k);
            if (v !== null) p.set(k, v);
        });
        // Ein Farbfeld kann nicht „nichts" sein – deshalb ein eigener Schalter
        var ohne = $('opt-bgnone');
        if (ohne && ohne.checked) p.set('bg', 'none');
        var logo = wert('opt-logo');
        if (logo) {
            p.set('logo', logo);
            if (wert('opt-lshape')) p.set('lshape', wert('opt-lshape'));
        }
        var ftext = wert('opt-ftext');
        if (ftext && ftext.trim()) p.set('ftext', ftext.trim());
        // CMYK nur mitschicken, wenn alle vier Felder gefüllt sind – drei
        // Werte ergeben keine Farbe, und eine halb gesetzte Angabe wäre eine
        // stille Falle für die Druckerei.
        ['fgc', 'bgc'].forEach(function (n) {
            var v = ['c', 'm', 'y', 'k'].map(function (k) { return wert('opt-' + n + '-' + k); });
            if (v.every(function (x) { return x !== null && x !== ''; })) p.set(n, v.join(','));
        });
        var mm = wert('opt-mm');
        if (mm) p.set('mm', mm);
        // Augenfarben nur, wenn ausdrücklich gewünscht – sonst erben sie die
        // Farbe der Module, und genau das soll die Vorgabe bleiben.
        var eigen = $('opt-eyeown');
        if (eigen && eigen.checked) {
            if (wert('opt-eyefg')) p.set('eyefg', wert('opt-eyefg'));
            if (wert('opt-eyecorefg')) p.set('eyecorefg', wert('opt-eyecorefg'));
        }
        var grad = wert('opt-grad');
        if (grad) {
            p.set('grad', grad);
            if (wert('opt-fg2')) p.set('fg2', wert('opt-fg2'));
            if (wert('opt-ga')) p.set('ga', wert('opt-ga'));
        }
        for (var k2 in extra) p.set(k2, extra[k2]);
        return p.toString();
    }

    function setzeHref(id, extra) {
        var el = $(id);
        if (el) el.href = basis + '?' + params(extra);
    }

    function refresh() {
        // Der Winkel ist nur beim linearen Verlauf eine Frage
        var gw = $('grad-winkel');
        if (gw) gw.hidden = wert('opt-grad') !== 'linear';
        var af = $('augenfarben'), ao = $('opt-eyeown');
        if (af && ao) af.hidden = !ao.checked;
        var gv = $('ga-val');
        if (gv && $('opt-ga')) gv.textContent = $('opt-ga').value;
        var m = $('margin-val'), l = $('ls-val');
        if (m && $('opt-margin')) m.textContent = $('opt-margin').value;
        if (l && $('opt-ls')) l.textContent = $('opt-ls').value;
        var vorschau = $('qr-preview');
        if (vorschau) vorschau.src = basis + '?' + params({ size: 320 });
        setzeHref('dl-svg', { format: 'svg', download: 1 });
        setzeHref('dl-png', { format: 'png', size: wert('opt-size') || 1024, download: 1 });
        setzeHref('dl-pdf', { format: 'pdf', download: 1 });
        setzeHref('dl-eps', { format: 'eps', download: 1 });
        pruefe();
    }

    /* Lesbarkeit prüfen lassen und die Hinweise neben der Vorschau zeigen.
     *
     * Die Bewertung kommt vom Server, nicht von hier: Die Schwellen gehören zu
     * den Regeln des Dienstes und sollen nicht davon abhängen, was ein Browser
     * gerade ausführt. Läuft die Abfrage ins Leere, bleibt der Bereich still –
     * eine Warnung, die nicht kommt, ist besser als eine Fehlermeldung über
     * eine Prüfung, die niemand angefordert hat.
     */
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
            .then(function (d) {
                box.innerHTML = '';
                (d.hinweise || []).forEach(function (h) {
                    var p = document.createElement('p');
                    p.className = 'pruef pruef-' + h.stufe;
                    p.textContent = h.text;
                    box.appendChild(p);
                });
            })
            .catch(function () { /* abgebrochen oder nicht erreichbar */ });
    }

    ['opt-u', 'opt-style', 'opt-eye', 'opt-fg', 'opt-bg', 'opt-ecc', 'opt-margin',
     'opt-logo', 'opt-ls', 'opt-size', 'opt-ftext', 'opt-mm',
     'opt-grad', 'opt-fg2', 'opt-ga',
     'opt-eyecore', 'opt-eyeown', 'opt-eyefg', 'opt-eyecorefg', 'opt-lshape', 'opt-bgnone',
     'opt-fgc-c', 'opt-fgc-m', 'opt-fgc-y', 'opt-fgc-k',
     'opt-bgc-c', 'opt-bgc-m', 'opt-bgc-y', 'opt-bgc-k'].forEach(function (id) {
        var el = $(id);
        if (!el) return;
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
    });

    // Farbvorlagen
    document.addEventListener('click', function (e) {
        var p = e.target.closest('[data-preset]');
        if (p && $('opt-fg') && $('opt-bg')) {
            var teile = p.getAttribute('data-preset').split('|');
            $('opt-fg').value = teile[0];
            $('opt-bg').value = teile[1];
            refresh();
            return;
        }
        var g = e.target.closest('[data-grad]');
        if (g && $('opt-grad')) {
            var t = g.getAttribute('data-grad').split('|');
            $('opt-grad').value = t[0];
            if ($('opt-ga')) $('opt-ga').value = t[1];
            if ($('opt-fg')) $('opt-fg').value = t[2];
            if ($('opt-fg2')) $('opt-fg2').value = t[3];
            refresh();
            return;
        }
        var b = e.target.closest('[data-pbg]');
        if (b) {
            var buehne = $('preview-stage');
            if (buehne) buehne.style.background = b.getAttribute('data-pbg');
            return;
        }
        if (e.target.id === 'ftext-preset' && $('opt-ftext')) {
            $('opt-ftext').value = 'Scan mich!';
            refresh();
        }
    });

    refresh();
})();
