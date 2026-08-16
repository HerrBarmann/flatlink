# Konten und Anmeldung

Zwei-Faktor-Anmeldung mit Passkeys oder Einmalkennwörtern, zentrale Anmeldung
über LDAP oder den Webserver, und was Konten selbst über ihre Daten bestimmen.
Zurück zur [README](../README.md). – 🇬🇧 [English version](konten.en.md).

## Zwei-Faktor-Anmeldung

Warum das hier drin ist: Wer ein Konto übernimmt, kann das Ziel eines
Kurzlinks ändern – auch das eines Codes, der längst gedruckt auf einem Schild
klebt. Der Schaden trifft dann nicht den Kontoinhaber, sondern jeden, der
scannt. Für einen Dienst, der gedruckte Codes ausgibt, ist ein Passwort allein
eine dünne Tür.

Zwei Verfahren stehen zur Wahl, beide im Profil einzurichten. Sie schließen
sich nicht aus – wer beide hinterlegt, hat beim Anmelden die Wahl.

### Passkeys (WebAuthn)

Fingerabdruck, Gesicht oder Geräte-PIN, hinterlegt im Telefon, im Rechner oder
auf einem Sicherheitsschlüssel. Bis zu zehn Geräte je Konto.

Der Unterschied zum Einmalkennwort ist nicht die Bequemlichkeit, sondern die
**Bindung an die Domain**. Ein sechsstelliger Code lässt sich auf einer
nachgebauten Anmeldeseite eintippen und binnen Sekunden weiterreichen; einen
Passkey gibt der Browser dort gar nicht erst heraus, weil die Herkunft nicht
stimmt. Das ist der eigentliche Gewinn.

Umgesetzt in [`inc/webauthn.php`](../inc/webauthn.php) – reines PHP, wie alles
hier: Der CBOR-Leser ist selbst geschrieben, die Unterschrift prüft das
OpenSSL, das PHP ohnehin mitbringt. Unterstützt werden ES256 (was Telefone und
Sicherheitsschlüssel praktisch immer liefern) und RS256 (ältere
Windows-Hello-Installationen). `assets/passkey.js` packt nur zwischen JSON und
der Binärschnittstelle des Browsers um; **geprüft wird ausschließlich auf dem
Server** – das Skript lässt sich ohne Sicherheitsverlust lesen, ändern und
umgehen.

Vier Prüfungen machen den Schutz aus, und keine davon darf wegfallen:

1. Die Aufgabe (*Challenge*) muss die sein, die der Server gestellt hat. Sie
   gilt fünf Minuten und genau einmal.
2. Die Herkunft (*Origin*) muss die eigene sein – hier hängt die
   Phishing-Abwehr.
3. Der Hash der Domain im Gerätedatensatz muss zur eigenen Domain passen.
4. Die Unterschrift muss zum hinterlegten Schlüssel passen.

Dazu der Signaturzähler: Läuft er zurück, wurde der Schlüssel vermutlich
kopiert, und die Anmeldung wird abgelehnt. Viele Geräte zählen gar nicht – nur
ein echter Rückschritt gilt als verdächtig.

Passkeys brauchen HTTPS (`localhost` ausgenommen). Auf einer Instanz ohne TLS
blendet das Profil den Knopf nicht ein, statt ein Versprechen zu geben, das der
Browser nicht einlöst.

**Es gibt keine Wiederherstellungscodes.** Ein Passkey lässt sich nicht
abschreiben und in den Safe legen. Deshalb zwei Wege zurück: ein zweites Gerät
hinterlegen – oder ein Administrator setzt die zweite Stufe unter *Nutzer*
zurück. Diese Möglichkeit ist Absicht und zugleich der schwächste Punkt der
Kette; wer sie benutzt, sollte sicher sein, mit wem er spricht.

### Einmalkennwörter aus einer App (TOTP)

QR-Code scannen, sechs Ziffern eintippen, fertig. Acht Wiederherstellungscodes
werden dabei einmal angezeigt; jeder gilt genau einmal, für den Fall, dass das
Telefon weg ist. Funktioniert auf jedem Gerät und in jedem Browser – aber es
lässt sich abtippen, und damit auch auf einer nachgebauten Seite eingeben.

Umgesetzt nach RFC 6238 in reinem PHP – HMAC-SHA1 und base32 bringt die
Sprache mit, den QR-Code erzeugt der eigene Encoder. Geprüft gegen die
Testvektoren des Standards.

Zwei Dinge, die nicht selbstverständlich sind:

- **Der QR-Code wird eingebettet, nicht verlinkt.** Die `otpauth`-Adresse
  enthält das Geheimnis; als URL landete es in Server-Protokollen, im Verlauf
  des Browsers und im Referrer. Das SVG entsteht im selben Aufruf.
- **Ein Kennwort gilt nur einmal.** Der zuletzt benutzte Zähler wird
  festgehalten. Ohne diese Sperre könnte jemand, der einmal über die Schulter
  geschaut hat, sich im selben halben Minutenfenster selbst anmelden.

### Erzwingen

Über `'totp_required'` (`off` | `admins` | `all`, auch unter *Einstellungen*)
lässt sich die zweite Stufe verlangen. **Erfüllt wird die Auflage durch eines
der beiden Verfahren** – der Schlüsselname ist aus der Zeit vor den Passkeys
und bleibt, damit bestehende Konfigurationen weiterlaufen. Wer noch keines
eingerichtet hat, wird nach der Anmeldung ins Profil geführt statt ausgesperrt;
das letzte verbliebene Verfahren lässt sich dann nicht mehr entfernen.

**API-Schlüssel sind davon nicht betroffen** – sie sind ein eigener Nachweis
und tragen kein Passwort, das ein Zweitfaktor absichern könnte. Wer ein Konto
besonders schützen will, prüft daher auch dessen Schlüsselliste.

## Zentrale Anmeldung

Beide Wege sind optional, stehen standardmäßig auf `false` und lassen sich
parallel zu lokalen Konten betreiben. Hier steht das Prinzip – die
Schritt-für-Schritt-Einrichtung samt Apache-Konfiguration, SP-Metadaten und
Attributfreigabe steht in der [Deployment-Anleitung](../DEPLOYMENT.md#8-shibboleth-saml-und-openid-connect).

### Über den Webserver (Shibboleth, SAML, OpenID Connect)

Der empfohlene Weg für einen Shibboleth-IdP. Die eigentliche Anmeldung erledigt
ein Servermodul – `mod_shib`, `mod_auth_mellon` oder `mod_auth_openidc` –, das
den Admin-Bereich schützt. flatlink liest nur, wen der Server bereits
authentifiziert hat. Für Apache:

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

Hier fragt flatlink selbst beim Verzeichnis nach; Kennung und Passwort werden
im gewohnten Login-Formular eingegeben. Braucht die PHP-Erweiterung `ldap`.

Geprüft wird per Bind als der gefundene Nutzer – das Passwort wird nirgends
gespeichert und nicht mit einem lokalen Hash verglichen. Eingaben werden vor
dem Einsetzen in den Suchfilter escaped, LDAP-Injection ist damit nicht
möglich; leere Passwörter werden abgelehnt, bevor sie als „unauthenticated
bind" fälschlich als Erfolg durchgehen könnten.

Reihenfolge beim Login: erst das lokale Passwort, dann das Verzeichnis. Lokale
Konten funktionieren also weiter – wichtig, damit man sich nicht aussperrt,
wenn der LDAP-Server einmal nicht erreichbar ist.

Bei `ldap://` unbedingt `start_tls` einschalten, sonst geht das Passwort im
Klartext über das Netz. Besser gleich `ldaps://`.

### Gruppen aus dem Verzeichnis

Beide Wege können Gruppenzugehörigkeiten übernehmen: bei SSO aus einem
Attribut wie `isMemberOf` oder `entitlement`, bei LDAP aus `memberOf` oder per
Suche im Gruppenbaum. Die Zuordnungstabelle `group_map` bildet externe Namen
auf lokale Gruppen ab:

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

Die Rolle bleibt beim erneuten Login unangetastet: Wer hier zum Administrator
gemacht wurde, bleibt es. Und ein Konto, das zentral verwaltet wird, kann sich
nicht mehr über das lokale Passwortformular anmelden – sonst wäre die zentrale
Anmeldung über ein altes Passwort umgehbar.

### Was zentral verwaltete Konten im Profil können

Wer sich über LDAP oder den Webserver anmeldet, hat hier keinen Passwort-Hash –
die Anmeldung weist solche Konten lokal ab, und jede Anmeldung über das
Verzeichnis entfernt einen etwaigen Alt-Hash. Das Profil zeigt darum kein
Passwortformular, sondern den Hinweis, wo das Passwort hingehört. Ebenso beim
Anzeigenamen: Liefert das Verzeichnis einen, gewinnt er. Eine E-Mail-Adresse
lässt sich dagegen eintragen – sie wird nur überschrieben, wenn das Verzeichnis
selbst eine mitliefert.

### Auskunft, Mitnahme, Löschung

Im Profil steht beides ohne Umweg über den Betreiber:

**Daten herunterladen** liefert eine JSON-Datei mit allem, was zum Konto
gespeichert ist – Kontodaten, angemeldete Geräte, der Stand der
Zwei-Faktor-Anmeldung samt Passkey-Bezeichnungen, die Zugangsschlüssel der
Schnittstelle, Gruppen, Rechte, Limits und jeder Kurzlink mit Ziel, Daten,
Klickzahlen, den Änderungen am Ziel und – bei Link-in-Bio – der Seite selbst.
Nicht dabei sind Zugangsmittel: Passwort-Hash, das Geheimnis der
Authenticator-App, das Schlüsselmaterial der Passkeys, die Hashes der
Zugangsschlüssel und der Abdruck laufender Sitzungen. Sie sind kein Inhalt,
und eine Datei damit landet danach im Download-Ordner. Die Datei selbst zählt
auf, was sie aus welchem Grund auslässt. Das deckt Art. 15 (Auskunft) und
Art. 20 (Mitnahme).

**Browser-Erweiterung** zeigt, was die Instanz anbietet – das hängt davon ab,
was unter *Einstellungen → Browser-Erweiterung* eingetragen ist.

*Verbindungscode* gibt es immer. Er enthält Adresse und einen frisch
erzeugten Zugangsschlüssel und richtet eine bereits installierte Erweiterung
mit einem Einfügen ein. Der Schlüssel ist ein eigener (Bezeichnung
„Browser-Erweiterung" mit Datum) und lässt sich einzeln zurückziehen, ohne
andere Programme lahmzulegen.

*Knöpfe in die Läden* erscheinen, sobald dort Adressen hinterlegt sind – für
Instanzen, deren Erweiterung im Chrome Web Store, bei den Firefox-Add-ons
oder den Edge-Add-ons steht.

*Archiv zum Selbstladen* baut ein Paket, das schon auf diese Instanz
eingerichtet ist: Adresse, Name und Symbole stehen drin, auf Wunsch auch der
Zugangsschlüssel – dann ist die Erweiterung nach dem Laden sofort benutzbar.
Wer den Haken wegnimmt, bekommt ein Archiv ohne Zugangsmittel; die Erweiterung
fragt dann beim ersten Öffnen danach. Für eine Instanz ohne Store-Eintrag ist
das der einzige Weg – aber es muss von Hand entpackt und im Entwicklermodus
geladen werden und aktualisiert sich nie. Wer im Laden steht, schaltet es in
den Einstellungen ab.

**Konto löschen** entfernt das Konto und alle Links, die nur daran hängen,
samt Klickzählern. Links **mit Gruppenzuordnung bleiben** und verlieren nur
ihren Besitzer – sie gehören der Gruppe, andere arbeiten damit weiter, und
gedruckte QR-Codes darauf sollen nicht ins Leere zeigen, weil eine Person
geht. Vorher wird das Passwort abgefragt (bei zentraler Anmeldung: die eigene
Kennung abgetippt), der letzte Administrator kann sich nicht selbst entfernen.

Auf einer Instanz mit zentral verwalteten Konten ist der Löschknopf
irreführend, weil das Verzeichnis das Konto bei der nächsten Anmeldung neu
anlegt. Dort `'self_delete' => false` setzen – der Export bleibt davon
unberührt.

### Zwei Hinweise zum Datenschutz

**Google Safe Browsing** ist standardmäßig **aus**. Wer es aktiviert, schickt
beim Anlegen eines Links dessen Ziel-URL an Google. Für eine öffentliche
Instanz ist das ein wirksamer Schutz gegen Phishing-Missbrauch, für eine interne
meist überflüssig. Wer es einschaltet, sollte es in seiner
Datenschutzerklärung angeben.

**Der Webserver protokolliert weiter.** flatlink speichert keine IP-Adressen,
die Zugriffs-Logs von Apache oder nginx tun es in aller Regel schon. Wer den
Anspruch ernst nimmt, kürzt oder deaktiviert sie dort.

