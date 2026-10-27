<?php
// api/appointments/book.php - Book a new appointment with double-booking lock
// POST only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';

requireLogin();
requireRole(['patient']);

$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);

$serviceId  = $data['service_id'] ?? null;
$dentistId  = $data['dentist_id'] ?? null;
$date       = $data['appointment_date'] ?? '';
$timeSlot   = $data['time_slot'] ?? '';
$notes      = trim($data['notes'] ?? '');

// Validate required fields
if (!$serviceId || !$dentistId || !$date || !$timeSlot) {
    respondError('Service, dentist, date, and time slot are required.', null, 400);
}

// Validate date is not in the past
$today = date('Y-m-d');
if ($date < $today) {
    respondError('Cannot book appointments in the past.', null, 400);
}

// Validate service exists and is active
$stmt = $pdo->prepare('SELECT id FROM services WHERE id = ?');
$stmt->execute([$serviceId]);
if (!$stmt->fetch()) {
    respondError('Selected service does not exist.', null, 400);
}

// Validate dentist exists
$stmt = $pdo->prepare('SELECT id, user_id FROM dentists WHERE id = ?');
$stmt->execute([$dentistId]);
$dentist = $stmt->fetch();
if (!$dentist) {
    respondError('Selected dentist does not exist.', null, 400);
}

// DOUBLE-BOOKING LOCK: same dentist + same date + same time slot already booked (not cancelled)
$stmt = $pdo->prepare("
    SELECT id FROM appointments
    WHERE dentist_id = ? AND appointment_date = ? AND time_slot = ? AND status != 'Cancelled'
    LIMIT 1
");
$stmt->execute([$dentistId, $date, $timeSlot]);
if ($stmt->fetch()) {
    respondError('This time slot is already booked. Please choose another time.', null, 409);
}

// Insert appointment
$stmt = $pdo->prepare("
    INSERT INTO appointments (patient_id, service_id, dentist_id, appointment_date, time_slot, notes, status)
    VALUES (?, ?, ?, ?, ?, ?, 'Pending')
");
$stmt->execute([$user['id'], $serviceId, $dentistId, $date, $timeSlot, $notes]);
$appointmentId = $pdo->lastInsertId();

// Create notification for the patient
$stmt = $pdo->prepare("
    INSERT INTO notifications (user_id, message, is_read)
    VALUES (?, ?, 0)
");
$stmt->execute([$user['id'], "Appointment booked for $date at $timeSlot is pending approval."]);

// Notify admin/super-admin of new booking
$stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'super-admin' LIMIT 1");
$stmt->execute();
$admin = $stmt->fetch();
if ($admin) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
    $stmt->execute([$admin['id'], "New appointment booking requested by {$user['full_name']} for $date at $timeSlot."]);
}

respondSuccess('Appointment booked successfully', ['id' => $appointmentId, 'status' => 'Pending'], 201);
