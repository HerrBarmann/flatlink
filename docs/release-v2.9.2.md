# Weichen schon beim Anlegen

Nachtrag zu [2.9.1](release-v2.9.1.md), dasselbe Thema von der anderen Seite.

Weichen ließen sich nur beim **Bearbeiten** eines Links setzen. Wer beim
Anlegen schon wusste, dass Handys woandershin sollen, musste den Link erst
speichern und dann wieder aufmachen. Die Datenschicht konnte es die ganze Zeit
– `link_create()` nimmt `rules` entgegen –, nur das Formular fragte nicht
danach.

Drei Zeilen stehen jetzt unter *Mehr Optionen*, ausgewertet von derselben
Funktion wie beim Bearbeiten. Bleiben sie leer, entsteht kein `rules`-Feld.
Die ausführliche Erklärung der Merkmale bleibt beim Bearbeiten: Das
Anlege-Formular soll keine Wand werden, und wer Weichen stellt, findet den Link
danach ohnehin in der Liste wieder.

## Getestet

Ein Link mit Gerät-Weiche und einem Anteil von 40 % in einem Zug angelegt,
beide in der Ablage wiedergefunden. Ein Link mit leeren Weichen-Zeilen bekommt
kein `rules`-Feld. `tests/optionen.php` 21 von 21, `tests/einstellungen.php`
bestanden.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt, keine neuen Dateien. Betroffen
sind `admin/index.php`, `inc/lang/en.php` und `docs/kurzlinks.md`.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
