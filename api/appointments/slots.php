<?php
// api/appointments/slots.php - Bookable slots for one dentist on one date
// GET: ?date=YYYY-MM-DD&dentist_id=int
// Patients and the dentist themselves (or the Super Admin) may read this.
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/booking.php';

requireLogin();
requireRole(['patient', 'doctor', 'super-admin']);
$user = currentUser();

$rawDate = $_GET['date'] ?? '';
$date = is_string($rawDate) ? parseBookingDate($rawDate) : null;
$dentistId = filter_var($_GET['dentist_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$date || !$dentistId) respondError('Choose a valid date and dentist.', null, 400);

// A dentist may only inspect their own calendar.
if ($user['role'] === 'doctor') {
    $stmt = $pdo->prepare('SELECT id FROM dentists WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $ownDentistId = (int)($stmt->fetchColumn() ?: 0);
    if ($ownDentistId !== (int)$dentistId) {
        respondError('You can only view your own schedule.', null, 403);
    }
}

$clinicOpen = isClinicOpenOn($pdo, $date);
$dayOffReason = dentistIsOffOn($pdo, (int)$dentistId, $date->format('Y-m-d'));
$hours = ($clinicOpen && $dayOffReason === null) ? dentistHoursForDate($pdo, (int)$dentistId, $date) : null;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
respondSuccess('Slots retrieved', [
    'date'    => $date->format('Y-m-d'),
    'open'    => $clinicOpen && $hours !== null,
    'day_off' => $dayOffReason,
    'hours'   => $hours ? [
        'start' => sprintf('%02d:%02d', intdiv($hours[0], 60), $hours[0] % 60),
        'end'   => sprintf('%02d:%02d', intdiv($hours[1], 60), $hours[1] % 60),
    ] : null,
    'slots'   => dentistSlotsForDate($pdo, (int)$dentistId, $date),
]);
