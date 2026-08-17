<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Link-in-Bio-Seiten: eine Seite mit mehreren Zielen unter einem Kurzcode.
 *
 * Gedacht für die eine Stelle, an der nur ein Link erlaubt ist – das Profil in
 * einem sozialen Netz, die Fußzeile einer Speisekarte, der Aufkleber am
 * Schaufenster.
 *
 * **Warum das hier hineinpasst und nicht ein Fremdkörper ist:** Eine solche
 * Seite ist im Kern nichts als ein Eintrag im Kurzlink-Bestand, der statt einer
 * Zieladresse eine Liste davon trägt. Dadurch erbt sie alles, was für Kurzlinks
 * schon gilt: die Vergabe eindeutiger Codes, Besitz und Gruppenzugehörigkeit,
 * die Zugriffsprüfung, Ablaufdatum, Sperre, Löschung und den QR-Code. Es gibt
 * keine zweite Ablage und kein zweites Rechtemodell.
 *
 * **Und warum sie datenschutzseitig nichts Neues aufmacht:** Gezählt wird wie
 * überall – ein Zähler je Tag, für die Seite und je Ziel. Kein Datensatz über
 * einen Besucher, keine Herkunft, kein Gerät. Genau darin unterscheidet sie
 * sich von den bekannten Anbietern: Deren Geschäft ist die Auswertung der
 * Besucher, nicht die Seite.
 */
require_once __DIR__ . '/store.php';

/** Höchstzahl der Ziele auf einer Seite – jenseits davon liest sie ohnehin niemand */
const BIO_MAX_ITEMS = 30;

function bio_is(?array $l): bool
{
    return is_array($l) && ($l['kind'] ?? '') === 'bio';
}

/**
 * Aus den Feldpaaren des Formulars eine Zielliste bauen.
 *
 * Beide Reihen kommen als gleich lange Listen aus dem Formular; leere Paare
 * werden übersprungen, damit stehengebliebene Leerzeilen niemanden aufhalten.
 *
 * @param string[] $labels
 * @param string[] $urls
 * @return array{0:?string,1:array<int,array{label:string,url:string}>}
 */
function bio_items_from_fields(array $labels, array $urls): array
{
    $items = [];
    foreach (array_values($urls) as $i => $url) {
        $url = trim((string)$url);
        $label = trim((string)(array_values($labels)[$i] ?? ''));
        if ($url === '' && $label === '') continue;

        if ($url === '') {
            return ['Zeile ' . ($i + 1) . ': „' . mb_strimwidth($label, 0, 40, '…')
                . '" hat keine Adresse.', []];
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }
        if (!valid_url($url)) {
            return ['Zeile ' . ($i + 1) . ': „' . mb_strimwidth($url, 0, 40, '…')
                . '" ist keine gültige Adresse (nur http/https).', []];
        }
        // Ohne Anzeigenamen die Adresse selbst, ohne Schema und Schrägstrich –
        // besser als eine leere Schaltfläche.
        if ($label === '') {
            $label = rtrim((string)preg_replace('#^https?://(www\.)?#', '', $url), '/');
        }
        $items[] = [
            'label' => mb_substr((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $label), 0, 80),
            'url' => $url,
        ];
        if (count($items) > BIO_MAX_ITEMS) {
            return [t('Höchstens %d Ziele pro Seite.', BIO_MAX_ITEMS), []];
        }
    }
    if ($items === []) return [t('Mindestens ein Ziel angeben.'), []];
    return [null, $items];
}

/**
 * Zielliste und Seitenangaben schreiben.
 *
 * Setzt zugleich die Kennzeichnung `kind`, an der der Weiterleitungspfad eine
 * Seite von einem gewöhnlichen Kurzlink unterscheidet.
 */
function bio_write(string $code, array $items, string $text, bool $index, ?array $stil = null): bool
{
    return link_write($code, function (?array $l) use ($items, $text, $index, $stil) {
        if ($l === null) return false;
        $l['kind'] = 'bio';
        $l['items'] = array_values($items);
        if ($text === '') unset($l['bio_text']); else $l['bio_text'] = $text;
        if ($index) $l['bio_index'] = true; else unset($l['bio_index']);
        // null heißt „nicht angefasst": Wem die Gestaltung fehlt, dessen
        // Speichern darf eine vorhandene nicht löschen – etwa wenn ein
        // Pro-Konto ausläuft und die Seite später wieder bearbeitet wird.
        if ($stil !== null) {
            $logo = (string)($stil['logo'] ?? '');
            if ($logo === '') unset($l['bio_logo']); else $l['bio_logo'] = $logo;
            $farben = [];
            foreach (bio_default_colors() as $k => $vor) {
                $v = strtolower(trim((string)($stil['colors'][$k] ?? '')));
                if (preg_match('/^#[0-9a-f]{6}$/', $v) === 1 && $v !== $vor) $farben[$k] = $v;
            }
            if ($farben === []) unset($l['bio_colors']); else $l['bio_colors'] = $farben;
        }
        $l['updated'] = date('c');
        return $l;
    });
}

/** Wie viele Bio-Seiten dieses Konto schon hat */
function bio_count(string $owner): int
{
    return count(array_filter(links_of_owner($owner), 'bio_is'));
}

/**
 * Vorgabe-Farben einer Bio-Seite.
 *
 * Aus der Konfiguration, damit eine Instanz ihr eigenes Aussehen durchreichen
 * kann: Wer nichts einstellt, soll keine fremde Seite bekommen, sondern eine,
 * die nach dem Dienst aussieht, über den sie läuft. Ohne Angabe bleibt ein
 * neutrales Dunkelgrau.
 */
function bio_default_colors(): array
{
    static $vor = null;
    if ($vor !== null) return $vor;
    $vor = ['bg' => '#0f1216', 'ink' => '#f2f4f7', 'btn' => '#f2f4f7', 'btn_ink' => '#0f1216'];
    foreach ((array)cfg('bio_default_colors') as $k => $v) {
        if (isset($vor[$k]) && is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1) {
            $vor[$k] = strtolower($v);
        }
    }
    return $vor;
}

/**
 * Adresse des eigenen Logos einer Seite, sonst leer.
 *
 * Kein Ersatz aus der Instanz: Wer kein eigenes Logo hat, bekommt oben die
 * Wortmarke des Dienstes – siehe bio_brand_block().
 */
function bio_logo_url(array $l): string
{
    $eigen = (string)($l['bio_logo'] ?? '');
    // Die Kennungen tragen eine Endung (a1b2….png); ältere aus der Zeit davor
    // nicht. Beides muss durchgehen, Punkte und Schrägstriche sonst nirgends.
    if ($eigen !== '' && preg_match('/^[a-f0-9]{16,64}(\.(png|jpe?g|webp|svg))?$/', $eigen) === 1
        && is_file(data_path('logos') . '/' . $eigen)) {
        return base_url() . '/logo.php?id=' . rawurlencode($eigen);
    }
    return '';
}

/** Die Wortmarke des Dienstes, wie im Seitenkopf ausgezeichnet */
function bio_wordmark(): string
{
    $name = (string)cfg('site_name');
    $punkt = strpos($name, '.');
    $marke = ($punkt === false || $punkt === 0)
        ? e($name)
        : e(substr($name, 0, $punkt)) . '<span class="bio-tld">' . e(substr($name, $punkt)) . '</span>';
    return '<span class="bio-brand">' . $marke . '</span>';
}

/**
 * Markenblock am Kopf einer Seite ohne eigenes Logo.
 *
 * Wo nichts Eigenes steht, steht der Dienst – aber als das, was er ist: die
 * Kopfzeile, die man von ihm kennt, Zeichen und Wortmarke nebeneinander. Wer
 * ein eigenes Logo hinterlegt, sieht diesen Block nicht; dort bleibt vom
 * Betreiber nur die Zeile im Fuß.
 */
function bio_brand_block(): string
{
    $logo = trim((string)cfg('logo'));
    $bild = '';
    if ($logo !== '' && is_file(dirname(__DIR__) . '/assets/' . basename($logo))) {
        $bild = '<img class="bio-brandmark" src="' . e(base_url() . '/assets/' . basename($logo))
            . '" alt="" width="44" height="44">';
    }
    return '<a class="bio-brandtop" href="' . e(base_url() . '/') . '" rel="noopener">'
        . $bild . bio_wordmark() . '</a>';
}

/**
 * Die gewählten Farben einer Seite, aufgefüllt mit den Vorgaben.
 *
 * @return array{bg:string,ink:string,btn:string,btn_ink:string}
 */
function bio_colors(array $l): array
{
    $out = bio_default_colors();
    foreach ((array)($l['bio_colors'] ?? []) as $k => $v) {
        if (isset($out[$k]) && is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1) {
            $out[$k] = strtolower($v);
        }
    }
    return $out;
}

/**
 * Die öffentliche Seite ausgeben.
 *
 * Bewusst ein eigenes Dokument statt page_header/page_footer: Eine Bio-Seite
 * ist nicht die Unterseite eines Kurzlink-Dienstes, sondern der Auftritt
 * dessen, der sie angelegt hat. Eine Navigation zu Anmeldung und Tarifen hätte
 * dort nichts zu suchen – wer den QR-Code am Schaufenster scannt, will die
 * Speisekarte und nicht unser Menü.
 *
 * Vom Betreiber bleibt eine Zeile im Fuß. Sie ist der Preis dafür, dass es die
 * Seite kostenlos gibt, und bewusst klein gehalten.
 *
 * Die Ziele zeigen nicht unmittelbar auf ihre Adresse, sondern zurück auf den
 * eigenen Code mit einer laufenden Nummer. Nur so lässt sich zählen, welches
 * Ziel gefragt ist – und auch das wieder nur als Zahl je Tag.
 */
function bio_render(string $code, array $l): never
{
    $items = (array)($l['items'] ?? []);
    $titel = trim((string)($l['title'] ?? '')) !== '' ? (string)$l['title'] : (string)$code;
    $text = trim((string)($l['bio_text'] ?? ''));
    $f = bio_colors($l);

    clicks_bump($code);

    $logoUrl = bio_logo_url($l);

    security_headers();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($titel) . '</title>';
    if ($text !== '') {
        echo '<meta name="description" content="' . e(mb_strimwidth($text, 0, 155, '…')) . '">';
    }
    // Sichtbarkeit für Suchmaschinen entscheidet der Besitzer. Vorgabe ist
    // „nicht indexieren": Eine Seite, die als QR-Code an einer Tür klebt, muss
    // nicht zwingend auch auffindbar sein – umgekehrt ist der Wunsch bewusst.
    if (empty($l['bio_index'])) {
        echo '<meta name="robots" content="noindex, follow">';
    }
    echo '<meta name="theme-color" content="' . e($f['bg']) . '">'
        . '<link rel="canonical" href="' . e(base_url() . '/' . $code) . '">';
    $favicon = (string)cfg('favicon');
    if ($favicon !== '') echo '<link rel="icon" href="' . e(base_url() . '/assets/' . $favicon) . '">';
    echo '<link rel="stylesheet" href="' . e(base_url()) . '/assets/bio.css">';
    // Nur die vier gewählten Farben als Variablen – kein Nutzer-Text landet in
    // einem style-Attribut, und die Werte sind zuvor gegen #rrggbb geprüft.
    $akzent = trim((string)cfg('bio_footer_accent'));
    $akzent = preg_match('/^#[0-9a-fA-F]{6}$/', $akzent) === 1 ? strtolower($akzent) : '';
    echo '<style>:root{--bio-bg:' . $f['bg'] . ';--bio-ink:' . $f['ink']
        . ';--bio-btn:' . $f['btn'] . ';--bio-btn-ink:' . $f['btn_ink']
        . ($akzent !== '' ? ';--bio-accent:' . $akzent : '') . '}</style>';
    echo '</head><body class="bio-page"><main class="bio-wrap">';

    if ($logoUrl !== '') {
        echo '<img class="bio-logo" src="' . e($logoUrl) . '" alt="" width="96" height="96">';
    } else {
        echo bio_brand_block();
    }
    echo '<h1 class="bio-title">' . e($titel) . '</h1>';
    if ($text !== '') echo '<p class="bio-text">' . nl2br(e($text)) . '</p>';

    echo '<ul class="bio-list">';
    foreach ($items as $i => $item) {
        echo '<li><a class="bio-link" rel="noopener"'
            . ' href="' . e(base_url() . '/' . $code . '?i=' . $i) . '">'
            . e((string)($item['label'] ?? '')) . '</a></li>';
    }
    echo '</ul></main>';

    echo '<footer class="bio-foot">' . bio_origin_note() . '</footer>';
    echo '</body></html>';
    exit;
}

/**
 * Die Herkunftszeile im Fuß einer Bio-Seite.
 *
 * Zeichen plus Wortmarke, aufgebaut wie in der Kopfzeile des Dienstes: Heißt
 * die Instanz wie eine Domain, wird der Teil ab dem ersten Punkt getrennt
 * ausgezeichnet, dahinter steht der Cursor. Das ist die einzige Stelle, an der
 * eine Bio-Seite den Betreiber zeigt – dann soll sie ihn auch richtig zeigen
 * und nicht als beliebigen Fließtext.
 *
 * 'bio_footer_text' ist der Vorspann vor der Wortmarke. Ein leerer Wert lässt
 * nur die Marke stehen, `null` die ganze Zeile weg.
 */
function bio_origin_note(): string
{
    $vorspann = cfg('bio_footer_text');
    if ($vorspann === null) return '';
    $vorspann = trim((string)$vorspann);

    $out = '';
    $glyph = trim((string)cfg('bio_footer_glyph'));
    if ($glyph !== '' && is_file(dirname(__DIR__) . '/assets/' . basename($glyph))) {
        $out .= '<img class="bio-mark" src="' . e(base_url() . '/assets/' . basename($glyph))
            . '" alt="" width="20" height="20">';
    }
    if ($vorspann !== '') {
        $out .= '<span class="bio-pre">' . e($vorspann) . '</span>';
    }
    $out .= bio_wordmark();

    return '<a href="' . e(base_url() . '/') . '" rel="noopener">' . $out . '</a>';
}

/**
 * Klick auf ein einzelnes Ziel: zählen und weiterleiten.
 *
 * Eine ungültige Nummer führt zur Seite zurück, nicht ins Leere – so lässt sich
 * über durchprobierte Nummern auch nichts erkunden.
 */
function bio_follow(string $code, array $l, int $i): never
{
    $items = array_values((array)($l['items'] ?? []));
    if (!isset($items[$i])) {
        header('Location: ' . base_url() . '/' . $code, true, 302);
        exit;
    }
    clicks_bump($code, $i);
    header('Location: ' . (string)$items[$i]['url'], true, 302);
    exit;
}
