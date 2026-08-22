<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Konfiguration.
 *
 * Diese Datei nach inc/config.php kopieren und anpassen:
 *     cp inc/config.example.php inc/config.php
 *
 * inc/config.php enthält Zugangsdaten und ist per .gitignore ausgeschlossen.
 */

return [
    // Anzeigename der Instanz (Titel, Kopfzeile, Absender)
    'site_name' => 'flatlink',

    // Basis-URL inkl. Schema, OHNE Slash am Ende, z. B. 'https://example.org'
    //
    // BITTE SETZEN. Bleibt der Wert leer, wird die Adresse aus dem
    // Host-Header des Requests erraten – und der ist eine Nutzereingabe.
    // Links in verschickten Mails (Passwort-Reset, Registrierung) würden
    // sich damit auf eine fremde Domain umbiegen lassen; flatlink verschickt
    // solche Mails deshalb gar nicht erst, solange hier nichts steht.
    // Auch das secure-Flag des Sitzungs-Cookies hängt an diesem Wert.
    'base_url' => '',

    // ---- Reverse Proxy ----
    // Adressen der Proxys, die vor dieser Instanz stehen. Nur wenn eine
    // Anfrage von einer davon kommt, wird X-Forwarded-For ausgewertet.
    //
    // Ohne diesen Eintrag sieht flatlink hinter einem Proxy für ALLE Besucher
    // dieselbe Adresse – Rate-Limit und Login-Sperre gelten dann versehentlich
    // für alle gemeinsam, und ein einzelner Nutzer kann den Dienst für die
    // anderen blockieren. Bei lokalem Proxy beide Schreibweisen eintragen.
    'trusted_proxies' => [],     // z. B. ['127.0.0.1', '::1']

    // ---- Ablageort der Laufzeitdaten ----
    // Leer = das Verzeichnis data/ neben der Anwendung, also im Webroot.
    // Dort schützt es nur eine .htaccess – die nginx, Caddy und LiteSpeed
    // ignorieren. Darin liegen Passwort-Hashes, gültige Reset-Token und im
    // Mail-Modus 'log' sämtliche Mails im Klartext.
    //
    // Wenn dein Hosting es zulässt: absoluten Pfad AUSSERHALB des Webroots
    // eintragen, z. B. '/var/lib/flatlink'. Das Verzeichnis muss dem
    // Webserver-Benutzer gehören.
    'data_dir' => '',

    // Länge zufällig erzeugter Codes
    'code_length' => 6,

    // Alphabet für zufällige Codes (ohne 0/O und 1/l/I – schwer zu unterscheiden)
    'alphabet' => '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ',

    // ---- Wer darf was, bevor jemand angemeldet ist ----
    // Vorgaben für eine frische Installation; ändern lassen sie sich später
    // in der Verwaltung unter „Einstellungen", ohne hier anzufassen. Sie
    // stehen hier, damit sich eine Instanz vollständig aus dieser Datei
    // ausliefern lässt – fertig eingerichtet ab dem ersten Aufruf.
    //
    // 'on'     – jeder darf kürzen, auch ohne Konto
    // 'prefix' – auch ohne Konto, aber die Codes landen unter 'public_prefix'
    // 'off'    – nur angemeldete Konten. Das Richtige für die Instanz einer
    //            Einrichtung, die keine fremden Links weiterleiten will.
    'public_mode' => 'on',
    'public_prefix' => 'p',

    // Dürfen sich Konten selbst anlegen (mit Bestätigung per E-Mail)?
    // 'off' heißt: Konten legt die Verwaltung an.
    'registration' => 'on',

    // Max. Links pro IP und Stunde über das öffentliche Formular
    'public_rate_limit' => 15,

    // Die statischen QR-Werkzeuge (WLAN, Kontakt, Termin, GS1 und der
    // Designer ohne Kurzlink). Sie speichern nichts – ihr Ergebnis ist ein
    // Bild, das die Daten selbst trägt.
    //   'auto' – öffentlich, solange auch das Kürzen öffentlich ist (Vorgabe)
    //   'on'   – öffentlich auch bei public_mode=off; die Startseite
    //            verweist dann darauf
    //   'off'  – nur für Angemeldete
    // Auch zur Laufzeit unter Grundregeln umstellbar.
    'qr_public' => 'auto',

    // Codes, die nie als Kurzlink vergeben werden dürfen.
    // Hier ergänzen, was an eigenen Seiten dazukommt.
    'reserved' => ['admin', 'assets', 'data', 'inc', 'qr', 'go', 'index',
        'login', 'logout', 'api', 'register', 'reset', 'report',
        'qr-designer', 'wlan-qr', 'vcard-qr', 'termin-qr', 'gs1-qr'],

    // Max. QR-Codes pro Adresse und Stunde ohne Anmeldung. Großzügig, weil der
    // Designer bei jedem Regler-Zug eine neue Vorschau zieht – wer gestaltet,
    // kommt leicht auf einige hundert Anfragen, ohne irgendetwas Böses zu tun.
    'qr_rate_limit' => 600,

    // Dasselbe für die schweren Formate (PDF, EPS, PNG über 2048 px): Sie
    // kosten ein Vielfaches und werden zum Herunterladen erzeugt, nicht zum
    // Ausprobieren. Angemeldete Konten sind von beidem ausgenommen.
    'qr_rate_limit_print' => 60,

    // Zählt je Kurzlink zusätzlich, WOHER die Aufrufe kamen: Hostname der
    // verweisenden Seite, Gerätegattung (Handy/Tablet/Rechner) und Sprache –
    // ausschließlich als Summen, ohne Datensatz je Aufruf. Aus: Es bleibt bei
    // den reinen Zählern, und der Beleg in der Statistik ist so knapp wie
    // möglich. Siehe docs/SECURITY.md.
    'click_dims' => true,

    // ---- Webhooks ----
    // Adressen, die bei Verwaltungsereignissen ein POST mit JSON bekommen:
    // Link angelegt/geändert/gelöscht/gesperrt, Meldung eingegangen, Konto
    // wartet auf Freischaltung. Es gibt bewusst KEIN Ereignis für Klicks –
    // der Weiterleitungspfad ist der eine Ort, an dem über Besucher nichts
    // passiert, und ein Webhook dort wäre Besucherverfolgung durch die
    // Hintertür (dazu würde er jede Weiterleitung verlangsamen).
    // Ein Versuch je Ereignis, 3 s Zeitlimit, keine Wiederholung.
    'webhooks' => [],

    // Wird es gesetzt, trägt jede Meldung eine Signatur
    // (X-Flatlink-Signature: sha256=…) über dem Rumpf – der Empfänger kann
    // damit prüfen, dass sie wirklich von dieser Instanz kommt.
    'webhook_secret' => '',

    // Max. Länge der Ziel-URL
    'max_url_length' => 2048,

    // Dürfen Kurzlinks auf private Adressbereiche zeigen (10.x, 192.168.x,
    // localhost, fc00::/7)? Vorgabe nein: Auf einer erreichbaren Instanz
    // wäre ein Kurzlink sonst eine hübsche Verpackung für interne Ziele.
    // Eine rein interne Instanz schaltet das ein.
    'allow_private_targets' => false,

    // ---- Wunsch-Codes ----
    // Mindestlänge selbst gewählter Codes; kürzere bleiben Admins vorbehalten.
    // Kontingent aktiver Wunsch-Codes pro Konto (0 = unbegrenzt).
    // Auf einer öffentlichen Instanz bremst das Namensraum-Squatting;
    // im internen Einsatz kann beides großzügig gesetzt werden.
    'custom_code_min_len' => 3,
    'custom_code_quota' => 0,

    // ---- Rechte ----
    // Rechte werden über Gruppen vergeben (Admin-Bereich → Gruppen). Was hier
    // steht, gilt zusätzlich für JEDES angemeldete Konto, auch ohne Gruppe.
    // Leer lassen = alles nur über Gruppen. Administratoren dürfen immer alles.
    //
    // Die vollständige Liste steht in perms_sections() (inc/groups.php) und
    // zerfällt in zwei Sorten: was ein Konto mit den EIGENEN Links darf
    // ('custom_code', 'csv_import', 'logo_upload', 'qr_unbranded',
    // 'api_access', 'bio_page', 'bio_style', 'link_rules') und was jemand FÜR
    // ANDERE darf ('links_all', 'reports_manage'). Die zweite Sorte gehört
    // nie hierher: Sie beschreibt eine Rolle, keine Grundausstattung.
    //
    // 'api_access' ist bewusst dabei: Ohne die Schnittstelle gibt es keinen
    // Verbindungscode, und ohne den ist die Browser-Erweiterung unbenutzbar.
    // Wer sie einschränken will, nimmt sie hier heraus und hängt sie an eine
    // Gruppe.
    'default_perms' => ['logo_upload', 'api_access'],

    // ---- Nutzungs-Limits (gelten je Konto; Admins haben keine Limits) ----
    // links: gleichzeitig aktive Links · stats_days: Tiefe der Klick-Statistik
    // logos: Bilder in der Logo-Bibliothek für QR-Codes · 0 = unbegrenzt
    'limits' => ['links' => 0, 'stats_days' => 365, 'logos' => 10, 'bio' => 0],

    // Optionale Absenderzeile unter erzeugten QR-Codes (leer = keine).
    // Nützlich, wenn Codes gedruckt werden und erkennbar sein soll, wer sie ausgibt.
    // Gemeint ist dein eigener Name – hier steht bewusst nichts vorbelegt.
    // Konten mit dem Recht 'qr_unbranded' bekommen die Zeile nicht.
    'qr_brand_text' => '',

    // Optionales Symbol links neben der Absenderzeile. Zwei Dateien,
    // weil Vektor- und Rasterausgabe verschiedene Quellen brauchen: eine
    // eigenständige SVG-Datei mit eigener viewBox fürs SVG, und eine
    // einfarbige PNG-Maske mit Alphakanal fürs Raster (PNG/PDF), die auf die
    // Textfarbe eingefärbt wird. Beide Dateinamen innerhalb von assets/.
    'qr_brand_glyph_svg' => '',
    'qr_brand_glyph_png' => '',

    // Darf ein Konto sich selbst löschen? Die DSGVO verlangt die Löschung auf
    // Verlangen, nicht den Knopf dafür – auf einer Instanz mit zentral
    // verwalteten Konten (LDAP, Shibboleth) ist er sogar irreführend, weil das
    // Verzeichnis das Konto bei der nächsten Anmeldung neu anlegt. Dort auf
    // false setzen und im Impressum den Weg über die Verwaltung nennen.
    // Die Auskunft (Datenexport im Profil) bleibt davon unberührt.
    'self_delete' => true,

    // Herkunftszeile im Seitenfuß: kleines Kiwi-Zeichen und der Hinweis auf
    // flatlink. Sie ist KEINE Höflichkeit, sondern Bedingung der Lizenz
    // (Zusatzbedingung nach §7(b) AGPL, siehe LICENSE). Übersetzen,
    // umformulieren und dezent gestalten ist erlaubt – weglassen nicht.
    // Dieser Schalter existiert für Tests und für den Fall einer schriftlichen
    // Freistellung durch den Rechteinhaber; er allein erlaubt den Betrieb
    // ohne die Zeile nicht. Anfragen: dennis@1337.hamburg
    'show_origin' => true,

    // ---- Aussehen ----
    // Ausführlich beschrieben in docs/CUSTOMIZATION.md.
    //
    // Farben, Schriften und Abstände ändert man nicht hier, sondern in
    // assets/custom.css: Diese Datei wird nach dem Standard-Stylesheet geladen,
    // überschreibt es damit und ist vom Update ausgenommen. Vorlage zum
    // Kopieren: assets/custom.example.css

    // Wohin der Hinweis im Profil zur Anleitung der Schnittstelle zeigt.
    // Vorgabe ist die mitgelieferte docs/API.md im Webverzeichnis; wer eine
    // eigene Seite dafür hat, trägt sie hier ein.
    'api_doc_url' => 'docs/API.md',

    // Sprache der Oberfläche für die ganze Instanz: 'de' oder 'en'.
    // Deutsch ist die Quellsprache; Englisch kommt aus inc/lang/en.php, und was
    // dort (noch) fehlt, bleibt sichtbar deutsch statt leer. Auch zur Laufzeit
    // unter Einstellungen änderbar.
    'language' => 'de',

    // Links und Konten liegen in einer SQLite-Datei – kein Server, nichts
    // zu warten, das Backup bleibt das Kopieren des data/-Ordners.
    // Leer = data/flatlink.sqlite; ein eigener Pfad gehört wie data_dir am
    // besten außerhalb des Webroots.
    'sqlite_file' => '',

    // Weitere Domains für Kurzlinks. Alle zeigen auf dieselbe Installation;
    // die Verwaltung bleibt auf der Adresse aus 'base_url'. Jede Domain hat
    // ihren EIGENEN Namensraum: kunde-a.link/shop und kunde-b.link/shop sind
    // zwei verschiedene Links. Fällt eine Domain weg, lösen ihre Links nicht
    // mehr auf – gelöscht wird dabei keiner.
    // Je Eintrag entweder nur der Host oder zusätzlich eine Gruppe, der die
    // Domain vorbehalten sein soll:
    //   ['host' => 'kunde.link', 'group' => 'kunde'],
    // Auch zur Laufzeit unter Einstellungen pflegbar.
    // ---- Browser-Erweiterung ------------------------------------------
    // Zwei Wege, wie Nutzende zu ihr kommen. Beide lassen sich in der
    // Verwaltung unter „Einstellungen" ändern, ohne hier anzufassen.
    //
    // Adressen in den Läden. Stehen sie hier, zeigt das Profil einen Knopf
    // dorthin. Nur https und nur die Läden selbst werden angenommen.
    'ext_stores' => [
        'chrome' => '',   // https://chromewebstore.google.com/detail/...
        'firefox' => '',  // https://addons.mozilla.org/firefox/addon/...
        'edge' => '',     // https://microsoftedge.microsoft.com/addons/detail/...
    ],
    'domains' => [],

    // Zwei-Faktor-Anmeldung verlangen: 'off' | 'admins' | 'all'.
    // Erfüllt wird die Auflage durch einen Passkey ODER ein Einmalkennwort aus
    // einer App. Auch zur Laufzeit unter Einstellungen änderbar.
    'totp_required' => 'off',

    // Konten ohne Passkey nach der Anmeldung einmal im Monat darauf
    // hinweisen: 'on' (alle), 'local' (nur lokale Konten – zentral
    // verwaltete bleiben außen vor) oder 'off'.
    //
    // 'local' ist für Häuser gedacht, in denen die Anmeldung am Verzeichnis
    // hängen soll: Ein Passkey käme auch dann noch durch, wenn dort das
    // Passwort gewechselt wurde. (Gesperrte Konten weist er weiterhin ab.)
    'passkey_hint' => 'on',

    // Das Protokoll der Verwaltungshandlungen auf die jüngsten N Einträge
    // begrenzen (0 = unbegrenzt, Vorgabe). Für Institutionen ist die
    // Nachvollziehbarkeit der Zweck des Protokolls – wer dagegen in seiner
    // Datenschutzerklärung eine Begrenzung zusagt, trägt die Zahl hier ein.
    'audit_keep' => 0,

    // Anfragen je Stunde und Zugangsschlüssel für die Programmierschnittstelle.
    // Gezählt wird nach Schlüssel, nicht nach IP – ein Server, der die
    // Schnittstelle bedient, kommt immer von derselben Adresse.
    'api_rate_limit' => 300,

    // Wie viele Zeilen der CSV-Import auf einmal annimmt. Die Grenze schützt vor
    // sehr langen Laufzeiten auf schwachen Servern; wer aus Bitly oder YOURLS
    // umzieht, darf sie beherzt erhöhen und den Import in Ruhe laufen lassen.
    'import_max_rows' => 100,

    // Aussehen der Link-in-Bio-Seiten, solange der Besitzer nichts eigenes
    // wählt. Sinnvoll sind die Farben der Instanz – sonst bekommt jemand ohne
    // eigene Gestaltung eine Seite, die nach nichts aussieht.
    'bio_default_colors' => [],   // z. B. ['bg' => '#ffffff', 'ink' => '#111111', …]

    // Fuß der Link-in-Bio-Seiten. Ab Werk aus: Eine Bio-Seite gehört dem, der
    // sie eingerichtet hat – die Kundin einer Bibliothek oder eines Cafés soll
    // dort den Namen dieser Einrichtung sehen und nicht den der Software.
    // Wer eine öffentliche Instanz mit kostenlosen Konten betreibt, hat gute
    // Gründe für eine Absenderzeile und setzt hier einen Vorspann; '' lässt
    // nur die Wortmarke aus 'site_name' stehen, null die ganze Zeile weg.
    // Das Zeichen ist eine Bilddatei in assets/ und ebenfalls freiwillig.
    'bio_footer_text' => null,
    'bio_footer_accent' => '',   // Farbe der Endung und des Cursors, leer = wie die Zeile
    'bio_footer_glyph' => '',

    // Klasse am <body>-Element. Daran lassen sich in assets/custom.css ganze
    // Gestaltungsvarianten aufhängen (alles unter `body.variante { … }`), die
    // sich hier mit einem leeren Wert wieder abschalten lassen – ohne eine
    // Zeile CSS zu löschen. Mehrere Klassen durch Leerzeichen getrennt.
    'body_class' => '',

    // Eigenes Logo in der Kopfzeile. Dateiname innerhalb von assets/,
    // leer = nur der Name der Instanz. SVG oder PNG, etwa 'logo.svg'.
    'logo' => '',

    // Eigenes Favicon, ebenfalls ein Dateiname innerhalb von assets/.
    'favicon' => '',

    // Weitere Symbole, rel => Dateiname in assets/. Zum Beispiel:
    // ['apple-touch-icon' => 'apple-touch-icon.png', 'manifest' => 'site.webmanifest']
    'icons' => [],

    // Farbe der Browserleiste je Erscheinungsbild, z. B.
    // ['light' => '#FAFCF6', 'dark' => '#0D110A']
    'theme_color' => [],

    // Vorschaubild beim Teilen in Messengern und sozialen Netzen
    // (Dateiname in assets/, quadratisch oder 1200×630). Erscheint nur auf
    // Seiten, die eine Beschreibung setzen.
    'og_image' => '',

    // Zusätzliche Einträge in der Kopf-Navigation, Beschriftung => Ziel.
    // Nützlich für eigene Zusatzseiten. Relative Ziele beziehen sich auf den
    // Webroot.
    'nav_links' => [
        // 'Hilfe' => 'hilfe.html',
    ],

    // Wie oben, aber nur für Nichtangemeldete sichtbar.
    'nav_links_guest' => [],

    // Zusätzliche Links im Seitenfuß, Beschriftung => Ziel. Hier gehören
    // Impressum und Datenschutzerklärung hin, zu denen öffentliche Instanzen
    // je nach Land verpflichtet sind. Relative Ziele beziehen sich auf den
    // Webroot, absolute (https://…) führen nach außen.
    'footer_links' => [
        // 'Impressum'   => 'impressum.html',
        // 'Datenschutz' => 'https://example.org/datenschutz',
    ],

    // Vorgabe für die Fußzeile von Link-in-Bio-Seiten: Impressum und
    // Datenschutzerklärung der Instanz. Jede Seite kann sie durch eigene
    // Adressen ersetzen – ein Kunde, der seine Seite geschäftlich betreibt,
    // verlinkt SEIN Impressum, nicht das des Dienstes. Relative Ziele meinen
    // Seiten dieser Instanz, absolute (https://…) führen nach außen.
    // Leer = keine Fußzeile, solange die Seite nichts Eigenes setzt.
    // ---- Demo-Modus ----
    // Eine öffentliche Spielwiese: Hinweisband über jeder Seite, und der ganze
    // Bestand wird träge beim Seitenaufbau zurückgesetzt (kein Cron nötig,
    // läuft auch auf Shared Hosting). Konto der Demo: demo / demo-1234.
    // Für eine Demo-Instanz gehören außerdem in DIESE Datei: mail 'mode'=>'log',
    // 'registration' aus, keine Webhooks – der Modus erzwingt das nicht.
    'demo_mode' => false,
    'demo_reset_minutes' => 60,

    'bio_legal_defaults' => [
        // 'imprint' => 'impressum.html',
        // 'privacy' => 'datenschutz.html',
    ],

    // ---- Automatisches Aufräumen ungenutzter Links ----
    // Löscht Links, die über den gesamten Zeitraum kein einziges Mal aufgerufen
    // wurden. Jeder Aufruf setzt die Frist zurück. Die kurze Frist gilt für
    // Links, deren Besitzer per E-Mail vorgewarnt werden kann (Warnung einen
    // Monat vorher, Löschung frühestens 30 Tage danach); die lange Frist gilt
    // für anonyme Links und Konten ohne E-Mail-Adresse.
    // Beide auf 0 setzen = Aufräumen komplett deaktiviert (Standard).
    //
    // Diese drei Werte stehen auch unter Einstellungen → Grundregeln. Was dort
    // gespeichert wird, gewinnt; diese Datei bleibt die Vorgabe für eine
    // frische Installation.
    'link_gc_years' => 0,
    'link_gc_years_unreachable' => 0,

    // Worauf sich die Löschung beruft – erscheint in der Warnmail in Klammern,
    // etwa 'AGB § 2' oder 'siehe Nutzungsbedingungen'. Leer lassen, wenn es
    // nichts zu zitieren gibt; dann endet der Satz einfach.
    'link_gc_note' => '',

    // Kontaktadresse für Rückfragen (erscheint in Systemmails)
    'contact_email' => '',

    // ---- E-Mail-Versand (Registrierung, Passwort-Reset) ----
    // mode 'log':  Mails landen in data/mail.log – kein Versand, ideal zum Testen
    // mode 'smtp': echter Versand über SMTP mit STARTTLS
    'mail' => [
        'mode' => 'log',
        'host' => 'smtp.example.org',
        'port' => 587,
        'user' => '',
        'pass' => '',
        'from' => 'noreply@example.org',
        'from_name' => 'flatlink',
    ],

    // ================================================================
    //  Zentrale Anmeldung – beide Wege sind optional und stehen auf AUS
    // ================================================================

    // ---- Weg 1: SSO über den Webserver (Shibboleth, SAML, OpenID Connect) ----
    //
    // Die Anmeldung erledigt ein Servermodul (mod_shib, mod_auth_mellon,
    // mod_auth_openidc). flatlink liest nur, wen der Server bereits
    // authentifiziert hat. Der Webserver muss den Pfad /admin/ dafür schützen,
    // z. B. in der Apache-Konfiguration:
    //
    //     <Location /admin>
    //         AuthType shibboleth
    //         ShibRequestSetting requireSession 1
    //         Require valid-user
    //     </Location>
    //
    // !! SICHERHEIT !!
    // 'user_var' benennt die Servervariable mit der Kennung. Variablen, die
    // der Webserver selbst setzt (REMOTE_USER und die Attribute von mod_shib),
    // sind vertrauenswürdig. Ein Wert, der als HTTP-Header ankommt (Name
    // beginnt dann mit HTTP_), ist es NICHT – den kann jeder Client frei
    // erfinden und sich damit als beliebiger Nutzer ausgeben. flatlink
    // akzeptiert HTTP_-Variablen deshalb nur, wenn unter 'trusted_proxies'
    // die IP des Reverse Proxy steht, der diese Header nachweislich
    // überschreibt. Ohne diesen Eintrag werden sie verworfen.
    'sso' => [
        'enabled' => false,

        // Servervariable mit der Kennung des angemeldeten Nutzers
        'user_var' => 'REMOTE_USER',
        // Optional: Variablen für E-Mail-Adresse und Gruppen
        'mail_var' => '',            // z. B. 'mail' (mod_shib) oder 'HTTP_MAIL'
        // Klarname für die Anzeige. Dringend empfohlen, wenn die Föderation
        // undurchsichtige Kennungen liefert (persistent-id, pairwise-id) –
        // ohne ihn steht in der Nutzerverwaltung nur Buchstabensalat.
        'name_var' => '',            // z. B. 'displayName' oder 'cn'
        'group_var' => '',           // z. B. 'isMemberOf' oder 'entitlement'
        'group_separator' => ';',

        // Externe Gruppennamen auf lokale Gruppen-Kennungen abbilden.
        // Ist die Tabelle leer, wird ein externer Name nur übernommen, wenn es
        // hier eine gleichnamige Gruppe gibt. Aus dem IdP kommende Namen
        // können nie neue Gruppen anlegen.
        'group_map' => [
            // 'urn:mace:example.org:group:marketing' => 'marketing',
            // 'cn=it,ou=groups,dc=example,dc=org'    => ['it', 'technik'],
        ],
        // Wie sich Gruppen aus dem Verzeichnis zu den hier vergebenen
        // verhalten:
        //   'merge'   – Verzeichnisgruppen kommen hinzu, hier von Hand
        //               vergebene bleiben erhalten. Voreinstellung, weil dabei
        //               nichts verlorengehen kann.
        //   'replace' – das Verzeichnis bestimmt allein. Nur richtig, wenn dort
        //               wirklich alle Zuordnungen gepflegt werden: Was hier von
        //               Hand zugewiesen wird, ist beim nächsten Login weg.
        //   'off'     – Gruppen kommen nie von außen; sie werden nur hier
        //               vergeben, egal was das Verzeichnis liefert.
        'group_sync' => 'merge',
        // Gruppen, die jedes über SSO angemeldete Konto zusätzlich bekommt
        'default_groups' => [],

        // ---- Wer darf überhaupt herein? ----
        //
        // WICHTIG bei Föderationen: Ein IdP-Verbund wie die DFN-AAI
        // authentifiziert Angehörige aller beteiligten Einrichtungen. Ohne
        // Einschränkung bekäme also jedes Mitglied jeder Hochschule hier ein
        // Konto. Drei Bremsen, beliebig kombinierbar:

        // 1. Nur bestimmte Einrichtungen. Greift bei Kennungen der Form
        //    name@einrichtung.de (eppn). Undurchsichtige Kennungen
        //    (persistent-id) tragen keine Einrichtung – dort helfen 2. und 3.
        'allowed_scopes' => [],      // z. B. ['hfmt-hamburg.de']

        // 2. Nur wer über 'group_map' in einer lokalen Gruppe landet.
        //    true = irgendeine Gruppe genügt, Liste = eine der genannten.
        'require_group' => false,    // z. B. true oder ['mitarbeitende']

        // 3. Nur Konten, die hier schon existieren. Die strengste Stufe.
        'auto_create' => true,

        // Zu 3.: Bei undurchsichtigen Kennungen kann niemand ein Konto vorab
        // anlegen – man kennt die Kennung ja nicht. Steht dies auf true,
        // landen abgewiesene Anmeldungen stattdessen in einer Warteschlange
        // in der Nutzerverwaltung, mit Klarname und E-Mail, und lassen sich
        // dort mit einem Klick freischalten.
        'approval_queue' => true,

        // Nur nötig, wenn die Kennung als HTTP-Header ankommt (siehe Warnung).
        // Leer lassen genügt, wenn oben schon 'trusted_proxies' gesetzt ist –
        // dann gilt diese Liste auch hier.
        'trusted_proxies' => [],     // z. B. ['127.0.0.1', '::1', '10.0.0.5']

        // Ziel des Anmelde-Knopfes und des Abmeldens (Single Logout)
        'login_url' => '',           // z. B. '/Shibboleth.sso/Login?target=/admin/'
        'logout_url' => '',          // z. B. '/Shibboleth.sso/Logout'
        'button_label' => 'Mit institutionellem Konto anmelden',
    ],

    // ---- Weg 2: LDAP / Active Directory ----
    //
    // flatlink fragt selbst beim Verzeichnis nach; Kennung und Passwort werden
    // im eigenen Login-Formular eingegeben. Braucht die PHP-Erweiterung ldap.
    // Geprüft wird per Bind als der gefundene Nutzer – das Passwort wird
    // nirgends gespeichert. Lokale Konten bleiben parallel nutzbar: Erst wird
    // das lokale Passwort geprüft, dann das Verzeichnis.
    'ldap' => [
        'enabled' => false,

        // ldaps:// bevorzugen; bei ldap:// unbedingt 'start_tls' setzen,
        // sonst geht das Passwort im Klartext über das Netz
        'uri' => 'ldaps://ldap.example.org:636',
        'start_tls' => false,
        'timeout' => 5,

        // Dienstkonto für die Suche (leer = anonyme Suche)
        'bind_dn' => '',
        'bind_pass' => '',

        // Wo und wonach gesucht wird; %s wird durch die Eingabe ersetzt
        // (sie wird vorher escaped – LDAP-Injection ist nicht möglich).
        // Active Directory: '(sAMAccountName=%s)'
        'base_dn' => 'ou=people,dc=example,dc=org',
        'user_filter' => '(uid=%s)',
        // Personensuche in der Nutzerverwaltung: Damit lassen sich Konten
        // anlegen, bevor sich jemand zum ersten Mal anmeldet.
        //
        // LEER LASSEN ist der Normalfall. Der Filter entsteht dann aus den
        // Attributen, die hier ohnehin stehen (uid_attr, name_attr, mail_attr)
        // plus cn, sn, givenName und mail – und mehrere Wörter werden
        // UND-verknüpft, sodass „Vorname Nachname" in beiden Reihenfolgen
        // trifft. Ein fester Filter kennt dagegen nur die Attribute, die
        // jemand hineingeschrieben hat.
        //
        // Nur bei einer besonderen Anforderung eintragen, etwa um auf eine
        // Abteilung einzugrenzen; %s ist die Eingabe (escaped), Wörter werden
        // dann nicht mehr getrennt:
        // '(&(ou=Bibliothek)(|(uid=*%s*)(cn=*%s*)))'
        'search_filter' => '',
        // Attribut mit der Kennung. Leer = aus dem user_filter ablesen.
        'uid_attr' => '',
        'mail_attr' => 'mail',
        // Attribut mit dem Klarnamen für die Anzeige; leer = keiner.
        // Ohne ihn steht in der Nutzerverwaltung nur die technische Kennung.
        // Active Directory: 'displayName' oder 'cn'
        'name_attr' => 'displayName',

        // Gruppen: 'memberof' liest das Attribut am Nutzereintrag,
        // 'search' sucht stattdessen im Gruppenbaum nach Einträgen mit dem
        // Nutzer als Mitglied (nötig bei groupOfNames ohne memberOf-Overlay).
        'group_mode' => 'memberof',
        'group_base_dn' => 'ou=groups,dc=example,dc=org',
        'group_filter' => '(&(objectClass=groupOfNames)(member=%s))',
        'group_attr' => 'cn',

        // Wie bei SSO: leer = nur namensgleiche lokale Gruppen zählen
        'group_map' => [],
        'default_groups' => [],

        // Wie sich Gruppen aus dem Verzeichnis zu den hier vergebenen
        // verhalten – siehe die ausführliche Erklärung im SSO-Block oben.
        // 'merge' (Vorgabe) lässt von Hand vergebene Gruppen bestehen,
        // 'replace' überschreibt sie bei jeder Anmeldung, 'off' nimmt gar
        // keine Gruppen aus dem Verzeichnis.
        'group_sync' => 'merge',

        // Zugangskontrolle wie beim SSO-Weg (siehe dort). Bei LDAP begrenzt
        // schon 'base_dn' den Kreis – 'require_group' verengt ihn weiter auf
        // Mitglieder bestimmter Verzeichnis-Gruppen.
        'require_group' => false,
        'auto_create' => true,
        'approval_queue' => true,
    ],

    // ---- Google Safe Browsing (optional, standardmäßig AUS) ----
    // Prüft beim Anlegen eines Links dessen Ziel-URL gegen Googles Phishing-Liste.
    // Achtung: Dabei wird die Ziel-URL an Google übertragen. Für öffentliche
    // Instanzen ein sinnvoller Missbrauchsschutz, für interne meist unnötig.
    // Wer es aktiviert, sollte es in seiner Datenschutzerklärung angeben.
    // Leer lassen = deaktiviert.
    // https://developers.google.com/safe-browsing/v4/get-started
    'safe_browsing_key' => '',

    // Wie oft der Bestand erneut gegen Safe Browsing geprüft wird (Tage).
    // Die Prüfung beim Anlegen fängt nur, was schon bösartig ist – Ziele, die
    // erst später übernommen werden, findet allein ein Wiederholungslauf.
    // Er läuft nebenbei, ausgelöst von einem beliebigen Besucher, und sperrt
    // Treffer (410) statt sie zu löschen. 0 = aus. Braucht einen Schlüssel.
    'safety_recheck_days' => 7,
];
