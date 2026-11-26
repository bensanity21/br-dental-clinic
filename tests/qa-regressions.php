<?php
/** Focused HTTP and helper regressions for the QA audit remediation. */

function runQaRegressionTests(TestRunner $t): void
{
    $t->suite('QA audit regressions');
    $pdo = testPdoFor(TEST_DB);
    require_once testProjectRoot() . '/includes/password_reset.php';
    require_once testProjectRoot() . '/includes/booking.php';

    // Password reset confirmation is required and a bad confirmation must not
    // consume the valid token.
    $janeId = (int)$pdo->query("SELECT id FROM users WHERE email = 'jane@test.com'")->fetchColumn();
    $anon = new TestClient('qa-reset');
    $rawToken = createPasswordReset($pdo, $janeId, '127.0.0.1');
    [$status, $body] = $anon->post('api/auth/password-reset-confirm.php', [
        'token' => $rawToken,
        'new_password' => 'AuditPassword123!',
    ]);
    $t->eq(422, $status, 'reset rejects missing confirmation');
    $t->eq(false, $body['success'] ?? null, 'missing confirmation returns an error response');
    [$status, $body] = $anon->post('api/auth/password-reset-confirm.php', [
        'token' => $rawToken,
        'new_password' => 'AuditPassword123!',
        'confirm_password' => 'MismatchPassword123!',
    ]);
    $t->eq(422, $status, 'reset rejects mismatched confirmation');
    [$status] = $anon->post('api/auth/password-reset-confirm.php', [
        'token' => $rawToken,
        'new_password' => 'AuditPassword123!',
        'confirm_password' => '',
    ]);
    $t->eq(422, $status, 'reset rejects empty confirmation');
    $t->ok(findUsableReset($pdo, $rawToken) !== null, 'invalid confirmation does not consume reset token');
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?")->execute([hash('sha256', $rawToken)]);

    // Pick an available future slot before deactivating the service fixture.
    $serviceId = (int)$pdo->query('SELECT id FROM services WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $dentistId = (int)$pdo->query('SELECT id FROM dentists ORDER BY id LIMIT 1')->fetchColumn();
    $patient = new TestClient('qa-booking');
    $patient->login('jane@test.com', 'TestPass123!');
    [$status, $body] = $patient->post('api/appointments/book.php', [
        'service_id' => $serviceId,
        'dentist_id' => $dentistId,
        'appointment_date' => (new DateTimeImmutable('+30 days', new DateTimeZone(BOOKING_TZ)))->format('Y-m-d'),
        'time_slot' => '09:00 AM',
        'notes' => [],
    ]);
    $t->eq(400, $status, 'booking rejects array input for notes before availability checks');

    $booking = null;
    for ($offset = 10; $offset <= 60 && $booking === null; $offset++) {
        $date = new DateTimeImmutable('+' . $offset . ' days', new DateTimeZone(BOOKING_TZ));
        if (!isClinicOpenOn($pdo, $date)) continue;
        foreach (dentistSlotsForDate($pdo, $dentistId, $date) as $slot) {
            if ($slot['available']) {
                $booking = [
                    'service_id' => $serviceId,
                    'dentist_id' => $dentistId,
                    'appointment_date' => $date->format('Y-m-d'),
                    'time_slot' => $slot['time'],
                    'notes' => 'QA regression fixture',
                ];
                break;
            }
        }
    }
    if ($booking === null) {
        $t->ok(false, 'test fixture has an available future booking slot');
    } else {
        $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = ?')->execute([$serviceId]);
        try {
            [$status, $body] = $patient->post('api/appointments/book.php', $booking);
            $t->eq(422, $status, 'booking rejects an inactive service');
            $t->eq(false, $body['success'] ?? null, 'inactive service booking returns an error');
        } finally {
            $pdo->prepare('UPDATE services SET is_active = 1 WHERE id = ?')->execute([$serviceId]);
        }
    }

    // Service management validates string fields before trim()/SQL.
    $admin = new TestClient('qa-admin');
    $admin->login('admin@brdental.com', 'TestPass123!');
    [$status] = $admin->post('api/services/manage.php', [
        'service_name' => [],
        'description' => 'Malformed type regression',
        'price' => 100,
        'duration_minutes' => 30,
    ]);
    $t->eq(422, $status, 'service endpoint rejects array string fields without a server error');

    // Invalid schedules must fail closed; always restore the original setting.
    $schedule = $pdo->query('SELECT operating_days FROM clinic_schedules WHERE id = 1')->fetchColumn();
    try {
        $t->eq([1, 2, 3, 4, 5, 6], operatingDayNumbers((string)$schedule), 'seeded Monday-to-Saturday clinic schedule parses');
        $t->eq(null, operatingDayNumbers(''), 'empty operating days are invalid');
        $t->eq(null, operatingDayNumbers('closed whenever'), 'unparseable operating days are invalid');
        $t->eq([1], operatingDayNumbers('Monday'), 'valid day names still parse');
        $pdo->prepare('UPDATE clinic_schedules SET operating_days = ? WHERE id = 1')->execute(['Mon']);
        $t->eq(false, isClinicOpenOn($pdo, new DateTimeImmutable('next tuesday', new DateTimeZone(BOOKING_TZ))), 'configured closed weekdays remain closed');
        $pdo->prepare('UPDATE clinic_schedules SET operating_days = ? WHERE id = 1')->execute(['not a schedule']);
        $probeDate = new DateTimeImmutable('next monday', new DateTimeZone(BOOKING_TZ));
        $t->eq(false, isClinicOpenOn($pdo, $probeDate), 'invalid clinic schedule blocks booking');
    } finally {
        $pdo->prepare('UPDATE clinic_schedules SET operating_days = ? WHERE id = 1')->execute([$schedule]);
    }

    // Pagination keeps data as a flat array and caps limits at 200.
    [$status, $body] = $admin->get('api/appointments/all.php?page=1&limit=2');
    $t->eq(200, $status, 'appointment pagination request succeeds');
    $t->ok(is_array($body['data'] ?? null), 'appointment response data remains a flat array');
    $t->eq(1, $body['pagination']['page'] ?? null, 'pagination metadata includes page');
    $t->eq(2, $body['pagination']['limit'] ?? null, 'pagination metadata includes limit');
    [$status, $body] = $admin->get('api/appointments/all.php?limit=999');
    $t->eq(200, $status, 'oversized appointment limit is safely capped');
    $t->eq(200, $body['pagination']['limit'] ?? null, 'pagination limit is capped at 200');
    [$status] = $admin->get('api/appointments/all.php?status=Invalid');
    $t->eq(400, $status, 'invalid appointment status filter is rejected');
    [$status] = $admin->get('api/appointments/all.php?date_from=2026-13-01');
    $t->eq(400, $status, 'invalid appointment date filter is rejected');
    [$status] = $admin->get('api/appointments/all.php?page[]=1');
    $t->eq(400, $status, 'array pagination query parameters are rejected safely');
}
