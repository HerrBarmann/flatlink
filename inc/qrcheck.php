<?php
declare(strict_types=1);

require_once __DIR__ . '/qrlib.php';
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Prüft, ob eine Gestaltung noch lesbar ist – bevor jemand sie druckt.
 *
 * Der Anlass: Je mehr sich gestalten lässt, desto leichter entsteht ein Code,
 * der auf dem Bildschirm gut aussieht und auf dem Aufsteller versagt. Zwei
 * Beispiele aus der eigenen Entwicklung, beide erst durch Messen aufgefallen:
 * eine Augenform, die die Hälfte des Suchmusters wegschnitt, und ein voller
 * Kreis als Augenring, der bei zehn Prozent der Rastergrößen nicht las.
 *
 * Ein Generator, der so etwas zulässt und schweigt, ist kein guter Generator.
 * Diese Datei sagt es – vor dem Druck, nicht danach.
 *
 * Die Schwellen sind bewusst großzügig: Eine Warnung, die zu oft erscheint,
 * wird weggeklickt und schützt niemanden mehr.
 *
 * **Woher die Zahlen kommen – und woher nicht.** Rand, Logo-Anteil und
 * Modulgröße folgen der Norm beziehungsweise der Fehlerkorrektur-Kapazität;
 * die sind nachrechenbar. Die Kontrast-Schwellen sind es nicht: Ein Test mit
 * einem Software-Decoder auf einem sauberen PNG liest noch hellgrau auf weiß
 * (1,3:1) fehlerfrei und kann sie deshalb gar nicht belegen. Was einen Code
 * scheitern lässt, ist die Kamera – Rauschen, schiefes Licht, Papier, in das
 * die Farbe läuft. Die Werte hier orientieren sich am Symbolkontrast, den die
 * Prüfnormen für gedruckte Codes verlangen, und liegen mit Absicht auf der
 * vorsichtigen Seite.
 */

/**
 * Relative Helligkeit nach WCAG – die übliche Rechnung für Kontrast.
 *
 * Nicht dasselbe wie die naive Mittelung von R, G und B: Das Auge sieht Grün
 * deutlich heller als Blau, und ein Code aus dunklem Blau auf Schwarz wäre
 * sonst rechnerisch kontrastreich und in Wirklichkeit unlesbar.
 */
function qr_luminance(string $hex): float
{
    $h = ltrim(trim($hex), '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (strlen($h) !== 6 || !ctype_xdigit($h)) return 0.0;
    $lin = function (int $v): float {
        $c = $v / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $lin((int)hexdec(substr($h, 0, 2)))
        + 0.7152 * $lin((int)hexdec(substr($h, 2, 2)))
        + 0.0722 * $lin((int)hexdec(substr($h, 4, 2)));
}

/** Kontrastverhältnis zweier Farben, 1 (gleich) bis 21 (Schwarz auf Weiß) */
function qr_contrast(string $a, string $b): float
{
    $la = qr_luminance($a);
    $lb = qr_luminance($b);
    return ($la > $lb ? ($la + 0.05) / ($lb + 0.05) : ($lb + 0.05) / ($la + 0.05));
}

/**
 * Was die Fehlerkorrektur an verdeckter Fläche verkraftet.
 *
 * Die Prozentsätze der Norm beziehen sich auf die Codewörter, nicht auf die
 * Fläche, und ein Teil davon geht für die Wiederherstellung selbst drauf.
 * Deshalb hier ein deutlicher Sicherheitsabstand – wer ihn ausreizt, bekommt
 * einen Code, der auf gutem Papier bei gutem Licht liest und sonst nicht.
 */
function qr_logo_budget(string $ecc): float
{
    return match (strtoupper($ecc)) {
        'H' => 0.22,
        'Q' => 0.16,
        'M' => 0.09,
        default => 0.04,
    };
}

/**
 * Alle Hinweise zu einer Gestaltung.
 *
 * @param array $o Optionen wie für QrRenderer, dazu 'ecc', 'sizePx', 'printMm'
 * @return array<int,array{stufe:string,text:string}> 'warn' oder 'info'
 */
function qr_readability(array $o, int $module): array
{
    $hin = [];
    $warn = function (string $t) use (&$hin) { $hin[] = ['stufe' => 'warn', 'text' => $t]; };
    $info = function (string $t) use (&$hin) { $hin[] = ['stufe' => 'info', 'text' => $t]; };

    $fg = (string)($o['fg'] ?? '#000000');
    $bg = (string)($o['bg'] ?? '#ffffff');

    // ---- Durchsichtiger Hintergrund ----
    // Hier lässt sich nichts rechnen: Der Kontrast entsteht erst gegen das,
    // worauf der Code später liegt. Statt eine Zahl zu erfinden, wird gesagt,
    // worauf zu achten ist.
    if (strtolower(trim($bg)) === 'none') {
        $hin[] = ['stufe' => 'info', 'text' => t('Der Hintergrund ist durchsichtig. Ob der Code liest, entscheidet die Fläche darunter – sie muss hell und ruhig sein, kein Foto und kein Muster. Prüfen lässt sich das von hier aus nicht.')];
        $bg = '#ffffff';   // für die folgenden Rechnungen der günstigste Fall
    }

    // ---- Kontrast ----
    // Alle Farben, die auf dem Hintergrund liegen, einzeln prüfen: Ein Verlauf
    // ist an einem Ende oft kräftig und am anderen zu blass.
    $vorn = [t('Vordergrund') => $fg];
    if (($o['grad'] ?? null) !== null) $vorn[t('zweite Verlaufsfarbe')] = (string)$o['gradTo'];
    if (trim((string)($o['eyeFg'] ?? '')) !== '') $vorn[t('Augenring')] = (string)$o['eyeFg'];
    if (trim((string)($o['eyeCoreFg'] ?? '')) !== '') $vorn[t('Augenkern')] = (string)$o['eyeCoreFg'];

    foreach ($vorn as $name => $farbe) {
        $k = qr_contrast($farbe, $bg);
        if ($k < 2.5) {
            $warn(t('%s und Hintergrund haben zu wenig Kontrast (%.1f:1). Ein Scanner unterscheidet die Felder dann nicht zuverlässig – mindestens 3:1, besser 7:1.', $name, $k));
        } elseif ($k < 4.0) {
            $info(t('%s liegt mit %.1f:1 knapp über der Grenze. Auf Papier und bei schlechtem Licht wird das eng.', $name, $k));
        }
        if (qr_luminance($farbe) > qr_luminance($bg)) {
            $warn(t('%s ist heller als der Hintergrund. Ein umgekehrter Code wird von vielen Kameras gelesen, von manchen aber nicht – im Zweifel dunkel auf hell.', $name));
        }
    }

    // ---- Ruhezone ----
    $rand = (int)($o['margin'] ?? 4);
    if ($rand < 2) {
        $warn(t('Der Rand um den Code ist zu schmal (%s). Die Norm verlangt vier freie Module, sonst findet der Scanner die Kanten nicht.',
            $rand . ' ' . ($rand === 1 ? t('Modul') : t('Module'))));
    } elseif ($rand < 4) {
        $info(t('Der Rand liegt mit %d Modulen unter den vier Modulen der Norm. Auf einer ruhigen Fläche geht das meist gut, vor einem Muster nicht.', $rand));
    }

    // ---- Logo ----
    $skala = (float)($o['logoScale'] ?? 0);
    if (($o['logo'] ?? null) !== null && $skala > 0) {
        // Die freigestellte Fläche ist größer als das Logo selbst
        $pad = (float)($o['logoPad'] ?? 0.12);
        $anteil = ($skala * (1 + 2 * $pad)) ** 2;
        $budget = qr_logo_budget((string)($o['ecc'] ?? 'H'));
        if ($anteil > $budget) {
            $warn(t('Das Logo verdeckt rund %d %% der Fläche, die Fehlerkorrektur trägt hier etwa %d %%. Kleiner machen oder die Fehlerkorrektur anheben.',
                (int)round($anteil * 100), (int)round($budget * 100)));
        } elseif ($anteil > $budget * 0.8) {
            $info(t('Das Logo nutzt mit rund %d %% fast den ganzen Spielraum der Fehlerkorrektur aus.', (int)round($anteil * 100)));
        }
    }

    // ---- Größe der Ausgabe ----
    $px = (int)($o['sizePx'] ?? 0);
    if ($px > 0) {
        $jeModul = $px / max(1, $module);
        if ($jeModul < 3) {
            $warn(t('Bei dieser Bildgröße entfallen nur %.1f Pixel auf ein Modul. Unter drei Pixeln verschwimmen die Felder – größer ausgeben.', $jeModul));
        } elseif ($jeModul < 5) {
            $info(t('Nur %.1f Pixel je Modul. Für den Bildschirm reicht das, zum Weiterverarbeiten besser mehr.', $jeModul));
        }
    }

    $mm = (float)($o['printMm'] ?? 0);
    if ($mm > 0) {
        $modulMm = $mm / max(1, $module);
        if ($modulMm < 0.4) {
            $warn(t('Auf %.0f mm Breite ist ein Modul nur %.2f mm groß. Unter 0,4 mm scheitern viele Kameras – breiter drucken oder den Inhalt kürzen.', $mm, $modulMm));
        } elseif ($modulMm < 0.6) {
            $info(t('Ein Modul misst %.2f mm. Das liest sich aus der Nähe, für ein Plakat sollte es mehr sein.', $modulMm));
        }
    }

    return $hin;
}
