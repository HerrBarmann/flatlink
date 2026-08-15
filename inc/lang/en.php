<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Englisches Wörterbuch. Schlüssel ist der deutsche Text, wie er im Code
 * steht – siehe inc/lang.php für das Warum. Einträge mit %s/%d sind
 * sprintf-Platzhalter; ihre Reihenfolge darf sich je Sprache ändern
 * (Positionsangaben wie %1$s sind erlaubt).
 *
 * '__name__' ist der Eigenname der Sprache für die Auswahl in den
 * Einstellungen.
 */
return [
    '__name__' => 'English',

    // ---- Zahlenformat (number_format-Trennzeichen) ----
    ',' => '.',
    '.' => ',',

    // ---- Seitengerüst ----
    'Links' => 'Links',
    'QR-Designer' => 'QR designer',
    'QR-Serie' => 'QR batch',
    'Link-in-Bio' => 'Link in bio',
    'Nutzer' => 'Users',
    'Gruppen' => 'Groups',
    'Meldungen' => 'Reports',
    'Einstellungen' => 'Settings',
    'Verwaltung' => 'Administration',
    'Abmelden' => 'Sign out',
    'Registrieren' => 'Sign up',
    'Login' => 'Sign in',
    'Missbrauch melden' => 'Report abuse',
    'Profil / Passwort ändern' => 'Profile / change password',
    'flatlink ist ein Open-Source-Projekt von ' => 'flatlink is an open source project by ',
    'Läuft mit flatlink, einem Open-Source-Projekt von ' => 'Runs on flatlink, an open source project by ',

    // ---- Startseite ----
    'Kurzlinks & QR-Codes' => 'Short links & QR codes',
    'Lange URL kürzen – der passende QR-Code kommt mit.' => 'Shorten a long URL – the matching QR code comes with it.',
    'Lange URL' => 'Long URL',
    'https://example.com/sehr/lange/adresse' => 'https://example.com/very/long/address',
    'Kürzen' => 'Shorten',
    'Fertig.' => 'Done.',
    'Kopieren' => 'Copy',
    'Kopiert' => 'Copied',
    'QR als SVG' => 'QR as SVG',
    'QR als PNG' => 'QR as PNG',
    'QR-Code für %s' => 'QR code for %s',
    'Das Ziel dieses Links ist fest. Links mit änderbarem Ziel und Klick-Statistik gibt es %snach dem Anmelden%s.'
        => 'The destination of this link is fixed. Links with editable destinations and click statistics are available %safter signing in%s.',
    'Nicht verfügbar' => 'Not available',
    'Die öffentliche Link-Erstellung ist deaktiviert.' => 'Public link creation is disabled.',
    'Zum Login' => 'Go to sign-in',
    'Das sieht nicht nach einer gültigen Adresse aus (http/https).' => 'That does not look like a valid address (http/https).',
    'Rate-Limit erreicht – bitte später erneut versuchen.' => 'Rate limit reached – please try again later.',
    'Limit erreicht: %d aktive Links. Lösche zuerst alte Links im Login-Bereich.'
        => 'Limit reached: %d active links. Delete old links in your account first.',
    'Diese Ziel-URL ist als schädlich gemeldet und kann nicht verkürzt werden.'
        => 'This destination URL has been reported as malicious and cannot be shortened.',
    'Das hat nicht geklappt.' => 'That did not work.',

    // ---- Weiterleitung (go.php) ----
    'Nicht gefunden' => 'Not found',
    'Diesen Kurzlink gibt es (noch) nicht.' => 'This short link does not exist (yet).',
    'Selbst einen anlegen' => 'Create one yourself',
    'Gesperrt' => 'Blocked',
    'Dieser Kurzlink wurde wegen Missbrauchs gesperrt.' => 'This short link has been blocked for abuse.',
    'Zur Startseite' => 'Back to the start page',
    'Abgelaufen' => 'Expired',
    'Dieser Kurzlink ist nicht mehr gültig.' => 'This short link is no longer valid.',
    'Geschützter Link' => 'Protected link',
    'Dieser Kurzlink ist passwortgeschützt.' => 'This short link is password-protected.',
    'Passwort' => 'Password',
    'Öffnen' => 'Open',
    'Falsches Passwort.' => 'Wrong password.',
    'Zu viele Versuche – bitte später erneut.' => 'Too many attempts – please try again later.',

    // ---- Registrierung ----
    'Registrierung geschlossen' => 'Registration closed',
    'Aktuell nehmen wir keine neuen Konten an. Kurzlinks kannst du trotzdem %sohne Konto erstellen%s.'
        => 'We are not accepting new accounts right now. You can still %screate short links without an account%s.',
    'Fast geschafft.' => 'Almost there.',
    'Wir haben dir eine E-Mail geschickt – ein Klick auf den Link darin, und dein Konto ist aktiv.'
        => 'We have sent you an email – one click on the link inside and your account is active.',
    'Nichts angekommen? Schau im Spam-Ordner nach. Der Link ist 24 Stunden gültig.'
        => 'Nothing arrived? Check your spam folder. The link is valid for 24 hours.',
    'Konto anlegen' => 'Create account',
    "Kostenlos. Damit gibt's den vollen QR-Designer mit eigenem Logo, Klick-Statistik, Bearbeiten und Ablaufdaten."
        => 'Free. It unlocks the full QR designer with your own logo, click statistics, editing and expiry dates.',
    'Dieser Bestätigungslink ist ungültig oder abgelaufen. Registriere dich einfach erneut.'
        => 'This confirmation link is invalid or has expired. Simply sign up again.',
    'E-Mail-Adresse' => 'Email address',
    'Passwort (mind. 8 Zeichen)' => 'Password (at least 8 characters)',
    'Passwort wiederholen' => 'Repeat password',
    'Schon ein Konto?' => 'Already have an account?',
    'Anmelden' => 'Sign in',
    'Passwort vergessen?' => 'Forgot your password?',
    'Konto bestätigt – willkommen!' => 'Account confirmed – welcome!',
    'Die Wiederholung stimmt nicht mit dem Passwort überein.' => 'The repetition does not match the password.',
    'Zu viele Versuche von dieser Adresse – bitte in einer Stunde erneut.'
        => 'Too many attempts from this address – please try again in an hour.',
    'Die Bestätigungsmail konnte gerade nicht verschickt werden – bitte später erneut versuchen.'
        => 'The confirmation email could not be sent right now – please try again later.',
    'Kostenloses Konto für %s: QR-Codes mit eigenem Logo, Klick-Statistik und bearbeitbare Kurzlinks.'
        => 'Free account for %s: QR codes with your own logo, click statistics and editable short links.',
    'Dein Konto bei %s' => 'Your account at %s',
    "Hallo,\n\njemand (vermutlich du) wollte sich mit dieser Adresse bei %s\nregistrieren – aber dazu gibt es schon ein Konto.\n\nPasswort vergessen? %s\n\nFalls das nicht du warst, kannst du diese Mail ignorieren.\n\n– %s"
        => "Hello,\n\nsomeone (probably you) tried to sign up at %s with this address –\nbut there is already an account for it.\n\nForgot your password? %s\n\nIf this was not you, you can ignore this email.\n\n– %s",
    'Bestätige deine Registrierung bei %s' => 'Confirm your registration at %s',
    "Hallo,\n\neinmal klicken, fertig:\n\n%s\n\nDer Link ist 24 Stunden gültig. Falls du dich nicht bei %s\nregistriert hast, ignoriere diese Mail einfach – es passiert nichts.\n\n– %s"
        => "Hello,\n\none click and you are done:\n\n%s\n\nThe link is valid for 24 hours. If you did not sign up at %s,\nsimply ignore this email – nothing will happen.\n\n– %s",

    // ---- Passwort-Reset ----
    'Passwort zurücksetzen' => 'Reset password',
    'Erledigt.' => 'Done.',
    'Dein Passwort ist geändert – du kannst dich jetzt anmelden.' => 'Your password has been changed – you can sign in now.',
    'Link abgelaufen' => 'Link expired',
    'Dieser Link ist ungültig oder abgelaufen – fordere einfach einen neuen an.'
        => 'This link is invalid or has expired – simply request a new one.',
    'Neuen Link anfordern' => 'Request a new link',
    'Neues Passwort' => 'New password',
    'Neues Passwort (mind. 8 Zeichen)' => 'New password (at least 8 characters)',
    'Wiederholen' => 'Repeat',
    'Passwort setzen' => 'Set password',
    'Schau in dein Postfach.' => 'Check your inbox.',
    'Falls zu dieser Adresse ein Konto existiert, ist ein Reset-Link unterwegs (eine Stunde gültig).'
        => 'If an account exists for this address, a reset link is on its way (valid for one hour).',
    'Kein Drama. Wir schicken dir einen Link zum Zurücksetzen.' => 'No drama. We will send you a link to reset it.',
    'Link anfordern' => 'Request link',
    'Passwort: mindestens 8 Zeichen.' => 'Password: at least 8 characters.',
    'Die Wiederholung stimmt nicht mit dem neuen Passwort überein.' => 'The repetition does not match the new password.',
    'Passwort zurücksetzen bei %s' => 'Reset your password at %s',
    "Hallo,\n\nhier kannst du ein neues Passwort setzen:\n\n%s\n\nDer Link ist eine Stunde gültig. Falls du das nicht angefordert hast,\nignoriere diese Mail – dein Passwort bleibt unverändert.\n\n– %s"
        => "Hello,\n\nyou can set a new password here:\n\n%s\n\nThe link is valid for one hour. If you did not request this,\nignore this email – your password stays unchanged.\n\n– %s",

    // ---- Missbrauch melden ----
    'Kurzlink von %s melden: Phishing, Malware oder Spam – wir prüfen und sperren schnell.'
        => 'Report a %s short link: phishing, malware or spam – we review and block quickly.',
    'Danke.' => 'Thank you.',
    'Deine Meldung ist eingegangen und wird geprüft. Gemeldete Links sperren wir im Zweifel schnell.'
        => 'Your report has been received and will be reviewed. When in doubt, we block reported links quickly.',
    'Du hast einen %s-Kurzlink erhalten, der auf Phishing, Malware oder Spam zeigt? Sag uns Bescheid – wir prüfen jede Meldung und sperren missbräuchliche Links.'
        => 'Received a %s short link that points to phishing, malware or spam? Let us know – we review every report and block abusive links.',
    'Der Kurzlink' => 'The short link',
    'Grund' => 'Reason',
    'Bitte wählen…' => 'Please choose…',
    'Phishing / Betrug' => 'Phishing / fraud',
    'Malware / Schadsoftware' => 'Malware / malicious software',
    'Spam / unerwünschte Werbung' => 'Spam / unwanted advertising',
    'Sonstiges' => 'Other',
    'Was ist passiert?' => 'What happened?',
    'optional' => 'optional',
    'z. B. gefälschte Bank-Login-Seite' => 'e.g. fake bank sign-in page',
    'Melden' => 'Report',
    'Bitte gib den Kurzlink an (z. B. %s oder nur den Code).' => 'Please provide the short link (e.g. %s or just the code).',
    'Bitte einen Grund auswählen.' => 'Please select a reason.',
    'Zu viele Meldungen von dieser Adresse – bitte in einer Stunde erneut.'
        => 'Too many reports from this address – please try again in an hour.',

    // ---- Anmeldung ----
    'Ersteinrichtung' => 'First-time setup',
    'Noch keine Nutzer vorhanden – leg dein Admin-Konto an.' => 'No users yet – create your admin account.',
    'Nutzername' => 'Username',
    'E-Mail oder Nutzername' => 'Email or username',
    '(mind. 8 Zeichen)' => '(at least 8 characters)',
    'Admin anlegen' => 'Create admin',
    'Login fehlgeschlagen.' => 'Sign-in failed.',
    'Willkommen! Admin-Konto angelegt.' => 'Welcome! Admin account created.',
    'oder mit lokalem Konto:' => 'or with a local account:',
    'Noch kein Konto?' => 'No account yet?',
    'Konten aus dem Verzeichnis melden sich hier mit ihrer gewohnten Kennung an.'
        => 'Directory accounts sign in here with their usual credentials.',
    'Bestätigung' => 'Confirmation',
    'Noch ein Schritt' => 'One more step',
    'Bestätige mit deinem Passkey – Fingerabdruck, Gesicht oder Geräte-PIN.'
        => 'Confirm with your passkey – fingerprint, face or device PIN.',
    'Mit Passkey bestätigen' => 'Confirm with passkey',
    'oder mit einem Code aus der App:' => 'or with a code from your app:',
    'Gib den sechsstelligen Code aus deiner Authenticator-App ein. Ein Wiederherstellungscode geht auch.'
        => 'Enter the six-digit code from your authenticator app. A recovery code works too.',
    'Code' => 'Code',
    'Bestätigen' => 'Confirm',
    'Abbrechen' => 'Cancel',
    'Der Code stimmt nicht.' => 'The code is not correct.',
    'Antwort unlesbar.' => 'Response unreadable.',
    'Für dieses Konto ist kein Passkey hinterlegt.' => 'No passkey is registered for this account.',

    // ---- Passkey-Skript ----
    'Dieser Browser kennt keine Passkeys.' => 'This browser does not support passkeys.',
    'Warte auf dein Gerät …' => 'Waiting for your device …',
    'Folge der Abfrage deines Geräts …' => 'Follow the prompt on your device …',
    'Abgebrochen oder zu lange gewartet.' => 'Cancelled or timed out.',
    'Dieses Gerät ist hier bereits hinterlegt.' => 'This device is already registered here.',
    'Passkeys brauchen eine gesicherte Verbindung (HTTPS).' => 'Passkeys require a secure connection (HTTPS).',
    'Es hat nicht geklappt.' => 'It did not work.',

    // ---- QR-Designer ----
    'Mit Kurzlink' => 'With short link',
    'Ohne Kürzen' => 'Without shortening',
    'QR-Code für eine Adresse' => 'QR code for an address',
    'Die Adresse steht unmittelbar im Code. Nichts wird gespeichert, nichts läuft über uns – der Code funktioniert auch dann noch, wenn es diesen Dienst nicht mehr gibt. Dafür steht das Ziel fest: Ändern lässt es sich später nur mit einem %sKurzlink%s, und eine Klickzahl gibt es hier nicht.'
        => 'The address is embedded directly in the code. Nothing is stored, nothing passes through us – the code keeps working even if this service no longer exists. In return, the destination is fixed: it can only be changed later with a %sshort link%s, and there is no click count here.',
    'Adresse oder Text' => 'Address or text',
    'Auch %s, %s oder einfach ein Text. Bis zu %s Zeichen – lange Adressen mit Kampagnen-Parametern passen also hinein.'
        => 'Also %s, %s or plain text. Up to %s characters – long addresses with campaign parameters fit easily.',
    'Neuer QR-Code' => 'New QR code',
    'Adresse eintragen – wir legen den Kurzlink an und öffnen ihn gleich im Designer. Das Ziel lässt sich später ändern, ohne den gedruckten Code auszutauschen.'
        => 'Enter an address – we create the short link and open it right in the designer. The destination can be changed later without replacing the printed code.',
    'Wohin soll der QR-Code führen?' => 'Where should the QR code lead?',
    'Anlegen' => 'Create',
    'Name' => 'Name',
    'optional, nur für deine Übersicht' => 'optional, only for your overview',
    'z. B. Speisekarte Sommer' => 'e.g. summer menu',
    'Ohne Konto ist das Ziel dauerhaft fest. Mit %skostenlosem Konto%s lässt es sich später ändern, ohne den gedruckten Code auszutauschen – dazu kommen eigenes Logo, Rahmentext und Druck-PDF.'
        => 'Without an account the destination is permanently fixed. With a %sfree account%s it can be changed later without replacing the printed code – plus your own logo, frame text and print PDF.',
    'Sobald ein Kurzlink da ist, erscheint hier der Designer – mit Farben, Formen%s.'
        => 'As soon as a short link exists, the designer appears here – with colours, shapes%s.',
    ', Logo, Rahmen und Druck-PDF' => ', logo, frame and print PDF',
    'für' => 'for',
    'Gestaltung' => 'Design',
    'Modul-Form' => 'Module shape',
    'Quadratisch' => 'Square',
    'Abgerundet' => 'Rounded',
    'Stark abgerundet' => 'Extra rounded',
    'Punkte' => 'Dots',
    'Raute' => 'Diamond',
    'Senkrechte Balken' => 'Vertical bars',
    'Waagerechte Balken' => 'Horizontal bars',
    'Augen-Form' => 'Eye shape',
    'Kreis' => 'Circle',
    'Blatt' => 'Leaf',
    'Augen-Kern' => 'Eye core',
    'wie der Ring' => 'same as the ring',
    'Augen eigene Farben geben' => 'Give the eyes their own colours',
    'Augen-Ring' => 'Eye ring',
    'Vordergrund' => 'Foreground',
    'Hintergrund' => 'Background',
    'Hintergrund durchsichtig' => 'Transparent background',
    'Farbverlauf' => 'Colour gradient',
    'Kein Verlauf' => 'No gradient',
    'Linear' => 'Linear',
    'Radial (von innen)' => 'Radial (from the centre)',
    'Richtung:' => 'Direction:',
    'Verlaufs-Vorlagen' => 'Gradient presets',
    'Wiese' => 'Meadow',
    'Nacht' => 'Night',
    'Sonne' => 'Sun',
    'See' => 'Lake',
    'Farbvorlagen' => 'Colour presets',
    'Klassik' => 'Classic',
    'Akzent' => 'Accent',
    'Papier' => 'Paper',
    'Invertiert' => 'Inverted',
    'Achtung: invertierte Codes lesen manche Scanner nicht' => 'Careful: some scanners cannot read inverted codes',
    'Fehlerkorrektur' => 'Error correction',
    'mit Logo automatisch H' => 'automatically H with a logo',
    'Rand (Quiet-Zone):' => 'Margin (quiet zone):',
    'Module' => 'modules',
    'Druckfarben (CMYK)' => 'Print colours (CMYK)',
    'Für Druckereien. Was hier steht, geht unverändert in PDF und EPS. Bildschirm, PNG und die Vorschau zeigen eine Umrechnung – ohne Farbprofil kann das nur eine Näherung sein, verbindlich ist die Druckdatei. Leer lassen heißt: die Bildschirmfarben oben gelten auch im Druck.'
        => 'For print shops. These values go into PDF and EPS unchanged. Screen, PNG and the preview show a conversion – without a colour profile that can only be an approximation; the print file is authoritative. Leave empty to use the screen colours above in print as well.',
    'in Prozent' => 'in percent',
    'Breite auf dem Papier' => 'Width on paper',
    'mm, für PDF und EPS' => 'mm, for PDF and EPS',
    'Rahmen' => 'Frame',
    'Text unter dem Code' => 'Text below the code',
    'leer = kein Rahmen, max. 24 Zeichen' => 'empty = no frame, max. 24 characters',
    'Scan mich!' => 'Scan me!',
    'z. B. Scan mich!' => 'e.g. Scan me!',
    'Logo' => 'Logo',
    'Kein Logo' => 'No logo',
    'Logo-Größe:' => 'Logo size:',
    'Freie Fläche hinter dem Logo' => 'Clear area behind the logo',
    'abgerundet' => 'rounded',
    'eckig' => 'square',
    'rund' => 'round',
    'keine' => 'none',
    'Ein Logo, das Module nur halb verdeckt, verwirrt die Erkennung mehr als eine sauber ausgesparte Fläche – die steckt die Fehlerkorrektur weg.'
        => 'A logo that half-covers modules confuses the scanner more than a cleanly cleared area – error correction absorbs the latter.',
    'SVG-Logos erscheinen nur im SVG-Export (PNG kann sie nicht rastern).'
        => 'SVG logos only appear in the SVG export (PNG cannot rasterise them).',
    'Anzeigename' => 'Display name',
    'leer = Dateiname' => 'empty = file name',
    'z. B. Firmenlogo weiß' => 'e.g. company logo white',
    'Hochladen' => 'Upload',
    'Ausgewähltes Logo löschen?' => 'Delete the selected logo?',
    'Logo löschen' => 'Delete logo',
    'Vorschau' => 'Preview',
    'auf' => 'on',
    'Hell' => 'Light',
    'Dunkel' => 'Dark',
    'QR-Code-Vorschau' => 'QR code preview',
    'Vektor-PDF für den Druck' => 'Vector PDF for print',
    'EPS für Satz und Belichtung' => 'EPS for typesetting and imaging',
    'PNG-Auflösung' => 'PNG resolution',
    'Zweite Farbe' => 'Second colour',
    'Zweite Farbe des Verlaufs' => 'Second colour of the gradient',
    'Die öffentliche Erstellung ist derzeit deaktiviert.' => 'Public creation is currently disabled.',
    'Rate-Limit erreicht – bitte später wieder vorbeischauen.' => 'Rate limit reached – please come back later.',
    'Für eigene Logos fehlt deinem Konto die Berechtigung.' => 'Your account lacks the permission for custom logos.',
    'Logo zu groß (max. 512 KB).' => 'Logo too large (max. 512 KB).',
    'Nur PNG, JPG, WebP oder SVG.' => 'Only PNG, JPG, WebP or SVG.',
    'Logo hochgeladen.' => 'Logo uploaded.',
    'Logo gelöscht.' => 'Logo deleted.',
    'Kein Zugriff auf dieses Logo.' => 'No access to this logo.',
    'Logo-Bibliothek voll (max. %d) – lösche zuerst ein Logo.' => 'Logo library full (max. %d) – delete a logo first.',
];
