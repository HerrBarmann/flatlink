# Zäune für die Werkzeuge

Eine Sicherheitsfassung nach dem Follow-up-Review vom 18.08. Die gute
Nachricht zuerst: **Alle acht Befunde aus dem Review zu 2.5.0 sind bestätigt
behoben**, und die seither gebauten Funktionen – Weichen, Klick-Dimensionen,
Vorschau, Webhooks, Erweiterung – brachten **keine ernste Lücke** mit. Zwei
kleine Punkte blieben, beide sind hier geschlossen.

## Testskripte eingezäunt (N1)

`tests/einstellungen.php` legte für seinen Lauf ein Admin-Konto an, dessen
Passwort im Quelltext steht – und räumte es nicht ab. Dazu fehlte den
Testskripten der CLI-Riegel, den `tools/` längst haben. Wer den Ordner
`tests/` mit ins Webroot lud, hätte das Skript im ungünstigen Fall über den
Webserver anstoßen können; das Ergebnis wäre ein Admin-Konto mit öffentlich
bekanntem Passwort gewesen.

Jetzt: `PHP_SAPI`-Riegel in allen Testskripten, das Testkonto wird am Ende
gelöscht (auch auf einer frischen Instanz, wo es der einzige Administrator
ist), die `.htaccess` sperrt `tests/`, `tools/` und `extension/`, und die
Deployment-Anleitung sagt ausdrücklich – samt nginx-Regel –, dass diese drei
Ordner nicht ins Webroot gehören.

## Webhook-Ziele gegen interne Adressen gesperrt (N2)

Ein Link-Ziel ruft der Server nie ab – ein **Webhook-Ziel schon**. Ein
Eintrag auf `169.254.169.254` (Cloud-Metadaten) oder einen internen Dienst
wäre damit echtes SSRF, nicht nur Kosmetik. Heute setzt nur ein Administrator
Webhooks, das Risiko war Selbst-Beschuss; die Sperre steht jetzt trotzdem,
bevor eine spätere Delegation sie scharf machen könnte.

`hook_fire()` prüft jedes Ziel mit derselben Funktion wie die Link-Ziele;
rein interne Instanzen schalten wie überall `allow_private_targets` ein.

## Die Sprachverhandlung steht jetzt fest (N5)

`tests/weichen.php` schreibt die in 2.9.4 zum dritten Mal umgebaute
Sprachlogik in zwölf Fällen fest – beide alten Fehler stehen als Fälle drin,
dazu die q-Gewichte, der leere Header und das Zusammenspiel mit einer
Split-Weiche. Solche mehrfach umgebauten Stellen sind erfahrungsgemäß die, an
denen beim nächsten Umbau ein Randfall kippt.

## Getestet

`tests/optionen.php` 21 von 21, `tests/einstellungen.php` samt neuer
Abräum-Prüfung in beiden Lagen (frische und eingerichtete Instanz),
`tests/weichen.php` 12 von 12. Die Webhook-Sperre nachgewiesen:
Metadaten-Adresse, `10.0.0.5` und `localhost` werden übersprungen, eine
öffentliche Adresse nicht.

## Aktualisieren

Dateien austauschen; neu ist `tests/weichen.php`. Die geänderte `.htaccess`
gehört mit hochgeladen – wer eine eigene pflegt, ergänzt in der Sperrzeile
`tests|tools|extension`. nginx-Betreiber ziehen die Regel aus der
Deployment-Anleitung nach. Am saubersten bleibt: `tests/`, `tools/` und
`extension/` gar nicht erst auf den Server laden.

## Lizenz

Unverändert **AGPL-3.0** mit Zusatzbedingung zur Namensnennung nach § 7(b).
