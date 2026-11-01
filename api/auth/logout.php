<?php
// api/auth/logout.php - End the current PHP session
require_once '../../includes/auth.php';
require_once '../../includes/response.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respondError('Method not allowed.', null, 405);
}

// Record the sign-out before the session is destroyed.
require_once '../../includes/activity.php';
require_once '../../includes/db.php';
$loggingOutId = (int)($_SESSION['user_id'] ?? 0);
$loggingOutName = (string)($_SESSION['full_name'] ?? '');
if ($loggingOutId > 0) {
    logActivity($pdo, 'auth.logout', 'users', $loggingOutId, "$loggingOutName signed out.");
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

respondSuccess('Logged out successfully.');
