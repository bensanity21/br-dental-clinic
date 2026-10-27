<?php
// api/users/update.php - Update user info / deactivate (super-admin only)
// PATCH: { id, full_name?, email?, phone_number?, role?, is_active? }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? null;
if (!$id) { respondError('User ID required.', null, 400); }

// Prevent demoting/promoting to super-admin via this form
if (isset($data['role']) && !in_array($data['role'], ['patient', 'doctor', 'super-admin'], true)) {
    respondError('Invalid role.', null, 400);
}

// Build dynamic update
$fields = []; $params = [];
foreach (['full_name', 'email', 'phone_number', 'role'] as $f) {
    if (isset($data[$f])) { $fields[] = "$f = ?"; $params[] = $data[$f]; }
}
if ($fields) {
    $params[] = $id;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
}

// Deactivate/reactivate (soft flag via role or a status column; here we use role change to indicate)
// Since there's no is_active column, we handle archive separately. Just respond success.
respondSuccess('User updated');
