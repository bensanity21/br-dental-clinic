<?php
/**
 * api/settings/admin-ip.php - manage the administrator IP allowlist.
 *
 * GET    list entries plus the current connection's address
 * POST   add an entry            { ip_address, label }
 * PATCH  enable/disable an entry { id, is_active }
 * DELETE remove an entry         { id }
 *
 * Super-admin only. The rules that stop an administrator locking themselves
 * out live here and in includes/admin_ip.php:
 *
 *  - Adding the last remaining entry is refused when the admin's own address
 *    would not match it, unless ?confirm_lockout=1 is supplied. That forces a
 *    deliberate second decision rather than a reflexive confirm.
 *  - The current address can always be added with POST { ip_address: "auto" }.
 *  - A brand new list is accepted even if it excludes the caller, because the
 *    list can be cleared again from phpMyAdmin.
 */

require_once '../../includes/auth.php';
require_once '../../includes/response.php';
require_once '../../includes/db.php';
require_once '../../includes/activity.php';
require_once '../../includes/admin_ip.php';

requireRole('super-admin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        $rows = $pdo->query(
            "SELECT id, ip_address, label, is_active, created_at
             FROM admin_ip_allowlist
             ORDER BY is_active DESC, ip_address"
        )->fetchAll();

        $currentIp = clientIpAddress();
        $enforced = false;
        $currentAllowed = true;
        foreach ($rows as $row) {
            if ((int)$row['is_active'] === 1) {
                $enforced = true;
                if (ipMatchesRule($currentIp, (string)$row['ip_address'])) {
                    $currentAllowed = true;
                }
            }
        }
        if ($enforced) {
            $currentAllowed = (bool)checkAdminIpAccess($pdo)['allowed'];
        }

        respondSuccess('Allowlist retrieved', [
            'entries'         => $rows,
            'current_ip'      => $currentIp,
            'enforced'        => $enforced,
            'current_allowed' => $currentAllowed,
        ]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $raw = trim((string)($data['ip_address'] ?? ''));
        $label = trim((string)($data['label'] ?? ''));
        $confirmLockout = !empty($data['confirm_lockout']);

        // Convenience: add whatever address this browser is coming from.
        if ($raw === 'auto') {
            $raw = clientIpAddress();
        }

        // Validate a plain address or a CIDR block.
        $valid = false;
        if (str_contains($raw, '/')) {
            [$subnet, $bits] = explode('/', $raw, 2);
            $valid = filter_var($subnet, FILTER_VALIDATE_IP) !== false
                && ctype_digit($bits)
                && (int)$bits >= 0
                && (int)$bits <= (str_contains($subnet, ':') ? 128 : 32);
        } else {
            $valid = filter_var($raw, FILTER_VALIDATE_IP) !== false;
        }

        if (!$valid) {
            respondError('Enter a valid IP address or CIDR block, for example 203.0.113.7 or 203.0.113.0/24.', null, 422);
        }

        $existing = $pdo->prepare("SELECT id FROM admin_ip_allowlist WHERE ip_address = ?");
        $existing->execute([$raw]);
        if ($existing->fetch()) {
            respondError('That address is already on the list.', null, 409);
        }

        // Would this entry lock out the person adding it?
        $currentIp = clientIpAddress();
        $selfCovered = ipMatchesRule($currentIp, $raw);
        $activeCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM admin_ip_allowlist WHERE is_active = 1"
        )->fetchColumn();

        if (!$selfCovered && $activeCount === 0 && !$confirmLockout) {
            respondError(
                sprintf(
                    'This entry does not include your current address (%s), so you would be locked out of the administrator portal immediately. Add %s instead, or repeat the request with confirm_lockout.',
                    $currentIp,
                    $currentIp
                ),
                ['current_ip' => $currentIp],
                409
            );
        }

        $pdo->prepare("INSERT INTO admin_ip_allowlist (ip_address, label, is_active) VALUES (?, ?, 1)")
            ->execute([$raw, $label !== '' ? $label : null]);
        $newId = (int)$pdo->lastInsertId();

        logActivity($pdo, 'settings.admin_ip_added', 'admin_ip_allowlist', $newId,
            'Administrator IP allowlist entry added: ' . $raw, ['ip' => $raw, 'label' => $label]);

        respondSuccess('Address added to the allowlist', ['id' => $newId, 'ip_address' => $raw]);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($data['id'] ?? 0);
        $isActive = !empty($data['is_active']);

        if ($id <= 0) {
            respondError('A valid entry id is required.', null, 400);
        }

        $stmt = $pdo->prepare("SELECT ip_address FROM admin_ip_allowlist WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            respondError('That entry does not exist.', null, 404);
        }

        $pdo->prepare("UPDATE admin_ip_allowlist SET is_active = ? WHERE id = ?")
            ->execute([$isActive ? 1 : 0, $id]);

        logActivity($pdo, 'settings.admin_ip_updated', 'admin_ip_allowlist', $id,
            ($isActive ? 'Enabled' : 'Disabled') . ' allowlist entry ' . $row['ip_address']);

        respondSuccess($isActive ? 'Entry enabled' : 'Entry disabled');
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($data['id'] ?? 0);

        if ($id <= 0) {
            respondError('A valid entry id is required.', null, 400);
        }

        $stmt = $pdo->prepare("SELECT ip_address FROM admin_ip_allowlist WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            respondError('That entry does not exist.', null, 404);
        }

        $pdo->prepare("DELETE FROM admin_ip_allowlist WHERE id = ?")->execute([$id]);

        logActivity($pdo, 'settings.admin_ip_removed', 'admin_ip_allowlist', $id,
            'Allowlist entry removed: ' . $row['ip_address'], ['ip' => $row['ip_address']]);

        respondSuccess('Entry removed');
        break;

    default:
        respondError('Method not allowed.', null, 405);
}
