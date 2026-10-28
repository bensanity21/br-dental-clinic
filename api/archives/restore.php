<?php
// api/archives/restore.php - Restore an archived record (super-admin only)
// POST: { id, table: 'users'|'services'|'appointments'|... }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true);
$archiveId = $data['id'] ?? null;

if (!$archiveId) { respondError('Archive ID required.', null, 400); }

$stmt = $pdo->prepare('SELECT record_data, original_table, record_id FROM archives WHERE id = ?');
$stmt->execute([$archiveId]);
$archived = $stmt->fetch();
if (!$archived) { respondError('Archived record not found.', null, 404); }
$table = $archived['original_table'];
$allowedTables = ['users', 'services', 'appointments', 'dental_records', 'dentists'];
if (!in_array($table, $allowedTables, true)) { respondError('Unsupported archived record type.', null, 400); }

$dataRow = json_decode($archived['record_data'], true);
if (!is_array($dataRow) || !$dataRow) { respondError('Archived record data is invalid.', null, 400); }
unset($dataRow['id']); // let MySQL re-assign

// Rebuild insert dynamically
$columns = implode(', ', array_keys($dataRow));
$placeholders = implode(', ', array_fill(0, count($dataRow), '?'));
$values = array_values($dataRow);

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)")->execute($values);
    $pdo->prepare('DELETE FROM archives WHERE id = ?')->execute([$archiveId]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    respondError('Failed to restore record.', null, 500);
}

respondSuccess('Record restored from archive');
