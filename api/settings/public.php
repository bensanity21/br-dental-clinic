<?php
// api/settings/public.php - Public clinic details (no login required)
// GET only. Powers the clinic name, phone, address and hours shown on the
// public website and the patient pages, so admins only edit them in one place.
require_once '../../includes/db.php';
require_once '../../includes/response.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    respondError('Method not allowed.', null, 405);
}

// Clinic identity (name, phone, address)
$row = $pdo->query('SELECT clinic_name, phone_number, address FROM clinic_settings WHERE id = 1')->fetch();

$details = [
    'clinic_name'     => $row['clinic_name'] ?? CLINIC_NAME,
    'phone_number'    => $row['phone_number'] ?? CLINIC_PHONE,
    'address'         => $row['address'] ?? CLINIC_ADDRESS,
    'operating_days'  => 'Monday to Saturday',
    'operating_hours' => '8:00 AM - 5:00 PM',
];

// Operating days and hours live in a separate table.
$schedule = $pdo->query('SELECT operating_days, operating_hours FROM clinic_schedules WHERE id = 1')->fetch();
if ($schedule) {
    $details['operating_days']  = $schedule['operating_days'];
    $details['operating_hours'] = $schedule['operating_hours'];
}

// Build a plain Google Maps search link from the stored address so the site
// never hardcodes a stale pin.
$details['map_url'] = 'https://maps.google.com/?q=' . rawurlencode($details['address']);

// Public details change rarely; let browsers cache them briefly.
header('Cache-Control: public, max-age=300');

respondSuccess('Clinic details retrieved', $details);
