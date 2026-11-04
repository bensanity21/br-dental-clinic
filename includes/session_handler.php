<?php
/**
 * includes/session_handler.php - database-backed PHP session storage.
 *
 * Replaces PHP's default file storage. Sessions then live in the `sessions`
 * table, which gives us three things file storage cannot:
 *
 *   1. Revocation - deleting a row logs that user out immediately, on every
 *      request, without touching anybody else's session.
 *   2. Visibility - an admin can see and revoke active sessions.
 *   3. Durability - sessions survive a web-server restart, and the same code
 *      works across multiple servers once there is more than one.
 *
 * The handler connects to the database itself instead of relying on $pdo,
 * because api/login.php loads the session before the database.
 *
 * Security: PHP's own use_strict_mode, HttpOnly and SameSite cookie settings
 * still apply. Only the session *storage* changes; the session id, hashing and
 * lifetime rules are unchanged. Session ids are stored hashed, so a database
 * read cannot be replayed as a cookie.
 */

require_once __DIR__ . '/../config/config.php';

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?PDO $pdo = null;

    /** Sessions idle for longer than this are treated as gone. */
    private const LIFETIME_SECONDS = 3600; // 1 hour absolute ceiling

    private function db(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $name = getenv('DB_NAME') ?: 'clinic';
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASSWORD');

        // Same rule as includes/db.php: environment first, then the gitignored
        // secrets file. No root fallback - a missing credential must fail
        // visibly rather than quietly borrow server-wide privileges.
        // $localSecrets is created at file scope by config.php, so it is read
        // through $GLOBALS here (this code runs inside a class method).
        $secrets = $GLOBALS['localSecrets'] ?? [];
        if ($user === false || $user === '') $user = $secrets['db_user'] ?? '';
        if ($pass === false || $pass === '') $pass = $secrets['db_password'] ?? '';

        if ($user === '' || $pass === '') {
            throw new RuntimeException('Database credentials are not configured. Run: php tools/create-db-user.php');
        }

        $this->pdo = new PDO(
            "mysql:host=$host;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        return $this->pdo;
    }

    /**
     * The session id is the value of the cookie. Hashing it means a dumped
     * database cannot be turned into a set of usable session cookies.
     */
    private function hashId(string $id): string
    {
        return hash('sha256', $id);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db()->prepare(
                "SELECT data FROM sessions
                 WHERE id = ? AND last_activity > (UNIX_TIMESTAMP() - ?)
                 LIMIT 1"
            );
            $stmt->execute([$this->hashId($id), self::LIFETIME_SECONDS]);
            $data = $stmt->fetchColumn();

            return $data === false ? '' : (string)$data;
        } catch (Throwable $e) {
            error_log('Session read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $stmt = $this->db()->prepare(
                "INSERT INTO sessions (id, data, last_activity)
                 VALUES (?, ?, UNIX_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = UNIX_TIMESTAMP()"
            );
            $stmt->execute([$this->hashId($id), $data]);
            return true;
        } catch (Throwable $e) {
            error_log('Session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->db()->prepare("DELETE FROM sessions WHERE id = ?")
                ->execute([$this->hashId($id)]);
            return true;
        } catch (Throwable $e) {
            error_log('Session destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db()->prepare(
                "DELETE FROM sessions WHERE last_activity < (UNIX_TIMESTAMP() - ?)"
            );
            $stmt->execute([self::LIFETIME_SECONDS]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('Session gc failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Tell PHP whether a session id already exists (use_strict_mode support). */
    public function validateId(string $id): bool
    {
        try {
            $stmt = $this->db()->prepare(
                "SELECT 1 FROM sessions
                 WHERE id = ? AND last_activity > (UNIX_TIMESTAMP() - ?)
                 LIMIT 1"
            );
            $stmt->execute([$this->hashId($id), self::LIFETIME_SECONDS]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}

