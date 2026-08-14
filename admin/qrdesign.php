<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// flatlink · Zusatzbedingung zur Namensnennung nach §7(b) AGPL: siehe LICENSE
/**
 * Der QR-Designer ist in den Webroot gezogen, weil er dort für Gäste und
 * Angemeldete dieselbe Seite sein kann. Diese Datei bleibt als Weiterleitung
 * stehen: Lesezeichen, alte Links und der Menüpunkt im Login-Bereich zeigten
 * jahrelang hierher.
 */
require_once __DIR__ . '/../inc/helpers.php';

$code = (string)($_GET['c'] ?? '');
redirect_to(base_url() . '/qr-designer.php' . ($code !== '' ? '?c=' . rawurlencode($code) : ''));
