<?php
// api/reports/generate.php - Admin reports by date range
// GET: ?from=YYYY-MM-DD&to=YYYY-MM-DD&type=appointments|services|dentists|patients|cancellations
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['super-admin']);

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'appointments';

if ($type === 'appointments') {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as total
        FROM appointments
        WHERE appointment_date BETWEEN ? AND ?
        GROUP BY status
    ");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();
    $title = 'Appointments Summary by Status';
} elseif ($type === 'services') {
    $stmt = $pdo->prepare("
        SELECT s.service_name, COUNT(a.id) as total
        FROM services s
        LEFT JOIN appointments a ON a.service_id = s.id AND a.appointment_date BETWEEN ? AND ?
        WHERE a.id IS NOT NULL
        GROUP BY s.id
        ORDER BY total DESC
    ");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();
    $title = 'Appointments per Service';
} elseif ($type === 'dentists') {
    $stmt = $pdo->prepare("
        SELECT d.full_name, COUNT(a.id) as total
        FROM dentists d
        LEFT JOIN appointments a ON a.dentist_id = d.id AND a.appointment_date BETWEEN ? AND ?
        WHERE a.id IS NOT NULL
        GROUP BY d.id
        ORDER BY total DESC
    ");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();
    $title = 'Appointments per Dentist';
} elseif ($type === 'patients') {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, created_at
        FROM users
        WHERE role = 'patient' AND DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();
    $title = 'New Patients Registered';
} elseif ($type === 'cancellations') {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as total
        FROM appointments
        WHERE appointment_date BETWEEN ? AND ? AND status IN ('Cancelled', 'No-show')
        GROUP BY status
    ");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetchAll();
    $title = 'Cancelled / No-show Appointments';
} else {
    respondError('Invalid report type.', null, 400);
}

respondSuccess("$title ($from to $to)", ['type' => $type, 'from' => $from, 'to' => $to, 'data' => $data]);
