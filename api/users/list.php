<?php
// api/users/list.php - List all users (admin only)
// GET: ?role=patient|doctor|super-admin (optional filter)
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$roleFilter = $_GET['role'] ?? '';

$sql = "SELECT id, full_name, email, phone_number, role, created_at FROM users WHERE 1=1";
$params = [];
if ($roleFilter && in_array($roleFilter, ['patient', 'doctor', 'super-admin'], true)) {
    $sql .= ' AND role = ?';
    $params[] = $roleFilter;
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

respondSuccess('Users retrieved', $users);
