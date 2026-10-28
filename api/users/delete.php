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
$allowedTables = ['users', 'services', 'appointments', 'dental_records', 'dentists'];

if (!$id) { respondError('Record ID required.', null, 400); }
if (!in_array($table, $allowedTables, true)) { respondError('Unsupported record type.', null, 400); }

// Prevent deleting the super-admin account itself
$self = $_SESSION['user_id'] ?? null;
if ($table === 'users' && (int)$id === (int)$self) {
    respondError('You cannot archive your own account.', null, 400);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    if (!$record) {
        $pdo->rollBack();
        respondError('Record not found.', null, 404);
    }

    $stmt = $pdo->prepare('INSERT INTO archives (original_table, record_id, record_data, deleted_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([$table, $id, json_encode($record), $self]);
    $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respondError('Could not archive this record.', null, 500);
}

respondSuccess("$table record archived (soft deleted)");
