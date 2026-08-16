// Das Popup: Adresse des aktuellen Tabs holen, über die Schnittstelle kürzen,
// Ergebnis zeigen.
//
// Was hier bewusst NICHT passiert: Es wird keine Seite gelesen, kein Skript
// eingespritzt, nichts an Dritte geschickt. Die Erweiterung braucht die
// Adresse des Tabs (activeTab) und redet ausschließlich mit der Instanz, die
// in den Einstellungen steht. Kein Analyse-Werkzeug, keine Fernwartung –
// dieselbe Haltung wie im Dienst selbst.

const $ = (id) => document.getElementById(id);
const zeige = (welche) => {
    for (const s of ['setup', 'kuerzen', 'fertig']) $(s).hidden = s !== welche;
};

let tabUrl = '';
let einst = {};

/** Einstellungen holen – bewusst storage.local: Ein Zugangsschlüssel hat in
 *  der Browser-Synchronisierung nichts verloren. */
async function laden() {
    const d = await chrome.storage.local.get(['instanz', 'token', 'pfad']);
    return {
        instanz: (d.instanz || '').replace(/\/+$/, ''),
        token: d.token || '',
        // Beim Einrichten wurde festgestellt, welcher Weg zur Schnittstelle
        // führt – /api (mit Umschreibung) oder /api.php (ohne).
        pfad: d.pfad || '/api',
    };
}

async function start() {
    einst = await laden();
    if (!einst.instanz || !einst.token) {
        zeige('setup');
        return;
    }

    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    tabUrl = tab?.url || '';
    // Interne Seiten des Browsers lassen sich nicht sinnvoll kürzen
    if (!/^https?:\/\//i.test(tabUrl)) {
        zeige('kuerzen');
        $('ziel').textContent = 'Diese Seite lässt sich nicht kürzen.';
        $('los').disabled = true;
        return;
    }

    zeige('kuerzen');
    $('ziel').textContent = tabUrl;
    $('ziel').title = tabUrl;
    // Der Seitentitel als Vorschlag – er landet nur in der eigenen Übersicht
    $('titel').value = (tab?.title || '').slice(0, 120);
    $('los').focus();
}

async function kuerzen() {
    const knopf = $('los');
    knopf.disabled = true;
    $('fehler').hidden = true;

    const rumpf = { url: tabUrl };
    const titel = $('titel').value.trim();
    const code = $('code').value.trim();
    if (titel) rumpf.title = titel;
    if (code) rumpf.code = code;

    try {
        const antwort = await fetch(einst.instanz + einst.pfad + '/links', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + einst.token,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(rumpf),
        });

        let daten = null;
        try { daten = await antwort.json(); } catch (e) { /* keine JSON-Antwort */ }

        if (!antwort.ok) {
            // Die Schnittstelle nennt den Grund im Klartext – den zeigen wir,
            // statt ihn hinter „Fehler" zu verstecken.
            const grund = daten?.error?.message
                || (antwort.status === 401 ? 'Der Zugangsschlüssel gilt nicht (mehr).'
                : antwort.status === 403 ? 'Diesem Konto fehlt die Berechtigung dafür.'
                : antwort.status === 429 ? 'Zu viele Anfragen – bitte kurz warten.'
                : 'Die Instanz antwortet mit ' + antwort.status + '.');
            throw new Error(grund);
        }

        // Ausdrücklich KEIN Rückfall auf daten.url: Das ist die lange
        // Zieladresse. Sie hier als Kurzlink anzuzeigen wäre schlimmer als
        // ein Fehler – kopiert würde etwas, das nur so aussieht wie das
        // Ergebnis.
        const kurz = daten?.short_url;
        if (!kurz) throw new Error('Die Antwort enthielt keinen Kurzlink.');

        $('kurzlink').textContent = kurz;
        $('kurzlink').href = kurz;
        $('qrbild').hidden = true;
        $('kopiert').hidden = true;
        zeige('fertig');
        $('kopieren').focus();
    } catch (e) {
        // Ein abgelehnter fetch heißt meist: Adresse falsch, Instanz nicht
        // erreichbar, oder die Host-Berechtigung fehlt.
        $('fehler').textContent = e.message === 'Failed to fetch'
            ? 'Die Instanz ist nicht erreichbar. Adresse in den Einstellungen prüfen – und dort die Berechtigung erteilen.'
            : e.message;
        $('fehler').hidden = false;
    } finally {
        knopf.disabled = false;
    }
}

async function kopieren() {
    await navigator.clipboard.writeText($('kurzlink').textContent);
    $('kopiert').hidden = false;
}

function qrZeigen() {
    const bild = $('qrbild');
    if (!bild.hidden) { bild.hidden = true; return; }
    // Der QR-Code kommt aus der eigenen Instanz, nicht von einem fremden
    // Dienst – sonst würde jeder gekürzte Link nebenbei woanders bekannt.
    const code = $('kurzlink').textContent.split('/').pop();
    bild.src = einst.instanz + '/qr.php?c=' + encodeURIComponent(code) + '&format=png&size=512';
    bild.hidden = false;
}

function zuruecksetzen() {
    $('code').value = '';
    $('fehler').hidden = true;
    zeige('kuerzen');
    $('los').focus();
}

$('los').addEventListener('click', kuerzen);
$('kopieren').addEventListener('click', kopieren);
$('qr').addEventListener('click', qrZeigen);
$('neu').addEventListener('click', zuruecksetzen);
$('zu-optionen').addEventListener('click', () => chrome.runtime.openOptionsPage());
// Eingabetaste in einem der Felder kürzt ebenfalls
for (const feld of ['titel', 'code']) {
    $(feld).addEventListener('keydown', (e) => { if (e.key === 'Enter') kuerzen(); });
}

start();
