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
    $stmt = $pdo->prepare("SELECT time_slot FROM appointments WHERE dentist_id = ? AND appointment_date = ? AND status != 'Cancelled'");
    $stmt->execute([$dentistId, $date]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

const BOOKING_SLOT_MINUTES = 60;   // change to 30 for half-hour slots

// "8:00 AM - 5:00 PM" -> [480, 1020] (minutes from midnight). null = unreadable.
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
    [$open, $close] = operatingHourRange($text) ?? [8 * 60, 17 * 60];
    $base = new DateTimeImmutable('today', new DateTimeZone(BOOKING_TZ));
    $slots = [];
    for ($m = $open; $m + BOOKING_SLOT_MINUTES <= $close; $m += BOOKING_SLOT_MINUTES) {
        $slots[] = $base->setTime(intdiv($m, 60), $m % 60)->format('h:i A');
    }
    return $slots;
}