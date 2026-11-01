<?php
/**
 * includes/password_reset.php - password reset tokens.
 *
 * Security properties:
 *  - The raw token is generated with random_bytes() and only ever sent to the
 *    user. The database stores a SHA-256 hash, so a database leak does not let
 *    an attacker reset passwords.
 *  - Tokens are single use (used_at) and time limited (expires_at).
 *  - Requesting a reset never reveals whether an email is registered.
 *  - Any existing unused token for that user is invalidated on a new request,
 *    so an old link stops working when a new one is requested.
 */

const RESET_TOKEN_TTL_MINUTES = 30;
const RESET_MAX_REQUESTS_PER_HOUR = 5;

/** Create a reset token for a user. Returns the raw token (never stored). */
function createPasswordReset(PDO $pdo, int $userId, ?string $ip = null): string
{
    // Invalidate any outstanding tokens so only the newest link works.
    $pdo->prepare(
        "UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL"
    )->execute([$userId]);

    $rawToken = bin2hex(random_bytes(32)); // 64 hex chars, 256 bits
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        "INSERT INTO password_resets (user_id, token_hash, requested_by_ip, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))"
    );
    $stmt->execute([$userId, $tokenHash, $ip, RESET_TOKEN_TTL_MINUTES]);

    return $rawToken;
}

/**
 * Find a usable token row, or null.
 * A token is usable when it exists, has not been used and has not expired.
 */
function findUsableReset(PDO $pdo, string $rawToken): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null; // wrong shape: never bother the database
    }

    $stmt = $pdo->prepare(
        "SELECT id, user_id, expires_at, used_at
         FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([hash('sha256', $rawToken)]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Burn a token and set the new password in one transaction.
 * Returns true on success, false when the token is no longer usable.
 */
function consumePasswordReset(PDO $pdo, string $rawToken, string $newPassword): bool
{
    $pdo->beginTransaction();
    try {
        // Re-check inside the transaction: two simultaneous submissions of the
        // same link must not both succeed.
        $stmt = $pdo->prepare(
            "SELECT id, user_id FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
            ->execute([$row['id']]);

        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $row['user_id']]);

        // Any other outstanding token for this user is now void.
        $pdo->prepare(
            "UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL"
        )->execute([$row['user_id']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Password reset failed: ' . $e->getMessage());
        return false;
    }
}

/** Housekeeping: drop rows that are long dead. Called opportunistically. */
function prunePasswordResets(PDO $pdo): void
{
    $pdo->prepare(
        "DELETE FROM password_resets WHERE expires_at < (NOW() - INTERVAL 7 DAY)"
    )->execute();
}

/**
 * Build the reset link handed to the user.
 *
 * The host comes from the request so the link works wherever the app is
 * deployed. Only REMOTE_ADDR-derived values are trusted elsewhere; for a link
 * the scheme and host are the deployment's own, not user input.
 */
function resetLink(string $rawToken): string
{
    $https  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // The app lives in a subdirectory on XAMPP; derive it from the script path
    // ( .../draft for clinic/api/auth/password-reset.php -> /draft for clinic ).
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base   = preg_replace('#/api/.*$#', '', $script) ?: '';

    return sprintf('%s://%s%s/reset-password.html?token=%s', $scheme, $host, $base, $rawToken);
}

/**
 * Deliver the reset link.
 *
 * XAMPP has no mail transport configured, so the link is written to the PHP
 * error log instead of being emailed. Replace the body of this function with
 * mail()/SMTP when the site is hosted. Returning it here also lets the
 * development build show it on screen - see api/auth/password-reset.php.
 */
function sendResetLink(string $email, string $link): bool
{
    error_log(sprintf('[password reset] %s -> %s', $email, $link));
    return true;
}
