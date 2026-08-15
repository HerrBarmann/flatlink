/* Gestaltungs-Panel der QR-Generatoren – die eine Sammelstelle.
 *
 * inc/qrpanel.php gibt die Bedienelemente aus, dieses Skript liest sie: Es
 * sammelt die Werte in der Sprache der qr.php-Parameter ein, hält die Anzeige
 * des Panels aktuell (Verlaufswinkel, Augenfarben, Reglerwerte) und meldet
 * jede Änderung an die einbindende Seite. Der QR-Designer, die Typ-Seiten
 * (WLAN, Kontakt, Termin, GS1) und die QR-Serie benutzen alle dieses eine
 * Skript – deshalb kennen sie alle dieselben Optionen.
 *
 * Ohne Inline-Code, wie überall im Projekt (Content-Security-Policy). */
(function () {
    'use strict';

    /* Übersetzungen: page_footer() legt sie als JSON-Datenblock in die Seite;
     * fehlt der Block (deutsche Instanz), bleibt der deutsche Text. */
    var UEB = {};
    try { UEB = JSON.parse(document.getElementById('lang-js').textContent); } catch (e) {}
    function t(s) { return UEB[s] || s; }

    var $ = function (id) { return document.getElementById(id); };
    var wert = function (id) { var el = $(id); return el ? el.value : null; };

    /* Alle Werte des Panels an set(name, wert) übergeben – in genau der Form,
     * die qr.php erwartet. Nicht Gesetztes wird nicht übergeben. */
    function sammle(set) {
        ['style', 'eye', 'eyecore', 'fg', 'bg', 'ecc', 'margin', 'ls'].forEach(function (k) {
            var v = wert('opt-' + k);
            if (v !== null) set(k, v);
        });
        // Ein Farbfeld kann nicht „nichts" sein – deshalb ein eigener Schalter
        var ohne = $('opt-bgnone');
        if (ohne && ohne.checked) set('bg', 'none');
        var logo = wert('opt-logo');
        if (logo) {
            set('logo', logo);
            if (wert('opt-lshape')) set('lshape', wert('opt-lshape'));
        }
        var ftext = wert('opt-ftext');
        if (ftext && ftext.trim()) set('ftext', ftext.trim());
        // CMYK nur mitgeben, wenn alle vier Felder gefüllt sind – drei Werte
        // ergeben keine Farbe, und eine halb gesetzte Angabe wäre eine stille
        // Falle für die Druckerei.
        ['fgc', 'bgc'].forEach(function (n) {
            var v = ['c', 'm', 'y', 'k'].map(function (k) { return wert('opt-' + n + '-' + k); });
            if (v.every(function (x) { return x !== null && x !== ''; })) set(n, v.join(','));
        });
        var mm = wert('opt-mm');
        if (mm) set('mm', mm);
        // Augenfarben nur, wenn ausdrücklich gewünscht – sonst erben sie die
        // Farbe der Module, und genau das soll die Vorgabe bleiben.
        var eigen = $('opt-eyeown');
        if (eigen && eigen.checked) {
            if (wert('opt-eyefg')) set('eyefg', wert('opt-eyefg'));
            if (wert('opt-eyecorefg')) set('eyecorefg', wert('opt-eyecorefg'));
        }
        var grad = wert('opt-grad');
        if (grad) {
            set('grad', grad);
            if (wert('opt-fg2')) set('fg2', wert('opt-fg2'));
            if (wert('opt-ga')) set('ga', wert('opt-ga'));
        }
    }

    /* Die Anzeige des Panels nachziehen: Was gerade keine Frage ist, bleibt
     * verborgen; die Regler zeigen ihren Wert als Zahl. */
    function sync() {
        var gw = $('grad-winkel');
        if (gw) gw.hidden = wert('opt-grad') !== 'linear';
        var af = $('augenfarben'), ao = $('opt-eyeown');
        if (af && ao) af.hidden = !ao.checked;
        var gv = $('ga-val');
        if (gv && $('opt-ga')) gv.textContent = $('opt-ga').value;
        var m = $('margin-val'), l = $('ls-val');
        if (m && $('opt-margin')) m.textContent = $('opt-margin').value;
        if (l && $('opt-ls')) l.textContent = $('opt-ls').value;
    }

    var FELDER = ['opt-style', 'opt-eye', 'opt-eyecore', 'opt-eyeown', 'opt-eyefg',
        'opt-eyecorefg', 'opt-fg', 'opt-bg', 'opt-bgnone', 'opt-grad', 'opt-fg2',
        'opt-ga', 'opt-ecc', 'opt-margin', 'opt-ftext', 'opt-logo', 'opt-ls',
        'opt-lshape', 'opt-mm',
        'opt-fgc-c', 'opt-fgc-m', 'opt-fgc-y', 'opt-fgc-k',
        'opt-bgc-c', 'opt-bgc-m', 'opt-bgc-y', 'opt-bgc-k'];

    /* Auf jede Änderung im Panel hören und cb aufrufen. Auch die Vorlagen-
     * Knöpfe (Farben, Verläufe, Rahmentext) und der Umschalter des
     * Vorschau-Untergrunds laufen hier durch. */
    function bind(cb) {
        FELDER.forEach(function (id) {
            var el = $(id);
            if (!el) return;
            el.addEventListener('input', function () { sync(); cb(); });
            el.addEventListener('change', function () { sync(); cb(); });
        });
        document.addEventListener('click', function (e) {
            var p = e.target.closest('[data-preset]');
            if (p && $('opt-fg') && $('opt-bg')) {
                var teile = p.getAttribute('data-preset').split('|');
                $('opt-fg').value = teile[0];
                $('opt-bg').value = teile[1];
                sync(); cb();
                return;
            }
            var g = e.target.closest('[data-grad]');
            if (g && $('opt-grad')) {
                var tl = g.getAttribute('data-grad').split('|');
                $('opt-grad').value = tl[0];
                if ($('opt-ga')) $('opt-ga').value = tl[1];
                if ($('opt-fg')) $('opt-fg').value = tl[2];
                if ($('opt-fg2')) $('opt-fg2').value = tl[3];
                sync(); cb();
                return;
            }
            // Heller oder dunkler Untergrund für die Vorschau – rein optisch
            var b = e.target.closest('[data-pbg]');
            if (b) {
                var buehne = $('preview-stage');
                if (buehne) buehne.style.background = b.getAttribute('data-pbg');
                return;
            }
            if (e.target.id === 'ftext-preset' && $('opt-ftext')) {
                $('opt-ftext').value = t('Scan mich!');
                sync(); cb();
            }
        });
        sync();
    }

    /* Lesbarkeits-Hinweise vom Server in eine Box schreiben. Die Bewertung
     * kommt bewusst von dort: Die Schwellen gehören zu den Regeln des
     * Dienstes, nicht in ein Skript, das jeder abschalten kann. */
    function zeigeHinweise(box, daten) {
        if (!box) return;
        box.innerHTML = '';
        ((daten && daten.hinweise) || []).forEach(function (h) {
            var p = document.createElement('p');
            p.className = 'pruef pruef-' + h.stufe;
            p.textContent = h.text;
            box.appendChild(p);
        });
    }

    window.QRGestaltung = { sammle: sammle, bind: bind, zeigeHinweise: zeigeHinweise };
})();
