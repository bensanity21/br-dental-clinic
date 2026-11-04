<?php
// includes/booking.php - Shared booking rules
require_once __DIR__ . '/db.php';

const BOOKING_TZ = 'Asia/Manila';


function parseBookingDate(string $date): ?DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(BOOKING_TZ));
    return ($d && $d->format('Y-m-d') === $date) ? $d : null;
}

// "Monday to Saturday", "Mon-Sat", "Mon, Wed, Fri" -> [1..7]. null = unreadable.
function operatingDayNumbers(string $text): ?array {
    $names = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    preg_match_all('/(mon|tue|wed|thu|fri|sat|sun)[a-z]*|\bto\b|[-–—]/u', strtolower($text), $m);
    $days = []; $prev = null; $range = false;
    foreach ($m[0] as $token) {
        $key = substr($token, 0, 3);
        if (isset($names[$key])) {
            $n = $names[$key];
            if ($range && $prev !== null) {
                for ($d = $prev; ; $d = $d % 7 + 1) { $days[$d] = true; if ($d === $n) break; }
            } else {
                $days[$n] = true;
            }
            $prev = $n; $range = false;
        } else {
            $range = true;
        }
    }
    return $days ? array_keys($days) : null;
}

function isClinicOpenOn(PDO $pdo, DateTimeImmutable $date): bool {
    $text = (string)$pdo->query('SELECT operating_days FROM clinic_schedules WHERE id = 1')->fetchColumn();
    $days = operatingDayNumbers($text);
    return $days === null || in_array((int)$date->format('N'), $days, true); // unreadable text = don't block
}

function slotHasPassed(DateTimeImmutable $date, string $slot): bool {
    $tz = new DateTimeZone(BOOKING_TZ);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d h:i A', $date->format('Y-m-d') . ' ' . $slot, $tz);
    return !$dt || $dt <= new DateTimeImmutable('now', $tz);
}

function takenSlots(PDO $pdo, int $dentistId, string $date): array {
    // Keep this list in step with the slot_key generated column: a Cancelled or
    // No-show appointment frees its time slot for someone else.
    $stmt = $pdo->prepare("
        SELECT time_slot
        FROM appointments
        WHERE dentist_id = ? AND appointment_date = ?
          AND status IN ('Pending','Approved','Rescheduled','Completed')
    ");
    $stmt->execute([$dentistId, $date]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

const BOOKING_SLOT_MINUTES = 60;   // change to 30 for half-hour slots

// "8:00 AM - 6:00 PM" -> [480, 1080] (minutes from midnight). null = unreadable.
function operatingHourRange(string $text): ?array {
    if (!preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)\s*(?:-|–|—|to)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i', $text, $m)) return null;
    $toMin = function (string $h, string $min, string $ap): int {
        $h = (int)$h % 12;
        if (strtolower($ap) === 'pm') $h += 12;
        return $h * 60 + (int)$min;
    };
    $open = $toMin($m[1], $m[2] ?: '0', $m[3]);
    $close = $toMin($m[4], $m[5] ?: '0', $m[6]);
    return $close > $open ? [$open, $close] : null;
}

function bookingSlots(PDO $pdo): array {
    $text = (string)$pdo->query('SELECT operating_hours FROM clinic_schedules WHERE id = 1')->fetchColumn();
    [$open, $close] = operatingHourRange($text) ?? operatingHourRange(CLINIC_HOURS);
    $base = new DateTimeImmutable('today', new DateTimeZone(BOOKING_TZ));
    $slots = [];
    for ($m = $open; $m + BOOKING_SLOT_MINUTES <= $close; $m += BOOKING_SLOT_MINUTES) {
        $slots[] = $base->setTime(intdiv($m, 60), $m % 60)->format('h:i A');
    }
    return $slots;
}

/**
 * A dentist's working window for one weekday.
 * Returns [startMinute, endMinute], or null when the dentist does not work
 * that day. A dentist with no availability rows at all follows the clinic
 * schedule, so accounts created before this feature keep working.
 */
function dentistHoursForDate(PDO $pdo, int $dentistId, DateTimeImmutable $date): ?array {
    $weekday = (int)$date->format('N');

    $stmt = $pdo->prepare('SELECT start_minute, end_minute FROM dentist_availability WHERE dentist_id = ? AND weekday = ?');
    $stmt->execute([$dentistId, $weekday]);
    $row = $stmt->fetch();

    if ($row) {
        return [(int)$row['start_minute'], (int)$row['end_minute']];
    }

    // No rows configured at all -> inherit the clinic hours for that weekday.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM dentist_availability WHERE dentist_id = ?');
    $stmt->execute([$dentistId]);
    if ((int)$stmt->fetchColumn() === 0 && isClinicOpenOn($pdo, $date)) {
        $text = (string)$pdo->query('SELECT operating_hours FROM clinic_schedules WHERE id = 1')->fetchColumn();
        return operatingHourRange($text) ?? operatingHourRange(CLINIC_HOURS);
    }

    return null; // configured, but not on this weekday
}

/** Is the dentist taking a specific dated day off? */
function dentistIsOffOn(PDO $pdo, int $dentistId, string $date): ?string {
    $stmt = $pdo->prepare('SELECT reason FROM dentist_days_off WHERE dentist_id = ? AND off_date = ?');
    $stmt->execute([$dentistId, $date]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return $row['reason'] ?: 'Day off';
}

/**
 * Bookable slots for one dentist on one date.
 * Each entry: ['time' => 'h:i A', 'available' => bool, 'reason' => ?string]
 * reason is one of: closed, off, passed, booked
 */
function dentistSlotsForDate(PDO $pdo, int $dentistId, DateTimeImmutable $date): array {
    $dateStr = $date->format('Y-m-d');

    // The clinic being closed or the dentist's own day off blocks everything.
    $clinicOpen = isClinicOpenOn($pdo, $date);
    $dayOff = $clinicOpen ? dentistIsOffOn($pdo, $dentistId, $dateStr) : null;
    $hours = ($clinicOpen && $dayOff === null) ? dentistHoursForDate($pdo, $dentistId, $date) : null;

    $taken = $hours ? takenSlots($pdo, $dentistId, $dateStr) : [];
    $base = new DateTimeImmutable('today', new DateTimeZone(BOOKING_TZ));

    $slots = [];
    foreach (bookingSlots($pdo) as $slot) {
        $reason = null;
        if (!$clinicOpen) {
            $reason = 'closed';
        } elseif ($dayOff !== null) {
            $reason = 'off';
        } elseif ($hours === null) {
            $reason = 'off';
        } else {
            // Slot must fit entirely inside the dentist's working window.
            $slotStart = DateTimeImmutable::createFromFormat('!h:i A', $slot, new DateTimeZone(BOOKING_TZ));
            $minuteOfDay = $slotStart ? ((int)$slotStart->format('G') * 60 + (int)$slotStart->format('i')) : -1;
            [$open, $close] = $hours;
            if ($minuteOfDay < $open || $minuteOfDay + BOOKING_SLOT_MINUTES > $close) {
                $reason = 'off';
            } elseif (slotHasPassed($date, $slot)) {
                $reason = 'passed';
            } elseif (in_array($slot, $taken, true)) {
                $reason = 'booked';
            }
        }
        $slots[] = ['time' => $slot, 'available' => $reason === null, 'reason' => $reason];
    }

    return $slots;
}
