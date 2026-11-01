<?php
/**
 * tests/tools/mock-smtp.php - a throwaway SMTP server for testing the mailer.
 *
 * Speaks just enough SMTP for includes/mailer.php to complete a session, and
 * writes every received command and the message body to a log file so the test
 * can assert on what was actually sent.
 *
 * Usage: php tests/tools/mock-smtp.php 2525 /path/to/log
 * Implicit TLS is disabled (plain TCP) - the test sets SMTP_SECURE=none.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$port = (int)($argv[1] ?? 2525);
$logPath = $argv[2] ?? sys_get_temp_dir() . '/mock-smtp.log';

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "cannot listen on $port: $errstr\n");
    exit(1);
}

file_put_contents($logPath, "listening on 127.0.0.1:$port\n");
echo "mock SMTP listening on 127.0.0.1:$port\n";

$client = @stream_socket_accept($server, 30);
if (!$client) {
    fwrite(STDERR, "no connection within 30s\n");
    exit(1);
}

$send = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};

$send('220 mock.local ESMTP ready');

$inData = false;
$dataBuffer = '';
$authUserSeen = false;

while (($line = fgets($client, 1024)) !== false) {
    $trimmed = rtrim($line, "\r\n");

    if ($inData) {
        if ($trimmed === '.') {
            $inData = false;
            file_put_contents($logPath, "=== MESSAGE ===\n" . $dataBuffer . "\n", FILE_APPEND);
            $send('250 2.0.0 Message accepted');
            continue;
        }
        $dataBuffer .= $trimmed . "\n";
        continue;
    }

    file_put_contents($logPath, "C: $trimmed\n", FILE_APPEND);

    if (stripos($trimmed, 'EHLO') === 0 || stripos($trimmed, 'HELO') === 0) {
        $send('250-mock.local');
        $send('250 AUTH LOGIN');
    } elseif (stripos($trimmed, 'AUTH LOGIN') === 0) {
        $send('334 VXNlcm5hbWU6');
    } elseif (stripos($trimmed, 'MAIL FROM') === 0) {
        $send('250 2.1.0 Sender OK');
    } elseif (stripos($trimmed, 'RCPT TO') === 0) {
        $send('250 2.1.5 Recipient OK');
    } elseif (strcasecmp($trimmed, 'DATA') === 0) {
        $inData = true;
        $dataBuffer = '';
        $send('354 End data with <CR><LF>.<CR><LF>');
    } elseif (strcasecmp($trimmed, 'QUIT') === 0) {
        $send('221 2.0.0 Bye');
        break;
    } elseif ($authUserSeen) {
        // Second base64 line is the password.
        $send('235 2.7.0 Authentication successful');
    } else {
        // First base64 line is the username.
        $authUserSeen = true;
        $send('334 UGFzc3dvcmQ6');
    }
}

fclose($client);
fclose($server);
echo "mock SMTP finished\n";
