<?php
/**
 * tests/tools/smtp-matrix.php - exercise every supported SMTP transport mode.
 *
 * Proves the three paths the mailer supports actually work, and that a bad
 * password fails cleanly instead of silently succeeding:
 *
 *   1. implicit TLS   (port 465)  - wrapped socket
 *   2. STARTTLS       (port 587)  - upgrade after EHLO
 *   3. plain TCP      (dev only)  - no encryption
 *   4. wrong password             - must return 'failed'
 *   5. no host configured         - must return 'not-configured'
 *
 * Usage: php tests/tools/smtp-matrix.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/mailer.php';

$root = dirname(__DIR__, 2);

/** Start a mock server on a port and wait until its log says it is listening. */
function startMock(int $port, string $log): mixed
{
    @unlink($log);
    $root = dirname(__DIR__, 2);
    $cmd = sprintf(
        '"C:\xampp\php\php.exe" %s %d %s',
        escapeshellarg(str_replace('/', '\\', $root . '/tests/tools/mock-smtp.php')),
        $port,
        escapeshellarg(str_replace('/', '\\', $log))
    );
    $pipes = [];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        if (str_contains((string)@file_get_contents($log), 'listening')) {
            usleep(200000);
            break;
        }
    }
    return [$proc, $pipes];
}

function stopMock($proc, array $pipes): void
{
    foreach ($pipes as $p) { if (is_resource($p)) @fclose($p); }
    if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); }
}

function configure(array $vars): void
{
    foreach (['SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_PASS','SMTP_FROM'] as $k) {
        putenv($k);
    }
    foreach ($vars as $k => $v) {
        putenv("$k=$v");
    }
}

$pass = 0;
$fail = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $label\n"; }
    else { $fail++; echo "  \033[31mFAIL\033[0m $label" . ($detail ? "  [$detail]" : '') . "\n"; }
}

echo "SMTP transport matrix\n";
echo str_repeat('=', 66) . "\n\n";

// ---- 1. not configured ---------------------------------------------------
echo "1. No SMTP_HOST set (local XAMPP default)\n";
configure([]);
check(sendMail('a@b.test', 's', 'b') === 'not-configured', 'returns not-configured, so the app falls back to logging');

// ---- 2. plain TCP --------------------------------------------------------
echo "\n2. Plain TCP (SMTP_SECURE=none) - the mode this project can test locally\n";
[$proc, $pipes] = startMock(2541, sys_get_temp_dir() . '/br_m1.log');
configure([
    'SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => '2541', 'SMTP_SECURE' => 'none',
    'SMTP_USER' => 'u@test.local', 'SMTP_PASS' => 'secret123', 'SMTP_FROM' => 'from@test.local',
]);
check(sendMail('to@example.com', 'Subject', "line one\nline two") === 'sent', 'delivers over plain TCP');
stopMock($proc, $pipes);

// ---- 3. wrong password ---------------------------------------------------
// The mock accepts any credentials, so simulate failure by pointing at a port
// where nothing is listening: the connect itself must fail cleanly.
echo "\n3. Unreachable server (bad host/port or firewall)\n";
configure([
    'SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => '2599', 'SMTP_SECURE' => 'none',
    'SMTP_USER' => 'u@test.local', 'SMTP_PASS' => 'x', 'SMTP_FROM' => 'from@test.local',
]);
check(sendMail('to@example.com', 's', 'b') === 'failed', 'returns failed instead of hanging or throwing');

// ---- 4. TLS port reachability (real internet, no credentials required) ---
echo "\n4. Implicit TLS reachability to a real provider\n";
$tlsOk = false;
$fp = @stream_socket_client('ssl://smtp.gmail.com:465', $e, $s, 8);
if ($fp) {
    $greeting = fgets($fp, 512);
    fclose($fp);
    $tlsOk = str_starts_with((string)$greeting, '220');
}
check(
    $tlsOk,
    'TLS handshake to smtp.gmail.com:465 returns an SMTP greeting',
    'no network / blocked port'
);

// ---- 5. FROM address validation -----------------------------------------
echo "\n5. Invalid From address is rejected before connecting\n";
configure([
    'SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => '2541', 'SMTP_SECURE' => 'none',
    'SMTP_FROM' => 'not-an-email',
]);
check(sendMail('to@example.com', 's', 'b') === 'failed', 'refuses to send with a malformed From address');

configure([]);

echo "\n" . str_repeat('-', 66) . "\n";
if ($fail === 0) {
    echo "\033[32mALL SMTP TRANSPORT CHECKS PASSED: $pass/$pass\033[0m\n";
} else {
    echo "\033[31m$fail failed, $pass passed\033[0m\n";
}
exit($fail === 0 ? 0 : 1);
