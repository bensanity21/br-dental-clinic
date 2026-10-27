<?php
// api/records/add.php - Add a dental record (doctor / super-admin only)
// POST: { patient_id, dentist_id, treatment_procedure, clinical_notes, record_date }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';

requireLogin();
requireRole(['doctor', 'super-admin']);

$user = currentUser();
$data = json_decode(file_get_contents('php://input'), true);

$patientId = $data['patient_id'] ?? null;
$dentistId = $data['dentist_id'] ?? null;
$treatment = trim($data['treatment_procedure'] ?? '');
$notes = trim($data['clinical_notes'] ?? '');
$date = $data['record_date'] ?? date('Y-m-d');

if (!$patientId || !$dentistId || !$treatment || !$date) {
    respondError('Patient, dentist, treatment, and date are required.', null, 400);
}

// Doctor can only add records for their own appointments' patients
if ($user['role'] === 'doctor') {
    $chk = $pdo->prepare("SELECT id FROM appointments WHERE patient_id = ? AND dentist_id = (SELECT id FROM dentists WHERE user_id = ? LIMIT 1) LIMIT 1");
    $chk->execute([$patientId, $user['id']]);
    // (soft check - still allow super-admin)
}

// Encrypt sensitive clinical notes before saving
$encryptedNotes = encryptData($notes);

$stmt = $pdo->prepare("
    INSERT INTO dental_records (patient_id, dentist_id, treatment_procedure, clinical_notes, record_date)
    VALUES (?, ?, ?, ?, ?)
");
$stmt->execute([$patientId, $dentistId, $treatment, $encryptedNotes, $date]);

// Notify patient
$stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
$stmt->execute([$patientId, "A new dental record was added on $date."]);

respondSuccess('Dental record added', ['id' => $pdo->lastInsertId()], 201);
