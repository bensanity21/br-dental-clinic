<?php
// api/signup.php
header("Content-Type: application/json");
require_once "../connectdb.php";

$data = json_decode(file_get_contents("php://input"), true);

$fullName = trim($data['fullName'] ?? '');
$email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = $data['password'] ?? '';

if (empty($fullName) || !$email || strlen($password) < 8) {
    echo json_encode(["status" => "error", "message" => "Please complete all fields properly."]);
    exit();
}

// Check if email already exists
$checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$checkStmt->execute([$email]);
if ($checkStmt->fetch()) {
    echo json_encode(["status" => "error", "message" => "Email address is already registered."]);
    exit();
}

// Insert into MySQL
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);
$insertStmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'patient')");

if ($insertStmt->execute([$fullName, $email, $hashedPassword])) {
    echo json_encode(["status" => "success", "message" => "Account created successfully!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to create account in database."]);
}
?>