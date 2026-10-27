<?php
// api/appointments/all.php - Get all appointments (admin/doctor only)
// GET only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['doctor', 'super-admin']);

$user = currentUser();

// Dentists see only their own appointments; super-admin sees all
if ($user['role'] === 'doctor') {
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.notes, a.status, a.created_at,
               s.service_name, s.price,
               u.full_name AS patient_name, u.email AS patient_email, u.phone_number AS patient_phone,
               d.full_name AS dentist_name
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN dentists d ON a.dentist_id = d.id
        WHERE d.user_id = ?
        ORDER BY a.appointment_date DESC, a.time_slot DESC
    ");
    $stmt->execute([$user['id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT a.id, a.appointment_date, a.time_slot, a.notes, a.status, a.created_at,
               s.service_name, s.price,
               u.full_name AS patient_name, u.email AS patient_email, u.phone_number AS patient_phone,
               d.full_name AS dentist_name
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN users u ON a.patient_id = u.id
        LEFT JOIN dentists d ON a.dentist_id = d.id
        ORDER BY a.appointment_date DESC, a.time_slot DESC
    ");
    $stmt->execute();
}

$appointments = $stmt->fetchAll();
respondSuccess('All appointments retrieved', $appointments);
