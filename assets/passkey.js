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

    /* Die Antwort des Geräts in das JSON packen, das der Server erwartet.
     * userHandle liefert nur der Weg ohne vorher bekannte Kennung – dort sagt
     * es uns, welches Konto gemeint ist. */
    function antwort(c) {
        return JSON.stringify({
            id: c.id,
            clientDataJSON: b64u(c.response.clientDataJSON),
            authenticatorData: b64u(c.response.authenticatorData),
            signature: b64u(c.response.signature),
            userHandle: c.response.userHandle ? b64u(c.response.userHandle) : ''
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

        /* leise = die Abfrage lief von selbst los, nicht auf Klick. Dann darf
         * ein Fehlschlag keine rote Meldung produzieren: Wer nichts angefasst
         * hat, hat auch nichts falsch gemacht. Er bekommt kommentarlos das
         * Passwortfeld zurück und den Knopf, um es doch zu versuchen. */
        var leise = false;

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
                    return senden(url, { action: 'pk_verify', _csrf: csrf, daten: antwort(c) });
                });
            }).then(function (a) {
                if (a && a.ok) { window.location.href = a.redirect || window.location.pathname; return; }
                throw new Error((a && a.error) || t('Es hat nicht geklappt.'));
            }).catch(function (e) {
                var vonSelbst = leise;
                leise = false;
                btn.disabled = false;
                melden(box, vonSelbst ? '' : fehlertext(e), true);
            });
        });

        /* Sofortstart: Die Abfrage beginnt von selbst, sobald die Seite steht.
         * Ein echter Klick ist das nicht, aber die Browser lassen es zu, und
         * es erspart einen Klick, der ohnehin nur „ja, bitte" bedeutet.
         * Verweigert einer die Abfrage ohne Geste, landet man im catch oben –
         * kommentarlos, denn darunter steht das Passwortfeld ohnehin schon. */
        if (btn.getAttribute('data-sofort') === '1') {
            leise = true;
            setTimeout(function () { btn.click(); }, 60);
        }
    });

    /* ---- Schritt 1: der Vorschlag im Namensfeld -------------------------
     *
     * „Conditional mediation" heißt: Wir fragen im Hintergrund nach einem
     * Passkey, und der Browser zeigt das Ergebnis nicht als Fenster, sondern
     * als Eintrag in der Vorschlagsliste des Namensfelds. Wer keinen hat,
     * merkt von alledem nichts – deshalb wird hier auch kein Fehler gemeldet,
     * wenn es nicht klappt.
     *
     * Welches Konto gemeint war, sagt uns das Gerät hinterher selbst. */
    document.querySelectorAll('[data-passkey-cond]').forEach(function (form) {
        var url = form.getAttribute('data-passkey-cond') || '';
        var csrf = form.getAttribute('data-csrf') || '';
        var box = document.getElementById(form.getAttribute('data-status') || '');

        if (!vorhanden || !window.PublicKeyCredential.isConditionalMediationAvailable) return;

        /* Die Anfrage wartet, bis jemand einen Vorschlag anklickt – notfalls
         * ewig. Wer stattdessen tippt und abschickt, soll sie nicht im Rücken
         * behalten: Das Formular bricht sie beim Absenden ab. */
        var stop = new AbortController();
        form.addEventListener('submit', function () { stop.abort(); });

        window.PublicKeyCredential.isConditionalMediationAvailable().then(function (geht) {
            if (!geht) return;
            return senden(url, { action: 'pk_any_challenge', _csrf: csrf }).then(function (opt) {
                if (opt.error) throw new Error(opt.error);
                opt.challenge = ab(opt.challenge);
                opt.allowCredentials = [];
                return navigator.credentials.get({ publicKey: opt, mediation: 'conditional', signal: stop.signal });
            }).then(function (c) {
                melden(box, t('Warte auf dein Gerät …'), false);
                return senden(url, { action: 'pk_any_verify', _csrf: csrf, daten: antwort(c) });
            }).then(function (a) {
                if (a && a.ok) { window.location.href = a.redirect || window.location.pathname; return; }
                throw new Error((a && a.error) || t('Es hat nicht geklappt.'));
            });
        }).catch(function (e) {
            // Kein Passkey, abgebrochen, Fenster gewechselt: alles kein Fehler.
            // Nur wenn der Server etwas zu sagen hatte, sagen wir es weiter.
            if (e && e.name !== 'NotAllowedError' && e.name !== 'AbortError') {
                melden(box, fehlertext(e), true);
            }
        });
    });
})();
