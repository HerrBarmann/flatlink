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

    // Max. Links pro IP und Stunde über das öffentliche Formular
    'public_rate_limit' => 15,

    // Codes, die nie als Kurzlink vergeben werden dürfen.
    // Hier ergänzen, was an eigenen Seiten dazukommt.
    'reserved' => ['admin', 'assets', 'data', 'inc', 'qr', 'go', 'index',
        'login', 'logout', 'api', 'register', 'reset', 'report'],

    // Max. Länge der Ziel-URL
    'max_url_length' => 2048,

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
    // Verfügbar: 'custom_code' (Wunsch-Namen), 'csv_import', 'logo_upload'.
    // Leer lassen = alles nur über Gruppen. Administratoren dürfen immer alles.
    'default_perms' => ['logo_upload'],

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
    // Ausführlich beschrieben in CUSTOMIZATION.md.
    //
    // Farben, Schriften und Abstände ändert man nicht hier, sondern in
    // assets/custom.css: Diese Datei wird nach dem Standard-Stylesheet geladen,
    // überschreibt es damit und ist vom Update ausgenommen. Vorlage zum
    // Kopieren: assets/custom.example.css

    // Wohin der Hinweis im Profil zur Anleitung der Schnittstelle zeigt.
    // Vorgabe ist die mitgelieferte API.md im Webverzeichnis; wer eine eigene
    // Seite dafür hat, trägt sie hier ein.
    'api_doc_url' => 'API.md',

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
    // die Verwaltung bleibt auf der Adresse aus 'base_url'. Ein Code gehört
    // der Instanz, nicht der Domain – er löst unter jeder von ihnen auf.
    // Je Eintrag entweder nur der Host oder zusätzlich eine Gruppe, der die
    // Domain vorbehalten sein soll:
    //   ['host' => 'kunde.link', 'group' => 'kunde'],
    // Auch zur Laufzeit unter Einstellungen pflegbar.
    'domains' => [],

    // Zwei-Faktor-Anmeldung verlangen: 'off' | 'admins' | 'all'.
    // Erfüllt wird die Auflage durch einen Passkey ODER ein Einmalkennwort aus
    // einer App. Auch zur Laufzeit unter Einstellungen änderbar.
    'totp_required' => 'off',

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

    // ---- Automatisches Aufräumen ungenutzter Links ----
    // Löscht Links, die über den gesamten Zeitraum kein einziges Mal aufgerufen
    // wurden. Jeder Aufruf setzt die Frist zurück. Die kurze Frist gilt für
    // Links, deren Besitzer per E-Mail vorgewarnt werden kann (Warnung einen
    // Monat vorher, Löschung frühestens 30 Tage danach); die lange Frist gilt
    // für anonyme Links und Konten ohne E-Mail-Adresse.
    // Beide auf 0 setzen = Aufräumen komplett deaktiviert (Standard).
    'link_gc_years' => 0,
    'link_gc_years_unreachable' => 0,

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
];
