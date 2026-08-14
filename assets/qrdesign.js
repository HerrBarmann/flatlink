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
    var $ = function (id) { return document.getElementById(id); };
    var wert = function (id) { var el = $(id); return el ? el.value : null; };

    function params(extra) {
        var p = new URLSearchParams({ c: code });
        ['style', 'eye', 'fg', 'bg', 'ecc', 'margin', 'ls'].forEach(function (k) {
            var v = wert('opt-' + k);
            if (v !== null) p.set(k, v);
        });
        var logo = wert('opt-logo');
        if (logo) p.set('logo', logo);
        var ftext = wert('opt-ftext');
        if (ftext && ftext.trim()) p.set('ftext', ftext.trim());
        for (var k2 in extra) p.set(k2, extra[k2]);
        return p.toString();
    }

    function setzeHref(id, extra) {
        var el = $(id);
        if (el) el.href = basis + '?' + params(extra);
    }

    function refresh() {
        var m = $('margin-val'), l = $('ls-val');
        if (m && $('opt-margin')) m.textContent = $('opt-margin').value;
        if (l && $('opt-ls')) l.textContent = $('opt-ls').value;
        var vorschau = $('qr-preview');
        if (vorschau) vorschau.src = basis + '?' + params({ size: 320 });
        setzeHref('dl-svg', { format: 'svg', download: 1 });
        setzeHref('dl-png', { format: 'png', size: wert('opt-size') || 1024, download: 1 });
        setzeHref('dl-pdf', { format: 'pdf', download: 1 });
    }

    ['opt-style', 'opt-eye', 'opt-fg', 'opt-bg', 'opt-ecc', 'opt-margin',
     'opt-logo', 'opt-ls', 'opt-size', 'opt-ftext'].forEach(function (id) {
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
