/* QR-Designer – Vorschau und Download-Adressen aktualisieren.
 * Der Kurz-Code kommt über data-code aus dem Markup, damit hier kein
 * PHP eingebettet werden muss und die CSP ohne Inline-Skripte auskommt.
 */
(function () {
    var stage = document.getElementById('qr-stage');
    var code = stage ? stage.getAttribute('data-code') : '';
    var $ = function (id) { return document.getElementById(id); };

    function params(extra) {
        var p = new URLSearchParams({
            c: code,
            style: $('opt-style').value,
            eye: $('opt-eye').value,
            fg: $('opt-fg').value,
            bg: $('opt-bg').value,
            ecc: $('opt-ecc').value,
            margin: $('opt-margin').value,
            ls: $('opt-ls').value
        });
        if ($('opt-logo').value) p.set('logo', $('opt-logo').value);
        if ($('opt-ftext').value.trim()) p.set('ftext', $('opt-ftext').value.trim());
        for (var k in extra) p.set(k, extra[k]);
        return p.toString();
    }

    function refresh() {
        $('margin-val').textContent = $('opt-margin').value;
        $('ls-val').textContent = $('opt-ls').value;
        $('qr-preview').src = '../qr.php?' + params({ size: 320 });
        $('dl-svg').href = '../qr.php?' + params({ format: 'svg', download: 1 });
        $('dl-png').href = '../qr.php?' + params({ format: 'png', size: $('opt-size').value, download: 1 });
        $('dl-pdf').href = '../qr.php?' + params({ format: 'pdf', download: 1 });
    }

    ['opt-style', 'opt-eye', 'opt-fg', 'opt-bg', 'opt-ecc', 'opt-margin', 'opt-logo', 'opt-ls', 'opt-size', 'opt-ftext'].forEach(function (id) {
        $(id).addEventListener('input', refresh);
        $(id).addEventListener('change', refresh);
    });

    document.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var p = btn.getAttribute('data-preset').split('|');
            $('opt-fg').value = p[0];
            $('opt-bg').value = p[1];
            refresh();
        });
    });

    $('ftext-preset').addEventListener('click', function () {
        $('opt-ftext').value = 'Scan mich!';
        autoMargin();
        refresh();
    });

    // Mit Rahmen wirkt die volle Quiet-Zone doppelt – Rand automatisch auf 2 Module
    // verringern (und zurück), solange der Nutzer den Regler nicht selbst verstellt hat
    function autoMargin() {
        var m = $('opt-margin');
        var has = $('opt-ftext').value.trim() !== '';
        if (has && m.value === '4') m.value = '2';
        if (!has && m.value === '2') m.value = '4';
    }
    $('opt-ftext').addEventListener('input', autoMargin);

    document.querySelectorAll('[data-pbg]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            $('preview-stage').style.background = btn.getAttribute('data-pbg');
        });
    });
    refresh();
})();
