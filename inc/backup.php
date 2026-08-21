<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Sicherung als Archiv – für alle, die keine Shell haben.
 *
 * „Backup ist Ordner kopieren" gilt weiterhin und bleibt der beste Weg. Auf
 * Shared Hosting hat aber nicht jeder einen Dateimanager zur Hand, der einen
 * Ordner außerhalb des Webroots erreicht – dort ist ein Knopf in der
 * Oberfläche der Unterschied zwischen „es gibt eine Sicherung" und „es gibt
 * keine".
 *
 * Die Datenbank wird NICHT einfach mitkopiert: Eine laufende SQLite-Datei
 * kann gerade mitten in einer Transaktion stehen, und ihre WAL-Datei enthält
 * dann Teile, die in der Hauptdatei noch fehlen. `VACUUM INTO` schreibt
 * stattdessen eine in sich geschlossene Kopie – der von SQLite vorgesehene
 * Weg, eine Datenbank im Betrieb zu sichern. Nebenbei ist das Ergebnis
 * kompakt, weil es freie Seiten weglässt.
 *
 * Nicht im Archiv: inc/config.php. Sie enthält SMTP-Zugangsdaten und das
 * Instanz-Geheimnis und gehört nicht in eine Datei, die anschließend im
 * Download-Ordner liegt – sie ändert sich ohnehin selten und wird von Hand
 * gesichert. Die Wiederherstellung braucht sie trotzdem – wie das geht,
 * steht als Zettel im Archiv selbst.
 */

/**
 * Die Sicherung bauen und als ZIP zurückgeben.
 *
 * @return array{0:string,1:array<string,int>} [ZIP-Inhalt, Übersicht Name => Bytes]
 */
function backup_build(): array
{
    $zip = new ZipWriter();
    $uebersicht = [];
    $jetzt = time();

    // 1) Die Datenbank – konsistente Kopie über VACUUM INTO
    $tmp = data_path() . '/.backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        $st = db()->prepare('VACUUM INTO ?');
        $st->execute([$tmp]);
        $inhalt = (string)file_get_contents($tmp);
        $zip->add(basename(db_file()), $inhalt, $jetzt);
        $uebersicht[basename(db_file())] = strlen($inhalt);
    } finally {
        // Auch wenn das Schreiben scheitert: keine halbe Kopie zurücklassen
        if (is_file($tmp)) @unlink($tmp);
    }

    // 2) Die kleinen Zustandsdateien neben der Datenbank
    foreach (backup_dateien() as $name) {
        $pfad = data_path() . '/' . $name;
        if (!is_file($pfad)) continue;
        $inhalt = (string)file_get_contents($pfad);
        $zip->add($name, $inhalt, filemtime($pfad) ?: $jetzt);
        $uebersicht[$name] = strlen($inhalt);
    }

    // 3) Verzeichnisse: Klickzähler, Logo-Dateien, Meldungen.
    //    Rate-Limit-Zähler und Sperrdateien bleiben draußen – nach 24 Stunden
    //    ohnehin hinfällig; die offenen Bestätigungen liegen seit 4.0 in der
    //    Datenbank und stecken im SQL-Teil.
    foreach (['clicks', 'logos', 'reports'] as $ordner) {
        $pfad = data_path() . '/' . $ordner;
        if (!is_dir($pfad)) continue;
        $n = 0;
        $bytes = 0;
        foreach (glob($pfad . '/*') ?: [] as $datei) {
            // Sperrdateien sind Betriebsgeräusch, kein Inhalt
            if (!is_file($datei) || str_ends_with($datei, '.lock')) continue;
            // Klick-Protokolle werden VOR dem Sichern gefaltet: Ins Archiv
            // gehören Summen, kein Anhang-Protokoll (Review 4.2.0, F1). Nach
            // dem Falten ist die Datei leer und wird übersprungen.
            if ($ordner === 'clicks' && str_ends_with($datei, '.log')) {
                clicks_altlog(rawurldecode(basename($datei, '.log')));
                clearstatcache(true, $datei);
                if (!is_file($datei) || filesize($datei) === 0) continue;
            }
            $inhalt = (string)file_get_contents($datei);
            $zip->add($ordner . '/' . basename($datei), $inhalt, filemtime($datei) ?: $jetzt);
            $n++;
            $bytes += strlen($inhalt);
        }
        if ($n > 0) $uebersicht[$ordner . '/ (' . $n . ')'] = $bytes;
    }

    // 4) Eine Anleitung dazulegen – eine Sicherung, die niemand
    //    zurückspielen kann, ist keine.
    $zip->add('WIEDERHERSTELLEN.txt', backup_anleitung($uebersicht), $jetzt);

    return [$zip->build(), $uebersicht];
}

/**
 * Die Einzeldateien, die mitgesichert werden (ohne Sperrdateien).
 *
 * Seit 4.0 ist das nur noch das Instanz-Geheimnis: Einstellungen, Gruppen,
 * Logo-Metadaten, Warteschlange, Bestätigungen, Audit und die Betriebs-Marker
 * liegen in der Datenbank und stecken damit im SQL-Teil der Sicherung.
 */
function backup_dateien(): array
{
    return ['secret.key'];
}

/** Der Zettel im Archiv */
function backup_anleitung(array $uebersicht): string
{
    $zeilen = [];
    foreach ($uebersicht as $name => $bytes) {
        $zeilen[] = sprintf('  %-28s %s', $name, number_format($bytes / 1024, 1, ',', '.') . ' KB');
    }
    return "Sicherung einer flatlink-Instanz\n"
        . "Erstellt: " . date('d.m.Y H:i') . "\n"
        . "Instanz:  " . cfg('site_name') . " (" . base_url() . ")\n"
        . "\nInhalt\n" . implode("\n", $zeilen) . "\n"
        . "\nWIEDERHERSTELLEN\n"
        . "1. flatlink in der passenden Fassung einspielen (Dateien in den Webroot).\n"
        . "2. inc/config.php wiederherstellen – sie ist NICHT in dieser Sicherung,\n"
        . "   weil sie Zugangsdaten und das Instanz-Geheimnis enthält. Wichtig ist,\n"
        . "   dass 'data_dir' wieder auf dasselbe Verzeichnis zeigt.\n"
        . "3. Den Inhalt dieses Archivs in das Datenverzeichnis entpacken.\n"
        . "4. Aufrufen. Es gibt keinen Migrationsschritt.\n"
        . "\nHINWEIS ZU secret.key: Aus ihr werden die IP-Hashes der Rate-Limits\n"
        . "gebildet. Eine andere Datei bedeutet nur, dass laufende Zähler neu\n"
        . "beginnen – kein Datenverlust.\n"
        . "\nNICHT enthalten: Rate-Limit-Zähler (nach 24 Stunden ohnehin hinfällig)\n"
        . "und inc/config.php (siehe oben).\n";
}
