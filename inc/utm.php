<?php
declare(strict_types=1);
/**
 * Kampagnen-Parameter (UTM) an die Ziel-URL bauen.
 *
 * Wer eine Aktion auswertet, hängt an die Ziel-Adresse Angaben wie
 * `?utm_source=plakat&utm_medium=qr&utm_campaign=sommer`. Die Auswertung
 * passiert **nicht hier**, sondern in der Statistik der Zielseite – Matomo,
 * Plausible, Google Analytics. Diese Parameter sind die einzige Möglichkeit,
 * ihr mitzuteilen, woher jemand kam.
 *
 * Das ist ein ehrlicher Widerspruch zum Rest des Projekts, und er ist es wert,
 * ausgesprochen zu werden: Der Kurzlink selbst zählt weiterhin nur Aufrufe je
 * Tag – keine Herkunft, kein Gerät, kein Datensatz je Besuch. Was die
 * Zielseite tut, entscheidet aber deren Betreiber. Wer UTM-Parameter setzt,
 * gibt die Herkunft absichtlich weiter. Es ist ein Werkzeug, keine Empfehlung.
 *
 * **Keine eigene Datenhaltung.** Die Parameter stehen in der Ziel-URL und
 * sonst nirgends. Der Baukasten liest sie von dort und schreibt sie dorthin
 * zurück. Sie zusätzlich am Link zu speichern, hieße zwei Wahrheiten zu
 * pflegen – und die erste, die jemand an der URL vorbei ändert, wäre falsch.
 */

require_once __DIR__ . '/helpers.php';   // e() für den Formularteil

/** Die fünf Parameter aus Googles ursprünglicher Festlegung, in üblicher Reihenfolge */
function utm_fields(): array
{
    return [
        'utm_source' => ['Quelle', 'newsletter, plakat, instagram'],
        'utm_medium' => ['Medium', 'qr, email, social, print'],
        'utm_campaign' => ['Kampagne', 'sommer-2026'],
        'utm_term' => ['Suchbegriff', 'nur bei bezahlter Suche'],
        'utm_content' => ['Variante', 'zum Unterscheiden zweier Motive'],
    ];
}

/** Höchstlänge je Wert – länger ist kein Parameter mehr, sondern ein Versehen */
const UTM_MAX = 120;

/** Einen Wert säubern: Steuerzeichen raus, Leerraum zusammen, gekürzt */
function utm_clean(string $wert): string
{
    $w = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $wert));
    $w = (string)preg_replace('/\s+/u', ' ', $w);
    return mb_substr($w, 0, UTM_MAX);
}

/**
 * Die gesetzten Kampagnen-Parameter einer Adresse auslesen.
 *
 * @return array<string,string> nur die fünf bekannten, leere fehlen
 */
function utm_extract(string $url): array
{
    $q = (string)parse_url($url, PHP_URL_QUERY);
    if ($q === '') return [];
    parse_str($q, $params);
    $out = [];
    foreach (array_keys(utm_fields()) as $k) {
        $v = utm_clean((string)($params[$k] ?? ''));
        if ($v !== '') $out[$k] = $v;
    }
    return $out;
}

/**
 * Kampagnen-Parameter in eine Adresse schreiben.
 *
 * Ein leerer Wert entfernt den Parameter. Andere Query-Parameter der Adresse
 * bleiben unangetastet und behalten ihre Reihenfolge – eine Ziel-URL trägt oft
 * eigene, die nichts mit der Kampagne zu tun haben und trotzdem gebraucht
 * werden. Ein Anker (`#oben`) bleibt hinten stehen, wo er hingehört.
 *
 * @param array<string,string> $werte
 */
function utm_apply(string $url, array $werte): string
{
    $u = parse_url($url);
    if ($u === false || !isset($u['host'])) return $url;

    $params = [];
    if (($u['query'] ?? '') !== '') parse_str((string)$u['query'], $params);

    foreach (array_keys(utm_fields()) as $k) {
        if (!array_key_exists($k, $werte)) continue;
        $v = utm_clean((string)$werte[$k]);
        if ($v === '') unset($params[$k]); else $params[$k] = $v;
    }

    $neu = ($u['scheme'] ?? 'https') . '://';
    if (($u['user'] ?? '') !== '') {
        $neu .= $u['user'] . (($u['pass'] ?? '') !== '' ? ':' . $u['pass'] : '') . '@';
    }
    $neu .= $u['host'];
    if (isset($u['port'])) $neu .= ':' . $u['port'];
    $neu .= (string)($u['path'] ?? '');
    // http_build_query kodiert nach RFC 1738; für Query-Werte ist das richtig
    // und macht aus einem Leerzeichen ein +, nicht %20.
    if ($params !== []) $neu .= '?' . http_build_query($params);
    if (($u['fragment'] ?? '') !== '') $neu .= '#' . $u['fragment'];
    return $neu;
}

/** Alle fünf Parameter entfernen */
function utm_strip(string $url): string
{
    return utm_apply($url, array_fill_keys(array_keys(utm_fields()), ''));
}

/** Trägt diese Adresse Kampagnen-Parameter? */
function utm_present(string $url): bool
{
    return utm_extract($url) !== [];
}

/**
 * Der Baukasten als Formularteil.
 *
 * Steht hier und nicht in der Oberfläche, damit Anlegen und Ändern nicht
 * auseinanderlaufen können – dieselbe Überlegung wie bei inc/linkrules.php.
 * Zugeklappt, weil die meisten Links keine Kampagne haben; aufgeklappt, sobald
 * schon Parameter gesetzt sind, damit niemand sie übersieht und beim Speichern
 * verliert.
 *
 * @param string $id Präfix für die Feld-IDs ('c' beim Anlegen, 'e' beim Ändern)
 * @param array<string,string> $werte bereits gesetzte Parameter
 * @param array<string,string[]> $vorschlaege aus utm_suggestions()
 */
function utm_form(string $id, array $werte, array $vorschlaege = []): string
{
    $h = '<details class="utm"' . ($werte !== [] ? ' open' : '') . '>'
        . '<summary>Kampagnen-Parameter (UTM)'
        . ($werte !== [] ? ' <span class="badge badge-quiet">gesetzt</span>' : '')
        . '</summary>'
        . '<p class="muted small">Wird an die Ziel-Adresse gehängt, damit die Statistik der '
        . '<strong>Zielseite</strong> erkennt, woher jemand kam. Dieser Dienst wertet nichts davon '
        . 'aus – er zählt weiterhin nur Aufrufe je Tag. Leer lassen heißt: kein Parameter.</p>';

    foreach (utm_fields() as $key => [$label, $hinweis]) {
        $feldId = $id . '-' . $key;
        $listId = $feldId . '-vor';
        $liste = $vorschlaege[$key] ?? [];
        $h .= '<label for="' . $feldId . '">' . e($label)
            . ' <span class="muted">(' . e($hinweis) . ')</span></label>'
            . '<input id="' . $feldId . '" type="text" name="utm[' . e($key) . ']"'
            . ' maxlength="' . UTM_MAX . '" value="' . e((string)($werte[$key] ?? '')) . '"'
            . ($liste !== [] ? ' list="' . $listId . '"' : '') . '>';
        if ($liste !== []) {
            $h .= '<datalist id="' . $listId . '">';
            foreach ($liste as $v) $h .= '<option value="' . e($v) . '"></option>';
            $h .= '</datalist>';
        }
    }
    return $h . '</details>';
}

/**
 * Werte, die dieses Konto schon einmal benutzt hat – als Vorschlagsliste.
 *
 * Aus den bestehenden Links gelesen statt gespeichert: Eine Kampagne heißt
 * beim zweiten Plakat genauso wie beim ersten, und ein Tippfehler zerlegt die
 * Auswertung in zwei Hälften. Die Vorschläge sind der billigste Schutz davor.
 *
 * @param array<string,array> $links bereits geladene Links des Kontos
 * @return array<string,string[]> Parametername => Werte, häufigste zuerst
 */
function utm_suggestions(array $links): array
{
    $z = [];
    foreach ($links as $l) {
        foreach (utm_extract((string)($l['url'] ?? '')) as $k => $v) {
            $z[$k][$v] = ($z[$k][$v] ?? 0) + 1;
        }
    }
    $out = [];
    foreach ($z as $k => $werte) {
        arsort($werte);
        $out[$k] = array_slice(array_keys($werte), 0, 12);
    }
    return $out;
}
