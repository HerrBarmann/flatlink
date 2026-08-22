<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';   // webauthn_possible() für den Passkey-Hinweis
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/domains.php';
require_once __DIR__ . '/../inc/zip.php';
require_once __DIR__ . '/../inc/backup.php';
require_once __DIR__ . '/../inc/probe.php';
require_once __DIR__ . '/../inc/extension.php';

$user = auth_require_admin();
$s = settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Die Seite trägt mehrere Formulare, die alle hierher schicken. Deshalb wird
    // der bestehende Stand als Grundlage genommen und nur überschrieben, was in
    // dieser Anfrage tatsächlich vorkommt – sonst löschte jedes Formular beim
    // Speichern die Felder der anderen.
    //
    // Grundlage ist bewusst der GESPEICHERTE Stand, nicht der aufgelöste aus
    // settings(): Der enthält auch alles, was nur in inc/config.php steht.
    // Schriebe man ihn zurück, wären nach dem ersten Speichern eines
    // beliebigen Formulars sämtliche Vorgaben eingefroren – und eine spätere
    // Änderung an der Konfigurationsdatei bliebe wirkungslos, ohne dass
    // irgendwo etwas davon zu sehen wäre. Genau so ist einmal ein
    // Standardrecht verlorengegangen, das per Upload nachgereicht wurde.
    $neu = settings_stored();
    $fehler = null;

    if (isset($_POST['public_mode'])) {
        $mode = (string)$_POST['public_mode'];
        $prefix = trim((string)($_POST['public_prefix'] ?? 'p'), '/ ');
        $rate = (int)($_POST['public_rate_limit'] ?? 15);
        if (!in_array($mode, ['on', 'prefix', 'off'], true)) {
            $fehler = t('Ungültiger Modus.');
        } elseif ($mode === 'prefix' && !valid_code($prefix)) {
            $fehler = t('Ungültiger Prefix: 1–64 Zeichen (a-z, A-Z, 0-9, _ und -), nicht reserviert.');
        } elseif ($rate < 1 || $rate > 1000) {
            $fehler = t('Rate-Limit: 1–1000 pro Stunde.');
        } else {
            $neu['public_mode'] = $mode;
            $neu['public_prefix'] = $prefix === '' ? 'p' : $prefix;
            $neu['public_rate_limit'] = $rate;
            $neu['registration'] = ($_POST['registration'] ?? '') === 'on' ? 'on' : 'off';
            $qrp = (string)($_POST['qr_public'] ?? 'auto');
            $neu['qr_public'] = in_array($qrp, ['auto', 'on', 'off'], true) ? $qrp : 'auto';
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
        $hinweis = (string)($_POST['passkey_hint'] ?? 'on');
        $neu['passkey_hint'] = in_array($hinweis, ['on', 'local', 'off'], true) ? $hinweis : 'on';

        // Aufräumen: 0 heißt aus, wie bei den Limits darüber. Die lange Frist
        // darf nicht kürzer sein als die kurze – wer nicht gewarnt werden
        // kann, soll nicht FRÜHER gelöscht werden als wer gewarnt wird.
        // links_gc() fängt das ohnehin mit max() ab; hier steht es, damit der
        // gespeicherte Wert dem entspricht, was die Seite anzeigt.
        $gcKurz = max(0, min(50, (int)($_POST['link_gc_years'] ?? 0)));
        $gcLang = max(0, min(50, (int)($_POST['link_gc_years_unreachable'] ?? 0)));
        $neu['link_gc_years'] = $gcKurz;
        $neu['link_gc_years_unreachable'] = $gcKurz > 0 ? max($gcKurz, $gcLang) : $gcLang;
        // Landet unverändert im Text einer Mail, nicht in HTML – Zeilenumbrüche
        // raus, damit niemand eigene Absätze in fremde Post schreibt.
        $neu['link_gc_note'] = mb_substr(trim(preg_replace('/\s+/u', ' ',
            (string)($_POST['link_gc_note'] ?? ''))), 0, 120);
        $sprache = (string)($_POST['language'] ?? 'de');
        $neu['language'] = isset(lang_available()[$sprache]) ? $sprache : 'de';
    }

    if ($fehler === null && isset($_POST['domains'])) {
        $liste = domains_extra();
        if (($_POST['domain_del'] ?? '') !== '') {
            $weg = domain_clean((string)$_POST['domain_del']);
            // Seit 5.0 hat jede Domain ihren eigenen Namensraum. Wird sie
            // ausgetragen, sind ihre Links nicht mehr auflösbar – sie
            // verschwinden nicht, aber sie führen nirgendwo mehr hin.
            // Deshalb: nicht ohne ausdrückliche Bestätigung.
            // Umkehrbar: Wird die Domain wieder eingetragen, lösen ihre Links
            // wieder auf. Deshalb genügt hier die Warnung im Formular (sie
            // nennt die Zahl) – aber ins Protokoll gehört sie mit Zahl.
            $betroffen = links_count_of_domain($weg);
            $liste = array_values(array_filter($liste, fn($d) => $d['host'] !== $weg));
            audit($betroffen > 0
                ? t('Domain „%s“ entfernt – %d Kurzlinks darunter sind bis zum Wiedereintragen nicht erreichbar', $weg, $betroffen)
                : t('Domain „%s“ entfernt', $weg), $weg);
        } else {
            $host = domain_clean((string)($_POST['domain_host'] ?? ''));
            $grp = (string)($_POST['domain_group'] ?? '');
            if ($host === '') {
                $fehler = t('Das sieht nicht nach einem Hostnamen aus (z. B. kunde.link).');
            } elseif ($host === domain_main()) {
                $fehler = t('Das ist bereits die Hauptdomain.');
            } elseif (in_array($host, array_column($liste, 'host'), true)) {
                $fehler = t('Diese Domain ist schon eingetragen.');
            } else {
                $liste[] = ['host' => $host, 'group' => isset(groups_all()[$grp]) ? $grp : ''];
                audit(t('Domain „%s“ hinzugefügt', $host), $host);
            }
        }
        if ($fehler === null) $neu['domains'] = $liste;
    }

    if ($fehler === null && isset($_POST['erweiterung'])) {
        $laeden = [];
        foreach (array_keys(ext_laden_namen()) as $laden) {
            $laeden[$laden] = trim((string)($_POST['ext_' . $laden] ?? ''));
        }
        // Eine Adresse, die nicht in ihren Laden zeigt, wird gar nicht erst
        // gespeichert: Sonst stünde sie im Feld, ohne zu wirken, und niemand
        // wüsste warum. Der Fehler nennt den Laden.
        foreach ($laeden as $laden => $url) {
            if ($url !== '' && !ext_store_gueltig($laden, $url)) {
                $fehler = t('Diese Adresse zeigt nicht in den Laden „%s“.', ext_laden_namen()[$laden]);
                break;
            }
        }
        if ($fehler === null) {
            $neu['ext_stores'] = $laeden;
            audit(t('Einstellungen zur Browser-Erweiterung geändert'));
        }
    }

    if (isset($_POST['probe'])) {
        probe_run(true);
        flash(t('Prüfung durchgeführt.'));
        redirect_to('settings.php');
    }

    if (isset($_POST['sicherung'])) {
        @set_time_limit(0);
        [$archiv, $uebersicht] = backup_build();
        audit(t('Sicherung heruntergeladen (%s)', number_format(strlen($archiv) / 1048576, 1, t(','), t('.')) . ' MB'));
        nosniff_header();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="flatlink-sicherung-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . strlen($archiv));
        header('Cache-Control: no-store');
        echo $archiv;
        exit;
    }

    if ($fehler !== null) {
        flash($fehler, 'err');
    } else {
        settings_save($neu);
        flash(t('Einstellungen gespeichert.'));
        audit(t('Einstellungen gespeichert.'));
    }
    redirect_to('settings.php');
}

page_header(t('Einstellungen'), true);
show_flash();
$host = preg_replace('#^https?://#', '', base_url());
?>

<div class="card">
    <h2><?= t('Grundregeln für alle Konten') ?></h2>
    <p class="muted small"><?= t('Gilt für jedes angemeldete Konto. Mehr gibt es über %sGruppen%s – deren Werte gewinnen, wo sie gesetzt sind. Die Vorgaben stehen in %s; was hier geändert wird, überschreibt sie.', '<a href="groups.php">', '</a>', '<code>inc/config.php</code>') ?></p>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="grundregeln" value="1">

        <label><?= t('Limits') ?> <span class="muted"><?= t('(0 = unbegrenzt)') ?></span></label>
        <div class="two-col">
            <div>
                <label for="s-links"><?= t('Aktive Links') ?></label>
                <input id="s-links" type="number" name="limit_links" min="0" max="1000000"
                       value="<?= (int)($s['limits']['links'] ?? 0) ?>">
            </div>
            <div>
                <label for="s-stats"><?= t('Statistik-Tiefe in Tagen') ?></label>
                <input id="s-stats" type="number" name="limit_stats_days" min="0" max="100000"
                       value="<?= (int)($s['limits']['stats_days'] ?? 0) ?>">
            </div>
        </div>
        <div class="two-col">
            <div>
                <label for="s-logos"><?= t('Logos in der Bibliothek') ?></label>
                <input id="s-logos" type="number" name="limit_logos" min="0" max="10000"
                       value="<?= (int)($s['limits']['logos'] ?? 0) ?>">
            </div>
            <div>
                <label for="s-bio"><?= t('Link-in-Bio-Seiten') ?></label>
                <input id="s-bio" type="number" name="limit_bio" min="0" max="10000"
                       value="<?= (int)($s['limits']['bio'] ?? 0) ?>">
            </div>
            <div>
                <label for="s-quota"><?= t('Wunsch-Codes je Konto') ?></label>
                <input id="s-quota" type="number" name="custom_code_quota" min="0" max="100000"
                       value="<?= (int)($s['custom_code_quota'] ?? 0) ?>">
            </div>
        </div>
        <div>
            <label for="s-minlen"><?= t('Mindestlänge für Wunsch-Codes') ?>
                <span class="muted"><?= t('(kürzere bleiben Administratoren vorbehalten)') ?></span></label>
            <input id="s-minlen" type="number" name="custom_code_min_len" min="1" max="64"
                   style="max-width:8rem" value="<?= (int)($s['custom_code_min_len'] ?? 5) ?>">
        </div>

        <label><?= t('Rechte, die jedes Konto hat') ?></label>
        <div class="check-row">
            <?php foreach (perms_sections() as $i => $abschnitt): ?>
            <?php if ($i > 0): ?><hr class="perm-trenner"><?php endif; ?>
            <p class="perm-titel"><?= e($abschnitt['titel']) ?></p>
            <?php foreach ($abschnitt['perms'] as $key => $label): ?>
            <label class="check">
                <input type="checkbox" name="default_perms[]" value="<?= e($key) ?>"
                    <?= in_array($key, (array)($s['default_perms'] ?? []), true) ? ' checked' : '' ?>>
                <?= e($label) ?>
            </label>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <p class="muted small"><?= t('Was hier nicht angekreuzt ist, lässt sich einzelnen Konten über eine Gruppe geben – so entsteht aus einem Recht ein Tarif.') ?></p>

        <label for="s-lang"><?= t('Sprache der Oberfläche') ?></label>
        <select id="s-lang" name="language" style="max-width:22rem">
            <?php foreach (lang_available() as $kuerzel => $eigenname): ?>
            <option value="<?= e($kuerzel) ?>"<?= ($s['language'] ?? 'de') === $kuerzel ? ' selected' : '' ?>><?= e($eigenname) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted small"><?= t('Gilt für die ganze Instanz. Deutsch ist die Quellsprache; was in einer Übersetzung fehlt, bleibt sichtbar deutsch statt leer.') ?></p>

        <label for="s-totp"><?= t('Zwei-Faktor-Anmeldung verlangen') ?></label>
        <select id="s-totp" name="totp_required" style="max-width:22rem">
            <?php foreach (['off' => t('freiwillig'), 'admins' => t('für Administratoren'), 'all' => t('für alle Konten')] as $k => $lbl): ?>
            <option value="<?= e($k) ?>"<?= ($s['totp_required'] ?? 'off') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted small"><?= t('Ein Passkey oder ein Einmalkennwort aus einer App – beides zählt. Wer noch keines eingerichtet hat, wird nach der Anmeldung dorthin geführt statt ausgesperrt.') ?></p>

        <label for="s-pkhint"><?= t('Passkey vorschlagen') ?></label>
        <select id="s-pkhint" name="passkey_hint" style="max-width:22rem">
            <?php foreach (['on' => t('allen Konten'), 'local' => t('nur lokalen Konten'), 'off' => t('gar nicht')] as $k => $lbl): ?>
            <option value="<?= e($k) ?>"<?= ($s['passkey_hint'] ?? 'on') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted small"><?= t('Wer noch keinen Passkey hat, bekommt nach der Anmeldung einmal im Monat das Angebot, einen einzurichten – mit einem „Nicht mehr fragen" daneben. Ohne HTTPS entfällt es von selbst.') ?></p>
        <?php if (!webauthn_possible()): ?>
        <p class="muted small"><strong><?= t('Auf dieser Instanz greift die Einstellung gerade nicht:') ?></strong> <?= t('Passkeys brauchen eine gesicherte Verbindung (HTTPS).') ?></p>
        <?php endif; ?>
        <p class="muted small"><?= t('%snur lokalen Konten%s ist für Häuser gedacht, in denen die Anmeldung am Verzeichnis hängen soll: Ein Passkey käme auch dann noch durch, wenn dort das Passwort gewechselt wurde. Gesperrte Konten weist er weiterhin ab.', '<em>', '</em>') ?></p>

        <h3><?= t('Ungenutzte Links aufräumen') ?></h3>
        <p class="muted small"><?= t('Löscht Links, die über den ganzen Zeitraum %skein einziges Mal%s aufgerufen wurden. Ein einziger Aufruf setzt die Frist vollständig zurück. %s0 = aus%s, wie bei den Limits oben.', '<strong>', '</strong>', '<strong>', '</strong>') ?></p>

        <div class="two-col">
            <div>
                <label for="s-gc"><?= t('Mit Warnung (Jahre)') ?></label>
                <input id="s-gc" type="number" name="link_gc_years" min="0" max="50"
                       value="<?= (int)($s['link_gc_years'] ?? 0) ?>">
            </div>
            <div>
                <label for="s-gc-lang"><?= t('Ohne Warnweg (Jahre)') ?></label>
                <input id="s-gc-lang" type="number" name="link_gc_years_unreachable" min="0" max="50"
                       value="<?= (int)($s['link_gc_years_unreachable'] ?? 0) ?>">
            </div>
        </div>
        <p class="muted small"><?= t('Die kurze Frist gilt, wo sich der Besitzer erreichen lässt: Einen Monat vor Ablauf geht eine Sammelmail an sein Konto, gelöscht wird frühestens 30 Tage danach. Die lange Frist gilt für anonyme Links und Konten ohne E-Mail-Adresse – dort kommt die Löschung ohne Vorwarnung, deshalb später. Kürzer als die kurze kann sie nicht sein.') ?></p>
        <label for="s-gc-note"><?= t('Worauf sich die Löschung beruft') ?> <span class="muted"><?= t('(optional)') ?></span></label>
        <input id="s-gc-note" type="text" name="link_gc_note" maxlength="120"
               placeholder="<?= e(t('z. B. AGB § 2')) ?>"
               value="<?= e((string)($s['link_gc_note'] ?? '')) ?>">
        <p class="muted small"><?= t('Steht in der Warnmail in Klammern hinter „automatisch gelöscht". Leer lassen, wenn es nichts zu zitieren gibt – dann endet der Satz einfach.') ?></p>

        <p class="muted small"><?= t('Gesperrte Links bleiben stehen, damit ihre Codes nicht neu vergeben werden. Der Lauf hängt an keinem Cronjob: Er beginnt höchstens einmal pro Woche, angestoßen vom nächsten angelegten Link.') ?>
        <?php $gcStand = (array)state_get('links-gc'); ?>
        <?php if (!empty($gcStand['last_run'])): ?><?= t('Zuletzt gelaufen: %s.', e(date('d.m.Y H:i', (int)strtotime((string)$gcStand['last_run'])))) ?><?php else: ?><?= t('Bisher noch nicht gelaufen.') ?><?php endif; ?></p>

        <button class="btn btn-primary" type="submit"><?= t('Grundregeln speichern') ?></button>
    </form>
</div>

<div class="card">
    <h2><?= t('Domains für Kurzlinks') ?></h2>
    <p class="muted small"><?= t('Kurzlinks können unter mehreren Adressen ausgegeben werden. Alle zeigen auf diese Installation – im DNS auf denselben Server, im Zertifikat mit aufgeführt. Die Verwaltung bleibt auf %s; wer %s unter einer Nebendomain aufruft, wird hierher zurückgeleitet.', '<code>' . e(domain_main()) . '</code>', '<code>/admin/</code>') ?></p>
    <p class="muted small"><strong><?= t('Jede Domain hat ihren eigenen Namensraum.') ?></strong> <?= t('%s und %s sind zwei verschiedene Kurzlinks – zwei Kunden können denselben Code haben, ohne sich abzustimmen, und niemand erreicht unter seiner Domain die Links eines anderen. Die Kehrseite: Wird eine Domain hier entfernt, während sie weiter auf diesen Server zeigt, lösen ihre Links nicht mehr auf. Gelöscht wird dabei keiner; ein Wiedereintragen macht sie alle wieder erreichbar.', '<code>kunde-a.link/shop</code>', '<code>kunde-b.link/shop</code>') ?></p>

    <ul class="key-list">
        <li>
            <div><strong><?= e(domain_main()) ?></strong><br>
            <span class="muted small"><?= t('Hauptdomain – aus %s, hier auch die Verwaltung', '<code>base_url</code>') ?></span></div>
        </li>
        <?php foreach (domains_extra() as $d): $anzahl = links_count_of_domain($d['host']); ?>
        <li>
            <div><strong><?= e($d['host']) ?></strong><br>
            <span class="muted small"><?= $d['group'] === ''
                ? t('für alle Konten wählbar')
                : t('nur für die Gruppe „%s“', e(group_label($d['group']))) ?><?= $anzahl > 0
                ? ' · ' . t('%d Kurzlinks in eigenem Namensraum', $anzahl) : '' ?></span></div>
            <form method="post" action="" class="inline" data-confirm="<?= e($anzahl > 0
                ? t('Domain „%s“ wirklich entfernen? Die %d Kurzlinks darunter werden nicht gelöscht, sind danach aber unter keiner Adresse mehr erreichbar.', $d['host'], $anzahl)
                : t('Domain „%s“ wirklich entfernen?', $d['host'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="domains" value="1">
                <input type="hidden" name="domain_del" value="<?= e($d['host']) ?>">
                <button class="btn btn-small btn-danger" type="submit"><?= t('Entfernen') ?></button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>

    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="domains" value="1">
        <div class="two-col">
            <div>
                <label for="d-host"><?= t('Weitere Domain') ?></label>
                <input id="d-host" type="text" name="domain_host" placeholder="kunde.link" autocomplete="off">
            </div>
            <div>
                <label for="d-group"><?= t('Vorbehalten für') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
                <select id="d-group" name="domain_group">
                    <option value=""><?= t('alle Konten') ?></option>
                    <?php foreach (groups_all() as $gid => $g): ?>
                    <option value="<?= e((string)$gid) ?>"><?= e((string)$g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p><button class="btn" type="submit"><?= t('Domain hinzufügen') ?></button></p>
    </form>
    <p class="muted small"><?= t('Die Domain muss zusätzlich beim Hoster auf dieses Verzeichnis zeigen und im Zertifikat stehen – das kann diese Oberfläche nicht für dich tun.') ?></p>
</div>

<div class="card">
    <h2><?= t('Browser-Erweiterung') ?></h2>
    <p class="muted small"><?= t('Wie Nutzende im Profil zu ihr kommen. Steht eine Adresse in einem Laden, erscheint dort ein Knopf dahin. Der Verbindungscode erscheint in jedem Fall – er richtet eine installierte Erweiterung mit einem Einfügen ein, auch die neutrale Fassung aus dem Laden.') ?></p>

    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="erweiterung" value="1">
        <?php $muster = [
            'chrome' => 'https://chromewebstore.google.com/detail/…',
            'firefox' => 'https://addons.mozilla.org/firefox/addon/…',
            'edge' => 'https://microsoftedge.microsoft.com/addons/detail/…',
        ]; ?>
        <?php foreach (ext_laden_namen() as $laden => $titel): ?>
        <label for="ext-<?= e($laden) ?>"><?= e($titel) ?></label>
        <input id="ext-<?= e($laden) ?>" type="url" name="ext_<?= e($laden) ?>" autocomplete="off"
               placeholder="<?= e($muster[$laden] ?? '') ?>" spellcheck="false"
               value="<?= e((string)($s['ext_stores'][$laden] ?? '')) ?>">
        <?php endforeach; ?>
        <p class="muted small"><?= t('Angenommen wird nur %s und nur die Adresse des jeweiligen Ladens – ein Knopf „Installieren“ ist eine Empfehlung und soll nicht irgendwohin zeigen können.', '<code>https</code>') ?></p>
        <p><button class="btn" type="submit"><?= t('Speichern') ?></button></p>
    </form>
</div>

<?php if (data_dir_in_webroot()): $probe = probe_last() ?? ['stand' => 'ungeprueft', 'zeit' => '', 'detail' => '']; ?>
<div class="flash flash-<?= ($probe['stand'] ?? '') === 'dicht' ? 'ok' : 'err' ?>">
    <?php if (($probe['stand'] ?? '') === 'offen'): ?>
    <strong><?= t('Das Datenverzeichnis ist über das Web abrufbar.') ?></strong>
    <?= t('Nachgeprüft am %s: Eine Testdatei in %s ließ sich von außen herunterladen. Damit sind auch Passwort-Hashes, gültige Reset-Token und das Instanz-Geheimnis abrufbar. Das ist kein Hinweis, sondern ein offener Zugang – bitte sofort handeln: entweder %s in %s auf einen Pfad außerhalb des Webroots stellen (Inhalt vorher kopieren) oder im Webserver einen Block für das Verzeichnis einrichten (siehe %s).',
        e(date('d.m.Y H:i', strtotime((string)$probe['zeit']))),
        '<code style="word-break:break-all">' . e(data_path()) . '</code>',
        '<code>data_dir</code>', '<code>inc/config.php</code>', '<code>docs/DEPLOYMENT.md</code>') ?>
    <?php elseif (($probe['stand'] ?? '') === 'dicht'): ?>
    <strong><?= t('Das Datenverzeichnis liegt im Webroot, ist aber dicht.') ?></strong>
    <?= t('Nachgeprüft am %s: %s Der Schutz hängt damit an der Webserver-Konfiguration – wird sie beim nächsten Umzug oder Upload nicht mitgenommen, liegt alles offen. Ein Pfad außerhalb des Webroots (%s) hängt an nichts.',
        e(date('d.m.Y H:i', strtotime((string)$probe['zeit']))), e((string)$probe['detail']), '<code>data_dir</code>') ?>
    <?php else: ?>
    <strong><?= t('Das Datenverzeichnis liegt im Webroot') ?></strong> (<code style="word-break:break-all"><?= e(data_path()) ?></code>).
    <?= t('Geschützt ist es dort nur durch die %s – die nginx, Caddy und LiteSpeed ignorieren. Darin stehen Passwort-Hashes und gültige Reset-Token. Wenn dein Hosting einen Pfad außerhalb zulässt, trag ihn als %s in %s ein – den Inhalt vorher kopieren, erst danach die Konfiguration umstellen.', '<code>.htaccess</code>', '<code>data_dir</code>', '<code>inc/config.php</code>') ?>
    <p class="small"><?= ($probe['stand'] ?? '') === 'ungeprueft'
        ? t('Ob der Schutz wirklich greift, lässt sich nachmessen: Der Knopf legt eine Testdatei in das Verzeichnis, ruft sie über die eigene Adresse ab und löscht sie wieder.')
        : t('Die Selbstprüfung konnte nichts feststellen: %s', e((string)($probe['detail'] ?? ''))) ?></p>
    <?php endif; ?>
    <form method="post" action="" style="margin-top:.6rem">
        <?= csrf_field() ?>
        <input type="hidden" name="probe" value="1">
        <button class="btn btn-small" type="submit"><?= ($probe['stand'] ?? '') === 'ungeprueft' ? t('Jetzt nachmessen') : t('Jetzt erneut prüfen') ?></button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= t('Öffentliche Oberfläche') ?></h2>
    <form method="post" action="">
        <?= csrf_field() ?>
        <div class="radio-group">
            <label class="radio">
                <input type="radio" name="public_mode" value="on"<?= $s['public_mode'] === 'on' ? ' checked' : '' ?>>
                <span><strong><?= t('Aktiv') ?></strong><br>
                <span class="muted"><?= t('Jeder kann zufällige Kurzlinks direkt unter %s/… erstellen.', e($host)) ?></span></span>
            </label>
            <label class="radio">
                <input type="radio" name="public_mode" value="prefix"<?= $s['public_mode'] === 'prefix' ? ' checked' : '' ?>>
                <span><strong><?= t('Aktiv, aber nur unter Prefix') ?></strong><br>
                <span class="muted"><?= t('Öffentlich erstellte Links landen unter %s/… – der restliche Namensraum bleibt für eingeloggte Nutzer reserviert.',
                    e($host) . '/<span id="pfx-preview">' . e($s['public_prefix']) . '</span>') ?></span></span>
            </label>
            <label class="radio">
                <input type="radio" name="public_mode" value="off"<?= $s['public_mode'] === 'off' ? ' checked' : '' ?>>
                <span><strong><?= t('Deaktiviert') ?></strong><br>
                <span class="muted"><?= t('Die Startseite zeigt nur einen Hinweis, Links entstehen ausschließlich im Login-Bereich. Bestehende Kurzlinks leiten weiterhin um.') ?></span></span>
            </label>
        </div>

        <label for="s-prefix"><?= t('Prefix für öffentliche Links') ?></label>
        <input id="s-prefix" type="text" name="public_prefix" value="<?= e($s['public_prefix']) ?>" pattern="[A-Za-z0-9_-]{1,64}"
               oninput="document.getElementById('pfx-preview').textContent = this.value || 'p'">
        <p class="muted small"><?= t('Gilt nur im Prefix-Modus und nur für neue Links – bestehende Kurzlinks behalten ihre Adresse.') ?></p>

        <label for="s-rate"><?= t('Rate-Limit der öffentlichen Erstellung (Links pro IP und Stunde)') ?></label>
        <input id="s-rate" type="number" name="public_rate_limit" value="<?= (int)$s['public_rate_limit'] ?>" min="1" max="1000">

        <h2 style="margin-top:1.5rem"><?= t('QR-Generatoren ohne Kurzlink') ?></h2>
        <p class="muted small"><?= t('WLAN, Kontakt, Termin, GS1 und der Designer im Modus „Ohne Kürzen“. Sie speichern nichts auf der Instanz – der fertige Code enthält die Daten selbst.') ?></p>
        <div class="radio-group">
            <label class="radio">
                <input type="radio" name="qr_public" value="auto"<?= ($s['qr_public'] ?? 'auto') === 'auto' ? ' checked' : '' ?>>
                <span><strong><?= t('Wie die Link-Erstellung') ?></strong><br>
                <span class="muted"><?= t('Öffentlich, solange auch das Kürzen öffentlich ist – wer oben deaktiviert, schließt die Generatoren mit.') ?></span></span>
            </label>
            <label class="radio">
                <input type="radio" name="qr_public" value="on"<?= ($s['qr_public'] ?? '') === 'on' ? ' checked' : '' ?>>
                <span><strong><?= t('Immer öffentlich') ?></strong><br>
                <span class="muted"><?= t('Auch bei deaktivierter Link-Erstellung – die Startseite verweist dann auf die Generatoren. Für Häuser, die allen QR-Codes anbieten, Kurzlinks aber den Konten vorbehalten.') ?></span></span>
            </label>
            <label class="radio">
                <input type="radio" name="qr_public" value="off"<?= ($s['qr_public'] ?? '') === 'off' ? ' checked' : '' ?>>
                <span><strong><?= t('Nur für Angemeldete') ?></strong><br>
                <span class="muted"><?= t('Gäste sehen die Generatoren nicht – auch dann nicht, wenn das Kürzen öffentlich ist.') ?></span></span>
            </label>
        </div>

        <h2 style="margin-top:1.5rem"><?= t('Registrierung') ?></h2>
        <div class="radio-group">
            <label class="radio">
                <input type="radio" name="registration" value="on"<?= $s['registration'] === 'on' ? ' checked' : '' ?>>
                <span><strong><?= t('Offen') ?></strong><br>
                <span class="muted"><?= t('Jeder kann sich mit E-Mail-Bestätigung (Double-Opt-In) ein Konto anlegen.') ?></span></span>
            </label>
            <label class="radio">
                <input type="radio" name="registration" value="off"<?= $s['registration'] !== 'on' ? ' checked' : '' ?>>
                <span><strong><?= t('Geschlossen') ?></strong><br>
                <span class="muted"><?= t('Neue Konten legt nur der Admin an. Bestehende Konten funktionieren weiter.') ?></span></span>
            </label>
        </div>

        <button class="btn btn-primary" type="submit" style="margin-top:1rem"><?= t('Speichern') ?></button>
    </form>
</div>
<div class="card">
    <h2><?= t('Sicherung') ?></h2>
    <p class="muted small"><?= t('Der beste Weg bleibt, das Datenverzeichnis zu kopieren. Wer dort nicht hinkommt – auf geteiltem Hosting die Regel –, bekommt hier dasselbe als Archiv: die Datenbank als in sich geschlossene Kopie (auch im laufenden Betrieb), dazu Einstellungen, Gruppen, Zugangsschlüssel, Klickzähler, Logos und Meldungen. Eine Anleitung zum Zurückspielen liegt bei.') ?></p>
    <p class="muted small"><?= t('Nicht enthalten ist %s – sie trägt Zugangsdaten und das Instanz-Geheimnis und gehört nicht in eine Datei, die danach im Download-Ordner liegt.', '<code>inc/config.php</code>') ?></p>
    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="sicherung" value="1">
        <button class="btn btn-primary" type="submit"><?= t('Sicherung herunterladen') ?></button>
    </form>
</div>

<?php page_footer(); ?>
