<?php
// GET: ?date=YYYY-MM-DD&dentist_id=int
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/booking.php';

requireLogin();
requireRole(['patient']);

$rawDate = $_GET['date'] ?? '';
$date = is_string($rawDate) ? parseBookingDate($rawDate) : null;
$dentistId = filter_var($_GET['dentist_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$date || !$dentistId) respondError('Choose a valid date and dentist.', null, 400);

$open = isClinicOpenOn($pdo, $date);
$taken = $open ? takenSlots($pdo, $dentistId, $date->format('Y-m-d')) : [];
$slots = [];
foreach (bookingSlots($pdo) as $slot) {
    $reason = null;
    if (!$open) $reason = 'closed';
    elseif (slotHasPassed($date, $slot)) $reason = 'passed';
    elseif (in_array($slot, $taken, true)) $reason = 'booked';
    $slots[] = ['time' => $slot, 'available' => $reason === null, 'reason' => $reason];
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
respondSuccess('Slots retrieved', ['date' => $date->format('Y-m-d'), 'open' => $open, 'slots' => $slots]);