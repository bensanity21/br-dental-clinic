<?php
// api/notifications/list.php - Get logged-in user's notifications + unread count
// GET only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
$user = currentUser();

$stmt = $pdo->prepare("
    SELECT id, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

// Unread count
$cnt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$cnt->execute([$user['id']]);
$unread = (int)$cnt->fetchColumn();

respondSuccess('Notifications retrieved', ['notifications' => $notifications, 'unread_count' => $unread]);
