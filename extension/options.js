// Einstellungen: Adresse und Zugangsschlüssel.
//
// Gespeichert wird nichts, was nicht vorher funktioniert hat: Der Knopf prüft
// die Angaben gegen /api/me und legt sie erst danach ab. Sonst steht der
// Fehler später im Popup, wo weniger Platz ist, ihn zu erklären.

const $ = (id) => document.getElementById(id);

function sauber(u) {
    u = u.trim().replace(/\/+$/, '');
    if (u && !/^https?:\/\//i.test(u)) u = 'https://' + u;
    return u;
}

async function anzeigen() {
    const d = await chrome.storage.local.get(['instanz', 'token', 'konto']);
    $('instanz').value = d.instanz || '';
    $('token').value = d.token || '';
    if (d.konto) {
        $('stand').textContent = 'Verbunden als ' + d.konto + '.';
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
        if (!instanz || !token) throw new Error('Adresse und Zugangsschlüssel werden beide gebraucht.');

        // Die Berechtigung für genau diese Adresse anfragen – die Erweiterung
        // verlangt bewusst keinen Zugriff auf „alle Seiten" im Voraus.
        const erlaubt = await chrome.permissions.request({ origins: [instanz + '/*'] });
        if (!erlaubt) throw new Error('Ohne Zugriff auf diese Adresse kann die Erweiterung nicht mit deiner Instanz sprechen.');

        const antwort = await fetch(instanz + '/api/me', {
            headers: { 'Authorization': 'Bearer ' + token },
        });
        if (antwort.status === 401) throw new Error('Der Zugangsschlüssel gilt nicht. Stimmt er, und gehört er zu dieser Instanz?');
        if (!antwort.ok) throw new Error('Die Instanz antwortet mit ' + antwort.status + '. Ist die Adresse richtig?');

        const daten = await antwort.json();
        // Die Schnittstelle nennt das Feld „account"; der Anzeigename ist
        // optional und fehlt bei den meisten Konten.
        const konto = daten?.display_name || daten?.account || 'unbekannt';

        await chrome.storage.local.set({ instanz, token, konto });
        $('stand').textContent = 'Gespeichert. Verbunden als ' + konto + '.';
        $('stand').hidden = false;
    } catch (e) {
        $('fehler').textContent = e.message === 'Failed to fetch'
            ? 'Die Instanz ist unter dieser Adresse nicht erreichbar.'
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
    $('stand').textContent = 'Zugang entfernt. Der Schlüssel selbst bleibt in deiner Instanz gültig – dort zurückziehen, wenn er weg soll.';
    $('stand').hidden = false;
    $('fehler').hidden = true;
}

$('pruefen').addEventListener('click', pruefen);
$('loeschen').addEventListener('click', loeschen);
anzeigen();
