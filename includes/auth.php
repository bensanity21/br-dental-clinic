<?php
// includes/auth.php - Session authentication, 5-minute timeout, role checks
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../config/config.php';

session_start();

// Update last activity timestamp on each request
function sessionExpired(): bool {
    if (empty($_SESSION['last_activity'])) {
        return true;
    }
    $elapsed = time() - $_SESSION['last_activity'];
    return $elapsed > (SESSION_TIMEOUT_MINUTES * 60);
}

// Update last activity timestamp on each request
function touchSession(): void {
    $_SESSION['last_activity'] = time();
}

touchSession();

// Require user to be logged in (session not expired)
function requireLogin(): void {
    if (sessionExpired() || empty($_SESSION['user_id'])) {
        session_destroy();
        respondError('Your session has expired. Please log in again.', null, 401);
    }
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
