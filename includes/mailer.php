<?php
/**
 * includes/mailer.php - minimal SMTP client with no external dependencies.
 *
 * Why hand-written: Composer is not usable in this project (it is a hard
 * requirement of the toolchain), and PHPMailer cannot be vendored without
 * adding a dependency. This sends authenticated mail over implicit TLS
 * (port 465) or STARTTLS (port 587), which covers Gmail, Outlook, Brevo,
 * Mailgun and most school/ISP mail hosts.
 *
 * CREDENTIALS NEVER LIVE IN SOURCE CODE. They are read from environment
 * variables, or from an optional config/secrets.local.php that is gitignored:
 *
 *   SMTP_HOST   e.g. smtp.gmail.com
 *   SMTP_PORT   465 (implicit TLS) or 587 (STARTTLS)
 *   SMTP_USER   the mailbox/login name
 *   SMTP_PASS   the password or app password
 *   SMTP_FROM   the From address (defaults to SMTP_USER)
 *   SMTP_FROM_NAME  display name (defaults to the clinic name)
 *   SMTP_SECURE tls | starttls | none  (default: inferred from the port)
 *
 * If SMTP_HOST is not configured, sendMail() returns 'not-configured' and the
 * caller falls back to logging the message, so local XAMPP development keeps
 * working exactly as before without any mail server.
 */

/** Read a setting from the environment, then from secrets.local.php. */
function mailerSetting(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    static $secrets = null;
    if ($secrets === null) {
        $secrets = [];
        $path = __DIR__ . '/../config/secrets.local.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $secrets = $loaded;
            }
        }
    }

    // Accept both SMTP_HOST and smtp_host spellings in the secrets file.
    foreach ([$key, strtolower($key)] as $candidate) {
        if (!empty($secrets[$candidate])) {
            return (string)$secrets[$candidate];
        }
    }

    return $default;
}

/** True when outbound mail has been configured. */
function mailerConfigured(): bool
{
    return (string)mailerSetting('SMTP_HOST', '') !== '';
}

/**
 * Send an email.
 *
 * Returns one of:
 *   'sent'             the server accepted the message
 *   'not-configured'   no SMTP_HOST set; caller should fall back to logging
 *   'failed'           a transport or authentication error occurred
 *
 * The detailed reason is written to the PHP error log, never to the browser,
 * because SMTP errors can echo credentials back.
 */
function sendMail(string $toEmail, string $subject, string $bodyText, string $bodyHtml = ''): string
{
    if (!mailerConfigured()) {
        return 'not-configured';
    }

    $host     = (string)mailerSetting('SMTP_HOST');
    $port     = (int)mailerSetting('SMTP_PORT', '465');
    $user     = (string)mailerSetting('SMTP_USER', '');
    $pass     = (string)mailerSetting('SMTP_PASS', '');
    $from     = (string)mailerSetting('SMTP_FROM', $user);
    $fromName = (string)mailerSetting('SMTP_FROM_NAME', defined('CLINIC_NAME') ? CLINIC_NAME : 'BR Dental Clinic');
    $secure   = strtolower((string)mailerSetting('SMTP_SECURE', $port === 587 ? 'starttls' : 'tls'));

    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('SMTP misconfigured: SMTP_FROM is missing or not an email address.');
        return 'failed';
    }

    // 'none' means plain TCP; 'starttls' upgrades after EHLO; anything else
    // (the default, 'tls') wraps the socket in TLS from the first byte.
    if ($secure === 'none') {
        $transport = 'tcp';
    } elseif ($secure === 'starttls') {
        $transport = 'tcp';
    } else {
        $transport = 'ssl';
    }
    $timeout = 15;

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        sprintf('%s://%s:%d', $transport, $host, $port),
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        error_log("SMTP connect failed to $host:$port - $errstr ($errno)");
        return 'failed';
    }

    stream_set_timeout($fp, $timeout);

    /** Read a (possibly multi-line) reply and return its status code. */
    $read = static function () use ($fp): array {
        $code = 0;
        $text = '';
        // Read until a line whose 4th character is a space (end of a reply).
        while (($line = fgets($fp, 515)) !== false) {
            $text .= $line;
            if (preg_match('/^(\d{3})[ ]/', $line, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
        return [$code, trim($text)];
    };

    /** Send a command and return the reply code. */
    $cmd = static function (string $command) use ($fp, $read): array {
        fwrite($fp, $command . "\r\n");
        return $read();
    };

    [$code, $text] = $read();               // greeting
    if ($code !== 220) {
        error_log("SMTP greeting unexpected ($code): $text");
        fclose($fp);
        return 'failed';
    }

    $ehloHost = gethostname() ?: 'localhost';
    [$code, $text] = $cmd('EHLO ' . $ehloHost);
    if ($code !== 250) {
        error_log("SMTP EHLO failed ($code): $text");
        fclose($fp);
        return 'failed';
    }

    if ($secure === 'starttls') {
        [$code, $text] = $cmd('STARTTLS');
        if ($code !== 220) {
            error_log("SMTP STARTTLS refused ($code): $text");
            fclose($fp);
            return 'failed';
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('SMTP STARTTLS negotiation failed.');
            fclose($fp);
            return 'failed';
        }
        // Re-issue EHLO over the encrypted channel.
        [$code, $text] = $cmd('EHLO ' . $ehloHost);
        if ($code !== 250) {
            error_log("SMTP EHLO after STARTTLS failed ($code): $text");
            fclose($fp);
            return 'failed';
        }
    }

    if ($user !== '') {
        [$code, $text] = $cmd('AUTH LOGIN');
        if ($code !== 334) {
            error_log("SMTP AUTH LOGIN refused ($code): $text");
            fclose($fp);
            return 'failed';
        }
        [$code, $text] = $cmd(base64_encode($user));
        if ($code !== 334) {
            error_log("SMTP username rejected ($code)");
            fclose($fp);
            return 'failed';
        }
        [$code, $text] = $cmd(base64_encode($pass));
        if ($code !== 235) {
            // Never log $text here: some servers echo the attempt.
            error_log("SMTP authentication failed ($code). Check SMTP_USER / SMTP_PASS.");
            fclose($fp);
            return 'failed';
        }
    }

    [$code, $text] = $cmd('MAIL FROM:<' . $from . '>');
    if ($code !== 250) {
        error_log("SMTP MAIL FROM rejected ($code): $text");
        fclose($fp);
        return 'failed';
    }

    [$code, $text] = $cmd('RCPT TO:<' . $toEmail . '>');
    if ($code !== 250 && $code !== 251) {
        error_log("SMTP RCPT TO rejected ($code): $text");
        fclose($fp);
        return 'failed';
    }

    [$code, $text] = $cmd('DATA');
    if ($code !== 354) {
        error_log("SMTP DATA refused ($code): $text");
        fclose($fp);
        return 'failed';
    }

    // ---- Build the message ------------------------------------------------
    $boundary = 'brd_' . bin2hex(random_bytes(12));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [
        'Date: ' . date('r'),
        'From: ' . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($fromName), $from),
        'To: <' . $toEmail . '>',
        'Subject: ' . $encodedSubject,
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $host . '>',
        'MIME-Version: 1.0',
    ];

    if ($bodyHtml !== '') {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($bodyText), 76, "\r\n")
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($bodyHtml), 76, "\r\n")
              . "--$boundary--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($bodyText), 76, "\r\n");
    }

    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    // Dot-stuffing, per RFC 5321.
    $data = preg_replace('/^\./m', '..', $data);
    fwrite($fp, $data . "\r\n.\r\n");

    [$code, $text] = $read();
    $cmd('QUIT');
    fclose($fp);

    if ($code !== 250) {
        error_log("SMTP message rejected ($code): $text");
        return 'failed';
    }

    return 'sent';
}
