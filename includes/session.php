<?php
// includes/session.php - Start the clinic session with explicit browser protections.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/session_handler.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SESSION_NAME);

    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Store sessions in MySQL instead of PHP's temp files, so a session can be
    // revoked centrally and survives a web-server restart.
    session_set_save_handler(new DatabaseSessionHandler(), true);

    session_start();
}
