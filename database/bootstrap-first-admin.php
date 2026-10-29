<?php
// Create the initial administrator without storing a password in SQL/source files.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$existing = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super-admin'")->fetchColumn();
if ($existing > 0) {
    fwrite(STDERR, "A super-admin account already exists; no account was created.\n");
    exit(1);
}

$name = trim((string)readline('Administrator full name: '));
$email = filter_var(trim((string)readline('Administrator email: ')), FILTER_VALIDATE_EMAIL);
$password = (string)readline('Administrator password (input visible): ');
if ($name === '' || !$email || strlen($password) < 12) {
    fwrite(STDERR, "Enter a name, valid email, and password of at least 12 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'super-admin')");
$stmt->execute([$name, $email, $hash]);
fwrite(STDOUT, "Initial super-admin created. Sign in, then remove this bootstrap script.\n");
