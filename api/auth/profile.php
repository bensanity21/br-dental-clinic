<?php
// api/auth/profile.php - Get/update current user profile
// GET: return current user info
// PATCH: update full_name and/or phone_number
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';

requireLogin();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respondSuccess('Profile retrieved', $user);
}

$data = json_decode(file_get_contents('php://input'), true);
$fullName = trim($data['full_name'] ?? '');
$phone = trim($data['phone_number'] ?? '');

if ($fullName === '' && $phone === '') {
    respondError('No fields to update.', null, 400);
}

$fields = []; $params = [];
if ($fullName !== '') { $fields[] = 'full_name = ?'; $params[] = $fullName; }
if ($phone !== '') { $fields[] = 'phone_number = ?'; $params[] = $phone; }
$params[] = $user['id'];

$pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

// Update session
$_SESSION['full_name'] = $fullName ?: $user['full_name'];
$_SESSION['phone_number'] = $phone;

respondSuccess('Profile updated');
