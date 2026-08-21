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
// Die Domain wandert mit: Seit 5.0 sagt sie mit, welcher Link gemeint ist.
$dom = (string)($_GET['d'] ?? '');
redirect_to(base_url() . '/qr-designer.php' . ($code !== ''
    ? '?c=' . rawurlencode($code) . ($dom !== '' ? '&d=' . rawurlencode($dom) : '')
    : ''));
