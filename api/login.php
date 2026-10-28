<?php
// api/login.php
header("Content-Type: application/json");
require_once "../config/config.php";
session_name(SESSION_NAME);
session_start();
require_once "../connectdb.php";

$data = json_decode(file_get_contents("php://input"), true);

$email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
$password = $data['password'] ?? '';

if (!$email || empty($password)) {
    echo json_encode(["success" => false, "message" => "Please enter a valid email address and password.", "data" => null]);
    exit();
}

// Fetch user by email
$stmt = $pdo->prepare("SELECT id, full_name, email, password_hash, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    session_regenerate_id(true); // Prevent session fixation after successful authentication.
    // Save session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['last_activity'] = time();

    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "data" => [
            "id" => $user['id'],
            "name" => $user['full_name'],
            "email" => $user['email'],
            "role" => $user['role']
        ]
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Invalid email or password.", "data" => null]);
}
?>
