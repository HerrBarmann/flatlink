# Die Weichen erklären sich

Eine Wartungsfassung mit genau einem Thema: Das Formular für die
[Weichen](../docs/kurzlinks.md#weichen-ein-link-mehrere-ziele) sagte nicht, was
es von einem will.

**Es gab genau eine leere Zeile.** Wer zwei Weichen anlegen wollte, musste
zwischendurch speichern – und niemand kommt von selbst darauf, dass Speichern
die nächste Zeile bringt. Jetzt stehen drei freie Zeilen bereit (bis zum
Höchstwert von acht); leere fallen beim Speichern ohnehin weg.

**Der Anteil (A/B) war unsichtbar.** Es gibt ihn seit 2.6.0 als viertes
Merkmal neben Gerät, Sprache und Land: Der Wert ist dann eine Prozentzahl,
`30` schickt knapp jeden dritten Aufruf auf dieses Ziel. Nur stand in der
Überschrift „je nach Gerät, Sprache oder Land", und der Platzhalter im Wertfeld
zeigte `mobile / en / at`. Dass dort auch eine Zahl stehen darf, war nur im
Quelltext zu sehen.

**Das mittlere Feld erklärte sich nirgends.** Unter den Zeilen steht jetzt, was
je Merkmal hineingehört – `mobile`, `en`, `at` oder eben `30` – samt der Lesart
des Anteils.

Am Verhalten der Weichen selbst ändert sich nichts: Die erste zutreffende
gewinnt, trifft keine zu, gilt das Hauptziel, und gespeichert wird von den
Merkmalen weiterhin nichts. Der Würfel für den Anteil fällt bei jedem Aufruf
neu – eine Wiedererkennung wäre die sauberere Statistik, kostet aber genau das,
was dieses Projekt nicht ausgibt.

## Getestet

0 Weichen ergeben 3 Zeilen, 3 Weichen 6, 7 Weichen 8 – das Maximum. Drei
Weichen in einem Speichervorgang angelegt (Gerät, Sprache, Anteil 30 %) und in
der Ablage wiedergefunden. `tests/optionen.php` 21 von 21,
`tests/einstellungen.php` bestanden.

Dabei selbst hineingelaufen: Die Schleife für die leeren Zeilen prüfte die
Länge der wachsenden Liste in ihrer Abbruchbedingung – die Schranke wuchs also
mit und das Formular lief bis zum Höchstwert voll. Acht Zeilen statt drei.

## Aktualisieren

Dateien austauschen, kein Migrationsschritt, keine neuen Dateien. Betroffen
sind `admin/index.php`, `inc/lang/en.php` und `docs/kurzlinks.md`.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
