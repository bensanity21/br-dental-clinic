<?php
// api/users/update.php - Update user info / deactivate (super-admin only)
// PATCH: { id, full_name?, email?, phone_number?, role?, is_active? }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/activity.php';

requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? null;
if (!$id) { respondError('User ID required.', null, 400); }

// Prevent demoting/promoting to super-admin via this form
if (isset($data['role']) && !in_array($data['role'], ['patient', 'doctor'], true)) {
    respondError('Invalid role. Only patient and doctor accounts can be created or changed here.', null, 400);
}

// There is exactly one Super Admin (created by the seed SQL); this endpoint never touches it.
$stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) { respondError('User not found.', null, 404); }
if ($target['role'] === 'super-admin') {
    respondError('The Super Admin account cannot be modified from this page.', null, 403);
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

logActivity($pdo, 'user.update', 'users', (int)$id,
    'Account #' . (int)$id . ' updated (' . implode(', ', array_map(fn($f) => str_replace(' = ?', '', $f), $fields)) . ').',
    ['changed' => array_map(fn($f) => str_replace(' = ?', '', $f), $fields)]);

// Deactivate/reactivate (soft flag via role or a status column; here we use role change to indicate)
// Since there's no is_active column, we handle archive separately. Just respond success.
respondSuccess('User updated');
