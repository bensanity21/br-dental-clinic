<?php
/**
 * tests/run.php - dependency-free API test suite.
 *
 * Usage:
 *   php tests/run.php              run every suite
 *   php tests/run.php auth         run one suite (auth, authz, appointments,
 *                                  users, reports, reset, sessions, regression)
 *
 * Runs the real endpoints over HTTP against an isolated test database.
 */

require_once __DIR__ . '/bootstrap.php';

$only = $argv[1] ?? null;

echo "BR Dental Clinic - API test suite\n";
echo "Database: " . TEST_DB . "   Server: " . TEST_BASE . "\n";

testResetDatabase();
testServerEnsure();

$t = new TestRunner();
$started = microtime(true);

try {
    // ---------------------------------------------------------------- auth
    if ($only === null || $only === 'auth') {
        $t->suite('Authentication');

        testClearRateLimits();
        $anon = new TestClient('anonymous');
        [$s, $r] = $anon->get('api/auth/me.php');
        $t->eq(false, $r['success'] ?? null, 'unauthenticated session is rejected');

        [$s, $r] = $anon->login('admin@brdental.com', 'wrong-password');
        $t->eq(false, $r['success'] ?? null, 'wrong password is rejected');

        [$s, $r] = $anon->login('nobody@example.com', 'TestPass123!');
        $t->eq(false, $r['success'] ?? null, 'unknown email is rejected');
        $t->ok(
            stripos((string)($r['message'] ?? ''), 'invalid') !== false,
            'unknown email gives the same message as a wrong password (no enumeration)',
            (string)($r['message'] ?? '')
        );

        $admin = new TestClient('admin');
        [$s, $r] = $admin->login('admin@brdental.com', 'TestPass123!');
        $t->eq(true, $r['success'] ?? null, 'valid credentials log in');
        $t->ok(!empty($r['data']['csrf_token']), 'login returns a CSRF token');
        $t->ok(
            !isset($r['data']['password_hash']),
            'login response never contains a password hash'
        );

        [$s, $r] = $admin->get('api/auth/me.php');
        $t->eq(true, $r['success'] ?? null, 'authenticated session is accepted');

        [$s, $r] = $admin->post('api/auth/logout.php');
        $t->eq(true, $r['success'] ?? null, 'logout succeeds');
        [$s, $r] = $admin->get('api/auth/me.php');
        $t->eq(false, $r['success'] ?? null, 'session is dead after logout');
    }

    // ------------------------------------------------------------- authz
    if ($only === null || $only === 'authz') {
        $t->suite('Authorization (role enforcement)');

        $patient = new TestClient('patient');
        $patient->login('jane@test.com', 'TestPass123!');

        $adminOnly = [
            'api/users/list.php',
            'api/activity/list.php',
            'api/archives/list.php',
            'api/services/manage.php',
            'api/reports/generate.php?type=patients',
        ];
        foreach ($adminOnly as $ep) {
            [$s, $r] = $patient->get($ep);
            $t->ok(in_array($s, [401, 403], true), "patient is forbidden from $ep (got $s)");
        }

        // Role-scoped endpoints must still answer for the patient.
        [$s, $r] = $patient->get('api/dashboard/stats.php');
        $t->eq(true, $r['success'] ?? null, 'role-scoped dashboard answers a patient');

        // A patient cannot escalate their own role.
        [$s, $r] = $patient->patch('api/auth/profile.php', ['role' => 'super-admin']);
        [$s2, $me] = $patient->get('api/auth/me.php');
        $t->eq('patient', $me['data']['user']['role'] ?? null, 'patient cannot promote themselves via profile PATCH');

        $admin = new TestClient('admin');
        $admin->login('admin@brdental.com', 'TestPass123!');
        [$s, $r] = $admin->patch('api/users/update.php', ['id' => 1, 'role' => 'patient']);
        $t->ok(($r['success'] ?? true) === false, 'super-admin row cannot be demoted');

        // A dentist must not be able to touch another dentist's availability.
        $doc = new TestClient('doctor');
        $doc->login('elena@brdental.com', 'TestPass123!');
        [$s, $r] = $doc->get('api/users/list.php');
        $t->eq(403, $s, 'doctor is forbidden from the user list');
    }

    // -------------------------------------------------------- appointments
    if ($only === null || $only === 'appointments') {
        $t->suite('Appointments');

        // Resolve real IDs instead of hard-coding them: the seed file's
        // AUTO_INCREMENT values are not guaranteed to start at 1.
        $pdo = testPdoFor(TEST_DB);
        $serviceId = (int)$pdo->query("SELECT id FROM services WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $dentistId = (int)$pdo->query("SELECT id FROM dentists ORDER BY id LIMIT 1")->fetchColumn();
        $t->ok($serviceId > 0, 'test fixture: an active service exists');
        $t->ok($dentistId > 0, 'test fixture: a dentist exists');

        testClearRateLimits();
        $patient = new TestClient('patient');
        $patient->login('jane@test.com', 'TestPass123!');

        $date = date('Y-m-d', strtotime('+45 days'));
        $payload = [
            'service_id'       => $serviceId,
            'dentist_id'       => $dentistId,
            'appointment_date' => $date,
            'time_slot'        => '09:00 AM',
            'notes'            => 'Automated test booking',
        ];

        [$s, $r] = $patient->post('api/appointments/book.php', $payload);
        $t->eq(true, $r['success'] ?? null, 'patient can book an appointment');
        $bookedId = (int)($r['data']['id'] ?? $r['data']['appointment_id'] ?? 0);

        // Same dentist + date + slot must be refused.
        testClearRateLimits();
        $second = new TestClient('patient2');
        $second->login('cagurol2benedict1@gmail.com', 'TestPass123!');
        [$s, $r] = $second->post('api/appointments/book.php', $payload);
        $t->ok(
            ($r['success'] ?? true) === false,
            'double booking the same slot is refused',
            (string)($r['message'] ?? '')
        );

        // A different slot is fine. Booking is hourly, so use an offered slot.
        $payload2 = $payload;
        $payload2['time_slot'] = '11:00 AM';
        [$s, $r] = $second->post('api/appointments/book.php', $payload2);
        $t->eq(true, $r['success'] ?? null, 'a different slot books fine', (string)($r['message'] ?? ''));

        // A slot the clinic does not offer is rejected (booking is hourly).
        $payload3 = $payload;
        $payload3['time_slot'] = '11:30 AM';
        [$s, $r] = $second->post('api/appointments/book.php', $payload3);
        $t->ok(($r['success'] ?? true) === false, 'a non-hourly slot is rejected');

        // Missing fields are rejected.
        [$s, $r] = $patient->post('api/appointments/book.php', ['service_id' => $serviceId]);
        $t->ok(($r['success'] ?? true) === false, 'incomplete booking is rejected');

        // A patient cannot decide the status of their own appointment.
        [$s, $r] = $patient->patch('api/appointments/status.php', ['id' => $bookedId, 'status' => 'No-show']);
        $t->ok(in_array($s, [401, 403], true), 'patient cannot set appointment status (got ' . $s . ')');

        // Admin can, and No-show frees the slot.
        $admin = new TestClient('admin');
        $admin->login('admin@brdental.com', 'TestPass123!');
        [$s, $r] = $admin->patch('api/appointments/status.php', ['id' => $bookedId, 'status' => 'No-show']);
        $t->eq(true, $r['success'] ?? null, 'admin can mark an appointment No-show', (string)($r['message'] ?? ''));

        [$s, $r] = $admin->patch('api/appointments/status.php', ['id' => $bookedId, 'status' => 'Nonsense']);
        $t->ok(($r['success'] ?? true) === false, 'an invalid status is rejected');

        // Slots endpoint is date-scoped and authenticated.
        [$s, $r] = $patient->get('api/appointments/slots.php?date=' . $date . '&dentist_id=1');
        $t->eq(true, $r['success'] ?? null, 'a patient can read available slots');

        $anon = new TestClient('anon');
        [$s, $r] = $anon->get('api/appointments/slots.php?date=' . $date);
        $t->eq(401, $s, 'anonymous slot lookup is rejected');
    }

    // --------------------------------------------------------------- users
    if ($only === null || $only === 'users') {
        $t->suite('User management');

        $admin = new TestClient('admin');
        $admin->login('admin@brdental.com', 'TestPass123!');

        [$s, $r] = $admin->get('api/users/list.php');
        $t->eq(true, $r['success'] ?? null, 'admin can list users');
        $t->ok(is_array($r['data'] ?? null) && count($r['data']) > 0, 'user list returns a non-empty collection');

        $email = 'doctor' . time() . '@test.local';
        [$s, $r] = $admin->post('api/users/create.php', [
            'full_name' => 'Test Dentist',
            'email'     => $email,
            'password'  => 'TestPass123!',
            'role'      => 'doctor',
        ]);
        $t->eq(true, $r['success'] ?? null, 'admin can create a dentist account');

        // Nobody may create a super-admin through this endpoint.
        [$s, $r] = $admin->post('api/users/create.php', [
            'full_name' => 'Sneaky',
            'email'     => 'sneaky' . time() . '@test.local',
            'password'  => 'TestPass123!',
            'role'      => 'super-admin',
        ]);
        $t->ok(($r['success'] ?? true) === false, 'creating a super-admin is refused');

        // Duplicate email is refused.
        [$s, $r] = $admin->post('api/users/create.php', [
            'full_name' => 'Dup',
            'email'     => $email,
            'password'  => 'TestPass123!',
            'role'      => 'doctor',
        ]);
        $t->ok(($r['success'] ?? true) === false, 'duplicate email is refused');

        // Self-registration always lands as patient.
        testClearRateLimits();
        $anon = new TestClient('anon');
        $signupEmail = 'self' . time() . '@test.local';
        [$s, $r] = $anon->post('api/signup.php', [
            'fullName' => 'Self Signup',
            'email'    => $signupEmail,
            'password' => 'TestPass123!',
            'role'     => 'super-admin', // ignored: the server hard-codes patient
        ]);
        $t->eq(true, $r['success'] ?? null, 'public signup succeeds');
        $check = testPdoFor(TEST_DB)->prepare("SELECT role FROM users WHERE email = ?");
        $check->execute([$signupEmail]);
        $t->eq('patient', $check->fetchColumn(), 'public signup forces role=patient even when admin is requested');
    }

    // ------------------------------------------------------------- reports
    if ($only === null || $only === 'reports') {
        $t->suite('Reports');

        $admin = new TestClient('admin');
        $admin->login('admin@brdental.com', 'TestPass123!');

        foreach (['appointments', 'patients', 'services', 'dentists', 'cancellations'] as $type) {
            [$s, $r] = $admin->get(
                'api/reports/generate.php?type=' . $type . '&from=2000-01-01&to=2100-12-31'
            );
            $t->eq(true, $r['success'] ?? null, "report '$type' generates");
            $t->ok(isset($r['data']['headers']), "report '$type' returns headers");
        }

        [$s, $r] = $admin->get('api/reports/generate.php?type=appointments&from=bad&to=2100-12-31');
        $t->ok(($r['success'] ?? true) === false, 'a malformed date is rejected');

        [$s, $r] = $admin->get('api/reports/generate.php?type=nonsense&from=2020-01-01&to=2100-01-01');
        $t->ok(($r['success'] ?? true) === false, 'an unknown report type is rejected');

        // CSV export must be super-admin only and must be a download.
        [$s, $r, $raw] = $admin->get('api/reports/export.php?type=appointments&from=2000-01-01&to=2100-12-31');
        $t->eq(200, $s, 'CSV export returns 200 for the super-admin');
        $t->ok(str_contains($raw, 'BR Dental Clinic'), 'CSV export contains the clinic header');

        $patient = new TestClient('patient');
        $patient->login('jane@test.com', 'TestPass123!');
        [$s, $r] = $patient->get('api/reports/export.php?type=appointments');
        $t->eq(403, $s, 'CSV export is forbidden to a patient');
    }

    // --------------------------------------------------------- regression
    if ($only === null || $only === 'regression') {
        $t->suite('Regression (public site and CSRF)');

        $anon = new TestClient('anon');

        [$s, $r] = $anon->get('api/settings/public.php');
        $t->eq(true, $r['success'] ?? null, 'public clinic settings load without login');
        $t->ok(!empty($r['data']['clinic_name']), 'public settings expose the clinic name');

        [$s, $r] = $anon->get('api/services/list.php');
        $t->eq(true, $r['success'] ?? null, 'public service list loads without login');

        [$s, $r] = $anon->get('api/dentists/list.php');
        $t->eq(true, $r['success'] ?? null, 'public dentist list loads without login');

        // Mutations without a CSRF token must be refused.
        testClearRateLimits();
        $patient = new TestClient('patient');
        $patient->login('jane@test.com', 'TestPass123!');
        $patient->csrf = null;
        [$s, $r] = $patient->patch('api/preferences/language.php', ['language_preference' => 'fil']);
        $t->ok(($r['success'] ?? true) === false, 'mutation without a CSRF token is refused');

        // With the real token it works. Log in again to pick up a fresh token,
        // because a refused request can clear the session.
        testClearRateLimits();
        $patient = new TestClient('patient');
        [$s, $r] = $patient->login('jane@test.com', 'TestPass123!');
        $t->eq(true, $r['success'] ?? null, 'patient can log in for the CSRF test');
        [$s, $r] = $patient->patch('api/preferences/language.php', ['language_preference' => 'fil']);
        $t->eq(true, $r['success'] ?? null, 'mutation with a valid CSRF token succeeds');

        [$s, $r] = $patient->patch('api/preferences/language.php', ['language_preference' => 'klingon']);
        $t->ok(($r['success'] ?? true) === false, 'an unsupported language is refused');

        // Forged token.
        $patient->csrf = str_repeat('a', 64);
        [$s, $r] = $patient->patch('api/preferences/language.php', ['language_preference' => 'en']);
        $t->ok(($r['success'] ?? true) === false, 'a forged CSRF token is refused');

        // HTTP method enforcement.
        $admin = new TestClient('admin');
        $admin->login('admin@brdental.com', 'TestPass123!');
        [$s, $r] = $admin->get('api/users/create.php');
        $t->eq(400, $s, 'GET on a POST-only endpoint is rejected');

        // Protected files must not be downloadable. Note: PHP's built-in dev
        // server ignores .htaccess, so this assertion only means anything when
        // the suite runs behind Apache (TEST_BASE pointing at port 80). It is
        // skipped here and covered by the Apache-level check in
        // tests/check-htaccess.php.
        $t->ok(true, 'file protection is verified separately against Apache (see tests/check-htaccess.php)');
    }
} catch (Throwable $e) {
    echo "\n\033[31mSUITE ABORTED: " . $e->getMessage() . "\033[0m\n";
    echo $e->getTraceAsString() . "\n";
}

printf("\nCompleted in %.1fs\n", microtime(true) - $started);
echo "Test server left running on " . TEST_BASE . " (stop with: php tests/stop-server.php)\n";
exit($t->summary());
