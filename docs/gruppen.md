# Gruppen, Rechte und Domains

Wie aus Rechten Tarife werden, wie Teams gemeinsam Links verwalten, wie sich
Namensräume und eigene Domains je Kunde abgrenzen lassen. Zurück zur
[README](../README.md). – 🇬🇧 [English version](gruppen.en.md).

## Gruppen und Rechte

Ohne Gruppen verhält sich flatlink wie ein Einzelplatz-Werkzeug: Jedes Konto
sieht nur seine eigenen Links. Gruppen ändern zwei Dinge.

**Geteilte Links.** Beim Anlegen eines Links lässt sich eine Gruppe wählen.
Der Link gehört dann dem ganzen Team: Jedes Mitglied sieht ihn, kann sein Ziel
ändern, den QR-Code gestalten, die Klickzahlen ansehen und ihn löschen. Das ist
der eigentliche Zweck – ein gedruckter Code soll nicht davon abhängen, ob die
Kollegin, die ihn angelegt hat, noch im Haus ist. Wer den Link ursprünglich
angelegt hat, behält ihn unabhängig von der Gruppe.

**Rechte.** Jede Gruppe trägt eine Menge von Rechten, die ihre Mitglieder
bekommen. Ein Konto in mehreren Gruppen hat die Summe aller Rechte.

Sie zerfallen in zwei Sorten, und die Unterscheidung ist keine Kosmetik: Die
erste sagt, **was ein Konto mit den eigenen Links tun darf** – auf einer Instanz
mit Tarifen ist genau das der Tarif. Die zweite sagt, **was jemand für andere
tun darf**, und beschreibt eine Rolle in der Organisation. Die Oberfläche zeigt
sie deshalb in zwei beschrifteten Blöcken; wer eine Arbeitsgruppe „Marketing"
anlegt, soll nicht versehentlich etwas anhaken, das Geld kostet.

*Was ein Konto selbst darf:*

| Recht | Bedeutung |
| --- | --- |
| `custom_code` | darf Wunsch-Namen vergeben statt Zufallscodes |
| `csv_import` | darf viele Links auf einmal importieren |
| `logo_upload` | darf eigene Logos für QR-Codes hochladen |
| `qr_unbranded` | erzeugt QR-Codes ohne die Absenderzeile |
| `api_access` | darf die Schnittstelle nutzen – und damit die Browser-Erweiterung |
| `bio_page` | darf Link-in-Bio-Seiten anlegen |
| `bio_style` | darf sie gestalten (Logo und Farben) |
| `link_rules` | darf Weichen stellen (Ziel je nach Gerät, Sprache, Land) |

*Was jemand für andere darf:*

| Recht | Bedeutung |
| --- | --- |
| `links_all` | sieht und verwaltet **alle** Links der Instanz |
| `reports_manage` | bearbeitet Missbrauchs-Meldungen und sperrt Links |

Ein Recht, das an einer Gruppe hängt, endet mit der Mitgliedschaft – auch mit
einer befristeten. Was damit **angelegt** wurde, bleibt aber: Bestehende Weichen
leiten weiter, eine gestaltete Bio-Seite behält ihr Aussehen, Wunsch-Adressen
bleiben bearbeitbar. Nur Neues folgt wieder den Regeln ohne das Recht. Wer
Tarife abbildet, sollte das so lassen: Ein gedruckter Code, der ins Leere zeigt,
weil eine Rechnung offen ist, schadet mehr als die entgangenen Einnahmen.

### Eine Redaktion ohne Administratorrechte

Die beiden letzten Rechte bilden zusammen das, was anderswo eine eigene Rolle
zwischen „Nutzer" und „Administrator" wäre – ohne dass es eine dritte Rolle
geben muss. Eine Gruppe „Redaktion" mit `links_all` und `reports_manage` darf:

* alle Links der Instanz sehen, bearbeiten und sperren,
* eingegangene Meldungen abarbeiten und die Bestandsprüfung anstoßen.

Sie darf ausdrücklich **nicht**: Konten anlegen, Gruppen ändern, Einstellungen
umstellen, das Protokoll lesen. Wer es doch versucht, bekommt 403. Das ist die
übliche Aufteilung im Betrieb: Wer den Missbrauchs-Posteingang hütet, braucht
Zugriff auf jeden Link – aber nicht auf die SMTP-Zugangsdaten.

Beide Rechte lassen sich auch einzeln vergeben. `links_all` allein ergibt eine
Aufsicht, die alles sieht, aber keine Meldungen bearbeitet; `reports_manage`
allein eine Beschwerdestelle, die nur die gemeldeten Links zu Gesicht bekommt.

### Namensräume

Eine Gruppe kann ein **Präfix** führen. Ihre Mitglieder legen Kurzlinks dann
ausschließlich darunter an:

```
kurz.hochschule.de/bib/oeffnungszeiten     ← Gruppe „Bibliothek", Präfix bib
kurz.hochschule.de/stud/mensaplan          ← Gruppe „Studierende", Präfix stud
```

Das löst den Streit um kurze Namen, bevor er entsteht: Jeder Bereich hat seinen
eigenen Raum, und `/mensaplan` bleibt frei für die zentrale Verwaltung. Wer in
mehreren Gruppen mit Präfix ist, wählt beim Anlegen; Administratoren sind nicht
beschränkt. Ohne Präfix verhält sich alles wie bisher.

### Limits und Befristung

Gruppen können außerdem **eigene Limits** mitbringen, die die globalen aus
`config.php` anheben – wer in mehreren ist, bekommt jeweils den höchsten Wert.
Und eine Mitgliedschaft lässt sich **befristen**: Nach dem Stichtag zählt sie
nicht mehr, ganz ohne Cronjob. Damit lässt sich ein gestaffeltes Angebot
abbilden, ohne dass die Software einen Tarifbegriff kennen müsste.

In `config.php` legt `default_perms` fest, was **jedes** angemeldete Konto
zusätzlich darf – auch ohne Gruppe. Administratoren dürfen immer alles.

Gerade Wunsch-Namen sind ein gutes Beispiel dafür, warum das an Gruppen hängt:
Der Namensraum einer Instanz ist endlich, und wer sich `/team` sichert, nimmt
ihn allen anderen weg. Als Gruppenrecht lässt sich das vergeben, statt es
entweder allen oder niemandem zu erlauben.

Angelegt werden Gruppen im Admin-Bereich unter **Gruppen**, zugeordnet werden
Konten unter **Nutzer**. Bei zentraler Anmeldung kann die Zuordnung auch aus
dem Verzeichnis kommen ([Gruppen aus dem Verzeichnis](konten.md#gruppen-aus-dem-verzeichnis)).

### Grundregeln und Gruppen

Was **jedes** Konto darf, steht unter *Einstellungen → Grundregeln*: Limits für
Links, Statistik-Tiefe und Logos, das Kontingent für Wunsch-Codes und die
Rechte, die alle bekommen. Die Vorgaben dafür stehen in `inc/config.php`; was
in der Oberfläche geändert wird, überschreibt sie und landet in
`data/settings.json`. Wer mehr bekommen soll als der Grundrahmen, bekommt es
über eine Gruppe.

### Zwei Arten von Gruppen

Eine Gruppe kann zweierlei bedeuten, und die beiden haben nichts miteinander zu
tun. Beim Anlegen wird es deshalb ausdrücklich gewählt:

| Art | Was sie tut | Wofür |
| --- | --- | --- |
| **Nur Rechte** | Vergibt Berechtigungen und Limits an ihre Mitglieder. Deren Links bleiben privat. | Tarife, Rollen, Kontingente |
| **Rechte und gemeinsame Linkverwaltung** | Zusätzlich lassen sich Links der Gruppe zuordnen; jedes Mitglied kann sie sehen, ändern und löschen. | Teams, die zusammenarbeiten |

Der Unterschied ist keine Feinheit. Hängt ein kostenpflichtiger Tarif an einer
Gruppe und ist diese als Arbeitsgruppe angelegt, taucht sie im Zuordnungsfeld
jedes Kunden auf – und wer sie versehentlich auswählt, gibt seinen Link für
sämtliche anderen Kunden zum Bearbeiten und Löschen frei.

Neue Gruppen legt die Oberfläche deshalb als **Rechtegruppe** an. Der
umgekehrte Irrtum ist billiger: Ein Team, das seine Links nicht sieht, meldet
sich sofort – ein Leck bemerkt niemand. Gruppen, die vor dieser Unterscheidung
angelegt wurden, gelten weiterhin als Arbeitsgruppen, damit bestehende Teams
nicht ausgesperrt werden; die Spalte *Art* in der Gruppenverwaltung zeigt für
jede Gruppe, woran man ist.

## Mehrere Domains

Kurzlinks lassen sich unter mehreren Adressen ausgeben – `kunde.link/shop`
statt `deine-instanz.de/shop`. Alle Domains zeigen auf dieselbe Installation:
im DNS auf denselben Server, im Zertifikat mit aufgeführt. Eingerichtet werden
sie unter *Einstellungen* oder über `'domains'` in der Konfiguration; eine
Domain lässt sich einer Gruppe vorbehalten, so wie ein Namensraum-Präfix.

**Ein Code gehört der Instanz, nicht der Domain.** Es gibt `/shop` genau
einmal, und er löst unter jeder eingerichteten Adresse auf. Das ist die
tragende Entscheidung, deshalb beide Seiten:

- *Dafür:* Ein gedruckter Code stirbt nicht, wenn eine Domain wegfällt. Zieht
  ein Kunde um oder läuft eine Domain aus, funktionieren die Aufkleber weiter.
  Für einen Dienst, dessen ganzer Zweck „gedruckt ist gedruckt" lautet, wiegt
  das schwerer als Exklusivität.
- *Dagegen:* Zwei Kunden können nicht beide `/shop` haben. Wer das braucht,
  gibt ihnen [Namensraum-Präfixe](#zwei-arten-von-gruppen) – dafür sind sie da.

Getrennte Namensräume je Domain wären eine andere Datenhaltung: Ein Link wäre
nicht mehr durch seinen Code bestimmt, sondern durch Domain *und* Code. Das
zöge sich durch Ablage, Schnittstelle, Import und jede Oberfläche.

Die **Verwaltung bleibt auf der Hauptdomain** – der aus `base_url`. Eine
Sitzung, ein Cookie, eine Adresse für Passkeys: Ein unter `kunde.link`
eingerichteter Passkey ließe sich auf der Hauptdomain nicht mehr benutzen.
Aufrufe von `/admin/` unter einer Nebendomain werden deshalb umgeleitet, bevor
überhaupt eine Sitzung entsteht.

Eine Nebendomain liefert **nur Kurzlinks** aus. Startseite, QR-Generatoren,
Meldeseite und Verwaltung leiten auf die Hauptdomain um (302, nicht 301 – eine
Domain kann später zur Hauptdomain werden). Ausgenommen sind die Seiten, die zu
einem Code gehören: Passwortabfrage, abgelaufen, gesperrt, nicht gefunden. Sie
bleiben unter der Adresse, unter der der Code gedruckt wurde.

Wählbar ist die Domain beim Anlegen, beim Ändern, im CSV-Import (für den
ganzen Vorgang, nicht je Zeile) und über die [Schnittstelle](../API.md)
(Feld `domain`). Wird eine Domain wieder entfernt, bleiben die Links bestehen –
sie zeigen dann auf eine Adresse, die nicht mehr eingerichtet ist, und müssen
einzeln umgestellt werden. Der Löschen-Knopf sagt das.

