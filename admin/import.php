<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
require_once __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/domains.php';
require_once __DIR__ . '/../inc/utm.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/safety.php';
require_once __DIR__ . '/../inc/groups.php';

$user = auth_require();
$isAdmin = $user['role'] === 'admin';

/**
 * Der Import ist zugleich die Brücke, über die jemand von einem anderen Dienst
 * herüberkommt. Sie ganz hinter eine Berechtigung zu stellen hieße: Wer
 * wechseln will – der Interessent mit der höchsten Absicht überhaupt – steht
 * im Moment dieser Absicht vor einer Wand.
 *
 * Deshalb zwei Stufen. Ohne das Recht `csv_import` darf jedes Konto so viele
 * Zeilen einlesen, wie sein Link-Limit ohnehin zulässt; das Limit greift beim
 * Anlegen sowieso, Zeile für Zeile, und kostet uns also nichts. Erst der
 * Massenbetrieb darüber hinaus hängt am Recht.
 */
$darfMasse = user_can($user['name'], 'csv_import');
if (!$darfMasse && !$isAdmin) {
    $frei = user_limit($user['name'], 'links') - link_count($user['name']);
    if ($frei < 1) {
        page_header(t('CSV-Import'), true);
        echo '<div class="card center"><h1>' . t('CSV-Import') . '</h1>'
            . '<p>' . t('Dein Link-Kontingent ist ausgeschöpft – es ist kein Platz für weitere Links.') . '</p>'
            . '<p class="muted small">' . t('Lösche zuerst nicht mehr benötigte Links, oder lass dir vom Administrator mehr Platz einräumen.') . '</p>'
            . '<p><a class="btn" href="index.php">' . t('Zurück zu den Links') . '</a></p></div>';
        page_footer();
        exit;
    }
}

$assignable = $isAdmin ? array_values(array_filter(array_keys(groups_all()), 'group_shared')) : user_shared_groups($user['name']);
$mayCustom = user_can($user['name'], 'custom_code');

$maxRows = max(1, (int)cfg('import_max_rows'));
// Ohne das Recht auf Massen-Import reicht der Durchgang so weit wie der noch
// freie Platz im eigenen Kontingent – mehr könnte ohnehin nicht angelegt werden.
if (!$darfMasse && !$isAdmin) {
    $maxRows = min($maxRows, max(1, user_limit($user['name'], 'links') - link_count($user['name'])));
}
$results = null;

/**
 * Spalten einer Kopfzeile auf unsere Felder abbilden.
 *
 * Statt eine feste Reihenfolge zu verlangen, wird die Kopfzeile gelesen. Damit
 * lassen sich die Exporte von Bitly und YOURLS unverändert einlesen – und
 * jede andere Tabelle, deren Spalten vernünftig heißen. Fehlt eine Kopfzeile,
 * gilt weiterhin die alte Reihenfolge url;code;ablauf;name.
 *
 * @return array{url:int,code:int,title:int,expires:int,starts:int,tags:int} Spaltennummern, -1 = nicht vorhanden
 */
function import_spalten(array $kopf): array
{
    $bekannt = [
        'url' => ['long url', 'long_url', 'longurl', 'url', 'original url', 'original_url',
                  'destination', 'target', 'ziel', 'ziel-url', 'ziel url', 'lange url'],
        // 'shortcode'/'shorturl' sind die Spalten des Shlink-Exports (der Code
        // steht dort VOR der ganzen Adresse und gewinnt), 'address' die von Kutt.
        'code' => ['keyword', 'custom bitlink', 'bitlink', 'shortcode', 'short code', 'short url',
                   'short_url', 'shorturl', 'shortlink', 'short link', 'slug', 'alias', 'address',
                   'code', 'kurzcode', 'wunsch-code', 'kurzlink'],
        'title' => ['title', 'titel', 'name', 'description', 'beschreibung'],
        'expires' => ['expires', 'expires_at', 'expiry', 'expiration', 'ablauf', 'ablaufdatum'],
        'starts' => ['starts', 'starts_at', 'start', 'startdatum', 'gueltig ab', 'gültig ab'],
        'tags' => ['tags', 'tag', 'schlagworte', 'schlagwort', 'labels', 'keywords'],
    ];
    $map = ['url' => -1, 'code' => -1, 'title' => -1, 'expires' => -1, 'starts' => -1, 'tags' => -1];
    foreach ($kopf as $i => $name) {
        $name = strtolower(trim($name));
        foreach ($bekannt as $feld => $namen) {
            // Die erste passende Spalte gewinnt: Bitly führt „Bitlink" und
            // „Custom Bitlink"; die vordere ist die, die immer gefüllt ist.
            if ($map[$feld] === -1 && in_array($name, $namen, true)) $map[$feld] = $i;
        }
    }
    return $map;
}

/**
 * Das Trennzeichen einer Zeile raten.
 *
 * Komma und Semikolon sind der Normalfall; der Tabulator kommt dazu, weil ihn
 * jeder Datenbank-Export auf der Kommandozeile liefert. `mysql --batch -e
 * "SELECT keyword, url, title FROM yourls_url"` ist der naheliegendste Weg,
 * einen YOURLS-Bestand herauszuholen – und der schrieb ohne diese Zeile alles
 * in eine einzige Spalte, woraufhin der Import jede Zeile verwarf.
 */
function csv_trenner(string $zeile): string
{
    $beste = ',';
    $meiste = substr_count($zeile, ',');
    foreach ([';', "\t"] as $kandidat) {
        $n = substr_count($zeile, $kandidat);
        if ($n > $meiste) { $meiste = $n; $beste = $kandidat; }
    }
    return $beste;
}

/**
 * Eine CSV-Zeile zerlegen.
 *
 * str_getcsv() bekommt das Escape-Zeichen ausdrücklich mit: Ab PHP 8.4 mahnt
 * die Funktion an, dass ihr Standardwert sich ändern wird, und schriebe sonst
 * je Zeile eine Deprecated-Meldung ins Fehlerprotokoll – bei einem Import mit
 * 500 Zeilen also 500 Stück. Der leere String ist dabei nicht nur der künftige
 * Standard, sondern auch der richtige Wert: CSV nach RFC 4180 kennt kein
 * Escape mit Backslash, und in Ziel-Adressen darf am Feldende einer stehen,
 * ohne das nächste Feld anzukleben.
 */
function csv_zerlegen(string $zeile, string $sep): array
{
    return str_getcsv($zeile, $sep, '"', '');
}

/**
 * Kurzcode aus einer Spalte holen, die auch eine ganze Adresse enthalten darf.
 *
 * Bitly exportiert „bit.ly/3xYz9", YOURLS nur „3xYz9". Wir wollen in beiden
 * Fällen den letzten Pfadteil – so behält ein Umziehender seine Codes.
 */
function import_code(string $wert): string
{
    $wert = trim($wert);
    if ($wert === '') return '';
    if (str_contains($wert, '/')) {
        $teile = explode('/', rtrim($wert, '/'));
        $wert = (string)end($teile);
    }
    return $wert;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $raw = '';
    if (isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['csv']['size'] > 100 * 1024) {
            flash(t('Datei zu groß (max. 100 KB).'), 'err');
            redirect_to('import.php');
        }
        $raw = (string)file_get_contents($_FILES['csv']['tmp_name']);
    } else {
        $raw = (string)($_POST['daten'] ?? '');
    }
    $raw = str_replace("\xEF\xBB\xBF", '', $raw); // BOM entfernen

    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($l) => $l !== ''));

    // Kopfzeile erkennen und auswerten. Eine Zeile, die mit http beginnt, ist
    // schon ein Datensatz – dann gilt die alte feste Reihenfolge.
    $map = ['url' => 0, 'code' => 1, 'expires' => 2, 'title' => 3, 'tags' => 4];
    if ($lines !== [] && stripos($lines[0], 'http') !== 0) {
        $kopf = $lines[0];
        $sep = csv_trenner($kopf);
        $erkannt = import_spalten(array_map('strval', csv_zerlegen($kopf, $sep)));
        // Ohne erkannte Ziel-Spalte bleibt es bei der festen Reihenfolge –
        // sonst würde eine unverstandene Kopfzeile alle Zeilen verwerfen.
        if ($erkannt['url'] !== -1) $map = $erkannt;
        array_shift($lines);
    }

    if ($lines === []) {
        flash(t('Keine Zeilen gefunden – Format: eine URL pro Zeile, optional „;wunsch-code;ablaufdatum“.'), 'err');
        redirect_to('import.php');
    }
    if (count($lines) > $maxRows) {
        flash(t('Maximal %d Zeilen pro Import (gefunden: %d) – bitte aufteilen', $maxRows, count($lines))
            . ($isAdmin ? ' ' . t('oder import_max_rows in der Konfiguration erhöhen.') : '.'), 'err');
        redirect_to('import.php');
    }

    // Zeilen parsen (Trennzeichen , ; oder Tab – je Zeile geraten)
    $rows = [];
    foreach ($lines as $i => $line) {
        $sep = csv_trenner($line);
        $cols = array_map('trim', csv_zerlegen($line, $sep));
        $holen = fn(string $feld) => ($map[$feld] ?? -1) >= 0 ? (string)($cols[$map[$feld]] ?? '') : '';
        $url = $holen('url');
        $url = url_normalize($url);
        $rows[] = [
            'zeile' => $i + 1,
            'url' => $url,
            'code' => import_code($holen('code')),
            'expires' => $holen('expires'),
            'starts' => $holen('starts'),
            'title' => $holen('title'),
            // Shlink trennt Schlagwörter mit |, wir mit Komma – beides annehmen
            'tags' => str_replace('|', ',', $holen('tags')),
        ];
    }

    // Eine Safe-Browsing-Anfrage für alle URLs zusammen
    $flagged = urls_flagged(array_column($rows, 'url'));

    $quotaLinks = user_limit($user['name'], 'links');
    $quotaCustom = (int)settings()['custom_code_quota'];
    $minLen = (int)settings()['custom_code_min_len'];
    $usedLinks = link_count($user['name']);
    $usedCustom = custom_code_count($user['name']);

    $group = trim((string)($_POST['group'] ?? ''));
    if ($group === '' || !in_array($group, $assignable, true)) $group = null;

    // Die Domain gilt für den ganzen Import, nicht je Zeile: Wer eine Liste
    // hochlädt, tut das für einen Kunden – nicht für fünf verschiedene.
    $domain = domain_clean((string)($_POST['domain'] ?? ''));
    if ($domain === domain_main() || !domain_allowed($domain, $user['name'])) $domain = '';

    // Ebenso die Kampagne: Eine hochgeladene Liste gehört zu einer Aktion.
    $utm = (array)($_POST['utm'] ?? []);

    $results = [];
    $created = 0;
    foreach ($rows as $r) {
        $err = null;
        [$expOk, $expires] = parse_expiry($r['expires']);
        [$startOk, $starts] = parse_start($r['starts']);
        if ($utm !== []) $r['url'] = utm_apply($r['url'], $utm);
        if (!valid_url($r['url'])) {
            $err = t('Ungültige URL');
        } elseif (in_array($r['url'], $flagged, true)) {
            $err = t('Als schädlich gemeldet (Safe Browsing)');
        } elseif (!$expOk) {
            $err = t('Ungültiges Ablaufdatum (JJJJ-MM-TT, frühestens heute)');
        } elseif (!$startOk) {
            $err = t('Ungültiges Startdatum (JJJJ-MM-TT).');
        } elseif ($starts !== null && $expires !== null && $expires < $starts) {
            $err = t('Der Link kann nicht ablaufen, bevor er beginnt.');
        } elseif (!$isAdmin && $usedLinks + $created >= $quotaLinks) {
            $err = t('Link-Limit erreicht (%d)', $quotaLinks);
        } elseif ($r['code'] !== '') {
            if (!$mayCustom) {
                $err = t('Wunsch-Namen sind für dieses Konto nicht freigeschaltet');
            } elseif (!$isAdmin && mb_strlen($r['code']) < $minLen) {
                $err = t('Wunsch-Code zu kurz (mind. %d Zeichen)', $minLen);
            } elseif (!$isAdmin && $quotaCustom > 0 && $usedCustom >= $quotaCustom) {
                $err = t('Wunsch-Code-Kontingent erreicht (%d)', $quotaCustom);
            } elseif (!valid_code($r['code'])) {
                $err = t('Ungültiger oder reservierter Wunsch-Code');
            }
        }

        if ($err === null) {
            [$ok, $result] = link_create($r['url'], $r['code'] === '' ? null : $r['code'], $user['name'],
                $r['code'] === '' ? 'random' : 'custom',
                ['expires' => $expires, 'starts' => $starts, 'group' => $group, 'title' => $r['title'],
                 'tags' => $r['tags'], 'domain' => $domain]);
            if ($ok) {
                $created++;
                if ($r['code'] !== '') $usedCustom++;
                $results[] = ['zeile' => $r['zeile'], 'ok' => true, 'text' => short_url($result, $domain), 'url' => $r['url']];
                continue;
            }
            $err = $result;
        }
        $results[] = ['zeile' => $r['zeile'], 'ok' => false, 'text' => $err, 'url' => $r['url']];
    }
}

page_header(t('CSV-Import'), true);
show_flash();
?>

<div class="card">
    <h2><?= t('CSV-Import') ?> <span class="muted"><?= t('(bis zu %d Links auf einmal)', (int)$maxRows) ?></span></h2>
    <?php if (!$darfMasse && !$isAdmin): ?>
    <p class="muted small"><?= t('Dein Konto kann so viele Links auf einmal einlesen, wie in dein Kontingent passen (%d frei). Für größere Durchgänge gibt es die Berechtigung zum Massen-Import.', (int)$maxRows) ?></p>
    <?php endif; ?>
    <p class="muted small"><?= t('Eine Zeile pro Link: %s — alles außer der URL ist optional, als Trennzeichen geht Semikolon oder Komma. Alle Ziel-URLs werden vor dem Anlegen gesammelt auf Phishing/Malware geprüft.', '<code>url;wunsch-code;ablaufdatum;name;schlagworte;startdatum</code>') ?></p>
    <p class="muted small"><strong><?= t('Umzug von einem anderen Dienst?') ?></strong> <?= t('Die Exporte von %sBitly%s, %sYOURLS%s, Shlink (Web-Client) und Kutt lassen sich unverändert einlesen: Steht eine Kopfzeile darüber, werden die Spalten daran erkannt statt an ihrer Reihenfolge (%s bzw. %s). Enthält die Code-Spalte eine ganze Adresse wie %s, wird der letzte Teil übernommen – die Kurzcodes bleiben also erhalten.',
        '<strong>', '</strong>', '<strong>', '</strong>',
        '<code>Long URL</code>, <code>Bitlink</code>, <code>Title</code>',
        '<code>url</code>, <code>keyword</code>, <code>title</code>',
        '<code>bit.ly/3xYz9</code>') ?></p>

    <form method="post" action="" enctype="multipart/form-data" class="grid-form">
        <?= csrf_field() ?>
        <div>
            <label for="i-file"><?= t('CSV-Datei') ?></label>
            <input id="i-file" type="file" name="csv" accept=".csv,.txt">
        </div>
        <div>
            <label for="i-daten"><?= t('… oder direkt einfügen') ?></label>
            <textarea id="i-daten" name="daten" rows="6" style="width:100%; font-family:var(--mono); font-size:0.9rem; padding:0.6rem 0.75rem; border:1px solid var(--line); border-radius:var(--radius); background:var(--paper); color:var(--ink);" placeholder="https://example.com/sommer;sommerfest-2026;2026-09-30&#10;https://example.com/karte"></textarea>
        </div>
        <?php if ($assignable !== []): ?>
        <div>
            <label for="i-group"><?= t('Gruppe für alle importierten Links') ?> <span class="muted">(<?= t('optional') ?>)</span></label>
            <select id="i-group" name="group">
                <option value=""><?= t('– keine, nur für dich –') ?></option>
                <?php foreach ($assignable as $gid): ?>
                <option value="<?= e($gid) ?>"><?= e(group_label($gid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php $meineDomains = domains_for($user['name']); if (count($meineDomains) > 1): ?>
        <div>
            <label for="i-domain"><?= t('Domain für alle importierten Links') ?></label>
            <select id="i-domain" name="domain">
                <?php foreach ($meineDomains as $d): ?>
                <option value="<?= e($d) ?>"><?= e($d) ?><?= $d === domain_main() ? ' (' . t('Standard') . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?= utm_form('i', [], []) ?>
        <button class="btn btn-primary" type="submit"><?= t('Importieren') ?></button>
    </form>
</div>

<?php if ($results !== null): $okCount = count(array_filter($results, fn($r) => $r['ok'])); ?>
<div class="card">
    <h2><?= t('Ergebnis') ?> <span class="muted"><?= t('%d von %d angelegt', $okCount, count($results)) ?></span></h2>
    <div class="table-scroll"><table>
        <tr><th><?= t('Zeile') ?></th><th><?= t('Ziel-URL') ?></th><th><?= t('Ergebnis') ?></th></tr>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><?= (int)$r['zeile'] ?></td>
            <td class="url-cell" title="<?= e($r['url']) ?>"><?= e(mb_strimwidth($r['url'], 0, 50, '…')) ?></td>
            <td><?php if ($r['ok']): ?><a href="<?= e($r['text']) ?>" target="_blank" rel="noopener"><?= e($r['text']) ?></a>
                <?php else: ?><span class="badge badge-expired"><?= e($r['text']) ?></span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <p><a class="btn" href="index.php"><?= t('Zu den Links') ?></a> <a class="btn" href="import.php"><?= t('Weiterer Import') ?></a></p>
</div>
<?php endif; ?>
<?php page_footer(); ?>
