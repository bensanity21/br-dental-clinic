<?php
// api/users/delete.php - Soft delete a user (move to archives) — super-admin only
// DELETE: { id, table: 'users' }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? null;
$table = $data['table'] ?? 'users';

if (!$id) { respondError('Record ID required.', null, 400); }

// Prevent deleting the super-admin account itself
$self = $_SESSION['user_id'] ?? null;
if ($table === 'users' && (int)$id === (int)$self) {
    respondError('You cannot archive your own account.', null, 400);
}

// Fetch the record before archiving
$stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) { respondError('Record not found.', null, 404); }

// Archive it
$recordData = json_encode($record);
$stmt = $pdo->prepare("
    INSERT INTO archives (original_table, record_id, record_data, deleted_by)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$table, $id, $recordData, $self]);

// Remove from original table
$pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);

respondSuccess("$table record archived (soft deleted)");
