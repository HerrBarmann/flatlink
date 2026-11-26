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
        // <button data-copy="#feldId"> kopiert den Inhalt eines Elements,
        // <button data-copy-text="…"> den angegebenen Text selbst
        var btn = e.target.closest('[data-copy], [data-copy-text]');
        if (!btn) return;
        var text = btn.getAttribute('data-copy-text');
        if (text === null) {
            var src = document.querySelector(btn.getAttribute('data-copy'));
            if (!src) return;
            text = src.value || src.textContent;
        }
        var done = btn.getAttribute('data-copied') || t('Kopiert');
        navigator.clipboard.writeText(text).then(function () {
            btn.textContent = done;
        });
    });

    // <select data-autosubmit> – Auswahl schickt das Formular ab
    document.addEventListener('change', function (e) {
        var el = e.target.closest('[data-autosubmit]');
        if (el && el.form) el.form.submit();
    });

    // <input data-mirror="#ziel" data-mirror-empty="…"> – schreibt die Eingabe
    // beim Tippen in ein anderes Element (Vorschau). War bis 5.4 ein oninput-
    // Attribut, das die CSP (script-src 'self') stillschweigend verworfen hat:
    // Die Vorschau des Präfixes in den Grundregeln reagierte nie.
    document.addEventListener('input', function (e) {
        var el = e.target.closest('[data-mirror]');
        if (!el) return;
        var ziel = document.querySelector(el.getAttribute('data-mirror'));
        if (ziel) ziel.textContent = el.value || el.getAttribute('data-mirror-empty') || '';
    });

    // <input readonly data-select> – ein Klick markiert den ganzen Inhalt
    // (Verbindungscode zum Abschreiben). Ebenfalls früher ein onclick, das die
    // CSP verworfen hat.
    document.addEventListener('click', function (e) {
        var el = e.target.closest('input[data-select]');
        if (el) el.select();
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

    // Weichen: Das Wertfeld richtet sich nach dem gewählten Merkmal.
    //
    // „mobile / en / at / 50" als Platzhalter für alle vier Merkmale zugleich
    // sagt niemandem, was jetzt gerade gefragt ist. Bei „Anteil" wird daraus
    // ein Zahlenfeld mit Grenzen, sonst ein Textfeld mit passenden Vorschlägen.
    // Ohne dieses Skript bleibt es beim Freitext – der Server prüft ohnehin.
    function weichenFeld(auswahl) {
        var zeile = auswahl.closest('.weiche');
        if (!zeile) return;
        var wert = zeile.querySelector('input[name^="ri["]');
        if (!wert) return;
        var art = auswahl.value;
        if (art === 'split') {
            wert.type = 'number';
            wert.min = '1';
            wert.max = '99';
            wert.placeholder = '30';
            wert.removeAttribute('list');
            wert.title = 'Anteil in Prozent, 1 bis 99';
        } else {
            wert.type = 'text';
            wert.removeAttribute('min');
            wert.removeAttribute('max');
            wert.setAttribute('list', art === 'device' ? 'weichen-geraete' : 'weichen-sprachen');
            wert.placeholder = art === 'device' ? 'mobile' : (art === 'lang' ? 'en' : 'at');
            wert.title = art === 'device'
                ? 'mobile, tablet oder desktop'
                : 'zwei Buchstaben, z. B. ' + (art === 'lang' ? 'de oder en' : 'at oder ch');
        }
    }
    document.addEventListener('change', function (e) {
        if (e.target.matches('.weiche select')) weichenFeld(e.target);
    });
    document.querySelectorAll('.weiche select').forEach(weichenFeld);

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

/* Ein Anker, der in einem eingeklappten Abschnitt liegt, öffnet ihn.
 * Der Server erledigt das für eigene Rücksprünge über ?zeige=…; das hier
 * deckt Lesezeichen und von Hand getippte #anker ab. */
(function () {
    'use strict';
    function oeffne() {
        if (!location.hash) return;
        var el = document.getElementById(location.hash.slice(1));
        if (!el) return;
        var d = el.tagName === 'DETAILS' ? el : el.closest('details');
        if (d) d.open = true;
    }
    window.addEventListener('hashchange', oeffne);
    oeffne();
})();

/* ---- Suche überall ('nav_search') -------------------------------------------
 *
 * Das Feld im Kopf schickt ohne Skript an admin/suche.php. Mit Skript öffnen
 * Tippen ins Feld, Absenden, Strg/⌘+K oder der Tab „Suche" einen Dialog; er
 * holt beim Tippen dieselbe Seite und setzt ihre .suche-ergebnisse ein. Was
 * gefunden wird und was das Konto sehen darf, entscheidet allein der Server. */
(function () {
    'use strict';
    var UEB = {};
    try { UEB = JSON.parse(document.getElementById('lang-js').textContent); } catch (e) {}
    function t(s) { return UEB[s] || s; }

    document.documentElement.classList.add('js');
    var kopf = document.querySelector('form[data-palette]');
    var dlg = kopf ? document.querySelector(kopf.getAttribute('data-palette')) : null;
    if (!kopf || !dlg || typeof dlg.showModal !== 'function') return;
    var form = dlg.querySelector('form');
    var feld = form.querySelector('input[name=q]');
    var aus = dlg.querySelector('.suche-ergebnisse');
    var kopfFeld = kopf.querySelector('input[name=q]');
    var lauf = 0, warte = null;

    if (!/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {
        document.querySelectorAll('[data-palette-key]').forEach(function (k) { k.textContent = t('Strg K'); });
    }

    function treffer() { return Array.prototype.slice.call(aus.querySelectorAll('.suche-treffer')); }
    function aktuell() {
        var alle = treffer();
        for (var i = 0; i < alle.length; i++) if (alle[i].classList.contains('is-current')) return i;
        return -1;
    }
    function markieren(i) {
        treffer().forEach(function (a, j) {
            a.classList.toggle('is-current', j === i);
            if (j === i) a.scrollIntoView({ block: 'nearest' });
        });
    }
    function hinweis(text) {
        aus.textContent = '';
        var p = document.createElement('p');
        p.className = 'suche-nichts';
        p.textContent = text;
        aus.appendChild(p);
    }
    function suchen() {
        var q = feld.value.trim();
        if (q.length < 2) { aus.textContent = ''; return; }
        var nr = ++lauf;
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                if (nr !== lauf) return;   // eine neuere Eingabe ist schon unterwegs
                var neu = new DOMParser().parseFromString(html, 'text/html').querySelector('.suche-ergebnisse');
                if (!neu) { hinweis(t('Die Suche hat nicht geklappt.')); return; }
                aus.replaceChildren.apply(aus, Array.prototype.map.call(neu.childNodes, function (n) {
                    return document.importNode(n, true);
                }));
                if (treffer().length) markieren(0);
                else if (!aus.textContent.trim()) hinweis(t('Nichts gefunden.'));
            })
            .catch(function () { if (nr === lauf) hinweis(t('Die Suche hat nicht geklappt.')); });
    }
    function oeffnen(text) {
        if (!dlg.open) dlg.showModal();
        if (typeof text === 'string') feld.value = text;
        feld.focus();
        try { feld.setSelectionRange(feld.value.length, feld.value.length); } catch (e) {}
        suchen();
    }

    kopf.addEventListener('submit', function (e) { e.preventDefault(); oeffnen(kopfFeld.value); });
    kopfFeld.addEventListener('input', function () { var v = kopfFeld.value; kopfFeld.value = ''; oeffnen(v); });
    document.querySelectorAll('[data-palette-open]').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); oeffnen(''); });
    });
    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); oeffnen(); }
    });
    // Enter öffnet den markierten Treffer; ohne Treffer wird gesucht.
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var i = aktuell();
        if (i >= 0) window.location.href = treffer()[i].href; else suchen();
    });
    feld.addEventListener('input', function () { clearTimeout(warte); warte = setTimeout(suchen, 150); });
    feld.addEventListener('keydown', function (e) {
        var n = treffer().length;
        if (!n) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); markieren((aktuell() + 1) % n); }
        if (e.key === 'ArrowUp') { e.preventDefault(); markieren((aktuell() - 1 + n) % n); }
    });
    // Ein Klick neben den Dialog (auf die abgedunkelte Fläche) schließt ihn.
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
})();
