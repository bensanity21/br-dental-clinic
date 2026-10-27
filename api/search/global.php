<?php
// api/search/global.php - Global search across appointments, services, patients, dentists
// GET: ?q=search term (role-aware results)
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
$user = currentUser();

$q = trim($_GET['q'] ?? '');
if (!$q) {
    respondSuccess('Search results', ['appointments' => [], 'services' => [], 'patients' => [], 'dentists' => []]);
}

$like = '%' . $q . '%';
$results = ['appointments' => [], 'services' => [], 'patients' => [], 'dentists' => []];

// Services — public, active
$stmt = $pdo->prepare("SELECT id, service_name, description, price FROM services WHERE service_name LIKE ?");
$stmt->execute([$like]);
$results['services'] = $stmt->fetchAll();

// Dentists — public
$stmt = $pdo->prepare("SELECT d.id, d.full_name, d.specialization FROM dentists d WHERE d.full_name LIKE ?");
$stmt->execute([$like]);
$results['dentists'] = $stmt->fetchAll();

// Patients — admin/doctor only; patient sees only self
if ($user['role'] === 'super-admin') {
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role = 'patient' AND full_name LIKE ? LIMIT 20");
    $stmt->execute([$like]);
} elseif ($user['role'] === 'doctor') {
    $dentist = $pdo->query("SELECT id FROM dentists WHERE user_id = {$user['id']} LIMIT 1")->fetch();
    $dId = $dentist ? (int)$dentist['id'] : 0;
    $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.full_name, u.email FROM users u INNER JOIN appointments a ON a.patient_id = u.id WHERE a.dentist_id = $dId AND u.full_name LIKE ? LIMIT 20");
    $stmt->execute([$like]);
} else {
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND full_name LIKE ? LIMIT 20");
    $stmt->execute([$user['id'], $like]);
}
$results['patients'] = $stmt->fetchAll();

// Appointments — role-aware
if ($user['role'] === 'super-admin') {
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE u.full_name LIKE ? OR s.service_name LIKE ?
        LIMIT 20
    ");
    $stmt->execute([$like, $like]);
} elseif ($user['role'] === 'doctor') {
    $dentist = $pdo->query("SELECT id FROM dentists WHERE user_id = {$user['id']} LIMIT 1")->fetch();
    $dId = $dentist ? (int)$dentist['id'] : 0;
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.dentist_id = $dId AND (u.full_name LIKE ? OR s.service_name LIKE ?)
        LIMIT 20
    ");
    $stmt->execute([$like, $like]);
} else {
    // patient: search their own appointments by service name
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, s.service_name
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.patient_id = ? AND s.service_name LIKE ?
        LIMIT 20
    ");
    $stmt->execute([$user['id'], $like]);
}

respondSuccess('Search results', $results);
