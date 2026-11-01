<?php
/**
 * tools/check-exposed.php - report which accounts still accept a password that
 * was published in this repository's Git history.
 *
 * READ-ONLY. It never changes a password and never prints one it discovers.
 *
 * Usage:
 *   php tools/check-exposed.php
 *
 * Exit codes: 0 = nothing exposed, 1 = at least one account still affected.
 *
 * Run it before rotating passwords to see the scope, and again afterwards to
 * confirm the fix. It is safe to run at any time.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Passwords that appeared in clinic.sql in earlier commits.
 * Kept here rather than in the database so this tool can test for them.
 *
 * Only passwords that were actually committed belong in this list. Adding a
 * password that was merely mentioned in conversation would publish it in the
 * repository and create the exact problem this tool exists to detect.
 */
const PUBLISHED_PASSWORDS = [
    'Admin123!',
    'Dentist123!',
    'Patient123!',
];

echo "Exposed password check\n";
echo str_repeat('=', 66) . "\n";
printf("  Database : %s\n", getenv('DB_NAME') ?: 'clinic');
echo "  Testing  : " . count(PUBLISHED_PASSWORDS) . " passwords that are in Git history\n";
echo "  (no password is printed)\n\n";

$rows = $pdo->query("SELECT id, email, role, password_hash FROM users ORDER BY id")->fetchAll();

$exposed = [];
$placeholders = [];
$clean = 0;

foreach ($rows as $u) {
    if ($u['password_hash'] === 'CHANGE_ME') {
        $placeholders[] = $u;
        continue;
    }

    $hit = null;
    foreach (PUBLISHED_PASSWORDS as $candidate) {
        if (password_verify($candidate, $u['password_hash'])) {
            $hit = $candidate;
            break;
        }
    }

    if ($hit !== null) {
        $exposed[] = $u;
        printf("  \033[31mEXPOSED\033[0m  %-30s %s\n", $u['email'], $u['role']);
    } else {
        $clean++;
        printf("  ok       %-30s %s\n", $u['email'], $u['role']);
    }
}

foreach ($placeholders as $u) {
    printf("  no login %-30s %s (password not set yet)\n", $u['email'], $u['role']);
}

echo "\n" . str_repeat('-', 66) . "\n";
printf("  accounts still accepting a published password : %d\n", count($exposed));
printf("  accounts with a unique password               : %d\n", $clean);
if ($placeholders) {
    printf("  accounts with no usable password              : %d\n", count($placeholders));
}
echo str_repeat('-', 66) . "\n\n";

if (!$exposed) {
    echo "\033[32mNo account accepts a password that is in Git history.\033[0m\n";
    echo "The exposure in the repository is now inert: the hashes are still in the\n";
    echo "history, but they no longer unlock anything.\n";
    exit(0);
}

echo "These accounts still accept a password published in this repository:\n\n";
foreach ($exposed as $u) {
    printf("  %s  (%s)\n", $u['email'], $u['role']);
}

echo "\nFix each one:\n\n";
foreach ($exposed as $u) {
    printf("  php database/set-password.php %s\n", $u['email']);
}

echo "\nEach command prompts twice with hidden input. It also signs out any\n";
echo "existing session for that account, so a stolen session stops working too.\n";

exit(1);
