<?php
/**
 * includes/admin_ip.php - optional IP allowlist for administrator sign-in.
 *
 * Default behaviour is OFF. If no allowlist rows exist, every administrator
 * can sign in from anywhere exactly as before. The feature only restricts
 * access once an admin deliberately adds an entry, which means switching it on
 * can never happen by accident and a fresh install is never locked out.
 *
 * Safety rules that prevent locking yourself out:
 *   1. The current IP can always be added to the list in one click.
 *   2. A patient or dentist account is never affected - only super-admin and
 *      doctor sign-in is checked.
 *   3. Entries can be disabled rather than deleted, so a mistake is
 *      recoverable from phpMyAdmin.
 *   4. If the table is missing or unreadable the check fails OPEN, so a
 *      database problem cannot lock every administrator out of the system.
 *
 * X-Forwarded-For is only trusted when the direct connection comes from an
 * address explicitly listed in TRUSTED_PROXIES below. Otherwise the header is
 * attacker-controlled and REMOTE_ADDR is used.
 */

require_once __DIR__ . '/../config/config.php';

/** Proxy addresses whose X-Forwarded-For header may be believed. */
const TRUSTED_PROXIES = [
    '127.0.0.1',
    '::1',
];

/** Normalise an IPv4/IPv6 address, returning null when it is unusable. */
function normalizeIp(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip === '') return null;

    // Strip an IPv4-mapped IPv6 prefix so ::ffff:127.0.0.1 matches 127.0.0.1.
    if (stripos($ip, '::ffff:') === 0) {
        $ip = substr($ip, 7);
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/**
 * The client's IP address.
 *
 * Only a connection from a trusted proxy may nominate a different address via
 * X-Forwarded-For; otherwise the forwarding header is ignored entirely.
 */
function clientIpAddress(): string
{
    $remote = normalizeIp($_SERVER['REMOTE_ADDR'] ?? '') ?? '0.0.0.0';
    $isTrustedProxy = in_array($remote, TRUSTED_PROXIES, true);

    if ($isTrustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // The left-most entry is the original client when proxies append.
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = normalizeIp($parts[0]);
        if ($candidate !== null) {
            return $candidate;
        }
    }

    return $remote;
}

/** True when an IP matches one allowlist row, honouring CIDR notation. */
function ipMatchesRule(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') return false;

    if (!str_contains($rule, '/')) {
        return $ip === $rule;
    }

    // CIDR: compare the first N bits.
    [$subnet, $bits] = explode('/', $rule, 2);
    $bits = (int)$bits;

    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) return false;
    if (strlen($ipBin) !== strlen($subnetBin)) return false;

    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) return false;

    $wholeBytes = intdiv($bits, 8);
    $remainingBits = $bits % 8;

    if ($wholeBytes > 0 && substr($ipBin, 0, $wholeBytes) !== substr($subnetBin, 0, $wholeBytes)) {
        return false;
    }

    if ($remainingBits === 0) return true;

    $mask = 0xFF << (8 - $remainingBits) & 0xFF;
    return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
}

/**
 * Decide whether this request may operate as an administrator.
 *
 * Returns ['allowed' => bool, 'reason' => string, 'ip' => string, 'enforced' => bool].
 * Fails open if the allowlist cannot be read.
 */
function checkAdminIpAccess(PDO $pdo): array
{
    $ip = clientIpAddress();

    try {
        $rows = $pdo->query(
            "SELECT id, ip_address, label FROM admin_ip_allowlist WHERE is_active = 1"
        )->fetchAll();
    } catch (Throwable $e) {
        // Table missing or unreadable: never lock administrators out because of
        // a database problem.
        error_log('Admin IP allowlist unavailable, allowing access: ' . $e->getMessage());
        return ['allowed' => true, 'reason' => 'allowlist-unavailable', 'ip' => $ip, 'enforced' => false];
    }

    if (!$rows) {
        // No rules configured: the feature is off.
        return ['allowed' => true, 'reason' => 'no-rules', 'ip' => $ip, 'enforced' => false];
    }

    foreach ($rows as $row) {
        if (ipMatchesRule($ip, (string)$row['ip_address'])) {
            return ['allowed' => true, 'reason' => 'matched:' . $row['ip_address'], 'ip' => $ip, 'enforced' => true];
        }
    }

    return ['allowed' => false, 'reason' => 'not-listed', 'ip' => $ip, 'enforced' => true];
}

/** Roles the allowlist applies to. Patients and signup are unaffected. */
function ipAllowlistAppliesTo(string $role): bool
{
    return in_array($role, ['super-admin', 'doctor'], true);
}
