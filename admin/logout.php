<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/sso.php';

auth_boot();
// Vor dem Aufräumen merken, woher die Anmeldung kam
$wasSso = ($_SESSION['auth_source'] ?? 'local') === 'sso';
auth_logout();

// Bei zentraler Anmeldung reicht das Beenden der lokalen Sitzung nicht: Sonst
// meldet der Webserver denselben Nutzer beim nächsten Klick sofort wieder an.
// Deshalb weiter zum Single Logout des Identity Providers, falls konfiguriert.
$logout = (string)sso_cfg()['logout_url'];
header('Location: ' . ($wasSso && $logout !== '' ? $logout : 'login.php'));
exit;
