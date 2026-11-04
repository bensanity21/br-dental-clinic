<?php
// includes/session.php - Start the clinic session with explicit browser protections.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/session_handler.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SESSION_NAME);

    $isProduction = (getenv('APP_ENV') ?: 'development') === 'production';
    $directHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    // In production the cookie must always be Secure. For TLS-terminating
    // reverse proxies, the deployment may explicitly trust a sanitized
    // X-Forwarded-Proto header; never trust that header by default.
    $trustedProxyHttps = getenv('TRUST_PROXY_HEADERS') === '1'
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $isHttps = $isProduction || $directHttps || $trustedProxyHttps;
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
