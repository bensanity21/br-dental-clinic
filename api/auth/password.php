<?php
// api/auth/password.php - Change password (with current password verification)
// POST: { current_password, new_password }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);
$currentPassword = $data['current_password'] ?? '';
$newPassword = $data['new_password'] ?? '';

if (!$currentPassword || !$newPassword || strlen($newPassword) < 8) {
    respondError('Current password and new password (min 8 chars) are required.', null, 400);
}

// Verify current password
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    respondError('Current password is incorrect.', null, 401);
}

// Hash and save new password
$hash = password_hash($newPassword, PASSWORD_BCRYPT);
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);

respondSuccess('Password changed successfully');
