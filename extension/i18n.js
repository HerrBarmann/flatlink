// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
//
// Setzt die Texte der Oberfläche in der Sprache des Browsers.
//
// WebExtensions ersetzen __MSG_…__ nur im Manifest und in CSS, nicht in HTML.
// Deshalb tragen die Elemente ein data-i18n-Attribut, und diese Datei füllt
// sie beim Laden. Sie läuft VOR popup.js/options.js, damit die dortigen
// Zuweisungen das letzte Wort behalten.
//
// Bewusst textContent statt innerHTML: Die Texte kommen zwar aus dem eigenen
// Paket, aber ein Weg, über den Markup in die Seite gelangen kann, gehört
// gar nicht erst angelegt.

const t = (schluessel, ...werte) =>
    chrome.i18n.getMessage(schluessel, werte.map(String)) || schluessel;

for (const el of document.querySelectorAll('[data-i18n]')) {
    el.textContent = t(el.dataset.i18n);
}
for (const el of document.querySelectorAll('[data-i18n-placeholder]')) {
    el.placeholder = t(el.dataset.i18nPlaceholder);
}
for (const el of document.querySelectorAll('[data-i18n-alt]')) {
    el.alt = t(el.dataset.i18nAlt);
}

// Die Sprache der geladenen Sprachdatei – nicht die des Browsers. Steht der
// Browser auf Französisch, greift die Rückfallsprache; dann muss hier deren
// Kürzel stehen, sonst behaupten wir eine Sprache, die nicht auf der Seite ist.
document.documentElement.lang = t('htmlLang');
