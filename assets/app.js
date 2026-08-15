/* flatlink – gemeinsame Oberflächenlogik.
 *
 * Liegt bewusst als Datei vor und nicht inline: Nur so lässt sich eine
 * Content-Security-Policy ohne 'unsafe-inline' durchsetzen, die künftige
 * XSS-Funde in ihrer Wirkung begrenzt. Verhalten wird über data-Attribute
 * im Markup angefordert, nicht über on*-Handler.
 */
(function () {

    /* Übersetzungen: page_footer() legt sie als JSON-Datenblock in die Seite
     * (die CSP erlaubt keine ausführbaren Inline-Skripte, Datenblöcke schon).
     * Ohne Block – auf einer deutschen Instanz – bleibt der deutsche Text. */
    var UEB = {};
    try { UEB = JSON.parse(document.getElementById('lang-js').textContent); } catch (e) {}
    function t(s) { return UEB[s] || s; }
    'use strict';

    document.addEventListener('click', function (e) {
        // <button data-copy="#feldId" data-copied="Kopiert ✓">
        var btn = e.target.closest('[data-copy]');
        if (!btn) return;
        var src = document.querySelector(btn.getAttribute('data-copy'));
        if (!src) return;
        var done = btn.getAttribute('data-copied') || t('Kopiert');
        navigator.clipboard.writeText(src.value || src.textContent).then(function () {
            btn.textContent = done;
        });
    });

    // <select data-autosubmit> – Auswahl schickt das Formular ab
    document.addEventListener('change', function (e) {
        var el = e.target.closest('[data-autosubmit]');
        if (el && el.form) el.form.submit();
    });

    // <form data-confirm="Wirklich löschen?">
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-confirm]');
        if (form && !window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });

    // Die Verwaltungsklappe schließt sich, wenn daneben oder Escape geklickt
    // wird. Ohne JavaScript bleibt sie offen, bis man sie wieder anklickt –
    // funktioniert also auch dann, nur weniger geschmeidig.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.nav-more[open]').forEach(function (d) {
            if (!d.contains(e.target)) d.open = false;
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('details.nav-more[open]').forEach(function (d) {
            d.open = false;
            var s = d.querySelector('summary');
            if (s) s.focus();
        });
    });

    // Wiederholbare Feldzeilen: <button data-add-row="#container"> hängt eine
    // Kopie der letzten Zeile an, <button data-remove-row> entfernt die eigene.
    // Die letzte Zeile bleibt immer stehen – ein Formular ganz ohne Felder wäre
    // eine Sackgasse.
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-add-row]');
        if (add) {
            var box = document.querySelector(add.getAttribute('data-add-row'));
            if (!box) return;
            var rows = box.querySelectorAll('[data-row]');
            if (!rows.length) return;
            var copy = rows[rows.length - 1].cloneNode(true);
            copy.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            box.appendChild(copy);
            var first = copy.querySelector('input');
            if (first) first.focus();
            return;
        }
        var mv = e.target.closest('[data-move-row]');
        if (mv) {
            var r = mv.closest('[data-row]');
            if (!r) return;
            var hoch = mv.getAttribute('data-move-row') === 'up';
            var nachbar = hoch ? r.previousElementSibling : r.nextElementSibling;
            // Nur echte Zeilen tauschen – zwischen ihnen kann anderes stehen
            while (nachbar && !nachbar.hasAttribute('data-row')) {
                nachbar = hoch ? nachbar.previousElementSibling : nachbar.nextElementSibling;
            }
            if (!nachbar) return;
            if (hoch) r.parentNode.insertBefore(r, nachbar);
            else r.parentNode.insertBefore(nachbar, r);
            // Den Knopf weiter unter dem Finger behalten, sonst muss man nach
            // jedem Schritt neu zielen
            mv.focus();
            return;
        }
        var rm = e.target.closest('[data-remove-row]');
        if (rm) {
            var row = rm.closest('[data-row]');
            if (!row) return;
            var parent = row.parentNode;
            if (parent.querySelectorAll('[data-row]').length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            }
        }
    });

    // <input type="checkbox" data-checkall="codes[]"> schaltet alle Kästchen
    // dieses Namens im selben Formular um. Ohne diesen Helfer müsste man bei
    // einer Serie jedes einzeln anklicken – und der Zweck der Serie ist ja,
    // sich das zu sparen.
    document.addEventListener('change', function (e) {
        var alle = e.target.closest('[data-checkall]');
        if (!alle || !alle.form) return;
        var name = alle.getAttribute('data-checkall');
        alle.form.querySelectorAll('input[type=checkbox][name="' + name + '"]').forEach(function (c) {
            if (!c.disabled) c.checked = alle.checked;
        });
    });

    // Absicherung: Enter in einem Eingabefeld schickt das Formular ab, auch
    // wenn Autofill oder Erweiterungen die implizite Übermittlung schlucken.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.defaultPrevented) return;
        var t = e.target;
        if (t && t.tagName === 'INPUT' && t.type !== 'submit' && t.form && t.form.hasAttribute('data-enter-submit')) {
            e.preventDefault();
            if (t.form.requestSubmit) t.form.requestSubmit(); else t.form.submit();
        }
    });
})();
