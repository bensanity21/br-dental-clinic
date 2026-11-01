<?php
// api/archives/hard-delete.php - Permanently delete an archived record (super-admin only)
// POST: { id, confirm: "DELETE" }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/activity.php';

requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true);
$archiveId = $data['id'] ?? null;
$confirm = $data['confirm'] ?? '';

if (!$archiveId) { respondError('Archive ID required.', null, 400); }
if ($confirm !== 'DELETE') {
    respondError('Type "DELETE" to confirm permanent deletion.', null, 400);
}

$stmt = $pdo->prepare('SELECT original_table FROM archives WHERE id = ?');
$stmt->execute([$archiveId]);
if (!$stmt->fetch()) { respondError('Archived record not found.', null, 404); }

$pdo->prepare('DELETE FROM archives WHERE id = ?')->execute([$archiveId]);
logActivity($pdo, 'archive.purge', 'archives', (int)$archiveId,
    "Archived record #$archiveId permanently deleted.", ['permanent' => true]);
respondSuccess('Record permanently deleted');
