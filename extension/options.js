// Einstellungen: Adresse und Zugangsschlüssel.
//
// Gespeichert wird nichts, was nicht vorher funktioniert hat: Der Knopf prüft
// die Angaben gegen /api/me und legt sie erst danach ab. Sonst steht der
// Fehler später im Popup, wo weniger Platz ist, ihn zu erklären.

const $ = (id) => document.getElementById(id);

function sauber(u) {
    u = u.trim().replace(/\/+$/, '');
    if (u && !/^https?:\/\//i.test(u)) u = 'https://' + u;
    // Die Adresse ist die STARTSEITE der Instanz, nicht die Seite, auf der man
    // gerade war. Wer seine Links verwaltet, hat /admin in der Adresszeile –
    // und trägt genau das hier ein. Das ergäbe /admin/api/me und damit 404.
    // Statt einer Fehlermeldung: die bekannten Enden abschneiden.
    u = u.replace(/\/(admin|index\.php|api|api\.php)(\/.*)?$/i, '');
    return u.replace(/\/+$/, '');
}

async function anzeigen() {
    const d = await chrome.storage.local.get(['instanz', 'token', 'konto']);
    $('instanz').value = d.instanz || '';
    $('token').value = d.token || '';
    if (d.konto) {
        $('stand').textContent = t('connectedAs', d.konto);
        $('stand').hidden = false;
    }
}

async function pruefen() {
    const knopf = $('pruefen');
    knopf.disabled = true;
    $('fehler').hidden = true;
    $('stand').hidden = true;

    const instanz = sauber($('instanz').value);
    const token = $('token').value.trim();
    $('instanz').value = instanz;

    try {
        if (!instanz || !token) throw new Error(t('errBothNeeded'));

        // Die Berechtigung für genau diese Adresse anfragen – die Erweiterung
        // verlangt bewusst keinen Zugriff auf „alle Seiten" im Voraus.
        const erlaubt = await chrome.permissions.request({ origins: [instanz + '/*'] });
        if (!erlaubt) throw new Error(t('errNoHostAccess'));

        // Zwei Wege zur Schnittstelle: /api/… setzt mod_rewrite voraus (die
        // mitgelieferte .htaccess), /api.php/… geht immer. Erst der schöne
        // Weg, bei 404 der sichere – so läuft die Erweiterung auch auf
        // Instanzen, deren Hoster keine Umschreibungen erlaubt.
        let antwort = await fetch(instanz + '/api/me', {
            headers: { 'Authorization': 'Bearer ' + token },
        });
        let pfad = '/api';
        if (antwort.status === 404) {
            antwort = await fetch(instanz + '/api.php/me', {
                headers: { 'Authorization': 'Bearer ' + token },
            });
            pfad = '/api.php';
        }
        if (antwort.status === 401) throw new Error(t('errKeyWrong'));
        if (antwort.status === 404) throw new Error(t('errNoApi'));
        if (!antwort.ok) throw new Error(t('errStatusAddress', antwort.status));

        const daten = await antwort.json();
        // Die Schnittstelle nennt das Feld „account"; der Anzeigename ist
        // optional und fehlt bei den meisten Konten.
        const konto = daten?.display_name || daten?.account || t('unknownAccount');

        // Der gefundene Weg wird gemerkt, damit das Popup nicht jedes Mal
        // beide durchprobieren muss.
        await chrome.storage.local.set({ instanz, token, konto, pfad });
        $('stand').textContent = t('savedConnectedAs', konto);
        $('stand').hidden = false;
    } catch (e) {
        $('fehler').textContent = e.message === 'Failed to fetch'
            ? t('errUnreachableShort')
            : e.message;
        $('fehler').hidden = false;
    } finally {
        knopf.disabled = false;
    }
}

async function loeschen() {
    await chrome.storage.local.remove(['instanz', 'token', 'konto']);
    $('instanz').value = '';
    $('token').value = '';
    $('stand').textContent = t('accessRemoved');
    $('stand').hidden = false;
    $('fehler').hidden = true;
}

/**
 * Verbindungscode auswerten und eintragen.
 *
 * Der Code trägt Adresse und Schlüssel; geprüft wird trotzdem, bevor etwas
 * gespeichert wird – ein abgetippter oder halb kopierter Code soll nicht
 * still danebengehen.
 */
async function einloesen() {
    const roh = $('code').value.trim();
    $('fehler').hidden = true;
    $('stand').hidden = true;
    try {
        if (!/^flc_/.test(roh)) throw new Error(t('errNotPairingCode'));
        const b64 = roh.slice(4).replace(/-/g, '+').replace(/_/g, '/');
        const daten = JSON.parse(decodeURIComponent(escape(atob(b64))));
        if (!daten.u || !daten.t) throw new Error(t('errCodeIncomplete'));
        $('instanz').value = daten.u;
        $('token').value = daten.t;
        $('code').value = '';
        await pruefen();
    } catch (e) {
        $('fehler').textContent = e instanceof SyntaxError
            ? t('errCodeUnreadable')
            : e.message;
        $('fehler').hidden = false;
    }
}

$('einloesen').addEventListener('click', einloesen);
$('code').addEventListener('keydown', (e) => { if (e.key === 'Enter') einloesen(); });
$('pruefen').addEventListener('click', pruefen);
$('loeschen').addEventListener('click', loeschen);
anzeigen();
