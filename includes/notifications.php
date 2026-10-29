<?php
// Insert an in-app notification, respecting the patient's saved preference.
function createInAppNotification(PDO $pdo, int $userId, string $message): bool {
    $stmt = $pdo->prepare("SELECT u.role, p.in_app_enabled
        FROM users u LEFT JOIN patient_notification_preferences p ON p.patient_id = u.id
        WHERE u.id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $recipient = $stmt->fetch();
    if (!$recipient) return false;
    if ($recipient['role'] === 'patient' && $recipient['in_app_enabled'] !== null && (int)$recipient['in_app_enabled'] === 0) {
        return false;
    }
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)');
    $stmt->execute([$userId, $message]);
    return true;
}
