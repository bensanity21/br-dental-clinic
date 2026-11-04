<?php
// api/signup.php
// Public registration: always creates a PATIENT account.
// Rate limited per IP so the form cannot be used to mass-create accounts.
header("Content-Type: application/json");
require_once "../connectdb.php";

const SIGNUP_MAX_PER_WINDOW = 5;    // accounts per IP per hour
const SIGNUP_WINDOW_MINUTES = 60;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

$fullName = trim($data['fullName'] ?? '');
$email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = $data['password'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ---- Rate limit ---------------------------------------------------------
// Reuse the login_attempts table: a signup is recorded with the pseudo-email
// 'signup' and counted per IP, since every real email here is new.
$pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ? MINUTE)")
    ->execute([SIGNUP_WINDOW_MINUTES]);

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM login_attempts
    WHERE email = 'signup' AND ip_address = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)
");
$stmt->execute([$ip, SIGNUP_WINDOW_MINUTES]);
$recentSignups = (int)$stmt->fetchColumn();

if ($recentSignups >= SIGNUP_MAX_PER_WINDOW) {
    respond([
        "success" => false,
        "message" => "Too many accounts created from this connection. Please try again later.",
        "data" => null,
    ], 429);
}

// ---- Validation ---------------------------------------------------------
// Minimum length is 12 everywhere (signup, change, reset, CLI, setup) so no
// path can create an account weaker than another. bcrypt silently ignores
// anything past 72 bytes, so that stays the ceiling.
const SIGNUP_MIN_PASSWORD = 12;
const SIGNUP_MAX_PASSWORD = 72;

if (empty($fullName) || !$email || strlen($password) < SIGNUP_MIN_PASSWORD || strlen($password) > SIGNUP_MAX_PASSWORD) {
    respond([
        "success" => false,
        "message" => "Please complete all fields properly. Passwords need at least " . SIGNUP_MIN_PASSWORD . " characters.",
        "data" => null,
    ], 400);
}

$nameLength = function_exists('mb_strlen') ? mb_strlen($fullName, 'UTF-8') : strlen($fullName);
if ($nameLength > 100) {
    respond(["success" => false, "message" => "Your name must be 100 characters or fewer.", "data" => null], 422);
}

// Reject the most obvious weak passwords, mirroring what the form promises.
$lowerPassword = strtolower($password);
$firstName = strtolower(explode(' ', $fullName)[0] ?? '');
if (preg_match('/^(password|12345678|qwerty|letmein|admin)/', $lowerPassword)
    || strcasecmp($password, $email) === 0
    || ($firstName !== '' && mb_strlen($firstName) >= 4 && strpos($lowerPassword, $firstName) !== false)) {
    respond([
        "success" => false,
        "message" => "Choose a stronger password. Avoid common words, your name or your email.",
        "data" => null,
    ], 422);
}

// Check if email already exists
$checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$checkStmt->execute([$email]);
if ($checkStmt->fetch()) {
    respond(["success" => false, "message" => "Email address is already registered.", "data" => null], 409);
}

// ---- Create the account -------------------------------------------------
try {
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    // Role is hard-coded: the client cannot ask for a privileged account.
    $insertStmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'patient')");
    $insertStmt->execute([$fullName, $email, $hashedPassword]);
    $newUserId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    // A duplicate key here means the address was registered a moment ago.
    if ($e->getCode() === '23000') {
        respond(["success" => false, "message" => "Email address is already registered.", "data" => null], 409);
    }
    error_log('Signup failed: ' . $e->getMessage());
    respond(["success" => false, "message" => "Failed to create account in database.", "data" => null], 500);
}

// Record the signup for the rate limiter and the audit trail.
$pdo->prepare("INSERT INTO login_attempts (email, ip_address) VALUES ('signup', ?)")->execute([$ip]);

require_once "../includes/activity.php";
logActivity(
    $pdo,
    'user.signup',
    'users',
    $newUserId,
    "New patient account registered: $fullName ($email).",
    ['role' => 'patient'],
    ['id' => $newUserId, 'name' => $fullName, 'role' => 'patient']
);

respond(["success" => true, "message" => "Account created successfully!", "data" => null], 201);
