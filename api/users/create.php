<?php
// api/users/create.php - Create a Doctor or Patient account (super-admin only)
// Role 'super-admin' is NOT allowed from this form.
// POST: { full_name, email, password, role: 'doctor'|'patient', phone_number, specialization }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';

requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true);

$fullName = trim($data['full_name'] ?? '');
$email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = $data['password'] ?? '';
$role = $data['role'] ?? '';
$phone = trim($data['phone_number'] ?? '');
$specialization = trim($data['specialization'] ?? '');

// Security: only doctor or patient can be created here — never super-admin
if (!in_array($role, ['doctor', 'patient'], true)) {
    respondError('Invalid role. Only Doctor and Patient accounts can be created here.', null, 400);
}
if (!$fullName || !$email || !$password || strlen($password) < 8) {
    respondError('Full name, valid email, and password (min 8 chars) are required.', null, 400);
}

// Email uniqueness
$chk = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$chk->execute([$email]);
if ($chk->fetch()) { respondError('Email already registered.', null, 409); }

$hash = password_hash($password, PASSWORD_BCRYPT);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, phone_number, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$fullName, $email, $hash, $phone, $role]);
    $userId = $pdo->lastInsertId();

    // If doctor, create linked dentist profile
    if ($role === 'doctor') {
        $stmt = $pdo->prepare('INSERT INTO dentists (user_id, full_name, specialization, phone_number) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $fullName, $specialization, $phone]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    respondError('Failed to create account.', null, 500);
}

respondSuccess("$role account created successfully", ['user_id' => $userId, 'role' => $role], 201);
