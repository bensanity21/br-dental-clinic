<?php
// includes/db.php - PDO database connection (prepared statements only)
// ============================================================
require_once __DIR__ . '/../config/config.php';

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'clinic';
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');
if ((getenv('APP_ENV') ?: 'development') !== 'production') {
    // XAMPP-only default. Production must use a dedicated least-privilege account.
    if ($username === false || $username === '') $username = 'root';
    if ($password === false) $password = '';
} elseif ($username === false || $username === '' || $password === false || $password === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Production database credentials are not configured.']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}
