<?php
// api/appointments/reschedule.php - Patient reschedules their own appointment
// PATCH only: { "id": int, "appointment_date": "YYYY-MM-DD", "time_slot": "h:i A" }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/booking.php';
require_once '../../includes/activity.php';
require_once '../../includes/notifications.php';

requireLogin();
requireRole(['patient']);
$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);
$appointmentId = $data['id'] ?? null;
$date = $data['appointment_date'] ?? '';
$timeSlot = $data['time_slot'] ?? '';

if (!$appointmentId || !is_string($date) || !is_string($timeSlot)) {
    respondError('Appointment ID, date, and time slot are required.', null, 400);
}

// Fetch the appointment and confirm ownership
$stmt = $pdo->prepare("
    SELECT a.id, a.patient_id, a.dentist_id, a.service_id, a.appointment_date, a.time_slot, a.status
    FROM appointments a
    WHERE a.id = ?
");
$stmt->execute([$appointmentId]);
$apt = $stmt->fetch();

if (!$apt) {
    respondError('Appointment not found.', null, 404);
}
if ((int)$apt['patient_id'] !== (int)$user['id']) {
    respondError('You can only modify your own appointments.', null, 403);
}
// Only Pending or Approved appointments can be rescheduled
if (!in_array($apt['status'], ['Pending', 'Approved'], true)) {
    respondError('Only pending or approved appointments can be rescheduled.', null, 422);
}

// Validate new date/time exactly like book.php
$dateObj = is_string($date) ? parseBookingDate($date) : null;
if (!$dateObj || !in_array($timeSlot, bookingSlots($pdo), true)) {
    respondError('Enter a valid appointment date and time slot.', null, 400);
}
if (!isClinicOpenOn($pdo, $dateObj)) {
    respondError('The clinic is closed on that day. Please choose another date.', null, 422);
}
// The appointment keeps its dentist, so their own hours must allow the new slot.
$rescheduleDentistId = (int)$apt['dentist_id'];
$dayOff = dentistIsOffOn($pdo, $rescheduleDentistId, $dateObj->format('Y-m-d'));
if ($dayOff !== null) {
    respondError('Your dentist is not available on that day' . ($dayOff !== 'Day off' ? " ($dayOff)" : '') . '. Please choose another date.', null, 422);
}
$dentistHours = dentistHoursForDate($pdo, $rescheduleDentistId, $dateObj);
if ($dentistHours === null) {
    respondError('Your dentist does not work on that day. Please choose another date.', null, 422);
}
$slotMinutes = (function (string $slot): int {
    $t = DateTimeImmutable::createFromFormat('!h:i A', $slot, new DateTimeZone(BOOKING_TZ));
    return $t ? ((int)$t->format('G') * 60 + (int)$t->format('i')) : -1;
})($timeSlot);
if ($slotMinutes < $dentistHours[0] || $slotMinutes + BOOKING_SLOT_MINUTES > $dentistHours[1]) {
    respondError('That time is outside your dentist\'s working hours. Please choose another time.', null, 422);
}
if (slotHasPassed($dateObj, $timeSlot)) {
    respondError('Cannot reschedule to a time in the past.', null, 400);
}

// Lock the dentist row, check the new slot is free (excluding this appointment),
// then update date/time and set status back to Pending for re-approval.
$pdo->beginTransaction();
try {
    $pdo->prepare('SELECT id FROM dentists WHERE id = ? FOR UPDATE')->execute([$apt['dentist_id']]);
    $taken = takenSlots($pdo, (int)$apt['dentist_id'], $dateObj->format('Y-m-d'));
    $taken = array_values(array_filter($taken, fn($t) => $t !== $apt['time_slot'] || $dateObj->format('Y-m-d') !== $apt['appointment_date']));
    if (in_array($timeSlot, $taken, true)) {
        $pdo->rollBack();
        respondError('This time slot is already booked. Please choose another time.', null, 409);
    }
    $stmt = $pdo->prepare('UPDATE appointments SET appointment_date = ?, time_slot = ?, status = ? WHERE id = ?');
    $stmt->execute([$dateObj->format('Y-m-d'), $timeSlot, 'Pending', $appointmentId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        respondError('This time slot was just taken. Please choose another time.', null, 409);
    }
    error_log('Reschedule failed: ' . $e->getMessage());
    respondError('Reschedule failed. Please try again.', null, 500);
}

// Notify the patient and the admin of the reschedule request
createInAppNotification($pdo, (int)$user['id'], "Your appointment has been rescheduled to $date at $timeSlot and is pending approval.");
$admin = $pdo->query("SELECT id FROM users WHERE role = 'super-admin' LIMIT 1")->fetch();
if ($admin) {
    createInAppNotification($pdo, (int)$admin['id'], "Appointment #$appointmentId was rescheduled by {$user['full_name']} to $date at $timeSlot.");
}

logActivity(
    $pdo,
    'appointment.reschedule',
    'appointments',
    (int)$appointmentId,
    "Appointment #$appointmentId moved from {$apt['appointment_date']} {$apt['time_slot']} to {$dateObj->format('Y-m-d')} $timeSlot.",
    ['from' => $apt['appointment_date'] . ' ' . $apt['time_slot'], 'to' => $dateObj->format('Y-m-d') . ' ' . $timeSlot]
);

respondSuccess('Appointment rescheduled', ['id' => (int)$appointmentId, 'appointment_date' => $dateObj->format('Y-m-d'), 'time_slot' => $timeSlot, 'status' => 'Pending']);
