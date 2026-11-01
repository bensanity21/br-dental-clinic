<?php
// api/dashboard/stats.php - KPI dashboard stats (role-aware)
// GET only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
$user = currentUser();

$today = date('Y-m-d');
$startMonth = date('Y-m-01');

/** Run a prepared COUNT query and return it as an int. */
function countQuery(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

if ($user['role'] === 'super-admin') {
    $totalPatients = countQuery($pdo, "SELECT COUNT(*) FROM users WHERE role = 'patient'");
    $todayAppts = countQuery($pdo, 'SELECT COUNT(*) FROM appointments WHERE appointment_date = ?', [$today]);
    $pending = countQuery($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Pending'");
    $activeDentists = countQuery($pdo, 'SELECT COUNT(*) FROM dentists');
    $completedMonth = countQuery($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Completed' AND appointment_date >= ?", [$startMonth]);
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date >= ? AND a.status IN ('Pending','Approved')
        ORDER BY a.appointment_date ASC, a.time_slot ASC
        LIMIT 10
    ");
    $stmt->execute([$today]);
    $upcoming = $stmt->fetchAll();
} elseif ($user['role'] === 'doctor') {
    $stmt = $pdo->prepare('SELECT id FROM dentists WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $dentist = $stmt->fetch();
    $dId = $dentist ? (int)$dentist['id'] : 0;
    $totalPatients = countQuery($pdo, 'SELECT COUNT(DISTINCT patient_id) FROM appointments WHERE dentist_id = ?', [$dId]);
    $todayAppts = countQuery($pdo, 'SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND dentist_id = ?', [$today, $dId]);
    $pending = countQuery($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND dentist_id = ?", [$dId]);
    $activeDentists = 1;
    $completedMonth = countQuery($pdo, "SELECT COUNT(*) FROM appointments WHERE status = 'Completed' AND appointment_date >= ? AND dentist_id = ?", [$startMonth, $dId]);
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.dentist_id = ? AND a.appointment_date >= ? AND a.status IN ('Pending','Approved')
        ORDER BY a.appointment_date ASC, a.time_slot ASC
        LIMIT 10
    ");
    $stmt->execute([$dId, $today]);
    $upcoming = $stmt->fetchAll();
} else {
    // patient
    $totalPatients = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND patient_id = ?');
    $stmt->execute([$today, $user['id']]);
    $todayAppts = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND patient_id = ?");
    $stmt->execute([$user['id']]);
    $pending = (int)$stmt->fetchColumn();
    $activeDentists = countQuery($pdo, 'SELECT COUNT(*) FROM dentists');
    $completedMonth = 0;
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, s.service_name
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.patient_id = ? AND a.appointment_date >= ? AND a.status IN ('Pending','Approved')
        ORDER BY a.appointment_date ASC, a.time_slot ASC
        LIMIT 10
    ");
    $stmt->execute([$user['id'], $today]);
    $upcoming = $stmt->fetchAll();
}

respondSuccess('Dashboard stats', [
    'total_patients' => $totalPatients,
    'today_appointments' => $todayAppts,
    'active_dentists' => $activeDentists,
    'pending_requests' => $pending,
    'completed_this_month' => $completedMonth,
    'upcoming' => $upcoming,
]);
