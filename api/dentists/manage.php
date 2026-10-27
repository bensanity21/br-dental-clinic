<?php
// api/dentists/manage.php - Create/manage dentist profiles (super-admin only)
// Links a dentist profile to a user account (role = doctor)
// POST: create | PATCH: update | DELETE: deactivate
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST') {
    $name = trim($data['full_name'] ?? '');
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $specialization = trim($data['specialization'] ?? '');
    $phone = trim($data['phone_number'] ?? '');
    if (!$name || !$email || !$specialization) {
        respondError('Full name, email, and specialization are required.', null, 400);
    }
    // Check email not already used
    $chk = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $chk->execute([$email]);
    if ($chk->fetch()) { respondError('Email already registered.', null, 409); }

    // Create user account with role 'doctor' + temporary password
    $tempPassword = bin2hex(random_bytes(8)); // temp password
    $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, phone_number, role) VALUES (?, ?, ?, ?, "doctor")');
        $stmt->execute([$name, $email, $hash, $phone]);
        $userId = $pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO dentists (user_id, full_name, specialization, phone_number) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $name, $specialization, $phone]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        respondError('Failed to create dentist account.', null, 500);
    }

    // Create notification / invite info (no SMTP - return temp password for admin to share)
    respondSuccess('Dentist account created. Temporary password: ' . $tempPassword, ['user_id' => $userId, 'temp_password' => $tempPassword], 201);
}

if ($method === 'PATCH') {
    $id = $data['id'] ?? null;
    $fields = []; $params = [];
    foreach (['full_name', 'specialization', 'phone_number'] as $f) {
        if (isset($data[$f])) { $fields[] = "$f = ?"; $params[] = $data[$f]; }
    }
    if (!$id || !$fields) { respondError('Nothing to update.', null, 400); }
    $params[] = $id;
    $pdo->prepare('UPDATE dentists SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    respondSuccess('Dentist updated');
}

if ($method === 'DELETE') {
    $id = $data['id'] ?? null;
    if (!$id) { respondError('Dentist ID required.', null, 400); }
    $pdo->prepare('DELETE FROM dentists WHERE id = ?')->execute([$id]);
    respondSuccess('Dentist deleted');
}

respondError('Invalid request.', null, 405);
