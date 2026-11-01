<?php
// includes/activity.php - Audit trail helper
// ============================================================
// One call writes one row into activity_logs. Logging must never break the
// feature being audited, so every failure here is swallowed and written to
// the PHP error log instead of bubbling up to the caller.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

if (!function_exists('logActivity')) {
    /**
     * Record one audit entry.
     *
     * @param PDO    $pdo     Connection to write with.
     * @param string $action  Dotted action name, e.g. 'appointment.status'.
     * @param string $entity  Table the record belongs to, e.g. 'appointments'.
     * @param int|null $entityId Record id, when there is one.
     * @param string $description Short sentence shown in the UI.
     * @param array|null $details Optional structured context (before/after).
     * @param array|null $actor Optional actor override; defaults to the session user.
     */
    function logActivity(
        PDO $pdo,
        string $action,
        string $entity = '',
        ?int $entityId = null,
        string $description = '',
        ?array $details = null,
        ?array $actor = null
    ): void {
        try {
            // Prefer the session. On login the session is already set, so the
            // caller can rely on that; other callers pass $actor explicitly.
            $userId = $actor['id'] ?? ($_SESSION['user_id'] ?? null);
            $userName = $actor['name'] ?? ($_SESSION['full_name'] ?? '');
            $userRole = $actor['role'] ?? ($_SESSION['role'] ?? '');

            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            $stmt = $pdo->prepare('
                INSERT INTO activity_logs
                    (user_id, user_name, user_role, action, entity, entity_id, description, details, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $userId !== null ? (int)$userId : null,
                mb_substr((string)$userName, 0, 100),
                mb_substr((string)$userRole, 0, 30),
                mb_substr($action, 0, 60),
                mb_substr($entity, 0, 40),
                $entityId,
                mb_substr($description, 0, 255),
                $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                mb_substr((string)$ip, 0, 45),
            ]);
        } catch (Throwable $e) {
            // Never let an audit failure break the action itself.
            error_log('Activity log failed: ' . $e->getMessage());
        }
    }
}
