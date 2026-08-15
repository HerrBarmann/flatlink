/* Passkeys: Brücke zwischen unserem JSON und der WebAuthn-Schnittstelle.
 *
 * Die Schnittstelle des Browsers nimmt und liefert ausschließlich Binärdaten;
 * JSON kann die nicht tragen. Alles, was hier passiert, ist deshalb Umpacken –
 * geprüft wird nichts davon im Browser, sondern ausnahmslos auf dem Server.
 * Dieses Skript darf man ohne Sicherheitsverlust lesen, ändern und umgehen.
 *
 * Ohne Inline-Code, wie überall im Projekt (Content-Security-Policy). */
(function () {
    'use strict';

    /* Übersetzungen: page_footer() legt sie als JSON-Datenblock in die Seite
     * (die CSP erlaubt keine ausführbaren Inline-Skripte, Datenblöcke schon).
     * Ohne Block – auf einer deutschen Instanz – bleibt der deutsche Text. */
    var UEB = {};
    try { UEB = JSON.parse(document.getElementById('lang-js').textContent); } catch (e) {}
    function t(s) { return UEB[s] || s; }

    var vorhanden = window.PublicKeyCredential && navigator.credentials;

    function ab(b64u) {                       // base64url -> ArrayBuffer
        var s = b64u.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = atob(s), arr = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        return arr.buffer;
    }

    function b64u(buf) {                       // ArrayBuffer -> base64url
        var arr = new Uint8Array(buf), s = '';
        for (var i = 0; i < arr.length; i++) s += String.fromCharCode(arr[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function idsUmbauen(liste) {
        return (liste || []).map(function (c) {
            return { type: c.type, id: ab(c.id) };
        });
    }

    function melden(box, text, fehler) {
        if (!box) return;
        box.textContent = text;
        box.className = 'flash ' + (fehler ? 'flash-err' : 'flash-ok');
        box.style.display = text ? '' : 'none';
    }

    function senden(url, felder) {
        var body = new URLSearchParams();
        Object.keys(felder).forEach(function (k) { body.append(k, felder[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    /* Der Browser meldet Abbruch und „geht hier nicht" mit derselben
     * Ausnahme. Für den Benutzer sind das zwei sehr verschiedene Dinge. */
    function fehlertext(e) {
        if (e && e.name === 'NotAllowedError') return t('Abgebrochen oder zu lange gewartet.');
        if (e && e.name === 'InvalidStateError') return t('Dieses Gerät ist hier bereits hinterlegt.');
        if (e && e.name === 'SecurityError') return t('Passkeys brauchen eine gesicherte Verbindung (HTTPS).');
        return (e && e.message) ? e.message : t('Es hat nicht geklappt.');
    }

    document.querySelectorAll('[data-passkey]').forEach(function (btn) {
        var modus = btn.getAttribute('data-passkey');          // 'register' | 'login'
        var url = btn.getAttribute('data-url') || '';
        var csrf = btn.getAttribute('data-csrf') || '';
        var box = document.getElementById(btn.getAttribute('data-status') || '');

        if (!vorhanden) {
            btn.disabled = true;
            melden(box, t('Dieser Browser kennt keine Passkeys.'), true);
            return;
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            melden(box, modus === 'login' ? t('Warte auf dein Gerät …') : t('Folge der Abfrage deines Geräts …'), false);

            senden(url, { action: 'pk_challenge', _csrf: csrf }).then(function (opt) {
                if (opt.error) throw new Error(opt.error);
                opt.challenge = ab(opt.challenge);

                if (modus === 'register') {
                    opt.user.id = ab(opt.user.id);
                    opt.excludeCredentials = idsUmbauen(opt.excludeCredentials);
                    return navigator.credentials.create({ publicKey: opt }).then(function (c) {
                        var label = document.getElementById(btn.getAttribute('data-label') || '');
                        return senden(url, {
                            action: 'pk_register',
                            _csrf: csrf,
                            label: label ? label.value : '',
                            daten: JSON.stringify({
                                clientDataJSON: b64u(c.response.clientDataJSON),
                                attestationObject: b64u(c.response.attestationObject)
                            })
                        });
                    });
                }

                opt.allowCredentials = idsUmbauen(opt.allowCredentials);
                return navigator.credentials.get({ publicKey: opt }).then(function (c) {
                    return senden(url, {
                        action: 'pk_verify',
                        _csrf: csrf,
                        daten: JSON.stringify({
                            id: c.id,
                            clientDataJSON: b64u(c.response.clientDataJSON),
                            authenticatorData: b64u(c.response.authenticatorData),
                            signature: b64u(c.response.signature)
                        })
                    });
                });
            }).then(function (a) {
                if (a && a.ok) { window.location.href = a.redirect || window.location.pathname; return; }
                throw new Error((a && a.error) || t('Es hat nicht geklappt.'));
            }).catch(function (e) {
                melden(box, fehlertext(e), true);
                btn.disabled = false;
            });
        });
    });
})();
