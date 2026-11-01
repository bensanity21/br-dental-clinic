<?php
// Current patient's own profile and appointment summary.
// GET: profile | PATCH: update full_name and phone_number
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['patient']);
$user = currentUser();
require_once '../../includes/encrypt.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT id, full_name, email, phone_number, emergency_contact, home_address, date_of_birth, created_at FROM users WHERE id = ? AND role = \'patient\' LIMIT 1');
        $stmt->execute([$user['id']]);
        $profile = $stmt->fetch();
        if (!$profile) respondError('Your profile could not be found.', null, 404);

        // Sensitive fields are encrypted at rest; decrypt only for the owner.
        $profile['emergency_contact'] = $profile['emergency_contact'] !== null ? decryptData($profile['emergency_contact']) : null;
        $profile['home_address']      = $profile['home_address'] !== null ? decryptData($profile['home_address']) : null;
        $profile['date_of_birth']     = $profile['date_of_birth'] !== null ? decryptData($profile['date_of_birth']) : null;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status = 'Completed'");
        $stmt->execute([$user['id']]);
        $profile['completed_appointments'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT a.appointment_date, a.time_slot, a.status, s.service_name
            FROM appointments a JOIN services s ON s.id = a.service_id
            WHERE a.patient_id = ? AND a.appointment_date >= CURRENT_DATE()
              AND a.status IN ('Pending','Approved')
            ORDER BY a.appointment_date ASC, a.time_slot ASC, a.id ASC LIMIT 1");
        $stmt->execute([$user['id']]);
        $profile['next_appointment'] = $stmt->fetch() ?: null;

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        respondSuccess('Profile retrieved', $profile);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
        header('Allow: GET, PATCH');
        respondError('Method not allowed.', null, 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !array_key_exists('full_name', $data) || !array_key_exists('phone_number', $data)) {
        respondError('Full name and phone number fields are required.', null, 400);
    }
    if (!is_string($data['full_name']) || !is_string($data['phone_number'])) {
        respondError('Full name and phone number must be text values.', null, 422);
    }
    $fullName = trim($data['full_name']);
    $phone = trim($data['phone_number']);
    $nameLength = function_exists('mb_strlen') ? mb_strlen($fullName, 'UTF-8') : strlen($fullName);
    if ($nameLength < 2 || $nameLength > 100 || preg_match('/[\x00-\x1F\x7F]/u', $fullName)) {
        respondError('Enter a name between 2 and 100 characters.', null, 422);
    }
    if ($phone !== '' && (strlen($phone) > 20 || !preg_match('/^\+?[0-9 ()\-\.]{7,20}$/', $phone))) {
        respondError('Enter a valid phone number using digits, spaces, +, parentheses, periods, or hyphens.', null, 422);
    }

    // Optional encrypted fields. Empty string clears the stored value.
    $emergency = is_string($data['emergency_contact'] ?? null) ? trim($data['emergency_contact']) : '';
    $address   = is_string($data['home_address'] ?? null) ? trim($data['home_address']) : '';
    $dob       = is_string($data['date_of_birth'] ?? null) ? trim($data['date_of_birth']) : '';

    if (strlen($emergency) > 150) {
        respondError('The emergency contact must be 150 characters or fewer.', null, 422);
    }
    if (strlen($address) > 255) {
        respondError('The address must be 255 characters or fewer.', null, 422);
    }
    if ($dob !== '') {
        $parsedDob = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$parsedDob || $parsedDob->format('Y-m-d') !== $dob) {
            respondError('Enter the date of birth as YYYY-MM-DD.', null, 422);
        }
        if ($parsedDob > new DateTime('today')) {
            respondError('The date of birth cannot be in the future.', null, 422);
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, phone_number = ?, emergency_contact = ?, home_address = ?, date_of_birth = ? WHERE id = ? AND role = 'patient'");
    $stmt->execute([
        $fullName,
        $phone !== '' ? $phone : null,
        $emergency !== '' ? encryptData($emergency) : null,
        $address !== '' ? encryptData($address) : null,
        $dob !== '' ? encryptData($dob) : null,
        $user['id'],
    ]);
    $_SESSION['full_name'] = $fullName;
    $_SESSION['phone_number'] = $phone;

    respondSuccess('Profile updated', [
        'full_name' => $fullName,
        'phone_number' => $phone !== '' ? $phone : null,
        'emergency_contact' => $emergency !== '' ? $emergency : null,
        'home_address' => $address !== '' ? $address : null,
        'date_of_birth' => $dob !== '' ? $dob : null,
    ]);
} catch (Throwable $e) {
    error_log('Patient profile request failed: ' . $e->getMessage());
    respondError('We could not process your profile request. Please try again.', null, 500);
}
