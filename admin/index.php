<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/safety.php';
require_once __DIR__ . '/../inc/groups.php';
require_once __DIR__ . '/../inc/routing.php';
require_once __DIR__ . '/../inc/linkrules.php';
require_once __DIR__ . '/../inc/domains.php';
require_once __DIR__ . '/../inc/utm.php';

$user = auth_require();
$isAdmin = $user['role'] === 'admin';
// Kontingent für Wunsch-Codes; 0 in der Konfiguration = unbegrenzt
$codeQuota = (int)settings()['custom_code_quota'];
$myGroups = user_groups($user['name']);
// Namensraum-Präfixe: leer = frei, sonst darf nur darunter angelegt werden
$myPrefixes = $isAdmin ? [] : user_prefixes($user['name']);
// Zuweisbar sind die eigenen Gruppen; Admins dürfen jeder Gruppe zuordnen
// Zuordnen lassen sich nur Arbeitsgruppen – eine Rechtegruppe wie ein Tarif
// hat mit der Verwaltung eines einzelnen Links nichts zu tun.
$assignable = $isAdmin ? array_values(array_filter(array_keys(groups_all()), 'group_shared')) : user_shared_groups($user['name']);
$mayCustom = user_can($user['name'], 'custom_code');
$darfWeichen = user_can($user['name'], 'link_rules');
// Den Besitzer umzuhängen heißt, einen Link aus einer fremden Liste zu nehmen
// oder in eine hineinzulegen. Das ist Verwaltung, kein Bearbeiten – deshalb an
// dieselben Rechte gebunden wie der Zugriff auf fremde Links.
$darfBesitzer = $isAdmin || user_can($user['name'], 'links_all');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        // Sämtliche Regeln stehen in inc/linkrules.php – dieselbe Fassung, die
        // auch die API benutzt.
        [$err, $full, $opts] = link_rules_create($user, [
            'url' => (string)($_POST['url'] ?? ''),
            'code' => (string)($_POST['code'] ?? ''),
            'prefix' => (string)($_POST['prefix'] ?? ''),
            'group' => (string)($_POST['group'] ?? ''),
            'expires' => (string)($_POST['expires'] ?? ''),
            'starts' => (string)($_POST['starts'] ?? ''),
            'title' => (string)($_POST['title'] ?? ''),
            'tags' => (string)($_POST['tags'] ?? ''),
            'lang' => (string)($_POST['lang'] ?? ''),
            'max_visits' => (string)($_POST['max_visits'] ?? ''),
            'domain' => (string)($_POST['domain'] ?? ''),
            'utm' => (array)($_POST['utm'] ?? []),
        ]);
        if ($err !== null) {
            flash($err, 'err');
        } else {
            $group = $opts['group'];
            // Weichen gleich beim Anlegen mitnehmen – dieselbe Auswertung wie
            // beim Bearbeiten. Bleiben die Zeilen leer, entsteht kein Feld.
            if ($darfWeichen) {
                [$wErr, $regeln] = route_from_form(
                    (array)($_POST['rw'] ?? []), (array)($_POST['ri'] ?? []), (array)($_POST['ru'] ?? []));
                if ($wErr !== null) {
                    flash($wErr, 'err');
                    redirect_to('index.php');
                }
                if ($regeln !== []) $opts['rules'] = $regeln;
            }
            [$ok, $result] = link_create($opts['url'], $full, $user['name'],
                $full === null ? 'random' : 'custom', $opts);
            if ($ok) {
                $linkpass = (string)($_POST['linkpass'] ?? '');
                if ($linkpass !== '') {
                    link_set_password($result, password_hash($linkpass, PASSWORD_DEFAULT));
                }
                // Die Ergebnis-Karte auf der Seite zeigt den Link selbst; die
                // Meldung kommt nur noch, wenn sie etwas hinzufügt.
                $zusatz = ($group !== null ? ' ' . t('für Gruppe „%s“', group_label($group)) : '')
                    . ($linkpass !== '' ? ' ' . t('(passwortgeschützt)') : '');
                if ($zusatz !== '') {
                    flash(t('Kurzlink %s angelegt', short_url($result, (string)($opts['domain'] ?? ''))) . $zusatz . '.');
                }
                redirect_to('index.php?hl=' . urlencode($result));
            }
            flash($result, 'err');
        }
    } elseif ($action === 'update') {
        $code = (string)($_POST['code'] ?? '');
        $link = link_get($code);
        $err = ($link === null || !link_access($user, $link)) ? t('Kein Zugriff auf diesen Link.') : null;
        $opts = [];
        if ($err === null) {
            [$err, $opts] = link_rules_update($user, $link, [
                'url' => (string)($_POST['url'] ?? ''),
                'expires' => (string)($_POST['expires'] ?? ''),
                'starts' => (string)($_POST['starts'] ?? ''),
                'group' => (string)($_POST['group'] ?? ''),
                'title' => (string)($_POST['title'] ?? ''),
                'tags' => (string)($_POST['tags'] ?? ''),
            'lang' => (string)($_POST['lang'] ?? ''),
            'max_visits' => (string)($_POST['max_visits'] ?? ''),
                'domain' => (string)($_POST['domain'] ?? ''),
                'utm' => (array)($_POST['utm'] ?? []),
            ]);
            foreach (['og_title', 'og_text', 'og_image'] as $f) {
                if (isset($_POST[$f])) $opts[$f] = (string)$_POST[$f];
            }
        }
        if ($err !== null) {
            flash($err, 'err');
        } else {
            if ($darfWeichen) {
                [$wErr, $regeln] = route_from_form(
                    (array)($_POST['rw'] ?? []), (array)($_POST['ri'] ?? []), (array)($_POST['ru'] ?? []));
                if ($wErr !== null) {
                    flash($wErr, 'err');
                    redirect_to('index.php?edit=' . urlencode($code));
                }
                $opts['rules'] = $regeln;
            }
            link_update($code, $opts['url'], $opts);
            // Gruppe nur anfassen, wenn das Formular sie überhaupt anbieten konnte
            if ($assignable !== [] || ($link['group'] ?? null) !== null) {
                link_set_group($code, $opts['group']);
            }
            // Ein abgelehnter Besitzerwechsel muss sichtbar werden. flash()
            // behält nur die letzte Meldung, und die käme unten – der Wechsel
            // wäre also stumm gescheitert, während „aktualisiert“ dasteht.
            $besitzerFehler = null;
            if ($darfBesitzer && isset($_POST['owner'])) {
                $neuerBesitzer = (string)$_POST['owner'];
                // Nur an existierende Konten übergeben – und niemanden im
                // Nirgendwo zurücklassen: ohne Gruppe kein leeres Feld.
                if ($neuerBesitzer !== '' && !isset(users_all()[$neuerBesitzer])) {
                    $besitzerFehler = t('dieses Konto gibt es nicht');
                } elseif ($neuerBesitzer === '' && (string)($opts['group'] ?? '') === '') {
                    $besitzerFehler = t('ohne Gruppe braucht der Link einen Besitzer, sonst findet ihn niemand mehr');
                } elseif ($neuerBesitzer !== (string)($link['owner'] ?? '')) {
                    link_set_owner($code, $neuerBesitzer === '' ? null : $neuerBesitzer);
                    audit(t('Besitzer von %s geändert: %s → %s', $code,
                        (string)($link['owner'] ?? '—'), $neuerBesitzer === '' ? '—' : $neuerBesitzer), $code);
                }
            }
            if (($_POST['linkpass_remove'] ?? '') === '1') {
                link_set_password($code, null);
            } elseif (($linkpass = (string)($_POST['linkpass'] ?? '')) !== '') {
                link_set_password($code, password_hash($linkpass, PASSWORD_DEFAULT));
            }
            if ($besitzerFehler === null) {
                flash(t('Kurzlink %s aktualisiert.', $code));
            } else {
                // Der Rest ist gespeichert – das zu verschweigen wäre so
                // irreführend wie den abgelehnten Wechsel zu verschweigen.
                flash(t('Kurzlink %s aktualisiert, aber der Besitzer blieb unverändert: %s.', $code, $besitzerFehler), 'err');
            }
        }
    } elseif ($action === 'delete') {
        $code = (string)($_POST['code'] ?? '');
        $link = link_get($code);
        if ($link === null || !link_access($user, $link)) {
            flash(t('Kein Zugriff auf diesen Link.'), 'err');
        } else {
            link_delete($code);
            flash(t('Kurzlink %s gelöscht.', $code));
        }
    }
    redirect_to('index.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$highlight = (string)($_GET['hl'] ?? '');
$gFilter = (string)($_GET['g'] ?? '');
$links = links_visible($user);
// Vorschläge für den Kampagnen-Baukasten aus dem Gesamtbestand, nicht aus dem
// gefilterten Ausschnitt: Sie sollen beim Anlegen vollständig sein, egal
// welcher Filter gerade in der Liste steht.
$utmVorschlaege = utm_suggestions($links);
// Zahlen für die Kopfzeile: Die Klicksumme liest je Link eine Zählerdatei –
// das lohnt bis ein paar tausend Links und wird darüber zur Bremse. Dann
// entfällt sie, die Liste liest ihre Zahlen ohnehin nur noch je Seite.
$klickVon = [];
$klicksGesamt = null;
$linksGesamt = count($links);
if ($linksGesamt <= 2000) {
    $klicksGesamt = 0;
    foreach ($links as $c => $l) {
        $klickVon[$c] = (int)(clicks_get((string)$c)['n'] ?? 0);
        $klicksGesamt += $klickVon[$c];
    }
}
if ($gFilter === '-') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === null);
} elseif ($gFilter !== '') {
    $links = array_filter($links, fn($l) => ($l['group'] ?? null) === $gFilter);
}
// Schlagwort-Filter vor der Suche: Die Wolke unter der Liste soll die Zahlen
// des gerade gewählten Ausschnitts zeigen, nicht die des Gesamtbestands.
$alleTags = tags_counts($links);
$tagFilter = mb_strtolower(trim((string)($_GET['tag'] ?? '')));
if ($tagFilter !== '') {
    $links = array_filter($links, fn($l) => in_array($tagFilter, (array)($l['tags'] ?? []), true));
}
if ($q !== '') {
    $links = array_filter($links, fn($l, $c) => stripos($c, $q) !== false
        || stripos($l['url'], $q) !== false
        || stripos((string)($l['title'] ?? ''), $q) !== false
        || in_array(mb_strtolower($q), (array)($l['tags'] ?? []), true), ARRAY_FILTER_USE_BOTH);
}
uasort($links, fn($a, $b) => strcmp($b['created'], $a['created']));

// CSV-Export des gefilterten Bestands – bewusst im Format des eigenen
// Imports (die Kopfzeile wird dort an den Namen erkannt): Wer exportiert,
// kann dieselbe Datei anderswo oder hier wieder einlesen. Der Rückweg ist
// kein Zusatz, sondern das Gegenstück zum Umzug HIERHER – niemand soll
// bleiben müssen, weil er seine Links nicht mitnehmen kann.
if (($_GET['export'] ?? '') === 'csv') {
    $csv = "code;url;name;schlagworte;startdatum;ablaufdatum;gruppe;domain;klicks;angelegt
";
    foreach ($links as $code => $l) {
        $csv .= implode(';', array_map('csv_feld', [
            (string)$code,
            (string)($l['url'] ?? ''),
            (string)($l['title'] ?? ''),
            implode(', ', (array)($l['tags'] ?? [])),
            (string)($l['starts'] ?? ''),
            (string)($l['expires'] ?? ''),
            (string)($l['group'] ?? ''),
            (string)($l['domain'] ?? ''),
            (string)(int)(clicks_get((string)$code)['n'] ?? 0),
            (string)($l['created'] ?? ''),
        ])) . "
";
    }
    nosniff_header();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="links-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    // BOM, damit Excel die Umlaute nicht verstümmelt – dieselbe Rücksicht
    // wie beim Serien-Export und beim Datenexport im Profil
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

// Blättern: 50 je Seite, neueste zuerst. $links bleibt der ganze gefilterte
// Bestand (für Zählung, Tag-Wolke und den Serien-Knopf), gerendert wird nur
// das aufgeschlagene Blatt – und nur dessen Klickzähler werden gelesen.
$jeSeite = 50;
$seiten = max(1, (int)ceil(count($links) / $jeSeite));
$seite = max(1, min($seiten, (int)($_GET['p'] ?? 1)));
$blatt = array_slice($links, ($seite - 1) * $jeSeite, $jeSeite, true);
foreach ($blatt as $c => $l) {
    if (!isset($klickVon[$c])) $klickVon[$c] = (int)(clicks_get((string)$c)['n'] ?? 0);
}

$editCode = (string)($_GET['edit'] ?? '');
$editLink = $editCode !== '' ? link_get($editCode) : null;
if ($editLink !== null && !link_access($user, $editLink)) $editLink = null;

page_header(t('Links'), true);
show_flash();
?>

<!-- .stage und .hero tragen keine eigene Bedeutung: Sie sind die Haken,
     an denen eine Instanz die Seite inszenieren kann – bei 1337.kiwi liegen
     Kopf und Formular damit auf derselben Fläche wie auf der Startseite und
     bei den QR-Generatoren. Ohne eigene Stile bleibt es eine schlichte Seite. -->
<div class="stage">
<div class="hero">
    <h1><?= $isAdmin ? t('Alle Links.') : t('Deine Links.') ?> <span class="accent"><?= t('Gezählt, nicht getrackt.') ?></span></h1>
    <p class="sub"><?= $klicksGesamt !== null
        ? t('%s Kurzlinks · %s Klicks – ein Zähler je Tag, mehr speichern wir nicht.',
            number_format($linksGesamt, 0, t(','), t('.')),
            number_format($klicksGesamt, 0, t(','), t('.')))
        : t('%s Kurzlinks – gezählt je Tag, mehr speichern wir nicht.',
            number_format($linksGesamt, 0, t(','), t('.'))) ?></p>
</div>

<?php
// Der frisch angelegte Link bekommt seine eigene Karte – mit Kurzadresse zum
// Kopieren und dem QR-Code, wie nach dem Kürzen auf der Startseite.
$neu = $highlight !== '' && $editLink === null ? link_get($highlight) : null;
if ($neu !== null && link_access($user, $neu)):
    $neuKurz = short_url($highlight, (string)($neu['domain'] ?? '')); ?>
<div class="card result">
    <h2><?= t('Angelegt.') ?></h2>
    <div class="short-row">
        <input id="short" type="text" readonly value="<?= e($neuKurz) ?>">
        <button class="btn" type="button" data-copy="#short"><?= t('Kopieren') ?></button>
    </div>
    <div class="qr-preview">
        <img src="../qr.php?c=<?= e(rawurlencode($highlight)) ?>&amp;size=240" width="180" height="180"
             alt="<?= t('QR-Code für %s', e($neuKurz)) ?>">
        <div class="qr-links">
            <a class="btn btn-small" href="qrdesign.php?c=<?= e(rawurlencode($highlight)) ?>"><?= t('Im QR-Designer gestalten') ?></a>
            <a class="btn btn-small" href="stats.php?c=<?= e(rawurlencode($highlight)) ?>"><?= t('Statistik') ?></a>
        </div>
    </div>
</div>
<?php endif; ?>

<form class="card create-form" method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label for="c-url"><?= t('Ziel-URL') ?></label>
    <div class="short-row">
        <input id="c-url" type="text" name="url" placeholder="https://example.com/…" required>
        <button class="btn btn-primary" type="submit"><?= t('Kürzen') ?></button>
    </div>
    <details class="mehr">
        <summary><?= t('Mehr Optionen: Name, Schlagworte, Wunsch-Name, Kampagne …') ?></summary>
        <div>
            <label for="c-title"><?= t('Name') ?> <span class="muted">(<?= t('optional – nur für dich, damit du den Link in der Liste wiederfindest') ?>)</span></label>
            <input id="c-title" type="text" name="title" maxlength="120" placeholder="<?= t('z. B. Speisekarte Sommer') ?>">
            <label for="c-tags"><?= t('Schlagworte') ?> <span class="muted">(<?= t('optional, mit Komma trennen – zum Filtern der Liste') ?>)</span></label>
            <input id="c-tags" type="text" name="tags" maxlength="220" placeholder="<?= t('kampagne, sommer, plakat') ?>">
        </div>
        <?= utm_form('c', [], $utmVorschlaege) ?>
        <?php $meineDomains = domains_for($user['name']); if (count($meineDomains) > 1): ?>
        <div>
            <label for="c-domain"><?= t('Domain') ?> <span class="muted">(<?= t('unter welcher Adresse der Link steht') ?>)</span></label>
            <select id="c-domain" name="domain">
                <?php foreach ($meineDomains as $d): ?>
                <option value="<?= e($d) ?>"><?= e($d) ?><?= $d === domain_main() ? ' ' . t('(Standard)') : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <div>
            <label for="c-code"><?= t('Wunsch-Name') ?> <span class="muted">(<?= t('leer = zufällig, Admin: ohne Limits') ?>)</span></label>
            <div class="short-row">
                <span class="prefix"><?= e(preg_replace('#^https?://#', '', base_url())) ?>/</span>
                <input id="c-code" type="text" name="code" placeholder="<?= t('wunschname') ?>" pattern="[A-Za-z0-9_-]{1,64}">
            </div>
        </div>
        <?php elseif ($mayCustom): ?>
        <div>
            <?php $used = custom_code_count($user['name']); ?>
            <label for="c-code"><?= t('Wunsch-Name') ?> <span class="muted">(<?= t('leer = zufällig · mind. %d Zeichen', (int)settings()['custom_code_min_len']) ?><?= $codeQuota > 0 ? ' · ' . t('%1$d/%2$d belegt', $used, $codeQuota) : '' ?>)</span></label>
            <div class="short-row">
                <span class="prefix"><?= e(preg_replace('#^https?://#', '', base_url())) ?>/</span>
                <?php if (count($myPrefixes) === 1): ?>
                    <span class="prefix"><?= e($myPrefixes[0]) ?>/</span>
                    <input type="hidden" name="prefix" value="<?= e($myPrefixes[0]) ?>">
                <?php elseif (count($myPrefixes) > 1): ?>
                    <select name="prefix" style="flex:0 0 auto;width:auto">
                        <?php foreach ($myPrefixes as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?>/</option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <input id="c-code" type="text" name="code" placeholder="<?= t('wunschname') ?>" pattern="[A-Za-z0-9_-]{<?= (int)settings()['custom_code_min_len'] ?>,64}">
            </div>
            <?php if ($myPrefixes !== []): ?>
            <p class="muted small"><?= t('Deine Links liegen im Namensraum %s – so kommen sich die Bereiche nicht in die Quere.',
                e(implode(t(' bzw. '), array_map(fn($p) => $p . '/', $myPrefixes)))) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!$mayCustom && !$isAdmin && $myPrefixes !== []): ?>
        <div>
            <label><?= t('Namensraum') ?></label>
            <?php if (count($myPrefixes) === 1): ?>
                <p class="muted small" style="margin:0"><?= t('Deine Links entstehen unter %s',
                '<code>' . e(preg_replace('#^https?://#', '', base_url())) . '/' . e($myPrefixes[0]) . '/…</code>') ?></p>
                <input type="hidden" name="prefix" value="<?= e($myPrefixes[0]) ?>">
            <?php else: ?>
                <select name="prefix">
                    <?php foreach ($myPrefixes as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?>/…</option><?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($assignable !== []): ?>
        <div>
            <label for="c-group"><?= t('Gruppe') ?> <span class="muted">(<?= t('optional – alle Mitglieder der Gruppe können den Link verwalten') ?>)</span></label>
            <select id="c-group" name="group">
                <option value="">– <?= t('keine, nur für dich') ?> –</option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="two-col">
            <div>
                <label for="c-starts"><?= t('Aktiv ab') ?> <span class="muted">(<?= t('optional, sonst sofort') ?>)</span></label>
                <input id="c-starts" type="date" name="starts" min="<?= e(date('Y-m-d')) ?>">
            </div>
            <div>
                <label for="c-expires"><?= t('Ablaufdatum') ?> <span class="muted">(<?= t('optional, gültig bis einschließlich') ?>)</span></label>
                <input id="c-expires" type="date" name="expires" min="<?= e(date('Y-m-d')) ?>">
            </div>
        </div>
        <div>
            <label for="c-maxv"><?= t('Aufruf-Limit') ?> <span class="muted">(<?= t('optional – danach antwortet der Link wie ein abgelaufener') ?>)</span></label>
            <input id="c-maxv" type="number" name="max_visits" min="1" placeholder="<?= t('leer = unbegrenzt') ?>" style="max-width:12rem">
        </div>
        <div>
            <label for="c-linkpass"><?= t('Passwortschutz') ?> <span class="muted">(<?= t('optional – Besucher müssen es vor der Weiterleitung eingeben') ?>)</span></label>
            <input id="c-linkpass" type="text" name="linkpass" autocomplete="off" placeholder="<?= t('leer = kein Schutz') ?>">
        </div>
        <?php if ($darfWeichen): ?>
        <?php // Weichen gab es nur beim Bearbeiten. Wer beim Anlegen schon
              // weiß, dass Handys woandershin sollen, musste erst speichern und
              // den Link danach wieder aufmachen. ?>
        <label style="margin-top:0.8rem"><?= t('Weichen') ?>
            <span class="muted"><?= t('(optional – je nach Gerät, Sprache, Land oder Anteil woandershin)') ?></span></label>
        <div class="ziel-sprache">
            <label for="c-lang"><?= t('Sprache der Ziel-URL oben') ?>
                <span class="muted"><?= t('(zwei Buchstaben, leer = unbekannt)') ?></span></label>
            <input id="c-lang" type="text" name="lang" maxlength="2" size="4" placeholder="de">
        </div>
        <?php foreach (range(0, 2) as $wi): ?>
        <div class="weiche">
            <select name="rw[<?= $wi ?>]" aria-label="<?= t('Merkmal') ?>">
                <?php foreach (route_kriterien() as $k => $label):
                    if ($k === 'country' && !route_land_moeglich()) continue; ?>
                <option value="<?= e($k) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="ri[<?= $wi ?>]" list="weichen-werte" maxlength="20" size="8"
                   placeholder="<?= t('mobile / en / at / 50') ?>" aria-label="<?= t('Wert') ?>">
            <input type="text" name="ru[<?= $wi ?>]" placeholder="https://…" aria-label="<?= t('Ziel dieser Weiche') ?>">
        </div>
        <?php endforeach; ?>
        <p class="muted small"><?= t('Die erste zutreffende Weiche gewinnt; trifft keine zu, gilt die Ziel-URL oben. Leere Zeilen werden nicht gespeichert. Was in das mittlere Feld gehört, steht beim Bearbeiten ausführlicher.') ?></p>
        <datalist id="weichen-werte">
            <?php foreach (route_werte('device') as $w => $label): ?>
            <option value="<?= e($w) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
            <option value="de">Deutsch</option><option value="en">English</option>
        </datalist>
        <?php endif; ?>
    </details>
    <p class="muted small" style="margin:0.7rem 0 0"><?= t('Viele auf einmal?') ?> <a href="import.php"><?= t('CSV-Import') ?></a></p>
</form>
</div><!-- /.stage -->

<?php if ($editLink !== null): ?>
<div class="card highlight">
    <h2><?= t('„%s“ bearbeiten', e($editCode)) ?></h2>
    <form method="post" action="" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="code" value="<?= e($editCode) ?>">
        <div>
            <label for="e-url"><?= t('Ziel-URL') ?></label>
            <input id="e-url" type="text" name="url" value="<?= e($editLink['url']) ?>" required>
            <label for="e-title"><?= t('Name') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
            <input id="e-title" type="text" name="title" maxlength="120" value="<?= e((string)($editLink['title'] ?? '')) ?>">
            <label for="e-tags"><?= t('Schlagworte') ?> <span class="muted">(<?= t('mit Komma trennen') ?>)</span></label>
            <input id="e-tags" type="text" name="tags" maxlength="220" value="<?= e(tags_text($editLink)) ?>">
            <?= utm_form('e', utm_extract((string)($editLink['url'] ?? '')), $utmVorschlaege) ?>
            <?php
            $meineDomains = domains_for($user['name']);
            $istDomain = (string)($editLink['domain'] ?? '');
            // Eine bereits gesetzte Domain gehört in die Liste, auch wenn das
            // Konto sie heute nicht mehr wählen dürfte – sonst verschwände sie
            // beim ersten Speichern, und der gedruckte Code zeigte ins Leere.
            if ($istDomain !== '' && !in_array($istDomain, $meineDomains, true)) $meineDomains[] = $istDomain;
            ?>
            <?php if (count($meineDomains) > 1): ?>
            <label for="e-domain"><?= t('Domain') ?></label>
            <select id="e-domain" name="domain">
                <?php foreach ($meineDomains as $d): ?>
                <option value="<?= e($d) ?>"<?= $d === ($istDomain === '' ? domain_main() : $istDomain) ? ' selected' : '' ?>><?= e($d) ?><?= $d === domain_main() ? ' ' . t('(Standard)') : '' ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted small"><?= t('Achtung: Ein bereits gedruckter QR-Code zeigt weiterhin auf die alte Adresse. Ändere sie nur, solange der Link noch nicht im Umlauf ist.') ?></p>
            <?php endif; ?>
        </div>
        <div class="two-col">
            <div>
                <label for="e-starts"><?= t('Aktiv ab') ?> <span class="muted">(<?= t('leer = sofort') ?>)</span></label>
                <input id="e-starts" type="date" name="starts" value="<?= e($editLink['starts'] ?? '') ?>">
            </div>
            <div>
                <label for="e-expires"><?= t('Ablaufdatum') ?> <span class="muted">(<?= t('leer = kein Ablauf') ?>)</span></label>
                <input id="e-expires" type="date" name="expires" value="<?= e($editLink['expires'] ?? '') ?>">
                <label for="e-maxv"><?= t('Aufruf-Limit') ?> <span class="muted">(<?= t('leer = unbegrenzt') ?>)</span></label>
                <input id="e-maxv" type="number" name="max_visits" min="1" style="max-width:12rem"
                       value="<?= (int)($editLink['max_visits'] ?? 0) > 0 ? (int)$editLink['max_visits'] : '' ?>">
            </div>
        </div>
        <?php if ($assignable !== [] || ($editLink['group'] ?? null) !== null): ?>
        <div>
            <label for="e-group"><?= t('Gruppe') ?></label>
            <select id="e-group" name="group">
                <option value="">– <?= t('keine, nur für dich') ?> –</option>
                <?php
                $opts = $assignable;
                // Eine bestehende Zuordnung bleibt wählbar, auch wenn die Gruppe
                // nicht mehr zu den eigenen gehört – sonst ginge sie beim
                // Speichern still verloren
                $cur = $editLink['group'] ?? null;
                if ($cur !== null && !in_array($cur, $opts, true)) $opts[] = $cur;
                foreach ($opts as $gid): ?>
                <option value="<?= e($gid) ?>"<?= $cur === $gid ? ' selected' : '' ?>><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($darfBesitzer): ?>
        <div>
            <label for="e-owner"><?= t('Besitzer') ?>
                <span class="muted"><?= t('(wer den Link in seiner Liste hat)') ?></span></label>
            <select id="e-owner" name="owner">
                <?php
                // „Niemand“ ist eine gültige Wahl – aber nur mit Gruppe, sonst
                // fände den Link außer Administratoren niemand mehr.
                $besitzerJetzt = (string)($editLink['owner'] ?? '');
                ?>
                <option value=""<?= $besitzerJetzt === '' ? ' selected' : '' ?>>
                    – <?= t('niemand, gehört nur der Gruppe') ?> –</option>
                <?php foreach (array_keys(users_all()) as $kandidat): ?>
                <option value="<?= e((string)$kandidat) ?>"<?= $besitzerJetzt === (string)$kandidat ? ' selected' : '' ?>>
                    <?= e(user_display((string)$kandidat)) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted small"><?= t('Ohne Besitzer braucht der Link eine Gruppe – sonst sieht ihn niemand mehr außer der Verwaltung.') ?></p>
        </div>
        <?php endif; ?>
        <div>
            <label for="e-linkpass"><?= t('Passwortschutz') ?>
                <span class="muted"><?= !empty($editLink['pass']) ? t('(aktiv – neues Passwort setzen oder entfernen)') : '(' . t('optional') . ')' ?></span></label>
            <input id="e-linkpass" type="text" name="linkpass" autocomplete="off" placeholder="<?= t('leer = unverändert') ?>">
            <?php if (!empty($editLink['pass'])): ?>
            <label class="radio" style="margin-top:0.4rem">
                <input type="checkbox" name="linkpass_remove" value="1">
                <span><?= t('Passwortschutz entfernen') ?></span>
            </label>
            <?php endif; ?>
        </div>
        <details class="mehr"<?= ($editLink['og_title'] ?? '') !== '' ? ' open' : '' ?>>
            <summary><?= t('Vorschau beim Teilen: eigener Titel und Bild') ?></summary>
            <p class="muted small"><?= t('Wer den Kurzlink in einen Chat oder ein soziales Netz klebt, sieht diese Angaben statt dessen, was die Zielseite hergibt. Ohne Titel passiert nichts – dann wird auch ein Vorschau-Abruf ganz normal weitergeleitet. Vorschau-Abrufe zählen nicht als Klicks.') ?></p>
            <label for="e-ogt"><?= t('Titel') ?></label>
            <input id="e-ogt" type="text" name="og_title" maxlength="120" value="<?= e((string)($editLink['og_title'] ?? '')) ?>">
            <label for="e-ogd"><?= t('Beschreibung') ?></label>
            <input id="e-ogd" type="text" name="og_text" maxlength="200" value="<?= e((string)($editLink['og_text'] ?? '')) ?>">
            <label for="e-ogi"><?= t('Bild-Adresse') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
            <input id="e-ogi" type="text" name="og_image" maxlength="500" placeholder="https://…"
                   value="<?= e((string)($editLink['og_image'] ?? '')) ?>">
        </details>
        <?php if ($darfWeichen):
            $weichen = array_values((array)($editLink['rules'] ?? []));
            // Eine leere Zeile mehr, damit sich ohne Klick eine Weiche
            // anlegen lässt; beim Speichern fällt sie weg, wenn sie leer bleibt.
            // Drei leere Zeilen statt einer: Mit nur einer ließe sich pro
            // Speichervorgang genau eine Weiche anlegen, und niemand kommt von
            // selbst darauf, dass Speichern die nächste Zeile bringt. Leere
            // Zeilen fallen beim Speichern ohnehin weg.
            // Zielzahl vorher festhalten: Stünde count($weichen) in der
            // Bedingung, wüchse die Schranke mit jeder angehängten Zeile mit –
            // und das Formular liefe bis ROUTE_MAX voll.
            $bisher = count($weichen);
            for ($f = $bisher; $f < min($bisher + 3, ROUTE_MAX); $f++) {
                $weichen[] = ['wenn' => 'device', 'ist' => '', 'url' => ''];
            }
            $klicks = (array)(clicks_get($editCode)['routes'] ?? []);
        ?>
        <details class="mehr"<?= count($weichen) > 1 ? ' open' : '' ?>>
            <summary><?= t('Weichen: je nach Gerät, Sprache, Land oder Anteil woandershin') ?></summary>
            <p class="muted small"><?= t('Die erste zutreffende Weiche gewinnt; trifft keine zu, gilt die Ziel-URL oben. Ausgewertet wird bei jedem Aufruf – gespeichert wird davon nichts. Höchstens %d Weichen.', ROUTE_MAX) ?>
            <?php if (!route_land_moeglich()): ?>
            <br><?= t('„Land“ steht auf dieser Instanz nicht zur Verfügung: Es kommt von einem vorgeschalteten Dienst, und dafür muss %s in der Konfiguration eingetragen sein.', '<code>trusted_proxies</code>') ?>
            <?php endif; ?></p>
            <?php foreach ($weichen as $i => $r): ?>
            <div class="weiche">
                <select name="rw[<?= $i ?>]" aria-label="<?= t('Merkmal') ?>">
                    <?php foreach (route_kriterien() as $k => $label):
                        if ($k === 'country' && !route_land_moeglich() && ($r['wenn'] ?? '') !== 'country') continue; ?>
                    <option value="<?= e($k) ?>"<?= ($r['wenn'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="ri[<?= $i ?>]" value="<?= e((string)($r['ist'] ?? '')) ?>"
                       list="weichen-werte" maxlength="20" size="8"
                       placeholder="<?= t('mobile / en / at / 50') ?>" aria-label="<?= t('Wert') ?>">
                <input type="text" name="ru[<?= $i ?>]" value="<?= e((string)($r['url'] ?? '')) ?>"
                       placeholder="https://…" aria-label="<?= t('Ziel dieser Weiche') ?>">
                <span class="muted small weiche-n"><?= isset($klicks[(string)$i]) ? t('%dx', (int)$klicks[(string)$i]) : '' ?></span>
            </div>
            <?php endforeach; ?>
            <datalist id="weichen-geraete">
                <?php foreach (route_werte('device') as $w => $label): ?>
                <option value="<?= e($w) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </datalist>
            <datalist id="weichen-sprachen">
                <option value="de">Deutsch</option><option value="en">English</option>
                <option value="fr">Français</option><option value="es">Español</option>
                <option value="at">Österreich</option><option value="ch">Schweiz</option>
            </datalist>
            <datalist id="weichen-werte"><?php // Rückfall ohne Skript ?>
                <?php foreach (route_werte('device') as $w => $label): ?>
                <option value="<?= e($w) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
                <option value="de">Deutsch</option><option value="en">English</option>
            </datalist>
            <div class="ziel-sprache">
                <label for="e-lang"><?= t('Sprache der Ziel-URL oben') ?>
                    <span class="muted"><?= t('(zwei Buchstaben, leer = unbekannt)') ?></span></label>
                <input id="e-lang" type="text" name="lang" maxlength="2" size="4"
                       value="<?= e((string)($editLink['lang'] ?? '')) ?>" placeholder="de">
                <p class="muted small">
                    <?= t('Nur mit dieser Angabe lässt sich richtig entscheiden: Wer die Sprache des Hauptziels bevorzugt, bleibt dort – wer eine andere bevorzugt, bekommt die passende Weiche, notfalls über seine Zweitsprache. Ohne die Angabe wird nur umgeleitet, wer die Sprache der Weiche <b>bevorzugt</b>.') ?>
                </p>
            </div>
            <table class="weichen-hilfe">
                <tr>
                    <th><?= t('Gerät') ?></th>
                    <td><code>mobile</code> · <code>tablet</code> · <code>desktop</code></td>
                    <td class="muted"><?= t('grob aus der Browser-Kennung; etwas anderes wird abgelehnt') ?></td>
                </tr>
                <tr>
                    <th><?= t('Sprache') ?></th>
                    <td><code>de</code> · <code>en</code> · <code>fr</code> …</td>
                    <td class="muted"><?= t('immer zwei Buchstaben; es zählt die <b>bevorzugte</b> Sprache des Browsers – <code>en</code> trifft auch bei <code>en-GB</code>, aber nicht bei jemandem, der Deutsch bevorzugt und Englisch nur als Zweitsprache führt') ?></td>
                </tr>
                <tr>
                    <th><?= t('Land') ?></th>
                    <td><code>at</code> · <code>ch</code> …</td>
                    <td class="muted"><?= t('zwei Buchstaben; kommt von einem vorgeschalteten Dienst') ?></td>
                </tr>
                <tr>
                    <th><?= t('Anteil (A/B)') ?></th>
                    <td><code>1</code> – <code>99</code></td>
                    <td class="muted"><?= t('Prozent, ohne Zeichen: <code>30</code> schickt knapp jeden dritten Aufruf hierher, der Rest geht weiter zur nächsten Weiche oder zum Hauptziel') ?></td>
                </tr>
            </table>
            <p class="muted small"><?= t('Leere Zeilen werden nicht gespeichert. Eine Weiche löschen: Wert und Ziel leeren.') ?></p>
        </details>
        <?php endif; ?>
        <div class="short-row">
            <button class="btn btn-primary" type="submit"><?= t('Speichern') ?></button>
            <a class="btn" href="index.php"><?= t('Abbrechen') ?></a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="list-head">
        <h2><?= $isAdmin ? t('Alle Links') : ($myGroups === [] ? t('Deine Links') : t('Deine und Gruppen-Links')) ?> <span class="muted">(<?= count($links) ?>)</span></h2>
        <form method="get" action="" class="short-row">
            <?php if ($assignable !== []): ?>
            <select name="g" data-autosubmit>
                <option value=""><?= t('alle Gruppen') ?></option>
                <option value="-"<?= $gFilter === '-' ? ' selected' : '' ?>><?= t('ohne Gruppe') ?></option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"<?= $gFilter === $gid ? ' selected' : '' ?>><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('Suchen…') ?>">
            <?php if ($tagFilter !== ''): ?><input type="hidden" name="tag" value="<?= e($tagFilter) ?>"><?php endif; ?>
            <button class="btn btn-small" type="submit"><?= t('Suchen') ?></button>
        </form>
    </div>
    <?php if ($alleTags !== []): ?>
    <p class="tag-row" style="margin:0 0 0.9rem">
        <?php if ($tagFilter !== ''): ?>
            <a class="tag" href="index.php<?= $q !== '' ? '?q=' . e(rawurlencode($q)) : '' ?>"><?= t('alle anzeigen') ?></a>
        <?php endif; ?>
        <?php foreach ($alleTags as $t => $n): ?>
        <a class="tag<?= $t === $tagFilter ? ' tag-on' : '' ?>"
           href="index.php?tag=<?= e(rawurlencode((string)$t)) ?><?= $q !== '' ? '&amp;q=' . e(rawurlencode($q)) : '' ?>"><?= e((string)$t) ?>
           <span class="muted"><?= (int)$n ?></span></a>
        <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <?php if ($links === []): ?>
        <p class="muted"><?= t('Noch keine Links') ?><?= $q !== '' ? ' ' . t('für diese Suche') : '' ?><?= $tagFilter !== '' ? ' ' . t('mit diesem Schlagwort') : '' ?>.</p>
    <?php else: ?>
    <?php
    // Der Filter wird mitgenommen: Wer nach einem Schlagwort gefiltert hat und
    // dann eine Serie zieht, meint genau diese Links.
    $serieFilter = array_filter(['g' => $gFilter, 'tag' => $tagFilter, 'q' => $q], fn($v) => $v !== '');
    ?>
    <p class="muted small" style="margin:0 0 0.5rem">
        <a class="btn btn-small" href="qrzip.php<?= $serieFilter !== [] ? '?' . e(http_build_query($serieFilter)) : '' ?>"><?= t('QR-Codes dieser %d Links als ZIP', count($links)) ?></a>
        <a class="btn btn-small" href="index.php?<?= e(http_build_query($serieFilter + ['export' => 'csv'])) ?>"><?= t('CSV-Export') ?></a>
    </p>
    <div class="link-list">
        <?php foreach ($blatt as $code => $link):
            $kurz = short_url((string)$code, (string)($link['domain'] ?? ''));
            $utm = utm_extract((string)($link['url'] ?? ''));
        ?>
        <article class="link-row<?= $code === $highlight ? ' row-hl' : '' ?>">
            <div class="link-main">
                <div class="link-line">
                    <a class="link-code" href="<?= e($kurz) ?>" target="_blank" rel="noopener"><?= e((string)$code) ?></a>
                    <button class="btn btn-small btn-copy" type="button"
                            data-copy-text="<?= e($kurz) ?>"><?= t('Kopieren') ?></button>
                    <?php if (($link['domain'] ?? '') !== ''): ?><span class="badge badge-quiet" title="<?= e((string)$link['domain']) ?>"><?= e((string)$link['domain']) ?></span><?php endif; ?>
                    <?php if (!empty($link['pass'])): ?><span class="badge badge-quiet" title="<?= t('passwortgeschützt') ?>">PW</span><?php endif; ?>
                    <?php if ($utm !== []): ?><span class="badge badge-quiet" title="<?= t('Kampagne:') ?> <?= e(implode(' · ', $utm)) ?>">UTM<?= isset($utm['utm_campaign']) ? ' ' . e($utm['utm_campaign']) : '' ?></span><?php endif; ?>
                    <?php if (($link['expires'] ?? null) !== null && link_expired($link)): ?>
                        <span class="badge badge-expired" title="<?= t('seit %s', e(date('d.m.Y', strtotime($link['expires'])))) ?>"><?= t('abgelaufen') ?></span>
                    <?php elseif (link_pending($link)): ?>
                        <span class="badge badge-quiet" title="<?= t('ab %s', e(date('d.m.Y', strtotime((string)$link['starts'])))) ?>"><?= t('geplant') ?></span>
                    <?php endif; ?>
                </div>
                <?php if (($link['title'] ?? '') !== ''): ?><div class="link-title"><?= e((string)$link['title']) ?></div><?php endif; ?>
                <div class="link-ziel" title="<?= e($link['url']) ?>">→ <?= e(mb_strimwidth($link['url'], 0, 80, '…')) ?></div>
                <div class="link-unter">
                    <?php
                    $g = $link['group'] ?? null;
                    if ($g !== null) echo '<span class="tag tag-on">' . e(group_label($g)) . '</span>';
                    ?>
                    <?php if (($link['tags'] ?? []) !== []): ?>
                        <?php foreach ((array)$link['tags'] as $t): ?>
                        <a class="tag<?= $t === $tagFilter ? ' tag-on' : '' ?>" href="index.php?tag=<?= e(rawurlencode($t)) ?>"><?= e($t) ?></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <span class="muted small"><?= e(date('d.m.Y', strtotime($link['created']))) ?></span>
                    <?php if (link_pending($link)): ?>
                        <span class="muted small" title="<?= t('Aktiv ab') ?>">▶ <?= e(date('d.m.Y', strtotime((string)$link['starts']))) ?></span>
                    <?php endif; ?>
                    <?php if (($link['expires'] ?? null) !== null && !link_expired($link)): ?>
                        <span class="muted small" title="<?= t('Läuft ab') ?>">⏳ <?= e(date('d.m.Y', strtotime($link['expires']))) ?></span>
                    <?php endif; ?>
                    <?php if ($isAdmin && ($link['owner'] ?? null) !== null): ?>
                        <span class="muted small" title="<?= e((string)$link['owner']) ?>"><?= e(user_display((string)$link['owner'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <a class="link-klicks" href="stats.php?c=<?= e(rawurlencode((string)$code)) ?>" title="<?= t('Statistik') ?>">
                <strong><?= number_format($klickVon[$code] ?? 0, 0, t(','), t('.')) ?></strong>
                <span><?= t('Klicks') ?></span>
            </a>
            <div class="link-actions actions">
                <a class="btn btn-small" href="qrdesign.php?c=<?= e(rawurlencode((string)$code)) ?>">QR</a>
                <a class="btn btn-small" href="index.php?edit=<?= e(rawurlencode((string)$code)) ?>"><?= t('Bearbeiten') ?></a>
                <form method="post" action="" class="inline" data-confirm="<?= t('Kurzlink „%s“ wirklich löschen?', e((string)$code)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="code" value="<?= e((string)$code) ?>">
                    <button class="btn btn-small btn-danger" type="submit"><?= t('Löschen') ?></button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php if ($seiten > 1):
        // Filter wandern mit von Seite zu Seite; nur die Seitenzahl wechselt
        $blattUrl = function (int $p) use ($gFilter, $tagFilter, $q): string {
            $teile = array_filter(['g' => $gFilter, 'tag' => $tagFilter, 'q' => $q, 'p' => $p > 1 ? $p : ''],
                fn($v) => $v !== '' && $v !== null);
            return 'index.php' . ($teile !== [] ? '?' . http_build_query($teile) : '');
        };
    ?>
    <nav class="blaettern" aria-label="<?= t('Seiten der Linkliste') ?>">
        <?php if ($seite > 1): ?>
            <a class="btn btn-small" href="<?= e($blattUrl($seite - 1)) ?>">← <?= t('Neuere') ?></a>
        <?php endif; ?>
        <span class="muted small"><?= t('Seite %d von %d', $seite, $seiten) ?></span>
        <?php if ($seite < $seiten): ?>
            <a class="btn btn-small" href="<?= e($blattUrl($seite + 1)) ?>"><?= t('Ältere') ?> →</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php page_footer(); ?>
