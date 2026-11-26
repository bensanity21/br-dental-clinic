<?php
/**
 * includes/mailer.php - PHPMailer SMTP transport.
 *
 * Settings are loaded from process environment variables first, then the
 * gitignored config/secrets.local.php file. Never put mailbox credentials in
 * tracked source files.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/** Read a mail setting from the environment, then secrets.local.php. */
function mailerSetting(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;

    static $secrets = null;
    if ($secrets === null) {
        $secrets = [];
        $path = __DIR__ . '/../config/secrets.local.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) $secrets = $loaded;
        }
    }

    foreach ([$key, strtolower($key)] as $candidate) {
        if (isset($secrets[$candidate]) && $secrets[$candidate] !== '') {
            return (string)$secrets[$candidate];
        }
    }
    return $default;
}

/** Gmail requires a host, authenticated mailbox, and App Password. */
function mailerConfigured(): bool
{
    $host = mailerSetting('SMTP_HOST', '');
    $user = mailerSetting('SMTP_USER', '');
    $pass = mailerSetting('SMTP_PASS', '');
    $from = mailerSetting('SMTP_FROM', $user);
    return $host !== '' && $user !== '' && $pass !== ''
        && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Send one email. Returns 'sent', 'not-configured', or 'failed'. Details go to
 * the PHP error log, never to the API response.
 */
function sendMail(string $toEmail, string $subject, string $bodyText, string $bodyHtml = ''): string
{
    if (!mailerConfigured()) return 'not-configured';
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('SMTP delivery failed: recipient address is invalid.');
        return 'failed';
    }

    $host = (string)mailerSetting('SMTP_HOST');
    $port = (int)mailerSetting('SMTP_PORT', '587');
    $user = (string)mailerSetting('SMTP_USER');
    $pass = (string)mailerSetting('SMTP_PASS');
    $from = (string)mailerSetting('SMTP_FROM', $user);
    $fromName = (string)mailerSetting('SMTP_FROM_NAME', defined('CLINIC_NAME') ? CLINIC_NAME : 'BR Dental Clinic');
    $secure = strtolower((string)mailerSetting('SMTP_SECURE', $port === 587 ? 'starttls' : 'tls'));

    $encryption = match ($secure) {
        'starttls' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
        'tls' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
        'none' => '', // reserved for the local SMTP test server only
        default => null,
    };
    if ($encryption === null) {
        error_log('SMTP configuration failed: SMTP_SECURE must be starttls, tls, or none.');
        return 'failed';
    }
    if ($secure === 'none' && !in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
        error_log('SMTP configuration failed: unencrypted SMTP is allowed only for a loopback test server.');
        return 'failed';
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->SMTPSecure = $encryption;
        $mail->SMTPAutoTLS = ($secure === 'starttls');
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;
        $mail->setFrom($from, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->isHTML($bodyHtml !== '');
        $mail->Body = $bodyHtml !== '' ? $bodyHtml : $bodyText;
        if ($bodyHtml !== '') $mail->AltBody = $bodyText;
        $mail->send();
        return 'sent';
    } catch (Throwable $e) {
        // PHPMailer error details are useful for local diagnosis; never log the
        // configured username/password or the reset token from the message.
        $detail = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
        error_log('SMTP delivery failed: ' . ($detail !== '' ? $detail : get_class($e)));
        return 'failed';
    }
}
