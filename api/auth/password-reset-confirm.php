<?php
// api/auth/password-reset-confirm.php
// Complete a password reset.
//
// POST { token, new_password, confirm_password }
//
// The token is single use and expires. A successful reset also clears the
// login rate limit for that account, so a user who was locked out after
// forgetting their password can sign in immediately.

header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../connectdb.php';
require_once '../../includes/password_reset.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.', 'data' => null], 405);
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$token = trim((string)($data['token'] ?? ''));
$newPassword = (string)($data['new_password'] ?? '');
$confirm = $data['confirm_password'] ?? null;

if ($token === '') {
    respond(['success' => false, 'message' => 'This reset link is not valid.', 'data' => null], 400);
}

if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
    respond([
        'success' => false,
        'message' => 'Choose a password between 8 and 72 characters.',
        'data'    => null,
    ], 422);
}

if ($confirm !== null && $newPassword !== $confirm) {
    respond(['success' => false, 'message' => 'The passwords do not match.', 'data' => null], 422);
}

// Reject the passwords the signup form already rejects.
$lower = strtolower($newPassword);
if (preg_match('/^(password|12345678|qwerty|letmein|admin)/', $lower)) {
    respond([
        'success' => false,
        'message' => 'Choose a stronger password.',
        'data'    => null,
    ], 422);
}

$usable = findUsableReset($pdo, $token);
if (!$usable) {
    respond([
        'success' => false,
        'message' => 'This reset link has expired or has already been used. Request a new one.',
        'data'    => null,
    ], 400);
}

$userId = (int)$usable['user_id'];

if (!consumePasswordReset($pdo, $token, $newPassword)) {
    respond([
        'success' => false,
        'message' => 'This reset link has expired or has already been used. Request a new one.',
        'data'    => null,
    ], 400);
}

// Clear the login lockout for this account.
$stmt = $pdo->prepare("SELECT email, full_name, role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if ($user) {
    $pdo->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$user['email']]);

    require_once '../../includes/activity.php';
    logActivity(
        $pdo,
        'auth.password_reset_completed',
        'users',
        $userId,
        'Password changed via reset link.',
        null,
        ['id' => $userId, 'name' => $user['full_name'], 'role' => $user['role']]
    );
}

respond(['success' => true, 'message' => 'Your password has been changed. You can sign in now.', 'data' => null]);
