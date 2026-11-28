<?php
// api/users/patient-profile.php - Staff-only view of a patient's editable profile.
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';
require_once '../../includes/phone.php';

requireLogin();
requireRole(['super-admin']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) respondError('A valid patient ID is required.', null, 400);

$stmt = $pdo->prepare("SELECT id, full_name, email, phone_number, emergency_contact, home_address, date_of_birth, created_at FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
$stmt->execute([$id]);
$profile = $stmt->fetch();
if (!$profile) respondError('Patient not found.', null, 404);

$profile['phone_number'] = phoneForDisplay($profile['phone_number']);
foreach (['emergency_contact', 'home_address', 'date_of_birth'] as $field) {
    $profile[$field] = $profile[$field] !== null ? decryptData($profile[$field]) : null;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status = 'Completed'");
$stmt->execute([$id]);
$profile['completed_appointments'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT a.appointment_date, a.time_slot, a.status, s.service_name
    FROM appointments a LEFT JOIN services s ON s.id = a.service_id
    WHERE a.patient_id = ? AND a.appointment_date >= CURRENT_DATE()
      AND a.status IN ('Pending', 'Approved')
    ORDER BY a.appointment_date ASC, a.time_slot ASC, a.id ASC LIMIT 1");
$stmt->execute([$id]);
$profile['next_appointment'] = $stmt->fetch() ?: null;

$stmt = $pdo->prepare('SELECT record_date, treatment_procedure FROM dental_records WHERE patient_id = ? ORDER BY record_date DESC, id DESC LIMIT 1');
$stmt->execute([$id]);
$profile['latest_record'] = $stmt->fetch() ?: null;
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
respondSuccess('Patient profile retrieved.', $profile);
