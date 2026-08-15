<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/domains.php';

$user = auth_require_admin();
$s = settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Die Seite trägt mehrere Formulare, die alle hierher schicken. Deshalb wird
    // der bestehende Stand als Grundlage genommen und nur überschrieben, was in
    // dieser Anfrage tatsächlich vorkommt – sonst löschte jedes Formular beim
    // Speichern die Felder der anderen.
    $neu = settings();
    $fehler = null;

    if (isset($_POST['public_mode'])) {
        $mode = (string)$_POST['public_mode'];
        $prefix = trim((string)($_POST['public_prefix'] ?? 'p'), '/ ');
        $rate = (int)($_POST['public_rate_limit'] ?? 15);
        if (!in_array($mode, ['on', 'prefix', 'off'], true)) {
            $fehler = 'Ungültiger Modus.';
        } elseif ($mode === 'prefix' && !valid_code($prefix)) {
            $fehler = 'Ungültiger Prefix: 1–64 Zeichen (a-z, A-Z, 0-9, _ und -), nicht reserviert.';
        } elseif ($rate < 1 || $rate > 1000) {
            $fehler = 'Rate-Limit: 1–1000 pro Stunde.';
        } else {
            $neu['public_mode'] = $mode;
            $neu['public_prefix'] = $prefix === '' ? 'p' : $prefix;
            $neu['public_rate_limit'] = $rate;
            $neu['registration'] = ($_POST['registration'] ?? '') === 'on' ? 'on' : 'off';
        }
    }

    if ($fehler === null && isset($_POST['grundregeln'])) {
        // 0 heißt „unbegrenzt", deshalb wird nicht auf größer null geprüft,
        // sondern nur auf sinnvolle Obergrenzen.
        foreach (['links' => 1000000, 'stats_days' => 100000, 'logos' => 10000, 'bio' => 10000] as $k => $max) {
            $neu['limits'][$k] = max(0, min($max, (int)($_POST['limit_' . $k] ?? 0)));
        }
        $neu['default_perms'] = array_values(array_intersect(
            (array)($_POST['default_perms'] ?? []), array_keys(perms_all())));
        $neu['custom_code_min_len'] = max(1, min(64, (int)($_POST['custom_code_min_len'] ?? 5)));
        $neu['custom_code_quota'] = max(0, min(100000, (int)($_POST['custom_code_quota'] ?? 0)));
        $modus = (string)($_POST['totp_required'] ?? 'off');
        $neu['totp_required'] = in_array($modus, ['off', 'admins', 'all'], true) ? $modus : 'off';
        $sprache = (string)($_POST['language'] ?? 'de');
        $neu['language'] = isset(lang_available()[$sprache]) ? $sprache : 'de';
    }

    if ($fehler === null && isset($_POST['domains'])) {
        $liste = domains_extra();
        if (($_POST['domain_del'] ?? '') !== '') {
            $weg = domain_clean((string)$_POST['domain_del']);
            $liste = array_values(array_filter($liste, fn($d) => $d['host'] !== $weg));
        } else {
            $host = domain_clean((string)($_POST['domain_host'] ?? ''));
            $grp = (string)($_POST['domain_group'] ?? '');
            if ($host === '') {
                $fehler = 'Das sieht nicht nach einem Hostnamen aus (z. B. kunde.link).';
            } elseif ($host === domain_main()) {
                $fehler = 'Das ist bereits die Hauptdomain.';
            } elseif (in_array($host, array_column($liste, 'host'), true)) {
                $fehler = 'Diese Domain ist schon eingetragen.';
            } else {
                $liste[] = ['host' => $host, 'group' => isset(groups_all()[$grp]) ? $grp : ''];
            }
        }
        if ($fehler === null) $neu['domains'] = $liste;
    }

    if ($fehler !== null) {
        flash($fehler, 'err');
    } else {
        settings_save($neu);
        flash('Einstellungen gespeichert.');
    }
    redirect_to('settings.php');
}

page_header('Einstellungen', true);
show_flash();
$host = preg_replace('#^https?://#', '', base_url());
?>

<div class="card">
    <h2>Grundregeln für alle Konten</h2>
    <p class="muted small">Gilt für jedes angemeldete Konto. Mehr gibt es über
    <a href="groups.php">Gruppen</a> – deren Werte gewinnen, wo sie gesetzt sind.
    Die Vorgaben stehen in <code>inc/config.php</code>; was hier geändert wird,
    überschreibt sie.</p>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="grundregeln" value="1">

        <label>Limits <span class="muted">(0 = unbegrenzt)</span></label>
        <div class="two-col">
            <div>
                <label for="s-links">Aktive Links</label>
                <input id="s-links" type="number" name="limit_links" min="0" max="1000000"
                       value="<?= (int)($s['limits']['links'] ?? 0) ?>">
            </div>
            <div>
                <label for="s-stats">Statistik-Tiefe in Tagen</label>
                <input id="s-stats" type="number" name="limit_stats_days" min="0" max="100000"
                       value="<?= (int)($s['limits']['stats_days'] ?? 0) ?>">
            </div>
        </div>
        <div class="two-col">
            <div>
                <label for="s-logos">Logos in der Bibliothek</label>
                <input id="s-logos" type="number" name="limit_logos" min="0" max="10000"
                       value="<?= (int)($s['limits']['logos'] ?? 0) ?>">
            </div>
            <div>
                <label for="s-bio">Link-in-Bio-Seiten</label>
                <input id="s-bio" type="number" name="limit_bio" min="0" max="10000"
                       value="<?= (int)($s['limits']['bio'] ?? 0) ?>">
            </div>
            <div>
                <label for="s-quota">Wunsch-Codes je Konto</label>
                <input id="s-quota" type="number" name="custom_code_quota" min="0" max="100000"
                       value="<?= (int)($s['custom_code_quota'] ?? 0) ?>">
            </div>
        </div>
        <div>
            <label for="s-minlen">Mindestlänge für Wunsch-Codes
                <span class="muted">(kürzere bleiben Administratoren vorbehalten)</span></label>
            <input id="s-minlen" type="number" name="custom_code_min_len" min="1" max="64"
                   style="max-width:8rem" value="<?= (int)($s['custom_code_min_len'] ?? 5) ?>">
        </div>

        <label>Rechte, die jedes Konto hat</label>
        <div class="check-row">
            <?php foreach (perms_all() as $key => $label): ?>
            <label class="check">
                <input type="checkbox" name="default_perms[]" value="<?= e($key) ?>"
                    <?= in_array($key, (array)($s['default_perms'] ?? []), true) ? ' checked' : '' ?>>
                <?= e($label) ?>
            </label>
            <?php endforeach; ?>
        </div>
        <p class="muted small">Was hier nicht angekreuzt ist, lässt sich einzelnen Konten über
        eine Gruppe geben – so entsteht aus einem Recht ein Tarif.</p>

        <label for="s-lang">Sprache der Oberfläche</label>
        <select id="s-lang" name="language" style="max-width:22rem">
            <?php foreach (lang_available() as $kuerzel => $eigenname): ?>
            <option value="<?= e($kuerzel) ?>"<?= ($s['language'] ?? 'de') === $kuerzel ? ' selected' : '' ?>><?= e($eigenname) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted small">Gilt für die ganze Instanz. Deutsch ist die Quellsprache; was in
        einer Übersetzung fehlt, bleibt sichtbar deutsch statt leer.</p>

        <label for="s-totp">Zwei-Faktor-Anmeldung verlangen</label>
        <select id="s-totp" name="totp_required" style="max-width:22rem">
            <?php foreach (['off' => 'freiwillig', 'admins' => 'für Administratoren', 'all' => 'für alle Konten'] as $k => $lbl): ?>
            <option value="<?= e($k) ?>"<?= ($s['totp_required'] ?? 'off') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted small">Ein Passkey oder ein Einmalkennwort aus einer App – beides zählt.
        Wer noch keines eingerichtet hat, wird nach der Anmeldung dorthin geführt statt
        ausgesperrt.</p>
        <button class="btn btn-primary" type="submit">Grundregeln speichern</button>
    </form>
</div>

<div class="card">
    <h2>Domains für Kurzlinks</h2>
    <p class="muted small">Kurzlinks können unter mehreren Adressen ausgegeben werden. Alle zeigen
    auf diese Installation – im DNS auf denselben Server, im Zertifikat mit aufgeführt. Die
    Verwaltung bleibt auf <code><?= e(domain_main()) ?></code>; wer <code>/admin/</code> unter
    einer Nebendomain aufruft, wird hierher zurückgeleitet.</p>
    <p class="muted small"><strong>Ein Code gehört der Instanz, nicht der Domain.</strong> Es gibt
    <code>/shop</code> genau einmal, und er löst unter jeder eingerichteten Adresse auf. Das hält
    gedruckte Codes am Leben, wenn eine Domain wegfällt – kostet aber, dass zwei Kunden nicht
    beide <code>/shop</code> haben können. Dafür sind die
    <a href="groups.php">Namensräume der Gruppen</a> da.</p>

    <ul class="key-list">
        <li>
            <div><strong><?= e(domain_main()) ?></strong><br>
            <span class="muted small">Hauptdomain – aus <code>base_url</code>, hier auch die Verwaltung</span></div>
        </li>
        <?php foreach (domains_extra() as $d): ?>
        <li>
            <div><strong><?= e($d['host']) ?></strong><br>
            <span class="muted small"><?= $d['group'] === ''
                ? 'für alle Konten wählbar'
                : 'nur für die Gruppe „' . e(group_label($d['group'])) . '“' ?></span></div>
            <form method="post" action="" class="inline" data-confirm="Domain „<?= e($d['host']) ?>“ wirklich entfernen? Links, die darauf zeigen, bleiben bestehen und fallen auf die Hauptdomain zurück.">
                <?= csrf_field() ?>
                <input type="hidden" name="domains" value="1">
                <input type="hidden" name="domain_del" value="<?= e($d['host']) ?>">
                <button class="btn btn-small btn-danger" type="submit">Entfernen</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>

    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="domains" value="1">
        <div class="two-col">
            <div>
                <label for="d-host">Weitere Domain</label>
                <input id="d-host" type="text" name="domain_host" placeholder="kunde.link" autocomplete="off">
            </div>
            <div>
                <label for="d-group">Vorbehalten für <span class="muted">(optional)</span></label>
                <select id="d-group" name="domain_group">
                    <option value="">alle Konten</option>
                    <?php foreach (groups_all() as $gid => $g): ?>
                    <option value="<?= e((string)$gid) ?>"><?= e((string)$g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p><button class="btn" type="submit">Domain hinzufügen</button></p>
    </form>
    <p class="muted small">Die Domain muss zusätzlich beim Hoster auf dieses Verzeichnis zeigen
    und im Zertifikat stehen – das kann diese Oberfläche nicht für dich tun.</p>
</div>

<div class="card">
    <h2>Ablage</h2>
    <?php
    $dataPath = data_path();
    $imWebroot = data_dir_in_webroot();
    $links = count(link_store_files());
    ?>
    <p class="muted small">Laufzeitdaten liegen in<br>
    <code style="word-break:break-all"><?= e($dataPath) ?></code></p>
    <?php if ($imWebroot): ?>
    <div class="flash flash-err" style="margin-top:0.8rem">
        <strong>Das Verzeichnis liegt im Webroot.</strong> Geschützt ist es dort nur durch die
        <code>.htaccess</code> – die nginx, Caddy und LiteSpeed ignorieren. Darin stehen
        Passwort-Hashes und gültige Reset-Token. Wenn dein Hosting einen Pfad außerhalb zulässt,
        trag ihn als <code>data_dir</code> in <code>inc/config.php</code> ein – den Inhalt vorher
        kopieren, erst danach die Konfiguration umstellen.
    </div>
    <?php else: ?>
    <p class="muted small">Außerhalb des Webroots – so soll es sein.</p>
    <?php endif; ?>
    <p class="muted small">Kurzlinks: <?= $links ?> <?= $links === 1 ? 'Datei' : 'Ablagen' ?><?php
        if (!links_sharded()) echo ' – noch die alte Sammeldatei, <code>migrate-links.php</code> teilt sie auf';
    ?></p>
</div>

<div class="card">
    <h2>Öffentliche Oberfläche</h2>
    <form method="post" action="">
        <?= csrf_field() ?>
        <div class="radio-group">
            <label class="radio">
                <input type="radio" name="public_mode" value="on"<?= $s['public_mode'] === 'on' ? ' checked' : '' ?>>
                <span><strong>Aktiv</strong><br>
                <span class="muted">Jeder kann zufällige Kurzlinks direkt unter <?= e($host) ?>/… erstellen.</span></span>
            </label>
            <label class="radio">
                <input type="radio" name="public_mode" value="prefix"<?= $s['public_mode'] === 'prefix' ? ' checked' : '' ?>>
                <span><strong>Aktiv, aber nur unter Prefix</strong><br>
                <span class="muted">Öffentlich erstellte Links landen unter <?= e($host) ?>/<span id="pfx-preview"><?= e($s['public_prefix']) ?></span>/… –
                der restliche Namensraum bleibt für eingeloggte Nutzer reserviert.</span></span>
            </label>
            <label class="radio">
                <input type="radio" name="public_mode" value="off"<?= $s['public_mode'] === 'off' ? ' checked' : '' ?>>
                <span><strong>Deaktiviert</strong><br>
                <span class="muted">Die Startseite zeigt nur einen Hinweis, Links entstehen ausschließlich im Login-Bereich. Bestehende Kurzlinks leiten weiterhin um.</span></span>
            </label>
        </div>

        <label for="s-prefix">Prefix für öffentliche Links</label>
        <input id="s-prefix" type="text" name="public_prefix" value="<?= e($s['public_prefix']) ?>" pattern="[A-Za-z0-9_-]{1,64}"
               oninput="document.getElementById('pfx-preview').textContent = this.value || 'p'">
        <p class="muted small">Gilt nur im Prefix-Modus und nur für neue Links – bestehende Kurzlinks behalten ihre Adresse.</p>

        <label for="s-rate">Rate-Limit der öffentlichen Erstellung (Links pro IP und Stunde)</label>
        <input id="s-rate" type="number" name="public_rate_limit" value="<?= (int)$s['public_rate_limit'] ?>" min="1" max="1000">

        <h2 style="margin-top:1.5rem">Registrierung</h2>
        <div class="radio-group">
            <label class="radio">
                <input type="radio" name="registration" value="on"<?= $s['registration'] === 'on' ? ' checked' : '' ?>>
                <span><strong>Offen</strong><br>
                <span class="muted">Jeder kann sich mit E-Mail-Bestätigung (Double-Opt-In) ein Konto anlegen.</span></span>
            </label>
            <label class="radio">
                <input type="radio" name="registration" value="off"<?= $s['registration'] !== 'on' ? ' checked' : '' ?>>
                <span><strong>Geschlossen</strong><br>
                <span class="muted">Neue Konten legt nur der Admin an. Bestehende Konten funktionieren weiter.</span></span>
            </label>
        </div>

        <button class="btn btn-primary" type="submit" style="margin-top:1rem">Speichern</button>
    </form>
</div>
<?php page_footer(); ?>
