<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Demo-Modus: eine öffentliche Spielwiese, die sich selbst zurücksetzt.
 *
 * Anfassen überzeugt mehr als lesen – aber eine offene Instanz, in der jeder
 * Administrator ist, verwahrlost in Stunden. Deshalb: Der Bestand wird
 * regelmäßig verworfen und aus einem festen Demo-Bestand neu aufgebaut.
 *
 * Ohne Cron, ohne SSH: Der Reset hängt träge am Seitenaufbau. Ist der Marker
 * älter als 'demo_reset_minutes', räumt die NÄCHSTE Anfrage auf – unter einer
 * Sperre, damit zwei gleichzeitige Besucher nicht beide wischen. Das läuft
 * damit auch auf Shared Hosting, wo es keinen Weg zu einem Cronjob gibt.
 *
 * Was der Modus bewusst NICHT übernimmt: Mailversand, Webhooks und
 * Registrierung abzuschalten ist Sache der Konfiguration der Demo-Instanz
 * (mail 'log', webhooks leer, registration aus) – eine Datei, einmal
 * eingerichtet. Der Modus macht Banner, Reset und Bestand.
 */
require_once __DIR__ . '/store.php';

function demo_mode(): bool
{
    return (bool)cfg('demo_mode');
}

/** Beim Seitenaufbau aufgerufen – prüft billig, räumt selten. */
function demo_boot(): void
{
    if (!demo_mode()) return;
    $marker = data_path() . '/demo-reset.marker';
    $minuten = max(5, (int)(cfg('demo_reset_minutes') ?: 60));
    if (is_file($marker) && filemtime($marker) > time() - $minuten * 60) return;

    // Nicht blockierend: Wer die Sperre nicht bekommt, arbeitet mit dem alten
    // Bestand weiter – der Kollege nebenan wischt gerade.
    $sperre = fopen(data_path() . '/demo-reset.lock', 'c');
    if ($sperre === false || !flock($sperre, LOCK_EX | LOCK_NB)) return;
    try {
        clearstatcache(true, $marker);
        if (is_file($marker) && filemtime($marker) > time() - $minuten * 60) return;
        demo_reset();
        touch($marker);
    } finally {
        flock($sperre, LOCK_UN);
        fclose($sperre);
    }
}

/** Alles verwerfen und den Demo-Bestand neu aufbauen. */
function demo_reset(): void
{
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/groups.php';
    require_once __DIR__ . '/bio.php';

    // Datenbank leeren – Tabellen bleiben, Inhalte gehen. Die eigene Sitzung
    // stirbt dabei mit (Tabelle sessions), und genau das soll sie: Der Reset
    // wirft alle raus, auch den, der ihn ausgelöst hat.
    foreach (['links', 'users', 'tokens', 'settings', 'state', 'groups',
              'logos', 'pending_users', 'confirmations', 'audit', 'sessions', 'clickdims'] as $t) {
        db()->exec('DELETE FROM ' . $t);
    }
    // Dateibestand: Zähler, Logo-Dateien, Meldungen
    foreach (['clicks', 'logos', 'reports'] as $ordner) {
        foreach (glob(data_path($ordner) . '/*') ?: [] as $f) {
            if (is_file($f) && !str_ends_with($f, '.lock')) @unlink($f);
        }
    }
    users_all(true);    // Konten-Zwischenspeicher dieser Anfrage verwerfen
    groups_all(true);   // dito für die Gruppen

    // ---- Der Bestand: genug, um jede Funktion anzufassen ----
    user_add('demo', 'demo-1234', 'admin');
    user_set_role('demo', 'admin');
    group_save('redaktion', t('Redaktion'), ['bio_style', 'bio_page', 'logo_upload'], [], '', true);
    user_set_groups('demo', ['redaktion']);

    $links = [
        ['programm', 'https://example.org/programme', t('Semesterprogramm'), 'kampagne,druck', 'redaktion'],
        ['tickets', 'https://example.org/tickets', t('Kartenverkauf'), 'kampagne', 'redaktion'],
        ['podcast', 'https://example.org/podcast', t('Podcast-Folge 12'), 'social', null],
        ['jobs', 'https://example.org/jobs', t('Stellenausschreibung'), '', null],
    ];
    foreach ($links as [$code, $url, $titel, $tags, $grp]) {
        link_create($url, $code, 'demo', 'custom', array_filter([
            'title' => $titel, 'tags' => $tags, 'group' => $grp,
        ]));
    }
    link_write('programm', function ($l) {
        $l['lang'] = 'de';
        $l['rules'] = [['wenn' => 'lang', 'ist' => 'en', 'url' => 'https://example.org/programme-en']];
        return $l;
    });
    // Eine Klickkurve, damit die Statistik nicht leer wirkt
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime("-$i days"))] = 3 + ($i * 7) % 17;
    }
    json_write(data_path('clicks') . '/programm.json',
        ['n' => array_sum($days), 'last' => date('c'), 'days' => $days,
         'devices' => ['mobile' => 91, 'desktop' => 34, 'tablet' => 8],
         'langs' => ['de' => 96, 'en' => 37]]);

    link_create('https://example.org/', 'bio', 'demo', 'custom', ['group' => 'redaktion']);
    bio_write('bio', [
        ['label' => t('Aktuelles Programm'), 'url' => 'https://example.org/programme'],
        ['label' => t('Karten kaufen'), 'url' => 'https://example.org/tickets'],
        ['label' => 'Instagram', 'url' => 'https://instagram.com/example'],
    ], t('Alle Termine an einem Ort – Demo-Seite.'), false, ['logo' => '', 'colors' => []]);
}

/** Das Hinweisband über jeder Seite der Demo. */
function demo_banner(): string
{
    if (!demo_mode()) return '';
    $minuten = max(5, (int)(cfg('demo_reset_minutes') ?: 60));
    return '<div class="demo-band" role="note">'
        . t('Demo-Instanz: Anmeldung mit %s – alle Daten werden etwa alle %d Minuten zurückgesetzt. Bitte nichts Echtes eintragen.',
            '<strong>demo / demo-1234</strong>', $minuten)
        . '</div>';
}
