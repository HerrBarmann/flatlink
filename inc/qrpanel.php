<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Das Gestaltungs-Panel der QR-Generatoren – eine Fassung für alle.
 *
 * Es gab die vollen Gestaltungsoptionen zunächst nur im QR-Designer; die
 * übrigen Generatoren (WLAN, Kontakt, Termin, GS1, QR-Serie) boten zwei
 * Farbfelder. Dieselben Optionen viermal zu pflegen hätte sie zwangsläufig
 * auseinanderlaufen lassen – deshalb steht das Panel hier genau einmal.
 *
 * Die Bedienelemente tragen feste IDs (opt-*), auf die assets/qroptions.js
 * hört. Wer das Panel einbindet, bindet auch dieses Skript ein; es sammelt
 * die Werte für qr.php ein und hält die Anzeige aktuell. Für Seiten, die
 * klassisch per Formular absenden (die QR-Serie), bekommen die Felder
 * zusätzlich name-Attribute.
 */

/**
 * Logo-Auswahl eines Kontos: id => Anzeigename, sortiert.
 *
 * Jeder sieht die eigenen Logos und die, die ihm über eine Gruppe freigegeben
 * wurden; Admins sehen alle (samt Altbestand ohne Besitzer). Gäste haben kein
 * Konto, an dem Logos hängen könnten – leer.
 *
 * Das Recht `logo_upload` wird hier bewusst NICHT verlangt: Es regelt das
 * Hochladen, nicht das Verwenden. Wer nichts hochladen darf, ist gerade der
 * typische Empfänger einer Freigabe – die Bedingung hätte die Freigabe für ihn
 * wirkungslos gemacht. Eigene Logos hat ohne das Recht ohnehin nur, wer es
 * einmal hatte.
 *
 * @return array<string,string>
 */
function qr_logo_choices(?array $user): array
{
    if ($user === null) return [];
    $isAdmin = $user['role'] === 'admin';
    $logosDir = data_path('logos');
    $files = array_values(array_filter(scandir($logosDir) ?: [],
        fn($f) => preg_match('/^[a-f0-9]{16}\.(png|jpe?g|webp|svg)$/', $f)));
    $meta = logos_meta();
    $gruppen = function_exists('user_groups') ? user_groups($user['name']) : [];
    $logos = [];
    foreach ($files as $f) {
        $m = (array)($meta[$f] ?? []);
        if (!logo_visible_for($m, $user['name'], (string)$user['role'], $gruppen)) continue;
        $ext = strtoupper(pathinfo($f, PATHINFO_EXTENSION));
        // Fremde Logos als solche kennzeichnen – mit dem Konto, dem sie
        // gehören. „geteilt" allein wäre für Administratoren falsch: Die sehen
        // auch Logos, die gar nicht freigegeben sind.
        $by = $m['by'] ?? null;
        $fremd = $by !== null && $by !== $user['name'];
        $logos[$f] = ($m['name'] ?? $f) . ' (' . $ext . ')' . ($fremd ? ' · ' . t('von %s', $by) : '');
    }
    asort($logos, SORT_NATURAL | SORT_FLAG_CASE);
    return $logos;
}

/**
 * Das Panel ausgeben.
 *
 * Optionen:
 *   'heading' => bool   Überschrift „Gestaltung" mit ausgeben (Vorgabe: ja)
 *   'frame'   => bool   Rahmentext-Eingabe zeigen (Vorgabe: nein)
 *   'logos'   => array  Logo-Auswahl (id => Name); leer = kein Logo-Abschnitt
 *   'print'   => bool   Druckfarben (CMYK) und Papierbreite (Vorgabe: ja)
 *   'named'   => bool   name-Attribute für klassische Formular-Abgabe
 */
function qr_design_panel(array $o = []): string
{
    $heading = $o['heading'] ?? true;
    $frame = $o['frame'] ?? false;
    $logos = $o['logos'] ?? [];
    $print = $o['print'] ?? true;
    $named = $o['named'] ?? false;
    // name-Attribute heißen wie die qr.php-Parameter, damit die klassische
    // Abgabe dieselbe Sprache spricht wie die per Skript gebaute Adresse.
    $n = fn(string $name) => $named ? ' name="' . $name . '"' : '';

    ob_start();
    ?>
    <?php if ($heading): ?><h3><?= t('Gestaltung') ?></h3><?php endif; ?>
    <label for="opt-style"><?= t('Modul-Form') ?></label>
    <select id="opt-style"<?= $n('style') ?>>
        <option value="square"><?= t('Quadratisch') ?></option>
        <option value="rounded"><?= t('Abgerundet') ?></option>
        <option value="smooth"><?= t('Stark abgerundet') ?></option>
        <option value="dot"><?= t('Punkte') ?></option>
        <option value="diamond"><?= t('Raute') ?></option>
        <option value="bars-v"><?= t('Senkrechte Balken') ?></option>
        <option value="bars-h"><?= t('Waagerechte Balken') ?></option>
    </select>
    <label for="opt-eye"><?= t('Augen-Form') ?></label>
    <select id="opt-eye"<?= $n('eye') ?>>
        <option value="square"><?= t('Quadratisch') ?></option>
        <option value="rounded"><?= t('Abgerundet') ?></option>
        <option value="circle"><?= t('Kreis') ?></option>
        <option value="leaf"><?= t('Blatt') ?></option>
    </select>
    <label for="opt-eyecore"><?= t('Augen-Kern') ?></label>
    <select id="opt-eyecore"<?= $n('eyecore') ?>>
        <option value=""><?= t('wie der Ring') ?></option>
        <option value="square"><?= t('Quadratisch') ?></option>
        <option value="rounded"><?= t('Abgerundet') ?></option>
        <option value="circle"><?= t('Kreis') ?></option>
        <option value="leaf"><?= t('Blatt') ?></option>
    </select>
    <label class="check" style="margin:0.5rem 0 0.2rem">
        <input id="opt-eyeown" type="checkbox"<?= $n('eyeown') ?> value="1">
        <span><?= t('Augen eigene Farben geben') ?></span>
    </label>
    <div id="augenfarben" class="two-col" hidden>
        <div><label for="opt-eyefg"><?= t('Augen-Ring') ?></label><input id="opt-eyefg" type="color" value="#C0392B"<?= $n('eyefg') ?>></div>
        <div><label for="opt-eyecorefg"><?= t('Augen-Kern') ?></label><input id="opt-eyecorefg" type="color" value="#16181D"<?= $n('eyecorefg') ?>></div>
    </div>
    <div class="two-col">
        <div><label for="opt-fg"><?= t('Vordergrund') ?></label><input id="opt-fg" type="color" value="#16181D"<?= $n('fg') ?>></div>
        <div><label for="opt-bg"><?= t('Hintergrund') ?></label><input id="opt-bg" type="color" value="#ffffff"<?= $n('bg') ?>></div>
    </div>
    <label class="check">
        <input id="opt-bgnone" type="checkbox"<?= $n('bgnone') ?> value="1">
        <span><?= t('Hintergrund durchsichtig') ?> <span class="muted">(SVG + PNG)</span></span>
    </label>
    <label for="opt-grad"><?= t('Farbverlauf') ?></label>
    <div class="short-row grad-row">
        <select id="opt-grad"<?= $n('grad') ?>>
            <option value=""><?= t('Kein Verlauf') ?></option>
            <option value="linear"><?= t('Linear') ?></option>
            <option value="radial"><?= t('Radial (von innen)') ?></option>
        </select>
        <input id="opt-fg2" type="color" value="#3B6EA8"<?= $n('fg2') ?> title="<?= t('Zweite Farbe') ?>" aria-label="<?= t('Zweite Farbe des Verlaufs') ?>">
    </div>
    <div id="grad-winkel" hidden>
        <label for="opt-ga"><?= t('Richtung:') ?> <span id="ga-val">45</span>°</label>
        <input id="opt-ga" type="range" min="0" max="345" step="15" value="45"<?= $n('ga') ?>>
    </div>
    <label><?= t('Verlaufs-Vorlagen') ?></label>
    <div class="qr-links">
        <button class="btn btn-small" type="button" data-grad="linear|45|#0B3D2E|#7ABA1C"><?= t('Wiese') ?></button>
        <button class="btn btn-small" type="button" data-grad="linear|0|#1a1a2e|#8e2de2"><?= t('Nacht') ?></button>
        <button class="btn btn-small" type="button" data-grad="radial|0|#3a1c71|#d76d77"><?= t('Sonne') ?></button>
        <button class="btn btn-small" type="button" data-grad="linear|135|#134E5E|#71B280"><?= t('See') ?></button>
    </div>

    <label><?= t('Farbvorlagen') ?></label>
    <div class="actions">
        <button class="btn btn-small" type="button" data-preset="#16181D|#ffffff"><?= t('Klassik') ?></button>
        <button class="btn btn-small" type="button" data-preset="#2C5480|#ffffff"><?= t('Akzent') ?></button>
        <button class="btn btn-small" type="button" data-preset="#16181D|#F3F4F6"><?= t('Papier') ?></button>
        <button class="btn btn-small" type="button" data-preset="#ffffff|#16181D" title="<?= t('Achtung: invertierte Codes lesen manche Scanner nicht') ?>"><?= t('Invertiert') ?></button>
    </div>
    <label for="opt-ecc"><?= t('Fehlerkorrektur') ?> <span class="muted">(<?= t('mit Logo automatisch H') ?>)</span></label>
    <select id="opt-ecc"<?= $n('ecc') ?>>
        <option value="L">L – 7 %</option>
        <option value="M" selected>M – 15 %</option>
        <option value="Q">Q – 25 %</option>
        <option value="H">H – 30 %</option>
    </select>
    <label for="opt-margin"><?= t('Rand (Quiet-Zone):') ?> <span id="margin-val">4</span> <?= t('Module') ?></label>
    <input id="opt-margin" type="range" min="0" max="10" value="4"<?= $n('margin') ?>>

    <?php if ($print): ?>
    <details class="druckfarben">
        <summary><?= t('Druckfarben (CMYK)') ?></summary>
        <p class="muted small"><?= t('Für Druckereien. Was hier steht, geht unverändert in PDF und EPS. Bildschirm, PNG und die Vorschau zeigen eine Umrechnung – ohne Farbprofil kann das nur eine Näherung sein, verbindlich ist die Druckdatei. Leer lassen heißt: die Bildschirmfarben oben gelten auch im Druck.') ?></p>
        <label><?= t('Vordergrund') ?> <span class="muted">C / M / Y / K <?= t('in Prozent') ?></span></label>
        <div class="cmyk-row">
            <input id="opt-fgc-c" type="number" min="0" max="100" placeholder="C" aria-label="Vordergrund Cyan">
            <input id="opt-fgc-m" type="number" min="0" max="100" placeholder="M" aria-label="Vordergrund Magenta">
            <input id="opt-fgc-y" type="number" min="0" max="100" placeholder="Y" aria-label="Vordergrund Gelb">
            <input id="opt-fgc-k" type="number" min="0" max="100" placeholder="K" aria-label="Vordergrund Schwarz">
        </div>
        <label><?= t('Hintergrund') ?></label>
        <div class="cmyk-row">
            <input id="opt-bgc-c" type="number" min="0" max="100" placeholder="C" aria-label="Hintergrund Cyan">
            <input id="opt-bgc-m" type="number" min="0" max="100" placeholder="M" aria-label="Hintergrund Magenta">
            <input id="opt-bgc-y" type="number" min="0" max="100" placeholder="Y" aria-label="Hintergrund Gelb">
            <input id="opt-bgc-k" type="number" min="0" max="100" placeholder="K" aria-label="Hintergrund Schwarz">
        </div>
        <label for="opt-mm"><?= t('Breite auf dem Papier') ?> <span class="muted">(<?= t('mm, für PDF und EPS') ?>)</span></label>
        <input id="opt-mm" type="number" min="10" max="1000" value="80" style="max-width:8rem">
    </details>
    <?php endif; ?>

    <?php if ($frame): ?>
    <h3><?= t('Rahmen') ?></h3>
    <label for="opt-ftext"><?= t('Text unter dem Code') ?> <span class="muted">(<?= t('leer = kein Rahmen, max. 24 Zeichen') ?>)</span></label>
    <div class="short-row">
        <input id="opt-ftext" type="text" maxlength="24"<?= $n('ftext') ?> placeholder="<?= t('z. B. Scan mich!') ?>">
        <button class="btn btn-small" type="button" id="ftext-preset">„<?= t('Scan mich!') ?>"</button>
    </div>
    <?php endif; ?>

    <?php if ($logos !== []): ?>
    <h3><?= t('Logo') ?></h3>
    <select id="opt-logo"<?= $n('logo') ?>>
        <option value=""><?= t('Kein Logo') ?></option>
        <?php foreach ($logos as $id => $name): ?>
            <option value="<?= e((string)$id) ?>"><?= e($name) ?></option>
        <?php endforeach; ?>
    </select>
    <label for="opt-ls"><?= t('Logo-Größe:') ?> <span id="ls-val">22</span> %</label>
    <input id="opt-ls" type="range" min="10" max="35" value="22"<?= $n('ls') ?>>
    <label for="opt-lshape"><?= t('Freie Fläche hinter dem Logo') ?></label>
    <select id="opt-lshape"<?= $n('lshape') ?>>
        <option value="rounded"><?= t('abgerundet') ?></option>
        <option value="square"><?= t('eckig') ?></option>
        <option value="circle"><?= t('rund') ?></option>
        <option value="none"><?= t('keine') ?></option>
    </select>
    <p class="muted small"><?= t('Ein Logo, das Module nur halb verdeckt, verwirrt die Erkennung mehr als eine sauber ausgesparte Fläche – die steckt die Fehlerkorrektur weg.') ?></p>
    <p class="muted small"><?= t('SVG-Logos erscheinen nur im SVG-Export (PNG kann sie nicht rastern).') ?></p>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

/**
 * Umschalter zwischen den QR-Typen – ein Generator, fünf Reiter.
 *
 * Der Encoder in qr.php kann WLAN, Kontakt, Termin und GS1 seit jeher; was
 * lange fehlte, war die Bedienung dafür. Die Reiter führen auf die jeweilige
 * Seite; angemeldet zeigt der erste auf den vollen Designer im Login-Bereich,
 * weil dort Logos und die Zuordnung zu einem Kurzlink dazukommen.
 *
 * @param string $active einer von link, wifi, vcard, event, gs1
 */
function qr_type_nav(string $active): string
{
    $root = $GLOBALS['_page_root'] ?? '.';
    $u = function_exists('auth_user') ? auth_user() : null;
    $linkZiel = $u !== null
        ? ($root === '..' ? 'qrdesign.php' : 'admin/qrdesign.php')
        : $root . '/qr-designer.php';
    $reiter = [
        'link'  => [$linkZiel, t('Link')],
        'wifi'  => [$root . '/wlan-qr.php', t('WLAN')],
        'vcard' => [$root . '/vcard-qr.php', t('Kontakt')],
        'event' => [$root . '/termin-qr.php', t('Termin')],
        'gs1'   => [$root . '/gs1-qr.php', t('GS1')],
    ];
    $out = '<nav class="type-nav" aria-label="' . t('QR-Code-Typ') . '">';
    foreach ($reiter as $key => [$href, $label]) {
        $out .= '<a class="btn btn-small' . ($key === $active ? ' active' : '') . '"'
            . ($key === $active ? ' aria-current="page"' : '')
            . ' href="' . e($href) . '">' . e($label) . '</a>';
    }
    return $out . '</nav>';
}
