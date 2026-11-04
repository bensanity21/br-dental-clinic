<?php
// includes/db.php - PDO database connection (prepared statements only)
// ============================================================
require_once __DIR__ . '/../config/config.php';

/**
 * Global safety net for every endpoint.
 *
 * Each API file calls requireLogin()/respondSuccess() from the shared includes,
 * so loading this file means the handler is registered once for the whole
 * request. An uncaught error then returns the same JSON shape as every other
 * response instead of leaking a PHP stack trace, file path or SQL fragment to
 * the browser, and the real cause goes to the PHP error log.
 */
if (!function_exists('brInstallErrorHandler')) {
    function brInstallErrorHandler(): void
    {
        static $installed = false;
        if ($installed) return;
        $installed = true;

        set_exception_handler(function (Throwable $e): void {
            error_log(sprintf(
                'Unhandled %s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Something went wrong on the server. Please try again.',
                'data' => null,
            ]);
        });

        // Turn warnings/notices into exceptions so they never print mid-JSON.
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) return false;
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }
}

brInstallErrorHandler();

$host = getenv('DB_HOST') ?: ($localSecrets['db_host'] ?? 'localhost');
$dbname = getenv('DB_NAME') ?: ($localSecrets['db_name'] ?? 'clinic');
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

// Credentials come from the environment in production, or from the gitignored
// config/secrets.local.php on a development machine. There is deliberately NO
// fallback to root: a missing credential is a configuration error that must be
// visible, not something that silently reconnects with full server privileges.
if ($username === false || $username === '') $username = $localSecrets['db_user'] ?? '';
if ($password === false || $password === '') $password = $localSecrets['db_password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database credentials are not configured. Run: php tools/create-db-user.php',
        'data' => null,
    ]);
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
