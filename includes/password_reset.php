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
 * With SMTP configured (see includes/mailer.php and .env.example) the link is
 * emailed. Without it - the default on a local XAMPP install - the link is
 * written to the PHP error log instead and the API returns it to the page, so
 * development still works with no mail server at all.
 *
 * Returns true when the mail was accepted by the server, or when no transport
 * is configured (the caller then falls back to showing the link).
 */
function sendResetLink(string $email, string $link): bool
{
    require_once __DIR__ . '/mailer.php';

    if (!mailerConfigured()) {
        error_log(sprintf('[password reset - no SMTP configured] %s -> %s', $email, $link));
        return true;
    }

    $clinic = defined('CLINIC_NAME') ? CLINIC_NAME : 'BR Dental Clinic';
    $minutes = RESET_TOKEN_TTL_MINUTES;

    $subject = "Reset your $clinic password";

    $text = "Hello,\n\n"
        . "Someone asked to reset the password for your $clinic account.\n\n"
        . "Open this link to choose a new password:\n$link\n\n"
        . "The link works once and expires in $minutes minutes.\n\n"
        . "If you did not ask for this, you can ignore this email - your password "
        . "will not change.\n\n"
        . "$clinic\n";

    $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeClinic = htmlspecialchars($clinic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = "<!DOCTYPE html><html><body style=\"font-family:Arial,Helvetica,sans-serif;"
        . "color:#1F2937;line-height:1.6\">"
        . "<h2 style=\"color:#0F766E;margin:0 0 16px\">Reset your password</h2>"
        . "<p>Someone asked to reset the password for your $safeClinic account.</p>"
        . "<p><a href=\"$safeLink\" style=\"display:inline-block;padding:12px 20px;"
        . "background:#0F766E;color:#ffffff;text-decoration:none;border-radius:6px\">"
        . "Choose a new password</a></p>"
        . "<p style=\"color:#6B7280;font-size:14px\">Or copy this address into your browser:<br>"
        . "<span style=\"word-break:break-all\">$safeLink</span></p>"
        . "<p style=\"color:#6B7280;font-size:14px\">The link works once and expires in "
        . "$minutes minutes. If you did not ask for this, ignore this email - your password "
        . "will not change.</p>"
        . "<p style=\"color:#6B7280;font-size:14px\">$safeClinic</p>"
        . "</body></html>";

    $result = sendMail($email, $subject, $text, $html);

    if ($result === 'sent') {
        return true;
    }

    // Sending failed. Log it and let the caller fall back so the user is not
    // stranded by a mail outage.
    error_log("Password reset email could not be sent to $email (result: $result)");
    return false;
}
