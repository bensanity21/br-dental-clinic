<?php
/**
 * tools/test-mail.php - verify SMTP configuration without sending a real
 * password reset.
 *
 * Usage:
 *   php tools/test-mail.php you@example.com
 *
 * Prints whether SMTP is configured, and if so attempts to deliver a short
 * test message. Credentials are never printed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mailer.php';

$to = filter_var($argv[1] ?? '', FILTER_VALIDATE_EMAIL);
if (!$to) {
    fwrite(STDERR, "usage: php tools/test-mail.php <destination-email>\n");
    exit(1);
}

echo "SMTP configuration\n";
echo str_repeat('-', 50) . "\n";
printf("Host:      %s\n", mailerSetting('SMTP_HOST', '(not set)'));
printf("Port:      %s\n", mailerSetting('SMTP_PORT', '465'));
printf("Encryption:%s\n", mailerSetting('SMTP_SECURE', '(inferred)'));
printf("User:      %s\n", mailerSetting('SMTP_USER') ? '(set)' : '(not set)');
printf("Password:  %s\n", mailerSetting('SMTP_PASS') ? '(set)' : '(not set)');
printf("From:      %s\n", mailerSetting('SMTP_FROM', '(defaults to user)'));
echo "\n";

if (!mailerConfigured()) {
    echo "SMTP is NOT configured.\n\n";
    echo "That is expected on a local XAMPP install. No reset token is written\n";
    echo "to logs; local page display requires the explicit development flag.\n\n";
    echo "Set SMTP_* values in the host environment or add them to the existing\n";
    echo "return array in config/secrets.local.php, then run this again.\n";
    exit(0);
}

echo "Sending a test message to $to ...\n";
$body = "This is a test message from the BR Dental Clinic system.\n\n"
      . "If you received this, password reset emails will be delivered correctly.\n";

$result = sendMail($to, 'BR Dental Clinic - SMTP test', $body);

switch ($result) {
    case 'sent':
        echo "\nSUCCESS: the mail server accepted the message.\n";
        echo "Check the inbox (and the spam folder) for $to.\n";
        exit(0);
    case 'not-configured':
        echo "\nSMTP became unconfigured during the run.\n";
        exit(1);
    default:
        echo "\nFAILED: the message was not accepted.\n";
        echo "The reason has been written to the PHP error log. Common causes:\n";
        echo "  - wrong SMTP_PASS (Gmail needs an App Password, not your login)\n";
        echo "  - the provider blocks sign-in from this network or country\n";
        echo "  - SMTP_FROM is not an address the account is allowed to send as\n";
        echo "  - a firewall is blocking outbound port 465/587\n";
        exit(1);
}
