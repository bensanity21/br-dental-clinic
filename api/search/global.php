<?php
// api/search/global.php - Role-aware search across clinic data
// GET: ?q=search term (role-aware results)
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['patient', 'doctor', 'super-admin']);
$user = currentUser();

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 100, 'UTF-8');
if ($q === '') {
    respondSuccess('Search results', ['appointments' => [], 'services' => [], 'patients' => [], 'dentists' => []]);
}

$like = '%' . $q . '%';
$results = ['appointments' => [], 'services' => [], 'patients' => [], 'dentists' => []];

// Search all user-facing service fields, including price.
$stmt = $pdo->prepare("SELECT id, service_name, description, price FROM services WHERE service_name LIKE ? OR description LIKE ? OR CAST(price AS CHAR) LIKE ? LIMIT 20");
$stmt->execute([$like, $like, $like]);
$results['services'] = $stmt->fetchAll();

// Dentists — public
$stmt = $pdo->prepare("SELECT d.id, d.full_name, d.specialization FROM dentists d WHERE d.full_name LIKE ? OR d.specialization LIKE ? LIMIT 20");
$stmt->execute([$like, $like]);
$results['dentists'] = $stmt->fetchAll();

// Patient data is limited to the signed-in patient's own profile or all patients
// for the Master Admin. Doctors find assigned patients through appointment results.
if ($user['role'] === 'super-admin') {
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role = 'patient' AND (full_name LIKE ? OR email LIKE ?) LIMIT 20");
    $stmt->execute([$like, $like]);
    $results['patients'] = $stmt->fetchAll();
} elseif ($user['role'] === 'patient') {
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND (full_name LIKE ? OR email LIKE ?) LIMIT 20");
    $stmt->execute([$user['id'], $like, $like]);
    $results['patients'] = $stmt->fetchAll();
}

// Appointments — role-aware
if ($user['role'] === 'super-admin') {
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE u.full_name LIKE ? OR u.email LIKE ? OR s.service_name LIKE ? OR s.description LIKE ?
          OR a.status LIKE ? OR CAST(a.appointment_date AS CHAR) LIKE ? OR a.time_slot LIKE ? OR a.notes LIKE ?
        LIMIT 20
    ");
    $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like]);
} elseif ($user['role'] === 'doctor') {
    $dentistStmt = $pdo->prepare('SELECT id FROM dentists WHERE user_id = ? LIMIT 1');
    $dentistStmt->execute([$user['id']]);
    $dentist = $dentistStmt->fetch();
    $dId = $dentist ? (int)$dentist['id'] : 0;
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, u.full_name AS patient_name, s.service_name
        FROM appointments a
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.dentist_id = ? AND (u.full_name LIKE ? OR u.email LIKE ? OR s.service_name LIKE ? OR s.description LIKE ?
          OR a.status LIKE ? OR CAST(a.appointment_date AS CHAR) LIKE ? OR a.time_slot LIKE ? OR a.notes LIKE ?)
        LIMIT 20
    ");
    $stmt->execute([$dId, $like, $like, $like, $like, $like, $like, $like, $like]);
} else {
    // Patients only search their own appointments and notes.
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.status, s.service_name
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.patient_id = ? AND (s.service_name LIKE ? OR s.description LIKE ? OR a.status LIKE ?
          OR CAST(a.appointment_date AS CHAR) LIKE ? OR a.time_slot LIKE ? OR a.notes LIKE ?)
        LIMIT 20
    ");
    $stmt->execute([$user['id'], $like, $like, $like, $like, $like, $like]);
}

respondSuccess('Search results', $results);
