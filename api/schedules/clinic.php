<?php
// api/schedules/clinic.php - Clinic operating days/hours (settings)
// GET: read | POST/PATCH: update (super-admin only)
require_once '../../includes/db.php';
require_once '../../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT operating_days, operating_hours, updated_at FROM clinic_schedules WHERE id = 1');
    $row = $stmt->fetch();
    if (!$row) {
        respondSuccess('No schedule set', ['operating_days' => 'Mon-Sat', 'operating_hours' => '8:00 AM - 6:00 PM']);
    }
    respondSuccess('Schedule retrieved', $row);
}

require_once '../../includes/auth.php';
requireLogin();
requireRole(['super-admin']);

$data = json_decode(file_get_contents('php://input'), true);
$days = trim($data['operating_days'] ?? '');
$hours = trim($data['operating_hours'] ?? '');
if (!$days || !$hours) {
    respondError('Operating days and hours are required.', null, 400);
}

// Upsert
$exists = $pdo->query('SELECT id FROM clinic_schedules WHERE id = 1')->fetch();
if ($exists) {
    $pdo->prepare('UPDATE clinic_schedules SET operating_days = ?, operating_hours = ? WHERE id = 1')->execute([$days, $hours]);
} else {
    $pdo->prepare('INSERT INTO clinic_schedules (id, operating_days, operating_hours) VALUES (1, ?, ?)')
        ->execute([$days, $hours]);
}
respondSuccess('Schedule updated');
