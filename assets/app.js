/* flatlink – gemeinsame Oberflächenlogik.
 *
 * Liegt bewusst als Datei vor und nicht inline: Nur so lässt sich eine
 * Content-Security-Policy ohne 'unsafe-inline' durchsetzen, die künftige
 * XSS-Funde in ihrer Wirkung begrenzt. Verhalten wird über data-Attribute
 * im Markup angefordert, nicht über on*-Handler.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        // <button data-copy="#feldId" data-copied="Kopiert ✓">
        var btn = e.target.closest('[data-copy]');
        if (!btn) return;
        var src = document.querySelector(btn.getAttribute('data-copy'));
        if (!src) return;
        var done = btn.getAttribute('data-copied') || 'Kopiert';
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
