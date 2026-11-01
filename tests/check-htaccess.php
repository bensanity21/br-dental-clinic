<?php
/**
 * tests/check-htaccess.php - verify Apache-level file protection.
 *
 * PHP's built-in dev server ignores .htaccess, so the API suite cannot prove
 * these rules work. This script checks the real XAMPP/Apache site instead.
 *
 * Usage: php tests/check-htaccess.php
 */

$base = $argv[1] ?? 'http://localhost/draft%20for%20clinic';

$mustBlock = [
    'clinic.sql',
    'full_codebase.txt',
    'SECURITY_SETUP.md',
    '.gitignore',
    '.env.example',
    'config/secrets.local.php',
    'database/migrate-activity-logs.sql',
    'database/set-password.php',
    'tools/test-mail.php',
    'docs/ADMIN-IP-ALLOWLIST.md',
];

$mustAllow = [
    'index.html',
    'login.html',
    'user.html',
    'admin.html',
    'setup.html',
    'forgot-password.html',
    'reset-password.html',
    'style.css',
    'scripts/portal.js',
    'scripts/site.js',
    'api/settings/public.php',
    'api/services/list.php',
];

/**
 * Probe a URL with GET. HEAD is not used because some endpoints correctly
 * answer 405 to HEAD, which would look like a broken page.
 */
function probe(string $url): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_NOBODY         => false,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$fail = 0;
echo "Checking " . $base . "\n\n";

echo "Must be blocked:\n";
foreach ($mustBlock as $path) {
    $code = probe($base . '/' . $path);
    $ok = in_array($code, [403, 404], true);
    printf("  %-40s %s (%d)\n", $path, $ok ? 'BLOCKED' : 'EXPOSED', $code);
    if (!$ok) $fail++;
}

echo "\nMust stay reachable:\n";
foreach ($mustAllow as $path) {
    $code = probe($base . '/' . $path);
    $ok = $code === 200;
    printf("  %-40s %s (%d)\n", $path, $ok ? 'OK' : 'BROKEN', $code);
    if (!$ok) $fail++;
}

echo "\n" . str_repeat('-', 60) . "\n";
if ($fail === 0) {
    echo "File protection OK\n";
    exit(0);
}
echo "$fail problem(s) found\n";
exit(1);
