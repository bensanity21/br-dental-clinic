<?php
// api/dentists/availability.php - Read and update a dentist's working hours
// GET   ?dentist_id=int  -> weekly rows + upcoming days off
// PATCH { dentist_id, availability:[{weekday,start_minute,end_minute}],
//         days_off:[{off_date,reason}] }
//
// A dentist edits only their own row; the Super Admin edits any of them.
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/booking.php';

requireLogin();
requireRole(['doctor', 'super-admin']);
$user = currentUser();

/** Resolve the dentist row this request is allowed to touch. */
function resolveDentistId(PDO $pdo, array $user, $requested): int {
    if ($user['role'] === 'doctor') {
        $stmt = $pdo->prepare('SELECT id FROM dentists WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $own = (int)($stmt->fetchColumn() ?: 0);
        if ($own === 0) {
            respondError('Your dentist profile is not linked to an account yet.', null, 404);
        }
        // A dentist can never widen their own reach by passing another id.
        if ($requested !== null && (int)$requested !== $own) {
            respondError('You can only manage your own availability.', null, 403);
        }
        return $own;
    }

    $requested = filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$requested) respondError('Choose a dentist.', null, 400);
    $stmt = $pdo->prepare('SELECT id FROM dentists WHERE id = ?');
    $stmt->execute([$requested]);
    if (!$stmt->fetch()) respondError('Dentist not found.', null, 404);
    return (int)$requested;
}

$dentistId = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $dentistId = resolveDentistId($pdo, $user, $_GET['dentist_id'] ?? null);

    $stmt = $pdo->prepare('SELECT weekday, start_minute, end_minute FROM dentist_availability WHERE dentist_id = ? ORDER BY weekday');
    $stmt->execute([$dentistId]);
    $availability = $stmt->fetchAll();

    // Upcoming days off only; past dates are history.
    $stmt = $pdo->prepare('
        SELECT off_date, reason
        FROM dentist_days_off
        WHERE dentist_id = ? AND off_date >= CURDATE()
        ORDER BY off_date ASC
    ');
    $stmt->execute([$dentistId]);
    $daysOff = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT id, full_name FROM dentists WHERE id = ?');
    $stmt->execute([$dentistId]);
    $dentist = $stmt->fetch();

    respondSuccess('Availability retrieved', [
        'dentist_id'   => $dentistId,
        'dentist_name' => $dentist['full_name'] ?? '',
        'availability' => $availability,
        'days_off'     => $daysOff,
        // Clinic hours, so the UI can show what "follow the clinic" means.
        'clinic_hours' => (function (PDO $pdo) {
            $text = (string)$pdo->query('SELECT operating_hours FROM clinic_schedules WHERE id = 1')->fetchColumn();
            [$open, $close] = operatingHourRange($text) ?? [480, 1020];
            return ['start_minute' => $open, 'end_minute' => $close];
        })($pdo),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    header('Allow: GET, PATCH');
    respondError('Method not allowed.', null, 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) respondError('Invalid request body.', null, 400);

$dentistId = resolveDentistId($pdo, $user, $data['dentist_id'] ?? null);

// ---- Validate the weekly availability rows -------------------------------
$availability = $data['availability'] ?? null;
if (!is_array($availability)) {
    respondError('Availability must be a list of working days.', null, 400);
}

$cleanAvailability = [];
$seenWeekdays = [];
foreach ($availability as $row) {
    if (!is_array($row)) respondError('Each availability entry must be an object.', null, 400);

    $weekday = (int)($row['weekday'] ?? 0);
    $start = (int)($row['start_minute'] ?? -1);
    $end = (int)($row['end_minute'] ?? -1);

    if ($weekday < 1 || $weekday > 7) {
        respondError('Weekday must be between 1 (Monday) and 7 (Sunday).', null, 400);
    }
    if (isset($seenWeekdays[$weekday])) {
        respondError('Each weekday can only appear once.', null, 400);
    }
    if ($start < 0 || $start > 1439 || $end < 0 || $end > 1440) {
        respondError('Working hours must be within a single day.', null, 400);
    }
    if ($end <= $start) {
        respondError('The end time must be after the start time.', null, 400);
    }
    if ($end - $start < BOOKING_SLOT_MINUTES) {
        respondError('Working hours must be at least ' . BOOKING_SLOT_MINUTES . ' minutes long.', null, 400);
    }

    $seenWeekdays[$weekday] = true;
    $cleanAvailability[] = ['weekday' => $weekday, 'start_minute' => $start, 'end_minute' => $end];
}

// ---- Validate the days off ----------------------------------------------
$daysOff = $data['days_off'] ?? [];
if (!is_array($daysOff)) {
    respondError('Days off must be a list.', null, 400);
}

$cleanDaysOff = [];
foreach ($daysOff as $row) {
    $offDate = is_array($row) ? trim((string)($row['off_date'] ?? '')) : '';
    $reason = is_array($row) ? trim((string)($row['reason'] ?? '')) : '';

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $offDate, new DateTimeZone(BOOKING_TZ));
    if (!$parsed || $parsed->format('Y-m-d') !== $offDate) {
        respondError('Enter each day off as YYYY-MM-DD.', null, 400);
    }
    if (mb_strlen($reason) > 150) {
        respondError('The day-off reason must be 150 characters or fewer.', null, 400);
    }
    $cleanDaysOff[$offDate] = $reason !== '' ? $reason : null;
}

// ---- Reject days off that already have live appointments -----------------
foreach (array_keys($cleanDaysOff) as $offDate) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments
        WHERE dentist_id = ? AND appointment_date = ?
          AND status IN ('Pending','Approved','Rescheduled')
    ");
    $stmt->execute([$dentistId, $offDate]);
    $live = (int)$stmt->fetchColumn();
    if ($live > 0) {
        respondError(
            "You still have $live appointment(s) on $offDate. Cancel or reschedule them before taking that day off.",
            ['off_date' => $offDate, 'appointments' => $live],
            409
        );
    }
}

// ---- Persist ------------------------------------------------------------
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM dentist_availability WHERE dentist_id = ?')->execute([$dentistId]);
    $insert = $pdo->prepare('
        INSERT INTO dentist_availability (dentist_id, weekday, start_minute, end_minute)
        VALUES (?, ?, ?, ?)
    ');
    foreach ($cleanAvailability as $row) {
        $insert->execute([$dentistId, $row['weekday'], $row['start_minute'], $row['end_minute']]);
    }

    $pdo->prepare('DELETE FROM dentist_days_off WHERE dentist_id = ?')->execute([$dentistId]);
    if ($cleanDaysOff) {
        $insertOff = $pdo->prepare('INSERT INTO dentist_days_off (dentist_id, off_date, reason) VALUES (?, ?, ?)');
        foreach ($cleanDaysOff as $offDate => $reason) {
            $insertOff->execute([$dentistId, $offDate, $reason]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Availability save failed: ' . $e->getMessage());
    respondError('Could not save your availability. Please try again.', null, 500);
}

respondSuccess('Availability saved', [
    'dentist_id'  => $dentistId,
    'working_days' => count($cleanAvailability),
    'days_off'    => count($cleanDaysOff),
]);
