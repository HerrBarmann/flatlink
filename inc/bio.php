<?php
declare(strict_types=1);
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
            return ['Höchstens ' . BIO_MAX_ITEMS . ' Ziele pro Seite.', []];
        }
    }
    if ($items === []) return ['Mindestens ein Ziel angeben.', []];
    return [null, $items];
}

/**
 * Zielliste und Seitenangaben schreiben.
 *
 * Setzt zugleich die Kennzeichnung `kind`, an der der Weiterleitungspfad eine
 * Seite von einem gewöhnlichen Kurzlink unterscheidet.
 */
function bio_write(string $code, array $items, string $text, bool $index): bool
{
    return link_write($code, function (?array $l) use ($items, $text, $index) {
        if ($l === null) return false;
        $l['kind'] = 'bio';
        $l['items'] = array_values($items);
        if ($text === '') unset($l['bio_text']); else $l['bio_text'] = $text;
        if ($index) $l['bio_index'] = true; else unset($l['bio_index']);
        $l['updated'] = date('c');
        return $l;
    });
}

/**
 * Die öffentliche Seite ausgeben.
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

    clicks_bump($code);

    // Sichtbarkeit für Suchmaschinen entscheidet der Besitzer. Vorgabe ist
    // „nicht indexieren": Eine Seite, die als QR-Code an einer Tür klebt, muss
    // nicht zwingend auch auffindbar sein – umgekehrt ist der Wunsch bewusst.
    $indexierbar = !empty($l['bio_index']);
    page_header($titel, false, $text !== '' ? mb_strimwidth($text, 0, 155, '…') : null,
        base_url() . '/' . $code);
    if (!$indexierbar) {
        // page_header setzt robots nur im Verwaltungsbereich; hier nachreichen
        echo '<meta name="robots" content="noindex, follow">';
    }
    echo '<div class="card narrow bio">';
    echo '<h1 class="bio-title">' . e($titel) . '</h1>';
    if ($text !== '') {
        echo '<p class="bio-text">' . nl2br(e($text)) . '</p>';
    }
    echo '<ul class="bio-list">';
    foreach ($items as $i => $item) {
        echo '<li><a class="btn bio-link" rel="noopener"'
            . ' href="' . e(base_url() . '/' . $code . '?i=' . $i) . '">'
            . e((string)($item['label'] ?? '')) . '</a></li>';
    }
    echo '</ul></div>';
    page_footer();
    exit;
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
