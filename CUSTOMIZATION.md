# flatlink anpassen

flatlink kommt bewusst schmucklos: ein zurückhaltendes Blaugrau, das nichts
behauptet. Es ist als Untergrund gedacht, nicht als fertiger Auftritt. Diese
Anleitung zeigt, wie daraus deiner wird.

**Inhalt**

1. [Die eine Regel](#1-die-eine-regel)
2. [Farben](#2-farben)
3. [Logo, Favicon und Name](#3-logo-favicon-und-name)
4. [Fußzeile](#4-fußzeile)
5. [Schrift](#5-schrift)
6. [Einzelne Bereiche](#6-einzelne-bereiche)
7. [QR-Codes](#7-qr-codes)
8. [Ein vollständiges Beispiel](#8-ein-vollständiges-beispiel)
9. [Was beim Update passiert](#9-was-beim-update-passiert)
10. [Eigene Seiten und Funktionen](#10-eigene-seiten-und-funktionen)
11. [Wo die Grenze liegt](#11-wo-die-grenze-liegt)

---

## 1. Die eine Regel

> **Ändere niemals `assets/style.css`.**
> Alles Eigene gehört in `assets/custom.css`.

Diese Datei wird **nach** dem Standard-Stylesheet geladen und überschreibt es
damit. Sie ist per `.gitignore` ausgenommen, ein `git pull` fasst sie also nie
an. Wer stattdessen `style.css` bearbeitet, hat beim nächsten Update einen
Konflikt – und irgendwann eine Instanz, die sich nicht mehr aktualisieren
lässt.

Loslegen:

```bash
cp assets/custom.example.css assets/custom.css
```

Die Vorlage enthält alle Variablen mit ihren Vorgabewerten und
auskommentierte Beispiele für die häufigsten Wünsche. Ab jetzt reicht Speichern
und neu laden – ein Zeitstempel im Query-String sorgt dafür, dass der Browser
nichts Altes zeigt.

---

## 2. Farben

Der schnellste Weg zum eigenen Auftritt. Die gesamte Oberfläche hängt an neun
Variablen; wer sie ersetzt, hat die Instanz umgefärbt, ohne eine einzige Regel
anzufassen.

| Variable | Wofür |
| --- | --- |
| `--paper` | Seitengrund |
| `--surface` | Karten und Flächen |
| `--ink` | Text und Ränder |
| `--muted` | Sekundärtext, Beschriftungen |
| `--line` | Trennlinien |
| `--accent` | Signalfarbe: Hauptaktion, Erfolg |
| `--accent-deep` | Akzent als **Text** auf hellem Grund |
| `--accent-tint` | Hover, hervorgehobene Zeilen |
| `--on-accent` | Text auf gefüllter Akzentfläche |

```css
:root {
    --paper:       #FFFDF7;
    --surface:     #FFF6E0;
    --ink:         #2B1A08;
    --muted:       #7A6244;
    --line:        #EBDCBD;
    --accent:      #C8102E;
    --accent-deep: #96001F;
    --accent-tint: #FBE9EC;
    --on-accent:   #FFFFFF;
}
```

Drei Dinge, an denen Umfärbungen regelmäßig scheitern:

**`--accent` und `--accent-deep` sind nicht dasselbe.** Der erste ist eine
Fläche (Schaltflächen, Ränder), der zweite ist Schrift auf hellem Grund. Ein
Rot, das als Fläche gut aussieht, ist als Fließtext oft zu hell. Deshalb gibt
es zwei Werte – setz beide, nicht nur einen.

**Prüf den Kontrast.** `--muted` auf `--paper` und `--accent-deep` auf
`--surface` sind die kritischen Paare. Sie sollten mindestens 4,5:1 erreichen,
damit der Text auch für Menschen mit eingeschränktem Sehvermögen lesbar bleibt.
Ein Kontrastrechner braucht dafür zehn Sekunden.

**Vergiss das dunkle Erscheinungsbild nicht.** Setzt du nur die hellen Werte,
sieht die Instanz für alle mit dunkler Systemeinstellung weiterhin blaugrau
aus – oder schlimmer, halb umgefärbt. Der zweite Block gehört dazu:

```css
@media (prefers-color-scheme: dark) {
    :root {
        --paper:       #14100A;
        --surface:     #1F1810;
        --ink:         #F2E9DA;
        --muted:       #B0A08A;
        --line:        #35291B;
        --accent:      #E8556E;   /* auf Dunkel kräftiger, sonst säuft er ab */
        --accent-deep: #F2919F;
        --accent-tint: #2A1A1E;
        --on-accent:   #14100A;
    }
}
```

Dabei nicht einfach die hellen Werte umdrehen: Dunkle Flächen schlucken
Farbe, der Akzent muss dort kräftiger und heller sein.

Wer bewusst nur ein helles Erscheinungsbild anbietet, lässt den Block weg und
setzt stattdessen `:root { color-scheme: light; }` – dann rendern auch
Formularfelder hell.

---

## 3. Logo, Favicon und Name

Diese drei stehen in `inc/config.php`, nicht im Stylesheet:

```php
'site_name' => 'Kurzlinks der Musterhochschule',
'logo'      => 'logo.svg',      // Datei in assets/
'favicon'   => 'favicon.svg',   // Datei in assets/
```

Das Logo erscheint links neben dem Namen in der Kopfzeile. Lege die Datei nach
`assets/` und trag nur den Dateinamen ein. SVG ist die beste Wahl – gestochen
scharf auf jedem Bildschirm und meist nur wenige Kilobyte.

Die Größe bestimmt das Stylesheet, nicht die Datei:

```css
.brand-logo { height: 2.2em; }        /* Vorgabe: 1.7em */
.brand { font-size: 1.15rem; }        /* Schriftgröße des Namens */
```

Nur das Logo ohne Schriftzug? Dann den Namen ausblenden – aber für Vorlesefunktionen
zugänglich lassen:

```css
.brand-logo { height: 2.4em; }
.brand { font-size: 0; }              /* verbirgt den Text, nicht das Bild */
```

Der Name aus `site_name` erscheint außerdem im Seitentitel, in den Systemmails
und in der Fußzeile – er sollte also auch ohne Logo für sich stehen können.

---

## 4. Fußzeile

Zusätzliche Links kommen aus der Konfiguration:

```php
'footer_links' => [
    'Impressum'   => 'impressum.html',
    'Datenschutz' => 'https://example.org/datenschutz',
],
```

Relative Ziele beziehen sich auf den Webroot, absolute (`https://…`) führen
nach außen. Eine einfache HTML-Datei neben `index.php` reicht völlig.

**Wer eine öffentliche Instanz betreibt, braucht das vermutlich.** In
Deutschland und weiten Teilen der EU sind Impressum und Datenschutzerklärung
Pflicht. flatlink liefert dafür bewusst keine Vorlagen – sie hängen von
Betreiber, Zweck und Nutzung ab, und eine mitgelieferte Vorlage würde mehr
Schaden anrichten als helfen.

Die Herkunftszeile mit dem kleinen Kiwi lässt sich abschalten:

```php
'show_origin' => false,
```

Die Lizenz verlangt sie nicht. Über ein Sternchen für das Projekt freuen wir
uns trotzdem.

---

## 5. Schrift

Ohne Zutun nutzt flatlink Systemschriften: eine Monospace für alles
Produkthafte – Kopfzeile, Kurzlinks, Codes, Schaltflächen, Beschriftungen –
und die Standard-Grotesk des Betriebssystems für Fließtext. Das lädt nichts
nach, ist sofort da und sieht überall stimmig aus.

Eigene Schrift:

```css
:root {
    --mono: "IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace;
}
body {
    font-family: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, sans-serif;
}
```

Mit eigener Schriftdatei – Datei nach `assets/fonts/` legen:

```css
@font-face {
    font-family: "Hausschrift";
    src: url("fonts/hausschrift.woff2") format("woff2");
    font-weight: 400;
    font-display: swap;
}
body { font-family: "Hausschrift", sans-serif; }
```

> **Bitte selbst hosten, nicht über ein CDN einbinden.** Ein externer
> Schrift-Dienst sieht die IP-Adresse jedes einzelnen Besuchers. Bei einer
> Software, deren ganzer Sinn darin besteht, Besucher *nicht* zu erfassen,
> wäre das ein Eigentor – und in der EU obendrein rechtlich heikel.

Behalte für `--mono` eine echte Monospace. An mehreren Stellen ist auf
gleiche Zeichenbreite gebaut, etwa bei ausgerichteten Zahlen und Codes.

---

## 6. Einzelne Bereiche

Wenn die Variablen nicht reichen, hier die Klassen, an denen sich gezielt
eingreifen lässt:

| Klasse | Bereich |
| --- | --- |
| `.site-head` / `.site-foot` | Kopf- und Fußzeile |
| `.brand` / `.brand-logo` | Wortmarke und Logo |
| `.hero` | Titelbereich der Startseite |
| `.card` | Karten: Formulare, Listen, Hinweise |
| `.card.highlight` | hervorgehobene Karte |
| `.btn` / `.btn-primary` | Schaltflächen |
| `.flash-ok` / `.flash-err` | Rückmeldungen |
| `.tag` / `.tag-on` | Gruppen-Kennzeichen |
| `.table-scroll table` | Tabellen |
| `.short-row` / `.grid-form` / `.check` | Formular-Layouts |
| `.designer` | zweispaltiges Layout des QR-Designers |
| `.origin` | Herkunftszeile im Fuß |
| `main` | Inhaltsbereich; wächst, damit der Fuß unten bleibt |
| `body.<name>` | ganze Gestaltungsvariante, siehe unten (`body_class`) |

Ein paar erprobte Eingriffe:

```css
/* Kantig statt rund */
:root { --radius: 0; }
.btn { border-radius: 0; }

/* Kopfzeile in Hausfarbe hinterlegen */
.site-head {
    background: var(--accent);
    border-bottom: 0;
    padding-inline: 1rem;
    margin-inline: -1rem;
}
.site-head .brand,
.site-head nav a { color: var(--on-accent); }

/* Karten mit Schatten statt Rahmen */
.card {
    border-color: transparent;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.08), 0 1px 2px rgb(0 0 0 / 0.04);
}

/* Breitere Seite */
.wrap { max-width: 1140px; }
```

### Varianten, die sich abschalten lassen

Ein größerer Umbau ist heikel: Gefällt er nicht, muss man ihn wieder
herausoperieren – und dabei geht meist versehentlich auch etwas Gutes mit
verloren. Dafür gibt es `body_class` in der Konfiguration. Der Wert landet als
Klasse am `<body>`, und alles, was darunter geschrieben ist, gilt nur, solange
er gesetzt ist:

```php
// inc/config.php
'body_class' => 'kantig',
```

```css
/* assets/custom.css */
body.kantig { --radius: 0; }
body.kantig .card { border-width: 2px; box-shadow: 5px 5px 0 var(--ink); }
body.kantig .btn { border-radius: 0; }
```

Ein leerer Wert nimmt die ganze Variante zurück, ohne dass eine Zeile CSS
gelöscht wird – und ein anderer Wert schaltet auf die nächste um. So lassen
sich zwei Entwürfe nebeneinander pflegen und im Wechsel ansehen.

Für **vollflächige Farbbänder**, die aus der Inhaltsspalte ausbrechen, hat sich
dieses Muster bewährt:

```css
body.variante .band { position: relative; }
body.variante .band::before {
    content: "";
    position: absolute;
    z-index: -1;               /* hinter dem Inhalt, nicht im Weg */
    inset: 0;
    left: 50%;
    width: 100vw;
    transform: translateX(-50%);
    background: var(--accent-tint);
    clip-path: polygon(0 0, 100% 0, 100% calc(100% - 3rem), 0 100%);
}
body.variante { overflow-x: clip; }   /* nicht 'hidden': das bricht position: sticky */
```

Zwei Stolpersteine dabei: Ein `100vw` breites Element ist bei sichtbarer
Bildlaufleiste minimal breiter als der Inhalt – daher `overflow-x: clip` am
`body`. Und eine solche Fläche darf nicht unter den letzten Inhalt hinausragen,
auch nicht „nur ein bisschen zur Sicherheit": Sie verlängert sonst die
Scrollfläche, und die Seite lässt sich ins Leere weiterscrollen.

---

## 7. QR-Codes

Die erzeugten Codes lassen sich unabhängig vom Aussehen der Website anpassen.

**Absenderzeile unter jedem Code** – nützlich, wenn gedruckte Codes erkennbar
sein sollen:

```php
'qr_brand_text' => 'musterhochschule.de',
```

Sie erscheint bei rahmenlosen Codes als dezente Zeile darunter und bei
gerahmten im Band. Leer lassen heißt: keine Zeile.

**Saubere Beschriftung im PNG.** Rahmen- und Absendertexte setzt flatlink im
SVG immer sauber. Für PNG und PDF braucht die Bildbibliothek eine
TrueType-Datei: irgendeine `.ttf` nach `assets/fonts/` legen, die erste
gefundene wird genommen. Ohne Datei greift ein grober Systemfont – lesbar,
aber nicht schön.

Es liegt bewusst keine Schrift bei, damit dem Projekt keine fremde
Font-Lizenz anhängt. Frei verwendbar sind etwa DejaVu Sans Mono, JetBrains
Mono oder Inter.

**Farben und Formen der Codes** stellt jeder Nutzer im QR-Designer selbst ein –
das ist Teil der Oberfläche, keine Konfiguration.

---

## 8. Ein vollständiges Beispiel

So sieht eine komplett umgestellte Instanz aus – warmes Papier, kräftiges Rot,
kantige Formen, eigenes Logo. Getestet, nicht ausgedacht.

`inc/config.php`:

```php
'site_name'    => 'Kurzlinks der Musterhochschule',
'logo'         => 'logo.svg',
'footer_links' => [
    'Impressum'   => 'impressum.html',
    'Datenschutz' => 'https://example.org/datenschutz',
],
```

`assets/custom.css`:

```css
:root {
    --paper: #FFFDF7; --surface: #FFF6E0; --ink: #2B1A08; --muted: #7A6244;
    --line: #EBDCBD; --accent: #C8102E; --accent-deep: #96001F;
    --accent-tint: #FBE9EC; --on-accent: #FFFFFF;
    --radius: 0;
}
@media (prefers-color-scheme: dark) {
    :root {
        --paper: #14100A; --surface: #1F1810; --ink: #F2E9DA; --muted: #B0A08A;
        --line: #35291B; --accent: #E8556E; --accent-deep: #F2919F;
        --accent-tint: #2A1A1E; --on-accent: #14100A;
    }
}
.btn { border-radius: 0; }
```

`assets/logo.svg` – als Platzhalter genügt zum Ausprobieren:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40">
  <rect width="60" height="40" rx="3" fill="#C8102E"/>
  <text x="30" y="26" font-family="monospace" font-size="17" font-weight="700"
        fill="#fff" text-anchor="middle">MH</text>
</svg>
```

Mehr braucht es nicht. Kein Quelltext angefasst, alles updatesicher.

---

## 9. Was beim Update passiert

Ein `git pull` ersetzt `assets/style.css` – deine `custom.css` bleibt
unangetastet, ebenso `inc/config.php`, `assets/fonts/` und alle eigenen
Bilddateien.

Was das bedeutet: Deine Überschreibungen greifen weiter, aber wenn eine neue
Version Klassen umbenennt oder Bereiche umbaut, können einzelne Regeln ins
Leere laufen. **Variablen sind davor sicher** – sie sind die verlässlichste
Ebene und der Grund, warum du möglichst viel darüber lösen solltest. Regeln,
die auf konkrete Klassen zielen, sind es weniger.

Nach einem größeren Update lohnt daher ein kurzer Blick auf die eigene Instanz.
Kommen neue Optionen dazu, tauchen sie zuerst in
`inc/config.example.php` und `assets/custom.example.css` auf.

---

## 10. Eigene Seiten und Funktionen

Zusätzliche Seiten legst du einfach als weitere Dateien in den Webroot – sie
können `inc/store.php` einbinden und alle Bausteine mitbenutzen. In die
Navigation kommen sie über die Konfiguration:

```php
'nav_links'       => ['Hilfe' => 'hilfe.php'],   // immer sichtbar
'nav_links_guest' => ['Preise' => 'preise.php'], // nur für Nichtangemeldete
```

Brauchen diese Seiten eigene Hilfsfunktionen, gehören die nach
**`inc/local.php`**. Existiert die Datei, wird sie automatisch geladen; sie ist
vom Update ausgenommen und damit der richtige Ort für alles, was nur deine
Installation braucht.

```php
<?php
// inc/local.php
function hinweis_kasten(string $text): string
{
    return '<div class="card highlight"><p>' . e($text) . '</p></div>';
}
```

Sie kann allerdings nur **ergänzen**: Vorhandene Funktionen lassen sich in PHP
nicht überschreiben.

## 11. Wo die Grenze liegt

Ohne Eingriff in den Quelltext lässt sich **nicht** ändern:

- **Die Texte der Oberfläche.** Sie stecken direkt in den PHP-Dateien. Es gibt
  keine Übersetzungsebene, und sie sind durchgehend deutsch.
- **Aufbau und Reihenfolge der bestehenden Seiten.** Welche Karte wo steht,
  entscheidet das jeweilige PHP-Skript.
- **Die Reihenfolge der Navigationspunkte.** Eigene Einträge stehen immer vorn.

Wer daran muss, kommt um einen Fork nicht herum – die MIT-Lizenz erlaubt das
ausdrücklich. Rechne dann damit, bei Updates gelegentlich von Hand
zusammenführen zu müssen.

Und falls dir für deine Anpassung ein Haken fehlt, der auch anderen nützen
würde: [Sag Bescheid](https://github.com/HerrBarmann/flatlink/issues). Genau
so ist diese Anleitung entstanden.
