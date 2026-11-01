<?php
// api/appointments/status.php - Update appointment status
// PATCH only: { "id": int, "status": "Approved|Completed|Cancelled|No-show|Rescheduled|Pending" }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/notifications.php';

requireLogin();
$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);
$appointmentId = $data['id'] ?? null;
$newStatus     = $data['status'] ?? '';

$validStatuses = ['Pending', 'Approved', 'Completed', 'Cancelled', 'Rescheduled', 'No-show'];

if (!$appointmentId || !in_array($newStatus, $validStatuses, true)) {
    respondError('Valid appointment ID and status are required.', null, 400);
}

// Fetch appointment + patient + dentist info
$stmt = $pdo->prepare("
    SELECT a.id, a.patient_id, a.dentist_id, a.appointment_date, a.time_slot, a.status,
           u.full_name AS patient_name, u.email AS patient_email,
           d.full_name AS dentist_name, d.user_id AS dentist_user_id
    FROM appointments a
    LEFT JOIN users u ON a.patient_id = u.id
    LEFT JOIN dentists d ON a.dentist_id = d.id
    WHERE a.id = ?
");
$stmt->execute([$appointmentId]);
$apt = $stmt->fetch();

if (!$apt) {
    respondError('Appointment not found.', null, 404);
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
    respondError("An appointment that is already {$from} cannot be changed to {$newStatus}.", null, 422);
}

// Authorization
if ($user['role'] === 'patient') {
    // Patients may only cancel their own appointment.
    if ((int)$apt['patient_id'] !== (int)$user['id']) {
        respondError('You can only modify your own appointments.', null, 403);
    }
    if ($newStatus !== 'Cancelled') {
        respondError('Patients can only cancel their own appointments.', null, 403);
    }
} elseif ($user['role'] === 'doctor') {
    // Dentists only touch their own appointments and never set one back to Pending.
    if ((int)($apt['dentist_user_id'] ?? 0) !== (int)$user['id']) {
        respondError('You can only modify your own appointments.', null, 403);
    }
    if ($newStatus === 'Pending') {
        respondError('Dentists cannot set an appointment back to pending.', null, 403);
    }
}
// super-admin can perform any permitted transition

$stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
$stmt->execute([$newStatus, $appointmentId]);

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

respondSuccess("Appointment status updated to $newStatus");
