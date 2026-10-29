<?php
// PATCH: { id, status: 'Completed' }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = $data['status'] ?? '';
if (!$id || $status !== 'Completed') {
    respondError('A valid dental record and Completed status are required.', null, 400);
}

$stmt = $pdo->prepare("UPDATE dental_records SET status = 'Completed' WHERE id = ?");
$stmt->execute([$id]);
if ($stmt->rowCount() === 0) {
    $exists = $pdo->prepare('SELECT id, status FROM dental_records WHERE id = ?');
    $exists->execute([$id]);
    $record = $exists->fetch();
    if (!$record) respondError('Dental record not found.', null, 404);
    if ($record['status'] !== 'Completed') respondError('Could not update dental record status.', null, 500);
}

respondSuccess('Dental record marked complete.', ['id' => $id, 'status' => 'Completed']);
