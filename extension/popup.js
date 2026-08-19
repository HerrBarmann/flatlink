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
        $('ziel').textContent = t('cannotShorten');
        $('los').disabled = true;
        return;
    }

    // Der Weg in die Verwaltung steht unabhängig vom Ergebnis
    $('zur-verwaltung-2').href = einst.instanz + '/admin/';

    zeige('kuerzen');
    $('ziel').textContent = tabUrl;
    $('ziel').title = tabUrl;
    // Der Seitentitel als Vorschlag – er landet nur in der eigenen Übersicht
    $('titel').value = (tab?.title || '').slice(0, 120);
    $('los').focus();

    // Beides nebenher: Das Formular steht schon, die Antworten füllen es auf.
    // Wer sofort auf „Kürzen" drückt, wartet auf nichts.
    konto();
    schonGekuerzt();
}

/**
 * Was dieses Konto darf: Domains, Gruppen, verbleibender Rahmen.
 *
 * Ohne das fiele jeder Kurzlink auf die Hauptdomain, auch wenn die Instanz
 * mehrere führt – und in Teams landete alles ohne Gruppe. Beide Felder
 * erscheinen nur, wenn es etwas zu wählen gibt; ein Auswahlfeld mit einem
 * Eintrag ist eine Zumutung, keine Funktion.
 */
async function konto() {
    try {
        const antwort = await fetch(einst.instanz + einst.pfad + '/me', {
            headers: { 'Authorization': 'Bearer ' + einst.token },
        });
        if (!antwort.ok) return;
        const d = await antwort.json();

        // Einträge werden gebaut, nicht als HTML zusammengesetzt: Domains und
        // Gruppennamen kommen zwar aus der eigenen Instanz, sind aber von
        // Menschen vergeben. Ein Anführungszeichen im Namen zerlegte sonst das
        // Attribut, spitze Klammern brächten Markup ins Popup.
        const option = (wert, text) => {
            const o = document.createElement('option');
            o.value = wert;
            o.textContent = text;
            return o;
        };

        const domains = (d.domains || []).filter(Boolean);
        if (domains.length > 1) {
            const feld = $('domain');
            feld.replaceChildren(...domains.map((x, i) =>
                option(x, i === 0 ? t('domainDefault', x) : x)));
            $('domain-block').hidden = false;
        }

        const gruppen = (d.assignable_groups || []).filter(Boolean);
        if (gruppen.length > 0) {
            const feld = $('gruppe');
            feld.replaceChildren(option('', t('groupNone')), ...gruppen.map(g => option(g, g)));
            $('gruppe-block').hidden = false;
        }

        // Der Rahmen interessiert erst, wenn er knapp wird – vorher ist er
        // Zahlensalat neben einem Knopf.
        const grenze = parseInt(d.limits?.links, 10);
        const belegt = parseInt(d.limits?.links_used, 10);
        if (Number.isFinite(grenze) && Number.isFinite(belegt) && grenze > 0 && belegt / grenze >= 0.8) {
            $('rahmen').textContent = t('quotaUsed', belegt, grenze);
        }
    } catch (e) { /* Ohne diese Angaben geht es auch – nur eben ohne Auswahl */ }
}

/**
 * Gibt es für diese Adresse schon einen Kurzlink?
 *
 * Wer eine Seite zweimal kürzt, bekommt zwei Kurzlinks auf dasselbe Ziel –
 * beide gültig, beide gedruckt vielleicht. Die Suche kostet eine Anfrage und
 * erspart das.
 */
async function schonGekuerzt() {
    try {
        const antwort = await fetch(
            einst.instanz + einst.pfad + '/links?limit=5&q=' + encodeURIComponent(tabUrl),
            { headers: { 'Authorization': 'Bearer ' + einst.token } });
        if (!antwort.ok) return;
        const d = await antwort.json();
        // Die Suche findet auch Teiltreffer; gemeint ist nur die Adresse selbst
        const treffer = (d.links || []).find(l => l.url === tabUrl);
        if (!treffer?.short_url) return;
        $('schon-link').textContent = treffer.short_url;
        $('schon-link').href = treffer.short_url;
        $('schon').hidden = false;
    } catch (e) { /* Ein fehlgeschlagener Blick ist kein Grund zu meckern */ }
}

async function kuerzen() {
    const knopf = $('los');
    knopf.disabled = true;
    $('fehler').hidden = true;

    const rumpf = { url: tabUrl };
    const titel = $('titel').value.trim();
    const code = $('code').value.trim();
    const tags = $('tags').value.trim();
    const expires = $('expires').value;
    if (titel) rumpf.title = titel;
    if (code) rumpf.code = code;
    if (tags) rumpf.tags = tags;
    if (expires) rumpf.expires = expires;
    // Die erste Domain ist die Hauptdomain – sie mitzuschicken wäre
    // überflüssig, aber schadet nicht; die Schnittstelle behandelt sie so.
    if (!$('domain-block').hidden) rumpf.domain = $('domain').value;
    if (!$('gruppe-block').hidden && $('gruppe').value) rumpf.group = $('gruppe').value;

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
                || (antwort.status === 401 ? t('errKeyInvalid')
                : antwort.status === 403 ? t('errNoPermission')
                : antwort.status === 429 ? t('errTooMany')
                : t('errStatus', antwort.status));
            throw new Error(grund);
        }

        // Ausdrücklich KEIN Rückfall auf daten.url: Das ist die lange
        // Zieladresse. Sie hier als Kurzlink anzuzeigen wäre schlimmer als
        // ein Fehler – kopiert würde etwas, das nur so aussieht wie das
        // Ergebnis.
        const kurz = daten?.short_url;
        if (!kurz) throw new Error(t('errNoLink'));

        $('kurzlink').textContent = kurz;
        $('kurzlink').href = kurz;

        // Der Code zum Abscannen. qr.php ist öffentlich – ein Bild braucht
        // keinen Schlüssel, weil der Code zum Link gehört und nicht zum
        // Konto. Deshalb genügt hier ein <img>; kein fetch, keine
        // Host-Berechtigung, kein Zwischenspeichern.
        const qr = (fmt, extra) => einst.instanz + '/qr.php?c='
            + encodeURIComponent(daten.code || kurz.split('/').pop())
            + '&format=' + fmt + extra;
        $('qr-bild').src = qr('svg', '&size=300');
        $('qr-png').href = qr('png', '&size=1024&download=1');
        $('qr-block').hidden = false;
        // Weiter geht es in der Instanz: Der QR-Designer dort kann Farben,
        // Formen, Logo, Rahmen und Druckdateien – hier wäre davon nur ein
        // Schatten unterzubringen.
        const kuerzel = daten.code || kurz.split('/').pop();
        $('zum-designer').href = einst.instanz + '/admin/qrdesign.php?c=' + encodeURIComponent(kuerzel);
        $('zur-verwaltung').href = einst.instanz + '/admin/index.php?hl=' + encodeURIComponent(kuerzel);
        $('kopiert').hidden = true;
        zeige('fertig');
        $('kopieren').focus();
    } catch (e) {
        // Ein abgelehnter fetch heißt meist: Adresse falsch, Instanz nicht
        // erreichbar, oder die Host-Berechtigung fehlt.
        $('fehler').textContent = e.message === 'Failed to fetch'
            ? t('errUnreachable')
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

function zuruecksetzen() {
    $('qr-block').hidden = true;
    $('qr-bild').removeAttribute('src');
    $('code').value = '';
    $('tags').value = '';
    $('expires').value = '';
    $('schon').hidden = true;
    $('fehler').hidden = true;
    zeige('kuerzen');
    $('los').focus();
}

$('los').addEventListener('click', kuerzen);
$('kopieren').addEventListener('click', kopieren);
$('neu').addEventListener('click', zuruecksetzen);
$('schon-kopieren').addEventListener('click', async () => {
    await navigator.clipboard.writeText($('schon-link').textContent);
    $('schon-kopieren').textContent = t('copiedShort');
});
$('zu-optionen').addEventListener('click', () => chrome.runtime.openOptionsPage());
// Eingabetaste in einem der Felder kürzt ebenfalls
for (const feld of ['titel', 'code', 'tags']) {
    $(feld).addEventListener('keydown', (e) => { if (e.key === 'Enter') kuerzen(); });
}

start();
