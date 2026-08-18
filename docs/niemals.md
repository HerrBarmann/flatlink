# Was flatlink nie tun wird

Die meisten Projekte listen auf, was sie können. Diese Seite listet auf, was
dieses hier **nicht** können wird – und zwar nicht, weil die Zeit fehlt,
sondern weil es dem Zweck widerspricht. Eine Funktion, die hier steht, ist
keine offene Aufgabe. Sie ist eine Entscheidung.

Der Grund für diese Seite: Bei einem Kurzlink-Dienst entscheidet nicht der
Funktionsumfang darüber, ob man ihn einsetzen kann, sondern was er über die
Menschen erfährt, die auf einen Link klicken. Diese Menschen haben den Dienst
nicht ausgesucht. Sie haben ein Plakat gesehen.

## Keine Wiedererkennung einzelner Besucher

Es wird nie ein Merkmal gespeichert, mit dem sich ein Aufruf einem bestimmten
Gerät oder einer bestimmten Person zuordnen lässt: kein Cookie im
Weiterleitungspfad, keine IP-Adresse, kein Fingerabdruck aus
Browser-Eigenschaften, keine Kennung, die zwei Aufrufe verbindet.

Der Weiterleitungspfad startet nicht einmal eine Sitzung, solange kein
Passwortschutz auf dem Link liegt. Was gezählt wird, sind Summen – wie oft,
an welchem Tag, von welchem Hostname, mit welcher Geräteart. Aus einer Summe
lässt sich niemand herauslesen.

## Keine minutengenauen Klickzeitpunkte

Der Zähler hält den **Tag** fest, nicht die Uhrzeit. Bei einem Link mit einer
Handvoll Aufrufe wäre ein sekundengenauer Zeitpunkt der einzige Wert im
gesamten Bestand, über den sich ein einzelner Besuch zeitlich verorten – und
mit anderen Quellen zusammenführen – ließe.

Das ist die Funktion, nach der am häufigsten gefragt wird. Die Antwort bleibt
nein.

## Kein Conversion-Tracking, keine Umsatz-Attribution

Zu wissen, welcher Klick zu einem Kauf geführt hat, setzt genau das voraus,
was oben ausgeschlossen ist: eine Kennung, die den Menschen vom Klick bis zur
Kasse begleitet. Wer das braucht, braucht ein anderes Werkzeug – und sollte
den Menschen davor sagen, dass er es benutzt.

## Keine Retargeting-Pixel, kein Cross-Site-Tracking

Es wird nie eine Möglichkeit geben, in den Weiterleitungspfad ein Skript oder
Zählpixel eines Dritten einzuhängen. Das ist bei kommerziellen Anbietern eine
beworbene Funktion: Wer deinen Link anklickt, bekommt anschließend deine
Werbung. Technisch ist es dasselbe wie eine Wanze im Türrahmen.

## Kein Link-Cloaking

Ein Kurzlink verbirgt sein Ziel ohnehin – das lässt sich nicht ändern, es ist
sein Wesen. Was sich ändern lässt, ist, ob er zusätzlich **täuscht**. Deshalb
gibt es keine Adressen mit Namensteil (`https://bank.de@boese.tld/`), und die
Vorschau-Seite für Chats zeigt dasselbe Ziel, auf das auch jeder Mensch
geleitet wird – sichtbar und klickbar.

## Keine Weiterleitung auf interne Adressen

Auf einer erreichbaren Instanz zeigt kein Kurzlink auf `10.0.0.5` oder
`localhost`. Sonst wäre er die hübsche Verpackung für eine Adresse, die
niemand von außen prüfen kann. Rein interne Instanzen schalten das frei
(`allow_private_targets`) – ausdrücklich und in ihrer eigenen Konfiguration.

## Kein Webhook bei Klicks

Es gibt Webhooks für Verwaltungsereignisse – Link angelegt, gesperrt, Meldung
eingegangen. Es gibt bewusst kein `link.clicked`: Das wäre Besucherverfolgung
durch die Hintertür, nur ausgelagert an einen Dritten.

## Keine Abo-Falle mit den Links anderer Leute

Ein einmal erstellter Kurzlink bleibt erreichbar. Es wird nie eine Funktion
geben, die bestehende Links abschaltet, weil eine Zahlung ausbleibt – ein
gedruckter QR-Code gehört dem, der ihn gedruckt hat, nicht dem Betreiber. Was
eine Instanz an ungenutzten Links aufräumt, steht in ihrer Konfiguration und
läuft nie ohne Vorwarnung.

---

## Was das nicht heißt

Es heißt nicht, dass es keine Statistik gibt. Es gibt eine – sie beantwortet
„wie oft", „an welchen Tagen" und „woher", nur eben in Summen. Es heißt auch
nicht, dass es keine Weichen nach Gerät oder Land gibt: Die werden zur
Anfragezeit ausgewertet und danach vergessen.

Der Unterschied ist immer derselbe. Eine **Eigenschaft der Anfrage** zu
prüfen und zu vergessen, ist etwas anderes, als sie einer **Person**
zuzuordnen und aufzuheben.

## Nachprüfbar

Nichts davon muss man glauben. Die Zählung steht in
[`inc/store.php`](../inc/store.php) (`clicks_bump`), der Weiterleitungspfad in
[`go.php`](../go.php), die Weichen in [`inc/routing.php`](../inc/routing.php).
Zusammen sind das ein paar Dutzend Zeilen. Genau dafür liegt der Code offen.

Wer eine Stelle findet, an der die Software mehr über Besucher speichert, als
hier steht, meldet das bitte als Sicherheitslücke – siehe
[SECURITY.md](SECURITY.md).
