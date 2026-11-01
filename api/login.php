<?php
// api/login.php
// Rate limited: 5 failed attempts per email+IP locks the pair for 15 minutes.
header("Content-Type: application/json");
require_once "../config/config.php";
require_once "../includes/session.php";
require_once "../connectdb.php";

const LOGIN_MAX_ATTEMPTS   = 5;   // failures allowed before lockout
const LOGIN_WINDOW_MINUTES = 15;  // how far back we count failures
const LOGIN_LOCK_MINUTES   = 15;  // lockout duration

function clientIp(): string {
    // Trust the direct connection; don't blindly trust X-Forwarded-For.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

$email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = $data['password'] ?? '';
$ip = clientIp();

if (!$email || empty($password)) {
    respond(["success" => false, "message" => "Please enter a valid email address and password.", "data" => null], 400);
}

// Drop failure rows outside the lockout window so the table stays small.
$pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ? MINUTE)")
    ->execute([LOGIN_LOCK_MINUTES]);

// Count recent failures for this email+IP pair.
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS failures,
           GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) / 60)) AS minutes_since_last
    FROM login_attempts
    WHERE email = ? AND ip_address = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)
");
$stmt->execute([$email, $ip, LOGIN_WINDOW_MINUTES]);
$attempt = $stmt->fetch();
$failures = (int)($attempt['failures'] ?? 0);

if ($failures >= LOGIN_MAX_ATTEMPTS) {
    $minutesLeft = max(1, LOGIN_LOCK_MINUTES - (int)$attempt['minutes_since_last']);
    respond([
        "success" => false,
        "message" => "Too many failed login attempts. Please try again in {$minutesLeft} minute(s).",
        "data" => null,
    ], 429);
}

// Fetch user by email
$stmt = $pdo->prepare("SELECT id, full_name, email, password_hash, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    // Successful login clears this pair's failure history.
    $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?")->execute([$email, $ip]);

    session_regenerate_id(true); // Prevent session fixation after successful authentication.
    // Save session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "data" => [
            "id" => $user['id'],
            "name" => $user['full_name'],
            "email" => $user['email'],
            "role" => $user['role'],
            "csrf_token" => $_SESSION['csrf_token']
        ]
    ]);
} else {
    // Failed attempt: record it, then report how many guesses remain.
    $pdo->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)")->execute([$email, $ip]);
    $remaining = LOGIN_MAX_ATTEMPTS - ($failures + 1);

    $message = "Invalid email or password.";
    if ($remaining > 0 && $remaining <= 2) {
        $message .= " {$remaining} attempt(s) left before a temporary lock.";
    }

    echo json_encode(["success" => false, "message" => $message, "data" => null]);
}
?>
