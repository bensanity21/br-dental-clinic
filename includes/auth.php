<?php
// includes/auth.php - Session authentication, 5-minute timeout, role checks
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/session.php';

// Update last activity timestamp on each request
function sessionExpired(): bool {
    if (empty($_SESSION['last_activity'])) {
        return true;
    }
    $elapsed = time() - $_SESSION['last_activity'];
    return $elapsed > (SESSION_TIMEOUT_MINUTES * 60);
}

function touchSession(): void {
    $_SESSION['last_activity'] = time();
}

// Require user to be logged in (session not expired)
function requireLogin(): void {
    if (sessionExpired() || empty($_SESSION['user_id'])) {
        $_SESSION = [];
        session_destroy();
        respondError('Your session has expired. Please log in again.', null, 401);
    }

    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $expected = (string)($_SESSION['csrf_token'] ?? '');
        $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            respondError('Security token is missing or expired. Refresh the page and try again.', null, 403);
        }
    }

    // Background notification polling must not keep an otherwise idle session alive.
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $isNotificationPoll = ($_SERVER['HTTP_X_BACKGROUND_POLL'] ?? '') === '1'
        && basename(dirname($scriptPath)) === 'notifications'
        && basename($scriptPath) === 'list.php';
    if (!$isNotificationPoll) touchSession();
}

// Require specific role(s) - accepts string or array
function requireRole($roles): void {
    requireLogin();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
        respondError('You do not have permission to perform this action.', null, 403);
    }
}

// Get current logged-in user info
function currentUser(): array {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'role' => $_SESSION['role'] ?? '',
    ];
}
