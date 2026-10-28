<?php
// api/auth/logout.php - End the current PHP session
require_once '../../includes/auth.php';
require_once '../../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respondError('Method not allowed.', null, 405);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

respondSuccess('Logged out successfully.');
