<?php
// api/notifications/read.php - Mark notification(s) as read
// POST: { id: int|null, mark_all: bool }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);
$markAll = $data['mark_all'] ?? false;

if ($markAll) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$user['id']]);
    respondSuccess('All notifications marked as read');
}

$id = $data['id'] ?? null;
if (!$id) { respondError('Notification ID required.', null, 400); }
$pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
respondSuccess('Notification marked as read');
