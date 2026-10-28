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

if ($user['role'] === 'super-admin') {
    $totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'patient'")->fetchColumn();
    $todayAppts = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = '$today'")->fetchColumn();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Pending'")->fetchColumn();
    $activeDentists = (int)$pdo->query("SELECT COUNT(*) FROM dentists")->fetchColumn();
    $completedMonth = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Completed' AND appointment_date >= '$startMonth'")->fetchColumn();
    $upcoming = $pdo->query("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date >= '$today' AND a.status IN ('Pending','Approved')
        ORDER BY a.appointment_date ASC, a.time_slot ASC
        LIMIT 10
    ")->fetchAll();
} elseif ($user['role'] === 'doctor') {
    $dentist = $pdo->query("SELECT id FROM dentists WHERE user_id = {$user['id']} LIMIT 1")->fetch();
    $dId = $dentist ? (int)$dentist['id'] : 0;
    $totalPatients = (int)$pdo->query("SELECT COUNT(DISTINCT patient_id) FROM appointments WHERE dentist_id = $dId")->fetchColumn();
    $todayAppts = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = '$today' AND dentist_id = $dId")->fetchColumn();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND dentist_id = $dId")->fetchColumn();
    $activeDentists = 1;
    $completedMonth = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Completed' AND appointment_date >= '$startMonth' AND dentist_id = $dId")->fetchColumn();
    $upcoming = $pdo->query("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.dentist_id = $dId AND a.appointment_date >= '$today' AND a.status IN ('Pending','Approved')
        ORDER BY a.appointment_date ASC, a.time_slot ASC
        LIMIT 10
    ")->fetchAll();
} else {
    // patient
    $totalPatients = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND patient_id = ?');
    $stmt->execute([$today, $user['id']]);
    $todayAppts = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND patient_id = ?");
    $stmt->execute([$user['id']]);
    $pending = (int)$stmt->fetchColumn();
    $activeDentists = (int)$pdo->query('SELECT COUNT(*) FROM dentists')->fetchColumn();
    $completedMonth = 0;
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, s.service_name
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.patient_id = ? AND a.appointment_date >= '$today' AND a.status IN ('Pending','Approved')
        ORDER BY a.appointment_date ASC, a.time_slot ASC
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
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
