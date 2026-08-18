<?php
declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Konfiguration aus Umgebungsvariablen – für den Betrieb im Container.
 *
 * Ein Image ist für viele Instanzen dasselbe; was sie unterscheidet, kommt
 * von außen. Deshalb liest diese Datei FLATLINK_*-Variablen, statt Werte
 * festzuschreiben.
 *
 * Der Entrypoint verlinkt sie nach inc/config.php, WENN dort nichts liegt.
 * Wer lieber eine ausgeschriebene Konfiguration mag, hängt seine eigene
 * inc/config.php in den Container – dann bleibt diese Datei unbenutzt, und
 * beide Wege stehen gleichberechtigt nebeneinander.
 *
 * Gesetzt wird hier nur, was auch wirklich in der Umgebung steht: cfg()
 * legt diese Rückgabe über inc/config.example.php, und alles, was fehlt,
 * behält dort seine Vorgabe. Ein Schlüssel, den wir mit null belegen
 * würden, würde die Vorgabe dagegen löschen – daher die Prüfungen.
 *
 * Alles steckt in einer Funktion, und das ist keine Förmlichkeit: cfg()
 * bindet diese Datei mit require MITTEN IN SICH ein, weshalb wir uns den
 * Variablenraum der Funktion teilen. Eine Schleifenvariable $key würde dort
 * den gleichnamigen Parameter überschreiben – cfg() lieferte danach für
 * jede Anfrage denselben falschen Wert. Ein eigener Geltungsbereich macht
 * das unmöglich, statt sich auf gut gewählte Namen zu verlassen.
 */

return (static function (): array {

/** Wert aus der Umgebung, leer zählt als „nicht gesetzt" */
$env = static function (string $name): ?string {
    $v = getenv($name);
    return ($v === false || trim($v) === '') ? null : trim($v);
};

/** „1", „true", „yes", „on" sind wahr – alles andere falsch */
$flag = static function (string $name) use ($env): ?bool {
    $v = $env($name);
    return $v === null ? null : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
};

/** Komma-getrennte Liste, z. B. für trusted_proxies oder default_perms */
$list = static function (string $name) use ($env): ?array {
    $v = $env($name);
    if ($v === null) return null;
    $out = array_values(array_filter(array_map('trim', explode(',', $v)), fn($s) => $s !== ''));
    return $out === [] ? null : $out;
};

$c = [];

// ---- Grundlegendes ----
// data_dir zeigt aus dem Webverzeichnis heraus: Im Container ist das der
// Ort, an dem das Volume hängt – Links, Konten und Zähler überleben damit
// jedes neue Image.
$c['data_dir'] = $env('FLATLINK_DATA_DIR') ?? '/var/lib/flatlink';

foreach ([
    'FLATLINK_SITE_NAME'    => 'site_name',
    'FLATLINK_BASE_URL'     => 'base_url',
    'FLATLINK_LANGUAGE'     => 'language',
    'FLATLINK_PUBLIC_MODE'  => 'public_mode',
    'FLATLINK_REGISTRATION' => 'registration',
    'FLATLINK_QR_BRAND_TEXT' => 'qr_brand_text',
    'FLATLINK_API_DOC_URL'  => 'api_doc_url',
    'FLATLINK_WEBHOOK_SECRET' => 'webhook_secret',
    'FLATLINK_SAFE_BROWSING_KEY' => 'safe_browsing_key',
    'FLATLINK_BODY_CLASS'   => 'body_class',
] as $var => $ziel) {
    $v = $env($var);
    if ($v !== null) $c[$ziel] = $v;
}

foreach ([
    'FLATLINK_CODE_LENGTH'      => 'code_length',
    'FLATLINK_PUBLIC_RATE_LIMIT' => 'public_rate_limit',
    'FLATLINK_API_RATE_LIMIT'   => 'api_rate_limit',
    'FLATLINK_QR_RATE_LIMIT'    => 'qr_rate_limit',
    'FLATLINK_CUSTOM_CODE_MIN_LEN' => 'custom_code_min_len',
    'FLATLINK_DEMO_RESET_MINUTES' => 'demo_reset_minutes',
    'FLATLINK_LINK_GC_YEARS'    => 'link_gc_years',
] as $var => $ziel) {
    $v = $env($var);
    if ($v !== null) $c[$ziel] = (int)$v;
}

foreach ([
    'FLATLINK_CLICK_DIMS'    => 'click_dims',
    'FLATLINK_DEMO_MODE'     => 'demo_mode',
    'FLATLINK_SELF_DELETE'   => 'self_delete',
    'FLATLINK_ALLOW_PRIVATE_TARGETS' => 'allow_private_targets',
] as $var => $ziel) {
    $v = $flag($var);
    if ($v !== null) $c[$ziel] = $v;
}

// totp_required ist kein Ja/Nein, sondern off | admins | all
$v = $env('FLATLINK_TOTP_REQUIRED');
if ($v !== null) $c['totp_required'] = $v;

// ---- Reverse Proxy ----
// Fast jeder Container steht hinter einem: Traefik, Caddy, nginx. Ohne
// diesen Eintrag sähe flatlink für alle Besucher dieselbe Adresse – Rate-
// Limit und Anmeldesperre würden versehentlich gemeinsam gelten.
$proxies = $list('FLATLINK_TRUSTED_PROXIES');
if ($proxies !== null) $c['trusted_proxies'] = $proxies;

$perms = $list('FLATLINK_DEFAULT_PERMS');
if ($perms !== null) $c['default_perms'] = $perms;

$domains = $list('FLATLINK_DOMAINS');
if ($domains !== null) $c['domains'] = $domains;

// ---- Mail ----
// Nur anfassen, wenn ein Weg genannt ist: cfg() ersetzt verschachtelte
// Angaben als Ganzes, ein halb gefüllter Block würde den Rest verwerfen.
$mailMode = $env('FLATLINK_MAIL_MODE');
$smtpHost = $env('FLATLINK_SMTP_HOST');
if ($mailMode !== null || $smtpHost !== null) {
    $c['mail'] = [
        'mode' => $mailMode ?? ($smtpHost !== null ? 'smtp' : 'log'),
        'host' => $smtpHost ?? '',
        'port' => (int)($env('FLATLINK_SMTP_PORT') ?? 587),
        'user' => $env('FLATLINK_SMTP_USER') ?? '',
        'pass' => $env('FLATLINK_SMTP_PASS') ?? '',
        'from' => $env('FLATLINK_MAIL_FROM') ?? 'noreply@example.org',
        'from_name' => $env('FLATLINK_MAIL_FROM_NAME') ?? ($env('FLATLINK_SITE_NAME') ?? 'flatlink'),
    ];
}

// ---- LDAP / Active Directory ----
$ldapUri = $env('FLATLINK_LDAP_URI');
if ($ldapUri !== null) {
    $c['ldap'] = array_merge(
        (require __DIR__ . '/config.example.php')['ldap'],
        array_filter([
            'enabled'     => true,
            'uri'         => $ldapUri,
            'start_tls'   => $flag('FLATLINK_LDAP_START_TLS'),
            'bind_dn'     => $env('FLATLINK_LDAP_BIND_DN'),
            'bind_pass'   => $env('FLATLINK_LDAP_BIND_PASS'),
            'base_dn'     => $env('FLATLINK_LDAP_BASE_DN'),
            'user_filter' => $env('FLATLINK_LDAP_USER_FILTER'),
            'mail_attr'   => $env('FLATLINK_LDAP_MAIL_ATTR'),
            'name_attr'   => $env('FLATLINK_LDAP_NAME_ATTR'),
            'auto_create' => $flag('FLATLINK_LDAP_AUTO_CREATE'),
        ], fn($wert) => $wert !== null)
    );
}

return $c;

})();
