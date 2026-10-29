<?php
// Read and update the clinic's public-facing details (super-admin only).
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = $pdo->query('SELECT clinic_name, phone_number, address FROM clinic_settings WHERE id = 1')->fetch();
    if (!$row) {
        respondSuccess('Clinic details retrieved', [
            'clinic_name' => CLINIC_NAME,
            'phone_number' => CLINIC_PHONE,
            'address' => CLINIC_ADDRESS,
        ]);
    }
    respondSuccess('Clinic details retrieved', $row);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    header('Allow: GET, POST, PATCH');
    respondError('Method not allowed.', null, 405);
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$phone = trim((string)($data['phone_number'] ?? ''));
$address = trim((string)($data['address'] ?? ''));
if ($phone === '' || strlen($phone) > 20 || $address === '' || strlen($address) > 2000) {
    respondError('Enter a phone number (up to 20 characters) and address (up to 2,000 characters).', null, 400);
}

$exists = $pdo->query('SELECT id FROM clinic_settings WHERE id = 1')->fetch();
if ($exists) {
    $pdo->prepare('UPDATE clinic_settings SET phone_number = ?, address = ? WHERE id = 1')->execute([$phone, $address]);
} else {
    $pdo->prepare('INSERT INTO clinic_settings (id, clinic_name, phone_number, address) VALUES (1, ?, ?, ?)')
        ->execute([CLINIC_NAME, $phone, $address]);
}

respondSuccess('Clinic details updated');
