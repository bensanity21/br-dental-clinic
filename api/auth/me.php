<?php
// api/auth/me.php - Get current logged-in user from PHP session
// GET only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';

requireLogin();
$user = currentUser();

// Also fetch dentist info if role is doctor
$dentist = null;
if ($user['role'] === 'doctor') {
    $stmt = $pdo->prepare('SELECT id, specialization, phone_number FROM dentists WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $dentist = $stmt->fetch();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
respondSuccess('Current user', ['user' => $user, 'dentist' => $dentist, 'csrf_token' => $_SESSION['csrf_token']]);
