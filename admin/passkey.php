<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Das Angebot nach der Anmeldung: einen Passkey einrichten.
 *
 * Erreichbar wird diese Seite nur über login_ziel() – und die schickt hierher
 * höchstens einmal im Monat und nur an Konten ohne Passkey. Wer sie von Hand
 * aufruft, ohne dass etwas anzubieten wäre, landet sofort auf der Linkliste.
 *
 * Warum überhaupt eine eigene Seite und kein Streifen über der Linkliste: Ein
 * Streifen wird überlesen, und die Einrichtung braucht ohnehin die volle
 * Aufmerksamkeit – das Gerät fragt gleich nach Fingerabdruck oder PIN.
 */
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

$user = auth_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // Die beiden JSON-Wege: gerufen vom Skript im Browser, nicht von einem
    // Formular. Dieselbe Mechanik wie im Profil, nur mit anderem Ziel danach.
    if ($action === 'pk_challenge') {
        wa_json(passkey_create_options($user));
    }
    if ($action === 'pk_register') {
        $daten = json_decode((string)($_POST['daten'] ?? ''), true);
        if (!is_array($daten)) wa_json(['error' => t('Antwort unlesbar.')], 400);
        $err = passkey_register($user['name'], $daten, (string)($_POST['label'] ?? ''));
        if ($err !== null) wa_json(['error' => $err], 422);
        flash(t('Passkey hinterlegt. Beim nächsten Mal genügt dein Gerät.'));
        wa_json(['ok' => true, 'redirect' => 'index.php']);
    }

    // „Nicht mehr fragen" ist kein Nebenweg, sondern die Bedingung dafür, dass
    // man überhaupt fragen darf. Ein Vorschlag ohne Nein ist eine Aufforderung.
    if ($action === 'nie') {
        passkey_hint_seen($user['name'], true);
        flash(t('Alles klar – wir fragen nicht mehr danach. Im Profil geht es weiterhin.'));
    }
    redirect_to(base_url() . '/admin/index.php');
}

// Nichts anzubieten? Dann auch keine Seite. Das greift für den Aufruf von
// Hand ebenso wie für den Rücksprung nach dem Einrichten.
if (!passkey_hint_due($user['name'])) redirect_to(base_url() . '/admin/index.php');

// Beim Anzeigen vermerken, nicht beim Wegklicken: Wer den Tab schließt, hat
// die Frage gesehen.
passkey_hint_seen($user['name']);

page_header(t('Passkey einrichten'), true);
?>
<div class="card narrow">
    <h1><?= t('Ohne Passwort anmelden?') ?></h1>

    <p><?= t('Mit einem Passkey meldest du dich mit Fingerabdruck, Gesicht oder Geräte-PIN an – das Passwort brauchst du dann nicht mehr. Es bleibt trotzdem gültig, für die Tage ohne dieses Gerät.') ?></p>

    <p class="muted small"><?= t('Der Passkey liegt in deinem Telefon, deinem Rechner oder deiner Passwortverwaltung und gilt %snur für diese Adresse%s: Auf einer nachgebauten Anmeldeseite gibt ihn dein Gerät gar nicht erst heraus. Genau davor schützt ein Passwort nicht.', '<strong>', '</strong>') ?></p>

    <div id="pk-status" class="flash" style="display:none"></div>
    <p><button class="btn btn-primary btn-block" type="button"
               data-passkey="register" data-url="passkey.php" data-csrf="<?= e(csrf_token()) ?>"
               data-status="pk-status"><?= t('Passkey einrichten') ?></button></p>

    <form method="post" action="">
        <?= csrf_field() ?>
        <p><button class="btn btn-block" type="submit"><?= t('Später') ?></button></p>
        <p class="muted small form-foot">
            <button class="btn-link small" type="submit" name="action" value="nie"><?= t('Nicht mehr fragen') ?></button>
        </p>
    </form>
</div>
<?php
page_script('assets/passkey.js');
page_footer();
