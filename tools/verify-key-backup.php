<?php
/**
 * tools/verify-key-backup.php - check an exported key file against the live key.
 *
 * Use this after copying the key to a USB drive or password manager, to prove
 * the copy is correct WITHOUT printing either key anywhere.
 *
 * Usage:
 *   php tools/verify-key-backup.php E:\encryption-key_2026-11-01_125814.txt
 *
 * Exit codes: 0 = the file matches, 1 = mismatch or unreadable.
 *
 * Why this matters: a backup of the database does not contain the key, so a
 * wrong key file means restored patient data is permanently unreadable. This
 * catches that before it matters.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$path = $argv[1] ?? '';

if ($path === '') {
    fwrite(STDERR, "usage: php tools/verify-key-backup.php <path-to-key-file>\n");
    fwrite(STDERR, "  e.g. php tools/verify-key-backup.php E:\\encryption-key_2026-11-01_125814.txt\n");
    exit(1);
}

if (!is_file($path)) {
    fwrite(STDERR, "File not found: $path\n");
    fwrite(STDERR, "If the drive is not connected, plug it in and try again.\n");
    exit(1);
}

$contents = (string)file_get_contents($path);

// The exported file contains the key in two places: a readable line and a
// drop-in PHP snippet. Accept either, and any 64-hex string in the file.
$found = [];
if (preg_match_all('/\b([a-f0-9]{64})\b/i', $contents, $m)) {
    $found = array_values(array_unique(array_map('strtolower', $m[1])));
}

if (!$found) {
    fwrite(STDERR, "No 64-character key found in that file.\n");
    fwrite(STDERR, "Is this really the file produced by tools/export-key.php?\n");
    exit(1);
}

$live = strtolower(ENCRYPTION_KEY);
$prev = defined('ENCRYPTION_KEY_PREVIOUS') ? strtolower(ENCRYPTION_KEY_PREVIOUS) : '';

echo "Key file verification\n";
echo str_repeat('=', 58) . "\n";
echo "  File : " . basename($path) . "\n";
echo "  Keys found in file : " . count($found) . "\n";
echo "  (neither key is printed)\n\n";

$matchCurrent  = in_array($live, $found, true);
$matchPrevious = $prev !== '' && in_array($prev, $found, true);

printf("  Contains the ACTIVE key   : %s\n", $matchCurrent ? 'YES' : 'NO');
printf("  Contains the retired key  : %s\n", $prev === '' ? '(none configured)' : ($matchPrevious ? 'YES' : 'NO'));

echo "\n" . str_repeat('-', 58) . "\n";

if (!$matchCurrent) {
    echo "\033[31mMISMATCH - do not rely on this file.\033[0m\n";
    echo "The active key is not in it. Re-run: php tools/export-key.php\n";
    echo "Keep the old file until you have a verified copy.\n";
    exit(1);
}

echo "\033[32mVERIFIED - this file holds the key that decrypts the current database.\033[0m\n";

if ($prev !== '' && !$matchPrevious) {
    echo "\nNote: the retired key is not in this file. Rows encrypted before the last\n";
    echo "rotation would not decrypt. Re-export to include it.\n";
}

exit(0);
