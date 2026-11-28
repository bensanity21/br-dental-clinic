<?php
// api/appointments/status.php - Update appointment status
// PATCH only: { "id": int, "status": "Approved|Completed|Cancelled|No-show|Rescheduled|Pending" }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/notifications.php';
require_once '../../includes/activity.php';

requireLogin();
$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    respondError('Invalid request body.', null, 400);
}
$appointmentId = $data['id'] ?? null;
$newStatus     = $data['status'] ?? '';

$validStatuses = ['Pending', 'Approved', 'Completed', 'Cancelled', 'Rescheduled', 'No-show'];

if ((!is_int($appointmentId) && !(is_string($appointmentId) && ctype_digit($appointmentId)))
    || (int)$appointmentId < 1 || !is_string($newStatus) || !in_array($newStatus, $validStatuses, true)) {
    respondError('Valid appointment ID and status are required.', null, 400);
}
$appointmentId = (int)$appointmentId;

$snapshot = $pdo->prepare('SELECT status FROM appointments WHERE id = ?');
$snapshot->execute([$appointmentId]);
$initialStatus = $snapshot->fetchColumn();
if ($initialStatus === false) {
    respondError('Appointment not found.', null, 404);
}

$pdo->beginTransaction();
try {
    // Serialize all transitions for this appointment. A competing request
    // reads the committed status after this transaction releases the lock.
    $stmt = $pdo->prepare('SELECT id, patient_id, dentist_id, appointment_date, time_slot, status FROM appointments WHERE id = ? FOR UPDATE');
    $stmt->execute([$appointmentId]);
    $lockedAppointment = $stmt->fetch();
    if (!$lockedAppointment) {
        $pdo->rollBack();
        respondError('Appointment not found.', null, 404);
    }

    $stmt = $pdo->prepare("SELECT u.full_name AS patient_name, u.email AS patient_email, d.full_name AS dentist_name, d.user_id AS dentist_user_id FROM appointments a LEFT JOIN users u ON a.patient_id = u.id LEFT JOIN dentists d ON a.dentist_id = d.id WHERE a.id = ?");
    $stmt->execute([$appointmentId]);
    $names = $stmt->fetch() ?: [];
    $apt = array_merge($lockedAppointment, $names);
    if ($apt['status'] !== $initialStatus) {
        $pdo->rollBack();
        respondError('This appointment was updated by another request. Refresh and try again.', null, 409);
    }

// Which transitions make sense from the current status.
// A finished appointment is final, so its history cannot be rewritten.
$allowedTransitions = [
    'Pending'     => ['Approved', 'Cancelled', 'Rescheduled', 'No-show'],
    'Approved'    => ['Completed', 'Cancelled', 'Rescheduled', 'No-show'],
    'Rescheduled' => ['Pending', 'Approved', 'Cancelled', 'No-show'],
    'Completed'   => [],
    'Cancelled'   => [],
    'No-show'     => ['Pending'], // lets the clinic correct a mistaken no-show
];
$from = $apt['status'];
if (!in_array($newStatus, $allowedTransitions[$from] ?? [], true)) {
    $pdo->rollBack();
    respondError("An appointment that is already {$from} cannot be changed to {$newStatus}.", null, 422);
}

// Authorization
if ($user['role'] === 'patient') {
    // Patients may only cancel their own appointment.
    if ((int)$apt['patient_id'] !== (int)$user['id']) {
        $pdo->rollBack();
        respondError('You can only modify your own appointments.', null, 403);
    }
    if ($newStatus !== 'Cancelled') {
        $pdo->rollBack();
        respondError('Patients can only cancel their own appointments.', null, 403);
    }
} elseif ($user['role'] === 'doctor') {
    // Dentists only touch their own appointments and never set one back to Pending.
    if ((int)($apt['dentist_user_id'] ?? 0) !== (int)$user['id']) {
        $pdo->rollBack();
        respondError('You can only modify your own appointments.', null, 403);
    }
    if ($newStatus === 'Pending') {
        $pdo->rollBack();
        respondError('Dentists cannot set an appointment back to pending.', null, 403);
    }
}
// super-admin can perform any permitted transition

$stmt = $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ? AND status = ?');
$stmt->execute([$newStatus, $appointmentId, $from]);
if ($stmt->rowCount() !== 1) {
    $pdo->rollBack();
    respondError('This appointment was updated by another request. Refresh and try again.', null, 409);
}

if ($newStatus === 'Completed') {
    // The appointment-to-record link is unique, so retries cannot create a
    // duplicate clinical visit. Keep this insert in the same transaction as
    // the status update and audit event.
    $detailsStmt = $pdo->prepare("SELECT a.patient_id, a.dentist_id, a.appointment_date, a.time_slot,
            s.service_name, p.full_name AS patient_name, p.phone_number, p.phone_number_hash
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        JOIN users p ON p.id = a.patient_id
        WHERE a.id = ?");
    $detailsStmt->execute([$appointmentId]);
    $details = $detailsStmt->fetch();
    if (!$details) throw new RuntimeException('Completed appointment details could not be loaded.');
    $recordTime = null;
    if (preg_match('/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/', $details['time_slot'])) {
        $recordTime = $details['time_slot'];
    } else {
        foreach (['g:i A', 'g:i a', 'h:i A', 'h:i a'] as $format) {
            $parsedTime = DateTimeImmutable::createFromFormat('!' . $format, $details['time_slot']);
            if ($parsedTime) { $recordTime = $parsedTime->format('H:i:s'); break; }
        }
    }
    $recordStmt = $pdo->prepare("INSERT INTO dental_records
        (appointment_id, patient_id, patient_name, dentist_id, treatment_procedure, clinical_notes,
         record_date, record_time, patient_contact, patient_contact_index, status)
        VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 'Completed')
        ON DUPLICATE KEY UPDATE appointment_id = VALUES(appointment_id)");
    $recordStmt->execute([
        $appointmentId, $details['patient_id'], $details['patient_name'], $details['dentist_id'],
        $details['service_name'], $details['appointment_date'], $recordTime,
        $details['phone_number'], $details['phone_number_hash'],
    ]);
}

logActivity(
    $pdo,
    'appointment.status',
    'appointments',
    (int)$appointmentId,
    "Appointment #$appointmentId ({$apt['patient_name']}, {$apt['appointment_date']}) changed from {$from} to {$newStatus}.",
    ['from' => $from, 'to' => $newStatus]
);

$pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Appointment status update failed: ' . $e->getMessage());
    respondError('Could not update appointment status.', null, 500);
}

$when = "{$apt['appointment_date']} at {$apt['time_slot']}";

// Notify the patient of the change.
$statusMessages = [
    'Approved'    => "Your appointment on $when has been approved.",
    'Completed'   => "Your appointment on $when has been completed. Thank you for visiting.",
    'Cancelled'   => "Your appointment on $when has been cancelled.",
    'Rescheduled' => "Your appointment on $when has been rescheduled.",
    'No-show'     => "You were marked as a no-show for your appointment on $when. Please contact the clinic to rebook.",
    'Pending'     => "Your appointment on $when is pending approval.",
];
try {
    createInAppNotification($pdo, (int)$apt['patient_id'], $statusMessages[$newStatus]);

    // Keep the dentist and the clinic informed.
    if (in_array($newStatus, ['Approved', 'Completed', 'No-show'], true) && !empty($apt['dentist_user_id'])) {
        createInAppNotification(
            $pdo,
            (int)$apt['dentist_user_id'],
            "Appointment with {$apt['patient_name']} on $when is now $newStatus."
        );
    }
    if ($newStatus === 'No-show') {
        $admin = $pdo->query("SELECT id FROM users WHERE role = 'super-admin' LIMIT 1")->fetch();
        if ($admin) {
            createInAppNotification($pdo, (int)$admin['id'], "{$apt['patient_name']} did not attend the appointment on $when.");
        }
    }
} catch (Throwable $e) {
    error_log('Appointment status notification failed: ' . $e->getMessage());
}

respondSuccess("Appointment status updated to $newStatus");
