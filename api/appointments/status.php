<?php
// api/appointments/status.php - Update appointment status (approve/complete/cancel)
// PATCH only: { "id": int, "status": "Approved|Completed|Cancelled" }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/notifications.php';

requireLogin();
$user = currentUser();

$data = json_decode(file_get_contents('php://input'), true);
$appointmentId = $data['id'] ?? null;
$newStatus     = $data['status'] ?? '';

if (!$appointmentId || !in_array($newStatus, ['Pending', 'Approved', 'Completed', 'Cancelled'], true)) {
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

// Authorization: patient can cancel their own, and reschedule their own Pending/Approved
if ($user['role'] === 'patient') {
    if ((int)$apt['patient_id'] !== (int)$user['id']) {
        respondError('You can only modify your own appointments.', null, 403);
    }
    if ($newStatus !== 'Cancelled') {
        // Allow a patient to reschedule their own Pending or Approved appointment
        if ($newStatus !== 'Rescheduled' || !in_array($apt['status'], ['Pending', 'Approved'], true)) {
            respondError('Patients can only cancel or reschedule their own appointments.', null, 403);
        }
    }
} elseif ($user['role'] === 'doctor') {
    if ((int)($apt['dentist_user_id'] ?? 0) !== (int)$user['id']) {
        respondError('You can only modify your own appointments.', null, 403);
    }
}
// super-admin can modify any

// Update status
$stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
$stmt->execute([$newStatus, $appointmentId]);

// Notify patient of status change
$statusMessages = [
    'Approved'   => "Your appointment on {$apt['appointment_date']} at {$apt['time_slot']} has been approved.",
    'Completed'  => "Your appointment on {$apt['appointment_date']} at {$apt['time_slot']} is marked completed.",
    'Cancelled'  => "Your appointment on {$apt['appointment_date']} at {$apt['time_slot']} has been cancelled.",
    'Rescheduled' => "Your appointment on {$apt['appointment_date']} at {$apt['time_slot']} has been rescheduled.",
    'Pending'    => "Your appointment on {$apt['appointment_date']} at {$apt['time_slot']} is pending.",
];
createInAppNotification($pdo, (int)$apt['patient_id'], $statusMessages[$newStatus]);

// Notify dentist if approved/completed
if (in_array($newStatus, ['Approved', 'Completed']) && $apt['dentist_user_id']) {
    createInAppNotification($pdo, (int)$apt['dentist_user_id'], "Appointment on {$apt['appointment_date']} at {$apt['time_slot']} is now $newStatus.");
}

respondSuccess("Appointment status updated to $newStatus");
