<?php
// api/appointments/all.php - Get all appointments (admin/doctor only)
// GET only
require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';

requireLogin();
requireRole(['doctor', 'super-admin']);

$user = currentUser();

$pageRaw = $_GET['page'] ?? '1';
$page = is_string($pageRaw) && ctype_digit($pageRaw)
    ? filter_var($pageRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($page === false) respondError('Page must be a positive integer.', null, 400);

$limitRaw = $_GET['limit'] ?? '50';
$limit = is_string($limitRaw) && ctype_digit($limitRaw)
    ? filter_var($limitRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($limit === false) respondError('Limit must be a positive integer.', null, 400);
$limit = min((int)$limit, 200);
$page = (int)$page;
if ($page > intdiv(PHP_INT_MAX, $limit)) respondError('Page is out of range.', null, 400);

$allowedStatuses = ['Pending', 'Approved', 'Completed', 'Cancelled', 'Rescheduled', 'No-show'];
$where = [];
$params = [];
if ($user['role'] === 'doctor') {
    $where[] = 'd.user_id = ?';
    $params[] = (int)$user['id'];
}
if (isset($_GET['status']) && $_GET['status'] !== '') {
    if (!is_string($_GET['status']) || !in_array($_GET['status'], $allowedStatuses, true)) {
        respondError('Invalid appointment status filter.', null, 400);
    }
    $where[] = 'a.status = ?';
    $params[] = $_GET['status'];
}
foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
    if (!isset($_GET[$key]) || $_GET[$key] === '') continue;
    $value = $_GET[$key];
    $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        respondError("$key must be a valid YYYY-MM-DD date.", null, 400);
    }
    $where[] = "a.appointment_date $operator ?";
    $params[] = $value;
}
if (isset($_GET['date_from'], $_GET['date_to']) && $_GET['date_from'] !== '' && $_GET['date_to'] !== ''
    && $_GET['date_from'] > $_GET['date_to']) {
    respondError('date_from must not be later than date_to.', null, 400);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) FROM appointments a LEFT JOIN dentists d ON a.dentist_id = d.id $whereSql");
$count->execute($params);
$total = (int)$count->fetchColumn();
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT a.id, a.patient_id, a.appointment_date, a.time_slot, a.notes, a.status, a.created_at,
           s.service_name, s.price,
           u.full_name AS patient_name, u.email AS patient_email, u.phone_number AS patient_phone,
           d.full_name AS dentist_name
    FROM appointments a
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN users u ON a.patient_id = u.id
    LEFT JOIN dentists d ON a.dentist_id = d.id
    $whereSql
    ORDER BY a.appointment_date DESC, a.time_slot DESC, a.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);

$appointments = $stmt->fetchAll();

// The stored phone number is ciphertext; decrypt it for staff looking at the
// appointment list. Anything unencrypted (legacy) passes through unchanged.
require_once '../../includes/phone.php';
foreach ($appointments as &$appointment) {
    if (array_key_exists('patient_phone', $appointment)) {
        $appointment['patient_phone'] = phoneForDisplay($appointment['patient_phone']);
    }
}
unset($appointment);

respond([
    'success' => true,
    'message' => 'All appointments retrieved',
    // Keep data as the same flat array for existing frontend callers.
    'data' => $appointments,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => (int)ceil($total / $limit),
    ],
]);
