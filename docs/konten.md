# Konten und Anmeldung

Anmeldung in zwei Schritten, Passkeys und Einmalkennwörter, zentrale
Anmeldung über LDAP oder den Webserver, und was Konten selbst über ihre
Daten bestimmen. Zurück zur [README](../README.de.md). – 🇬🇧 [English
version](konten.en.md).

## Die Anmeldemaske

Die Anmeldung läuft in zwei Schritten: erst die Kennung, dann der Nachweis.

Das ist keine Mode, sondern eine Notwendigkeit. Ein Passkey ist an ein Konto
gebunden – welche Geräte in Frage kommen, weiß die Seite erst, wenn sie weiß,
wer sich anmelden will. Solange Kennung und Passwort in einer Maske standen,
blieb dem Passkey nur der Platz *hinter* dem Passwort, also die Rolle des
zweiten Faktors. Das verschenkt ihn: Ein Passkey ist selbst schon zweierlei –
Besitz des Geräts **und** dessen Entsperrung.

Also:

1. **Kennung.** E-Mail oder Nutzername. Wer einen auffindbaren Passkey hat,
   bekommt ihn hier schon in der Vorschlagsliste des Feldes angeboten und ist
   mit einem Tippen drin – getippt werden muss nichts. Das Feld trägt dafür
   `autocomplete="username webauthn"`, die Suche macht das Gerät, nicht der
   Server.
2. **Nachweis.** Gibt es Passkeys, startet die Abfrage von selbst. Darunter
   steht, durch ein *oder* getrennt, das Passwortfeld — nicht hinter einem
   Umschalter, sondern einfach da. Gibt es keine Passkeys, entfällt der obere
   Teil.

*Nicht du?* führt zurück in Schritt 1. Ohne JavaScript entfällt allein der
Passkey-Knopf; alles andere steht, wo es steht — es gibt nichts zu verbergen
und nichts hervorzuholen.

### Was der Passkey allein tragen muss

Als zweite Stufe genügte dem Server das Bit *User Present* (0x01): Irgendwer
hat das Gerät berührt – der Wissensnachweis war ja das Passwort davor. Als
Ersatz fürs Passwort reicht das nicht. Dafür verlangt flatlink zusätzlich
*User Verified* (0x04): Das Gerät hat Fingerabdruck, Gesicht oder PIN geprüft.
Ohne diesen Nachweis wäre ein liegengelassenes, entsperrtes Telefon die ganze
Anmeldung.

Deshalb steht bei der Einrichtung im Profil `userVerification: 'required'` –
ein Gerät, das gar nicht erst nachfragt, käme später nicht durch, und das sagt
man besser vorher. Dieselbe Antwort geht als zweite Stufe weiterhin durch;
der Unterschied steckt allein im Verwendungszweck.

Festgehalten ist das in [`tests/passkey-anmeldung.php`](../tests/passkey-anmeldung.php):
Der Test spielt selbst den Authenticator und prüft unter anderem, dass
dieselbe Antwort als zweite Stufe angenommen und als Passwortersatz abgelehnt
wird.

### Was die Maske über Konten verrät

Ehrlich benannt, weil es sich nicht ganz vermeiden lässt: Ein unbekannter Name
sieht in Schritt 2 genauso aus wie ein Konto ohne Passkey – Passwortfeld, und
der Fehler kommt erst nach dem Absenden. Wer aber einen Passkey hinterlegt hat,
ist an der startenden Abfrage zu erkennen.

Das ist der Preis des Angebots, und es ist derselbe Handel, den die großen
Anbieter eingehen. Der Weg über die Vorschlagsliste in Schritt 1 verrät
dagegen nichts: Dort sucht das Gerät. Beide Wege liegen unter derselben
Fehlversuchsbremse wie die Passwortanmeldung.

## Zwei-Faktor-Anmeldung

Warum das hier drin ist: Wer ein Konto übernimmt, kann das Ziel eines
Kurzlinks ändern – auch das eines Codes, der längst gedruckt auf einem
Schild klebt. Der Schaden trifft dann nicht den Kontoinhaber, sondern jeden,
der scannt. Für einen Dienst, der gedruckte Codes ausgibt, ist ein Passwort
allein eine dünne Tür.

Zwei Verfahren stehen zur Wahl, beide im Profil einzurichten. Sie schließen
sich nicht aus – wer beide hinterlegt, hat beim Anmelden die Wahl.

Der Name der Überschrift stimmt streng genommen nur noch für das
Einmalkennwort: Es tritt *neben* das Passwort. Der Passkey tritt an *dessen
Stelle* (siehe oben) und bringt seinen zweiten Faktor selbst mit.

### Passkeys (WebAuthn)

Fingerabdruck, Gesicht oder Geräte-PIN, hinterlegt im Telefon, im Rechner
oder auf einem Sicherheitsschlüssel. Bis zu zehn Geräte je Konto.

Der Unterschied zum Einmalkennwort ist nicht die Bequemlichkeit, sondern die
**Bindung an die Domain**. Ein sechsstelliger Code lässt sich auf einer
nachgebauten Anmeldeseite eintippen und binnen Sekunden weiterreichen; einen
Passkey gibt der Browser dort gar nicht erst heraus, weil die Herkunft nicht
stimmt. Das ist der eigentliche Gewinn.

Umgesetzt in [`inc/webauthn.php`](../inc/webauthn.php) – reines PHP, wie
alles hier: Der CBOR-Leser ist selbst geschrieben, die Unterschrift prüft
das OpenSSL, das PHP ohnehin mitbringt. Unterstützt werden ES256 (was
Telefone und Sicherheitsschlüssel praktisch immer liefern) und RS256 (ältere
Windows-Hello-Installationen). `assets/passkey.js` packt nur zwischen JSON
und der Binärschnittstelle des Browsers um; **geprüft wird ausschließlich
auf dem Server** – das Skript lässt sich ohne Sicherheitsverlust lesen,
ändern und umgehen.

Vier Prüfungen machen den Schutz aus, und keine davon darf wegfallen:

1. Die Aufgabe (*Challenge*) muss die sein, die der Server gestellt hat. Sie
   gilt fünf Minuten und genau einmal.
2. Die Herkunft (*Origin*) muss die eigene sein – hier hängt die
   Phishing-Abwehr.
3. Der Hash der Domain im Gerätedatensatz muss zur eigenen Domain passen.
4. Die Unterschrift muss zum hinterlegten Schlüssel passen.

Dazu der Signaturzähler: Läuft er zurück, wurde der Schlüssel vermutlich
kopiert, und die Anmeldung wird abgelehnt. Viele Geräte zählen gar nicht –
nur ein echter Rückschritt gilt als verdächtig.

Passkeys brauchen HTTPS (`localhost` ausgenommen). Auf einer Instanz ohne
TLS blendet das Profil den Knopf nicht ein, statt ein Versprechen zu geben,
das der Browser nicht einlöst.

**Es gibt keine Wiederherstellungscodes.** Ein Passkey lässt sich nicht
abschreiben und in den Safe legen. Deshalb zwei Wege zurück: ein zweites
Gerät hinterlegen – oder ein Administrator setzt die zweite Stufe unter
*Nutzer* zurück. Diese Möglichkeit ist Absicht und zugleich das schwächste
Glied der Kette; wer sie benutzt, sollte sicher sein, mit wem er spricht.

### Einmalkennwörter aus einer App (TOTP)

QR-Code scannen, sechs Ziffern eintippen, fertig. Acht
Wiederherstellungscodes werden dabei einmal angezeigt; jeder gilt genau
einmal, für den Fall, dass das Telefon weg ist. Funktioniert auf jedem Gerät
und in jedem Browser – aber es lässt sich abtippen, und damit auch auf einer
nachgebauten Seite eingeben.

Umgesetzt nach RFC 6238 in reinem PHP – HMAC-SHA1 und base32 bringt die
Sprache mit, den QR-Code erzeugt der eigene Encoder. Geprüft gegen die
Testvektoren des Standards.

Zwei Dinge, die nicht selbstverständlich sind:

- **Der QR-Code wird eingebettet, nicht verlinkt.** Die `otpauth`-Adresse
  enthält das Geheimnis; als URL landete es in Server-Protokollen, im
  Verlauf des Browsers und im Referrer. Das SVG entsteht im selben Aufruf.
- **Ein Kennwort gilt nur einmal.** Der zuletzt benutzte Zähler wird
  festgehalten. Ohne diese Sperre könnte jemand, der einmal über die
  Schulter geschaut hat, sich im selben 30-Sekunden-Fenster selbst anmelden.

### Erzwingen

Über `'totp_required'` (`off` | `admins` | `all`, auch unter
*Einstellungen*) lässt sich die zweite Stufe verlangen. **Erfüllt wird die
Auflage durch eines der beiden Verfahren** – der Schlüsselname ist aus der
Zeit vor den Passkeys und bleibt, damit bestehende Konfigurationen
weiterlaufen. Wer noch keines eingerichtet hat, wird nach der Anmeldung ins
Profil geführt statt ausgesperrt; das letzte verbliebene Verfahren lässt
sich dann nicht mehr entfernen.

**API-Schlüssel sind davon nicht betroffen** – sie sind ein eigener Nachweis
und tragen kein Passwort, das ein Zweitfaktor absichern könnte. Wer ein
Konto besonders schützen will, prüft daher auch dessen Schlüsselliste.

### Vorschlagen statt verlangen

Ein Passkey nützt nur dem, der einen hat. Im Profil steht er seit je, aber
dorthin geht selten jemand ohne Anlass. `'passkey_hint'` (`on` | `local` |
`off`, auch unter *Einstellungen*) bietet ihn deshalb von sich aus an: Wer
keinen hat, landet nach der Anmeldung auf einer Seite, die erklärt, wozu er
gut ist — mit *Passkey einrichten*, *Später* und *Nicht mehr fragen*.

**Einmal im Monat, nicht öfter.** Der Stand steht am Konto (`pk_hint`): ein
Datum oder `nie`. Gesetzt wird er beim **Anzeigen**, nicht beim Wegklicken —
wer den Tab schließt, hat die Frage gesehen. Ein Vorschlag, den man dreimal
die Woche wegklickt, ist kein Vorschlag mehr, sondern eine Tür, die klemmt;
darum gibt es das *Nicht mehr fragen* daneben und nicht erst auf Nachfrage.

`local` lässt zentral verwaltete Konten aus. Das ist für Häuser gedacht, in
denen die Anmeldung am Verzeichnis hängen soll: Ein Passkey käme auch dann
noch durch, wenn dort das Passwort gewechselt wurde. Gesperrte Konten weist
er weiterhin ab — `auth_login_passkey()` prüft das —, und der
Verzeichnisabgleich sperrt, wer dort verschwindet. Wem das genügt, kann `on`
stehen lassen.

Ohne HTTPS entfällt das Angebot von selbst, wie der Knopf im Profil auch.
Wann gefragt wird und wann nicht, hält
[`tests/passkey-hinweis.php`](../tests/passkey-hinweis.php) fest.

## Zentrale Anmeldung

Beide Wege sind optional, stehen standardmäßig auf `false` und lassen sich
parallel zu lokalen Konten betreiben. Hier steht das Prinzip – die
Schritt-für-Schritt-Einrichtung samt Apache-Konfiguration, SP-Metadaten und
Attributfreigabe steht in der
[Deployment-Anleitung](DEPLOYMENT.md#8-shibboleth-saml-und-openid-connect).

### Über den Webserver (Shibboleth, SAML, OpenID Connect)

Der empfohlene Weg für einen Shibboleth-IdP. Die eigentliche Anmeldung
erledigt ein Servermodul – `mod_shib`, `mod_auth_mellon` oder
`mod_auth_openidc` –, das den Admin-Bereich schützt. flatlink liest nur, wen
der Server bereits authentifiziert hat. Für Apache:

```apache
<Location /admin>
    AuthType shibboleth
    ShibRequestSetting requireSession 1
    Require valid-user
</Location>
```

Dann in `config.php` unter `sso` die Variable benennen, in der die Kennung
steht (meist `REMOTE_USER`), optional die für E-Mail-Adresse und
Gruppenzugehörigkeit, und `login_url` auf `/Shibboleth.sso/Login` setzen.
Konten entstehen beim ersten Login automatisch.

> **Sicherheitshinweis, bitte nicht überlesen.** Variablen, die der Webserver
> selbst setzt (`REMOTE_USER`, die Attribute von `mod_shib`), sind
> vertrauenswürdig. Ein Wert, der als **HTTP-Header** ankommt – der
> Variablenname beginnt dann mit `HTTP_` –, ist es nicht: Den kann jeder
> Client frei erfinden und sich damit als beliebiger Nutzer ausgeben, inklusive
> Administrator. flatlink akzeptiert solche Variablen deshalb nur, wenn unter
> `trusted_proxies` die IP-Adresse des Reverse Proxy steht, der diese Header
> nachweislich überschreibt. Ohne diesen Eintrag werden sie verworfen und die
> Anmeldung schlägt fehl. Das ist Absicht.

### Über LDAP oder Active Directory

Hier fragt flatlink selbst beim Verzeichnis nach; Kennung und Passwort
werden im gewohnten Login-Formular eingegeben. Braucht die PHP-Erweiterung
`ldap`.

Geprüft wird per Bind als der gefundene Nutzer – das Passwort wird nirgends
gespeichert und nicht mit einem lokalen Hash verglichen. Eingaben werden vor
dem Einsetzen in den Suchfilter maskiert, LDAP-Injection ist damit nicht
möglich; leere Passwörter werden abgelehnt, bevor sie als „unauthenticated
bind“ fälschlich als Erfolg durchgehen könnten.

Reihenfolge beim Login: erst das lokale Passwort, dann das Verzeichnis.
Lokale Konten funktionieren also weiter – wichtig, damit man sich nicht
aussperrt, wenn der LDAP-Server einmal nicht erreichbar ist.

Bei `ldap://` unbedingt `start_tls` einschalten, sonst geht das Passwort im
Klartext über das Netz. Besser gleich `ldaps://`.

**Wenn die Anmeldung scheitert**, sagt die Oberfläche absichtlich nicht,
woran – sonst ließe sich daran ablesen, welche Kennungen es gibt. Wer die
Instanz einrichtet, braucht die Auskunft aber:

```bash
php tools/ldap-check.php kennung -p
```

Das Werkzeug geht Erweiterung, Konfiguration, Verbindung, Bind, Suche und
Passwortprüfung der Reihe nach durch und hält an der ersten Stelle an, die
nicht stimmt – mit einem konkreten Rat statt einer Fehlernummer. Das
Passwort wird abgefragt, nicht als Argument übergeben, sonst stünde es in
der Prozessliste. Zusätzlich schreibt die Anmeldung den Grund ins
Fehlerprotokoll des Webservers.

### Ein Konto löschen

Beide Wege – die Selbstlöschung im Profil und das Löschen durch die
Verwaltung – räumen beide gleichermaßen auf: Zugangsschlüssel werden
widerrufen, offene Bestätigungen verworfen, und die Links werden verteilt.

| | |
| --- | --- |
| Links einer **Arbeitsgruppe** | bleiben der Gruppe und verlieren nur den Besitzer. Dafür gibt es Gruppen – ein ausgeschiedener Kollege nimmt das gemeinsame Plakat nicht mit. |
| Links **ohne Gruppe** | wären danach herrenlos. Beim Löschen durch die Verwaltung entscheidet der Administrator: an sich übertragen oder mitlöschen. Wer sich selbst löscht, hat niemanden, an den er übergeben könnte – dort werden sie gelöscht. |

Im Zweifel übertragen: Ein gedruckter Code, dessen Ziel verschwindet, führt
ins Leere, und das merkt man erst, wenn sich jemand beschwert.

**Den Besitzer eines Links ändern** geht auch ohne Löschen – im
Bearbeiten-Formular der Linkliste, sichtbar für Administratoren und Konten
mit `links_all`. Dort lässt sich auch „niemand“ wählen: Dann gehört der Link
nur noch seiner Gruppe. Ohne Gruppe lehnt die Instanz das ab, sonst fände
den Link außer der Verwaltung niemand mehr.

### Konten aus dem Verzeichnis anlegen

Steht `auto_create` auf `false`, entstand ein Konto bisher erst *nach* einem
vergeblichen Anmeldeversuch: Der Versuch legte einen Eintrag in der
Warteschlange an, den ein Administrator freischaltete. Das funktioniert,
mutet den Leuten aber einen Fehlschlag zu, den sie nicht einordnen können –
und wer ein Konto vorbereiten will, bevor jemand anfängt, kann es gar nicht.

Unter *Nutzer → Aus dem Verzeichnis anlegen* lässt sich deshalb direkt
suchen, nach Name, Kennung oder E-Mail. Ein Klick legt das Konto an, mit
Klarname und Adresse aus dem Verzeichnis; die Anmeldung funktioniert sofort.
Wer schon ein Konto hat, wird als solcher ausgewiesen, ohne Knopf zum
Anlegen.

Gesucht wird mit dem Dienstkonto aus `bind_dn`, also mit denselben Rechten
wie bei der Anmeldung. Zwei Schlüssel steuern das:

| | |
| --- | --- |
| `search_filter` | **Leer lassen.** Der Filter entsteht dann aus den Attributen, die ohnehin konfiguriert sind (`uid_attr`, `name_attr`, `mail_attr`) plus `cn`, `sn`, `givenName`, `mail`. Nur für Sonderfälle eintragen, etwa um auf eine Abteilung einzugrenzen. |
| `uid_attr` | Attribut mit der Kennung. Leer = aus dem `user_filter` ablesen, was in aller Regel stimmt. |

Dass der Filter aus der Konfiguration entsteht, ist der springende Punkt:
Ein Verzeichnis, das seinen Anzeigenamen in einem eigenen Feld führt – an
der HfMT etwa `hfmtDisplayNameStr` –, findet mit einem fest eingebauten
`(cn=*%s*)` nur über die Kennung. Wer `name_attr` gesetzt hat, hat damit
auch gesagt, wo der Name steht; ein zweiter Eintrag dafür wäre eine
Fehlerquelle mehr.

Mehrere Wörter werden UND-verknüpft, jedes für sich über alle Attribute.
„Dennis Bormann“ trifft damit auch einen Eintrag „Bormann, Dennis“ – und
zwei Namensteile machen die Suche enger statt breiter.

Die Warteschlange bleibt daneben bestehen: Wer sich ohne Konto anmeldet,
landet weiterhin dort. Beides führt zum selben Ergebnis, nur von
verschiedenen Seiten.

### Gruppen aus dem Verzeichnis

Beide Wege können Gruppenzugehörigkeiten übernehmen: bei SSO aus einem
Attribut wie `isMemberOf` oder `entitlement`, bei LDAP aus `memberOf` oder
per Suche im Gruppenbaum. Die Zuordnungstabelle `group_map` bildet externe
Namen auf lokale Gruppen ab:

```php
'group_map' => [
    'urn:mace:example.org:group:marketing' => 'marketing',
    'cn=it,ou=groups,dc=example,dc=org'    => ['it', 'technik'],
],
```

Ist die Tabelle leer, wird ein externer Name nur übernommen, wenn es lokal
eine gleichnamige Gruppe gibt. Aus dem Verzeichnis kommende Namen können nie
neue Gruppen anlegen und nie Rechte erfinden – welche Rechte an einer Gruppe
hängen, entscheidet immer die lokale Konfiguration.

### Anzeigenamen

Kommt die Kennung als undurchsichtige Zeichenkette aus der Föderation
(`persistent-id`, `pairwise-id`), ist die Nutzerverwaltung ohne Klarnamen
kaum bedienbar. Deshalb übernimmt flatlink auf Wunsch einen Anzeigenamen aus
dem Verzeichnis – bei SSO über `name_var`, bei LDAP über `name_attr`. In der
Oberfläche steht dann der Name, die technische Kennung nur klein darunter.
Lokale Konten setzen ihren Anzeigenamen selbst im Profil, Administratoren
können ihn überall nachpflegen. Gesucht wird über Name, Kennung und
E-Mail-Adresse gleichzeitig.

Die Rolle bleibt beim erneuten Login unangetastet: Wer hier zum
Administrator gemacht wurde, bleibt es. Und ein Konto, das zentral verwaltet
wird, kann sich nicht mehr über das lokale Passwortformular anmelden – sonst
wäre die zentrale Anmeldung über ein altes Passwort umgehbar.

### Was zentral verwaltete Konten im Profil können

Wer sich über LDAP oder den Webserver anmeldet, hat hier keinen
Passwort-Hash – die Anmeldung weist solche Konten lokal ab, und jede
Anmeldung über das Verzeichnis entfernt einen etwaigen Alt-Hash. Das Profil
zeigt darum kein Passwortformular, sondern den Hinweis, wo das Passwort
hingehört. Ebenso beim Anzeigenamen: Liefert das Verzeichnis einen, gewinnt
er. Eine E-Mail-Adresse lässt sich dagegen eintragen – sie wird nur
überschrieben, wenn das Verzeichnis selbst eine mitliefert.

### Auskunft, Mitnahme, Löschung

Im Profil steht beides ohne Umweg über den Betreiber:

**Daten herunterladen** liefert eine JSON-Datei mit allem, was zum Konto
gespeichert ist – Kontodaten, angemeldete Geräte, der Stand der
Zwei-Faktor-Anmeldung samt Passkey-Bezeichnungen, die Zugangsschlüssel der
Schnittstelle, Gruppen, Rechte, Limits und jeder Kurzlink mit Ziel, Daten,
Klickzahlen, den Änderungen am Ziel und – bei Link-in-Bio – der Seite
selbst. Nicht dabei sind Zugangsmittel: Passwort-Hash, das Geheimnis der
Authenticator-App, das Schlüsselmaterial der Passkeys, die Hashes der
Zugangsschlüssel und der Abdruck laufender Sitzungen. Sie sind kein Inhalt,
und eine Datei damit landet danach im Download-Ordner. Die Datei selbst
zählt auf, was sie aus welchem Grund auslässt. Das deckt Art. 15 (Auskunft)
und Art. 20 (Mitnahme).

**Browser-Erweiterung** zeigt, was die Instanz anbietet – das hängt davon
ab, was unter *Einstellungen → Browser-Erweiterung* eingetragen ist.

*Verbindungscode* gibt es immer. Er enthält Adresse und einen frisch
erzeugten Zugangsschlüssel und richtet eine bereits installierte Erweiterung
mit einem einzigen Einfügen ein. Der Schlüssel ist ein eigener (Bezeichnung
„Browser-Erweiterung“ mit Datum) und lässt sich einzeln zurückziehen, ohne
andere Programme lahmzulegen.

*Knöpfe in die Läden* erscheinen, sobald dort Adressen hinterlegt sind – für
Instanzen, deren Erweiterung im Chrome Web Store, bei den Firefox-Add-ons
oder den Edge-Add-ons steht.

Eine Instanz ohne eigenen Store-Eintrag braucht nichts weiter: Die neutrale
Fassung steht in den Läden, fragt beim ersten Öffnen nach der Adresse, und
der Verbindungscode trägt Adresse und Schlüssel in einem Zug ein.

**Konto sperren** hält jemanden draußen, ohne etwas wegzuwerfen: Anmeldung,
Zugangsschlüssel und laufende Sitzungen greifen sofort nicht mehr, aber Links,
Statistik und gedruckte QR-Codes bleiben unangetastet. Das ist der Unterschied
zum Löschen, und er ist der Grund, warum es beides gibt – eine Sperre lässt
sich aufheben, ein gelöschtes Konto nicht wiederholen. Für jemanden, der das
Haus verlässt, ist Sperren fast immer das Richtige: Die Kurzlinks auf seinen
Aushängen sollen weiter funktionieren.

### Verzeichnisabgleich

Läuft die Anmeldung über LDAP, regelt das Verzeichnis den *Zugang*, nicht den
*Bestand*: Wer ausscheidet, kommt nicht mehr herein – sein Konto und seine
Zugangsschlüssel bleiben trotzdem. `php tools/flatlink ldap:abgleich` schließt
diese Lücke. Er holt alle Kennungen aus dem Verzeichnis und sperrt die Konten,
die dort nicht mehr stehen.

Vier Sicherungen sind eingebaut, und sie sind der eigentliche Punkt:

* **Ohne `--anwenden` passiert nichts.** Der Probelauf zeigt nur, was er täte.
* **Antwortet das Verzeichnis nicht, bricht er ab.** Eine Zeitüberschreitung
  ist kein Grund, ein Haus auszusperren.
* **Fehlen mehr als 20 Prozent der Konten, bricht er ebenfalls ab.** Dann ist
  wahrscheinlich der Suchzweig falsch und nicht die Belegschaft entlassen.
  Mit `--grenze=` lässt sich das anheben, wenn es doch stimmt.
* **Lokale Konten fasst er nicht an**, und aufheben tut er nur Sperren, die er
  selbst gesetzt hat.
* **Administratoren sperrt er nie**, sondern listet sie zur Prüfung auf. Sind
  die Administratoren einer Hochschule LDAP-Konten – der Normalfall –, könnte
  ein einziger Lauf sie alle gleichzeitig aussperren; herausgeholfen hätte
  danach nur der Dateizugriff, den es auf Shared Hosting nicht gibt. Wer es
  trotzdem will, hängt `--auch-admins` an.
* **Er blättert durch das Verzeichnis** und bricht ab, wenn der Server seine
  Antwort gekürzt hat. Active Directory liefert von Haus aus höchstens 1000
  Einträge, OpenLDAP meist 500 – und zwar nicht mit einem Fehler, sondern mit
  einer Teilmenge. Wer die für das ganze Verzeichnis hält, sperrt alles
  dahinter.

Für den regelmäßigen Lauf genügt ein Eintrag in der Aufgabenplanung:

```
17 3 * * * cd /var/www/flatlink && php tools/flatlink ldap:abgleich --anwenden
```

**Konto löschen** entfernt das Konto und alle Links, die nur daran hängen,
samt Klickzählern. Links **mit Gruppenzuordnung bleiben** und verlieren nur
ihren Besitzer – sie gehören der Gruppe, andere arbeiten damit weiter, und
gedruckte QR-Codes darauf sollen nicht ins Leere zeigen, weil eine Person
geht. Vorher wird das Passwort abgefragt (bei zentraler Anmeldung: die
eigene Kennung abgetippt), der letzte Administrator kann sich nicht selbst
entfernen.

Auf einer Instanz mit zentral verwalteten Konten ist der Löschknopf
irreführend, weil das Verzeichnis das Konto bei der nächsten Anmeldung neu
anlegt. Dort `'self_delete' => false` setzen – der Export bleibt davon
unberührt.

### Zwei Hinweise zum Datenschutz

**Google Safe Browsing** ist standardmäßig **aus**. Wer es aktiviert,
schickt beim Anlegen eines Links dessen Ziel-URL an Google. Für eine
öffentliche Instanz ist das ein wirksamer Schutz gegen Phishing-Missbrauch,
für eine interne meist überflüssig. Wer es einschaltet, sollte es in seiner
Datenschutzerklärung angeben.

**Der Webserver protokolliert weiter.** flatlink speichert keine
IP-Adressen, die Zugriffs-Logs von Apache oder nginx tun es in aller Regel
schon. Wer den Anspruch ernst nimmt, kürzt oder deaktiviert sie dort.

