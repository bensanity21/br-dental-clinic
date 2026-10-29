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

if (!is_string($currentPassword) || $currentPassword === '' || !is_string($newPassword) || strlen($newPassword) < 8 || strlen($newPassword) > 72) {
    respondError('Enter your current password and a new password between 8 and 72 characters.', null, 400);
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

respondSuccess('Password changed successfully');
