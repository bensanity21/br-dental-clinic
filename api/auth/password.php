<?php
// api/auth/password.php - Change password (with current password verification)
// POST: { current_password, new_password }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);
$currentPassword = is_array($data) ? ($data['current_password'] ?? '') : '';
$newPassword = is_array($data) ? ($data['new_password'] ?? '') : '';
$confirmPassword = is_array($data) ? ($data['confirm_password'] ?? null) : null;

// Minimum length matches every other password path (see docs/PASSWORD-POLICY.md).
// bcrypt ignores bytes past 72, so that remains the upper bound.
if (!is_string($currentPassword) || $currentPassword === '' || !is_string($newPassword) || strlen($newPassword) < 12 || strlen($newPassword) > 72) {
    respondError('Enter your current password and a new password between 12 and 72 characters.', null, 400);
}
if ($user['role'] === 'patient' && (!is_string($confirmPassword) || !hash_equals($newPassword, $confirmPassword))) {
    respondError('The new password confirmation does not match.', null, 422);
}
if (hash_equals($currentPassword, $newPassword)) {
    respondError('Choose a new password different from your current password.', null, 422);
}

// Verify current password
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    respondError('Current password is incorrect.', null, 401);
}

// Hash and save new password
$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);

// Changing a password must invalidate every other open session for that
// account, so a person who changed it because someone else knew the old
// password actually gets that person out. The current session is kept.
require_once '../../includes/session_handler.php';
$currentId = session_id();
$pdo->prepare(
    "DELETE FROM sessions WHERE data LIKE ? AND id <> ?"
)->execute(['%user_id|i:' . (int)$user['id'] . ';%', hash('sha256', $currentId)]);

respondSuccess('Password changed successfully');
