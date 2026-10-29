<?php
// api/records/add.php - Add a dental record (doctor / super-admin only)
// POST: { patient_name, patient_id?, dentist_id, service_id, patient_contact, clinical_notes, record_date, record_time }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';

requireLogin();
requireRole(['doctor', 'super-admin']);

$user = currentUser();
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$patientId = $data['patient_id'] ?? null;
$patientName = trim((string)($data['patient_name'] ?? ''));
$dentistId = $data['dentist_id'] ?? null;
$serviceId = $data['service_id'] ?? null;
$contact = trim($data['patient_contact'] ?? '');
$notes = trim($data['clinical_notes'] ?? '');
$date = $data['record_date'] ?? date('Y-m-d');
$time = $data['record_time'] ?? '';

if ($patientName === '' || strlen($patientName) > 255
    || !filter_var($dentistId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    || !filter_var($serviceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    || strlen($contact) > 40) {
    respondError('Enter a patient name and choose a service and attending doctor. Contact number must be 40 characters or fewer.', null, 400);
}

if ($patientId !== null && $patientId !== '' && !filter_var($patientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    respondError('Patient account reference is invalid.', null, 400);
}

$dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$date);
if (!$dateValue || $dateValue->format('Y-m-d') !== $date) {
    respondError('Enter a valid clinical record date.', null, 400);
}
if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
    respondError('Enter a valid clinical record time.', null, 400);
}

$patientId = ($patientId === '' || $patientId === null) ? null : (int)$patientId;
if ($patientId !== null) {
    $patientStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $patientStmt->execute([$patientId]);
    if (!$patientStmt->fetch()) respondError('The selected patient account could not be found.', null, 404);
} elseif ($user['role'] === 'super-admin' && $contact !== '') {
    // Link a matching registered patient only when name + phone identify one
    // account uniquely; otherwise preserve this as a standalone record.
    $matchStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'patient' AND full_name = ? AND phone_number = ? LIMIT 2");
    $matchStmt->execute([$patientName, $contact]);
    $matches = $matchStmt->fetchAll();
    if (count($matches) === 1) $patientId = (int)$matches[0]['id'];
}

$serviceStmt = $pdo->prepare('SELECT service_name FROM services WHERE id = ? LIMIT 1');
$serviceStmt->execute([$serviceId]);
$service = $serviceStmt->fetch();
if (!$service) respondError('The selected service is no longer available.', null, 404);
$treatment = $service['service_name'];

$dentistExists = $pdo->prepare('SELECT id FROM dentists WHERE id = ? LIMIT 1');
$dentistExists->execute([$dentistId]);
if (!$dentistExists->fetch()) respondError('The selected attending doctor could not be found.', null, 404);

// Doctor can only add records for their own appointments' patients
if ($user['role'] === 'doctor') {
    $dentistStmt = $pdo->prepare('SELECT id FROM dentists WHERE user_id = ? LIMIT 1');
    $dentistStmt->execute([$user['id']]);
    $assignedDentist = $dentistStmt->fetch();
    if (!$assignedDentist || (int)$assignedDentist['id'] !== (int)$dentistId) {
        respondError('You can only add records as your own dentist account.', null, 403);
    }

    $patientStmt = $pdo->prepare("SELECT p.id FROM appointments a JOIN users p ON p.id = a.patient_id
        WHERE a.dentist_id = ? AND p.role = 'patient' AND p.full_name = ?
          AND (? = '' OR p.phone_number = ?) LIMIT 1");
    $patientStmt->execute([$assignedDentist['id'], $patientName, $contact, $contact]);
    $matchedPatient = $patientStmt->fetch();
    if (!$matchedPatient || ($patientId !== null && $patientId !== (int)$matchedPatient['id'])) {
        respondError('You can only add records for patients assigned to you.', null, 403);
    }
    $patientId = (int)$matchedPatient['id'];
}

// Encrypt sensitive clinical notes before saving
$encryptedNotes = $notes !== '' ? encryptData($notes) : null;

$stmt = $pdo->prepare("
    INSERT INTO dental_records (patient_id, patient_name, dentist_id, treatment_procedure, clinical_notes, record_date, record_time, patient_contact, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'In Progress')
");
$stmt->execute([$patientId, $patientName, $dentistId, $treatment, $encryptedNotes, $date, $time, $contact !== '' ? $contact : null]);

// Notify patient
if ($patientId !== null) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
    $stmt->execute([$patientId, "A new dental record was added on $date."]);
}

respondSuccess('Dental record added', ['id' => $pdo->lastInsertId()], 201);
