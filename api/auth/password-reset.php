<?php
// api/auth/password-reset.php
// Request a password reset link.
//
// POST { email }
//
// Always answers with the same success message, whether or not the address is
// registered, so the endpoint cannot be used to discover valid accounts.
// Rate limited per IP to stop the form being used to spam a mailbox.

header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../connectdb.php';
require_once '../../includes/password_reset.php';

const RESET_REQUEST_MAX_PER_HOUR = 5;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.', 'data' => null], 405);
}

$data  = json_decode(file_get_contents('php://input'), true) ?: [];
$email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!$email) {
    respond(['success' => false, 'message' => 'Enter a valid email address.', 'data' => null], 400);
}

// ---- Rate limit ---------------------------------------------------------
$pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 60 MINUTE)")
    ->execute();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM login_attempts
     WHERE email = 'reset' AND ip_address = ? AND attempted_at > (NOW() - INTERVAL 60 MINUTE)"
);
$stmt->execute([$ip]);
if ((int)$stmt->fetchColumn() >= RESET_REQUEST_MAX_PER_HOUR) {
    respond([
        'success' => false,
        'message' => 'Too many reset requests from this connection. Please try again later.',
        'data'    => null,
    ], 429);
}

$pdo->prepare("INSERT INTO login_attempts (email, ip_address) VALUES ('reset', ?)")->execute([$ip]);

// ---- Issue the token ----------------------------------------------------
// The same reply is used for a registered and an unregistered address.
$genericReply = [
    'success' => true,
    'message' => 'If that email address is registered, a reset link has been sent.',
    'data'    => null,
];

$stmt = $pdo->prepare("SELECT id, full_name, email, role FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    respond($genericReply);
}

prunePasswordResets($pdo);
$token = createPasswordReset($pdo, (int)$user['id'], $ip);
$link  = resetLink($token);
$delivered = sendResetLink($user['email'], $link);

require_once '../../includes/activity.php';
logActivity(
    $pdo,
    'auth.password_reset_requested',
    'users',
    (int)$user['id'],
    'Password reset link requested.',
    ['ip' => $ip, 'email_delivered' => $delivered],
    ['id' => $user['id'], 'name' => $user['full_name'], 'role' => $user['role'] ?? 'patient']
);

// Returning a reset token in a response is only allowed when explicitly
// enabled for local development. This remains off if APP_ENV is unset or
// misconfigured, and can never be enabled in production.
require_once '../../includes/mailer.php';
$reply = $genericReply;
$allowLocalResetLink = getenv('APP_ENV') === 'development'
    && getenv('ALLOW_RESET_LINK_IN_RESPONSE') === '1'
    && !mailerConfigured();

if ($allowLocalResetLink) {
    $reply['data'] = ['reset_link' => $link, 'email_sent' => false];
} elseif (!$delivered) {
    $reply['message'] = 'We could not send the email right now. Please try again shortly.';
}

respond($reply);
