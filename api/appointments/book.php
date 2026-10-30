<?php
// api/appointments/book.php - Book a new appointment with double-booking lock
// POST only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';
require_once '../../includes/notifications.php';
require_once '../../includes/booking.php';

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
$dateObj = is_string($date) ? parseBookingDate($date) : null;
if (!$dateObj || !is_string($timeSlot) || !in_array($timeSlot, bookingSlots($pdo), true)) {
    respondError('Enter a valid appointment date and time slot.', null, 400);
}
if (!isClinicOpenOn($pdo, $dateObj)) {
    respondError('The clinic is closed on that day. Please choose another date.', null, 422);
}
if (slotHasPassed($dateObj, $timeSlot)) {
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

$dateObj = is_string($date) ? parseBookingDate($date) : null;
if (!$dateObj || !is_string($timeSlot) || !in_array($timeSlot, bookingSlots($pdo), true)) {
    respondError('Enter a valid appointment date and time slot.', null, 400);
}
if (!isClinicOpenOn($pdo, $dateObj)) {
    respondError('The clinic is closed on that day. Please choose another date.', null, 422);
}
if (slotHasPassed($dateObj, $timeSlot)) {
    respondError('Cannot book appointments in the past.', null, 400);
}
// Lock the dentist row so two patients can't take the same slot at once
$pdo->beginTransaction();
try {
    $pdo->prepare('SELECT id FROM dentists WHERE id = ? FOR UPDATE')->execute([$dentistId]);
    if (in_array($timeSlot, takenSlots($pdo, (int)$dentistId, $date), true)) {
        $pdo->rollBack();
        respondError('This time slot is already booked. Please choose another time.', null, 409);
    }
    $stmt = $pdo->prepare("
        INSERT INTO appointments (patient_id, service_id, dentist_id, appointment_date, time_slot, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, 'Pending')
    ");
    $stmt->execute([$user['id'], $serviceId, $dentistId, $date, $timeSlot, $notes]);
    $appointmentId = $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Booking failed: ' . $e->getMessage());
    respondError('Booking failed.', null, 500);
}

// Create notification for the patient, respecting their saved preference.
createInAppNotification($pdo, (int)$user['id'], "Appointment booked for $date at $timeSlot is pending approval.");

// Notify admin/super-admin of new booking
$stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'super-admin' LIMIT 1");
$stmt->execute();
$admin = $stmt->fetch();
if ($admin) {
    createInAppNotification($pdo, (int)$admin['id'], "New appointment booking requested by {$user['full_name']} for $date at $timeSlot.");
}

respondSuccess('Appointment booked successfully', ['id' => $appointmentId, 'status' => 'Pending'], 201);
