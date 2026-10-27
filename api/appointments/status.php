<?php
// api/appointments/status.php - Update appointment status (approve/complete/cancel)
// PATCH only: { "id": int, "status": "Approved|Completed|Cancelled" }
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

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

// Authorization: patient can only cancel their own; doctor/super-admin can change any
if ($user['role'] === 'patient') {
    if ($apt['patient_id'] !== $user['id']) {
        respondError('You can only modify your own appointments.', null, 403);
    }
    if ($newStatus !== 'Cancelled') {
        respondError('Patients can only cancel appointments.', null, 403);
    }
} elseif ($user['role'] === 'doctor') {
    if ($apt['dentist_user_id'] !== $user['id'] && $apt['dentist_user_id'] !== null) {
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
    'Pending'    => "Your appointment on {$apt['appointment_date']} at {$apt['time_slot']} is pending.",
];
$stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
$stmt->execute([$apt['patient_id'], $statusMessages[$newStatus]]);

// Notify dentist if approved/completed
if (in_array($newStatus, ['Approved', 'Completed']) && $apt['dentist_user_id']) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
    $stmt->execute([$apt['dentist_user_id'], "Appointment on {$apt['appointment_date']} at {$apt['time_slot']} is now $newStatus."]);
}

respondSuccess("Appointment status updated to $newStatus");
