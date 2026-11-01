<?php
/**
 * api/setup/first-admin.php
 *
 * Sets the password for the seeded Super Admin on a FRESH INSTALL, so a new
 * deployment never needs a password written into a SQL file.
 *
 * GET   -> { needs_setup: bool, email, name }
 * POST  -> { password, confirm_password }
 *
 * Locked down deliberately:
 *  - Only works while the account's password is still the CHANGE_ME sentinel.
 *    Once a real password exists this endpoint returns 409 forever, so it
 *    cannot be used to take over a live site.
 *  - Only creates a password for the seeded super-admin. No other account.
 *  - Rate limited per IP.
 *  - Refuses the passwords that have appeared in this repository.
 */

header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../connectdb.php';

const SETUP_SENTINEL = 'CHANGE_ME';
const SETUP_MAX_PER_HOUR = 10;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

/** The seeded super-admin that still needs a password, or null. */
function pendingFirstAdmin(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email
         FROM users
         WHERE role = 'super-admin' AND password_hash = ?
         ORDER BY id
         LIMIT 1"
    );
    $stmt->execute([SETUP_SENTINEL]);
    $row = $stmt->fetch();

    return $row ?: null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $pending = pendingFirstAdmin($pdo);

    respond([
        'success' => true,
        'message' => $pending ? 'Setup required.' : 'Setup already completed.',
        'data'    => [
            'needs_setup' => $pending !== null,
            'email'       => $pending['email'] ?? null,
            'name'        => $pending['full_name'] ?? null,
        ],
    ]);
}

if ($method !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.', 'data' => null], 405);
}

// ---- Rate limit ---------------------------------------------------------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 60 MINUTE)")->execute();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM login_attempts
     WHERE email = 'setup' AND ip_address = ? AND attempted_at > (NOW() - INTERVAL 60 MINUTE)"
);
$stmt->execute([$ip]);
if ((int)$stmt->fetchColumn() >= SETUP_MAX_PER_HOUR) {
    respond([
        'success' => false,
        'message' => 'Too many attempts from this connection. Please try again later.',
        'data'    => null,
    ], 429);
}
$pdo->prepare("INSERT INTO login_attempts (email, ip_address) VALUES ('setup', ?)")->execute([$ip]);

// ---- Validate -----------------------------------------------------------
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$password = (string)($data['password'] ?? '');
$confirm  = (string)($data['confirm_password'] ?? '');

if ($password !== $confirm) {
    respond(['success' => false, 'message' => 'The passwords do not match.', 'data' => null], 422);
}

$banned = ['admin123!', 'dentist123!', 'patient123!', 'password', 'changeme', 'admin'];
$lower = strtolower($password);
foreach ($banned as $bad) {
    if (str_contains($lower, $bad)) {
        respond([
            'success' => false,
            'message' => 'That password is a known default and is refused. Choose something unique.',
            'data'    => null,
        ], 422);
    }
}

if (strlen($password) < 12 || strlen($password) > 72) {
    respond([
        'success' => false,
        'message' => 'Choose a password between 12 and 72 characters.',
        'data'    => null,
    ], 422);
}

// ---- Apply --------------------------------------------------------------
$pending = pendingFirstAdmin($pdo);
if (!$pending) {
    respond([
        'success' => false,
        'message' => 'Setup has already been completed. Sign in instead.',
        'data'    => null,
    ], 409);
}

// Guard the write with the sentinel still in place, so two simultaneous
// submissions cannot both succeed.
$stmt = $pdo->prepare(
    "UPDATE users SET password_hash = ?
     WHERE id = ? AND password_hash = ?"
);
$stmt->execute([password_hash($password, PASSWORD_BCRYPT), $pending['id'], SETUP_SENTINEL]);

if ($stmt->rowCount() !== 1) {
    respond([
        'success' => false,
        'message' => 'Setup has already been completed. Sign in instead.',
        'data'    => null,
    ], 409);
}

require_once '../../includes/activity.php';
logActivity(
    $pdo,
    'auth.first_admin_configured',
    'users',
    (int)$pending['id'],
    'First administrator password set through the setup page.',
    ['ip' => $ip],
    ['id' => $pending['id'], 'name' => $pending['full_name'], 'role' => 'super-admin']
);

respond([
    'success' => true,
    'message' => 'Administrator password set. You can sign in now.',
    'data'    => ['email' => $pending['email']],
]);
