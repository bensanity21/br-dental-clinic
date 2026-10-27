<?php
// api/appointments/mine.php - Get logged-in patient's own appointments
// GET only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['patient', 'doctor', 'super-admin']);

$user = currentUser();

$stmt = $pdo->prepare("
    SELECT a.id, a.appointment_date, a.time_slot, a.notes, a.status, a.created_at, a.updated_at,
           s.service_name, s.description, s.price,
           d.full_name AS dentist_name, d.specialization,
           u.full_name AS patient_name
    FROM appointments a
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN dentists d ON a.dentist_id = d.id
    LEFT JOIN users u ON a.patient_id = u.id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.time_slot DESC
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();

respondSuccess('Appointments retrieved', $appointments);
