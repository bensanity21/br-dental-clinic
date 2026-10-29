<?php
// GET/PATCH the signed-in patient's in-app notification preference.
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['patient']);
$user = currentUser();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT in_app_enabled FROM patient_notification_preferences WHERE patient_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $value = $stmt->fetchColumn();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        respondSuccess('Notification preference retrieved', ['in_app_enabled' => $value === false ? true : (bool)$value]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        header('Allow: GET, PATCH');
        respondError('Method not allowed.', null, 405);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !is_bool($data['in_app_enabled'] ?? null)) {
        respondError('Choose whether in-app notifications are enabled.', null, 400);
    }
    $stmt = $pdo->prepare('INSERT INTO patient_notification_preferences (patient_id, in_app_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE in_app_enabled = VALUES(in_app_enabled)');
    $stmt->execute([$user['id'], $data['in_app_enabled'] ? 1 : 0]);
    respondSuccess('Notification preference saved', ['in_app_enabled' => $data['in_app_enabled']]);
} catch (Throwable $e) {
    error_log('Patient notification preference request failed: ' . $e->getMessage());
    respondError('We could not save your notification preference. Please try again.', null, 500);
}
