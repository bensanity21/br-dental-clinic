<?php
// api/records/mine.php - Get dental records for logged-in patient
// GET only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/encrypt.php';

requireLogin();
requireRole(['patient', 'doctor', 'super-admin']);

$user = currentUser();

if ($user['role'] === 'patient') {
    $stmt = $pdo->prepare("
        SELECT dr.id, dr.record_date, dr.record_time, dr.status,
               COALESCE(dr.patient_name, p.full_name) AS patient_name,
               COALESCE(dr.patient_contact, p.phone_number) AS patient_contact,
               dr.treatment_procedure, dr.clinical_notes,
               d.full_name AS dentist_name, u.full_name AS doctor_name
        FROM dental_records dr
        LEFT JOIN users p ON dr.patient_id = p.id
        LEFT JOIN dentists d ON dr.dentist_id = d.id
        LEFT JOIN users u ON d.user_id = u.id
        WHERE dr.patient_id = ?
        ORDER BY dr.record_date DESC, dr.record_time DESC, dr.id DESC
    ");
    $stmt->execute([$user['id']]);
} else {
    if ($user['role'] === 'doctor') {
        $stmt = $pdo->prepare("
            SELECT dr.id, dr.record_date, dr.record_time, dr.status,
                   COALESCE(dr.patient_name, p.full_name) AS patient_name,
                   COALESCE(dr.patient_contact, p.phone_number) AS patient_contact,
                   dr.treatment_procedure, dr.clinical_notes,
                   d.full_name AS dentist_name
            FROM dental_records dr
            INNER JOIN dentists d ON dr.dentist_id = d.id
            LEFT JOIN users p ON dr.patient_id = p.id
            WHERE d.user_id = ?
            ORDER BY dr.record_date DESC, dr.record_time DESC, dr.id DESC
        ");
        $stmt->execute([$user['id']]);
    } else {
        // Super-admin can review all records.
        $stmt = $pdo->prepare("
        SELECT dr.id, dr.record_date, dr.record_time, dr.status,
               COALESCE(dr.patient_name, p.full_name) AS patient_name,
               COALESCE(dr.patient_contact, p.phone_number) AS patient_contact,
               dr.treatment_procedure, dr.clinical_notes,
               d.full_name AS dentist_name
        FROM dental_records dr
        LEFT JOIN users p ON dr.patient_id = p.id
        LEFT JOIN dentists d ON dr.dentist_id = d.id
        ORDER BY dr.record_date DESC, dr.record_time DESC, dr.id DESC
    ");
    $stmt->execute();
    }
}

$records = $stmt->fetchAll();
// Decrypt clinical notes for authorized viewers
foreach ($records as &$r) {
    if ($r['clinical_notes'] !== null) {
        $r['clinical_notes'] = decryptData($r['clinical_notes']);
    }
}
unset($r);

respondSuccess('Dental records retrieved', $records);
