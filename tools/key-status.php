<?php
/**
 * tools/key-status.php - report whether the encryption key has a verified
 * off-machine copy, and refuse to pretend the job is done when it has not.
 *
 * READ-ONLY. Prints no key material.
 *
 * The distinction this tool exists to make:
 *   - config/secrets.local.php ALWAYS holds the key (the app needs it), and it
 *     is gitignored. That is the running copy, not a backup.
 *   - A loose copy in backups/ is NOT a backup either: it is on the same disk
 *     as the database it unlocks, so one disk failure takes both.
 *   - A real backup lives on separate media, and only verify-key-backup.php
 *     can prove it is the right key.
 *
 * Usage:
 *   php tools/key-status.php                  status report
 *   php tools/key-status.php --mark-offsite=E:\encryption-key_....txt
 *                                             record a verified off-machine copy
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';

$args = array_slice($argv, 1);
$markPath = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--mark-offsite=')) {
        $markPath = substr($a, strlen('--mark-offsite='));
    }
}

$ledgerPath = $root . '/backups/key-custody.json';
$ledger = is_file($ledgerPath) ? (json_decode((string)file_get_contents($ledgerPath), true) ?: []) : [];

echo "Encryption key custody\n";
echo str_repeat('=', 64) . "\n";

// ---------------------------------------------------------------- live key
echo "\n  ACTIVE KEY\n";
printf("    Source            : %s\n",
    getenv('BR_DENTAL_ENCRYPTION_KEY') ? 'environment variable BL_DENTAL_ENCRYPTION_KEY' : 'config/secrets.local.php');
printf("    Fingerprint       : %s\n", substr(hash('sha256', ENCRYPTION_KEY), 0, 16));
printf("    Previous key      : %s\n", ENCRYPTION_KEY_PREVIOUS !== '' ? 'present (fallback for older ciphertext)' : 'none');

// Check the ignore rule by reading git's own pattern list, not by shelling out
// to git: the CLI is not on PATH for every account and the failure was silent.
$ignored = false;
$gitignore = $root . '/.gitignore';
if (is_file($gitignore)) {
    foreach (file($gitignore, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pattern = ltrim($line, '/');
        if ($pattern === 'config/secrets.local.php' || $pattern === 'secrets.local.php'
            || $pattern === 'config/' || $pattern === 'config/*'
            || $pattern === '*.local.php' || $pattern === 'config/*.local.php') {
            $ignored = true;
            break;
        }
    }
}
printf("    Gitignored        : %s\n", $ignored ? 'yes' : 'NO - DANGER');

// ------------------------------------------------------- loose local copies
echo "\n  LOOSE COPIES IN backups/ (same disk as the database)\n";
$local = glob($root . '/backups/encryption-key_*.txt') ?: [];
if (!$local) {
    echo "    none - good\n";
} else {
    foreach ($local as $f) {
        printf("    %-46s %d bytes\n", basename($f), filesize($f));
    }
    echo "\n    These do NOT satisfy key custody. They sit on the same disk as\n";
    echo "    MySQL, so a disk failure removes the data, the backups and the key.\n";
}

// ------------------------------------------------------ off-machine ledger
echo "\n  OFF-MACHINE COPIES (recorded)\n";
if (!$ledger) {
    echo "    none recorded\n";
} else {
    foreach ($ledger as $entry) {
        printf("    %s  %s\n", $entry['recorded_at'] ?? '?', $entry['label'] ?? '?');
        printf("      fingerprint %s  verified: %s\n",
            $entry['fingerprint'] ?? '?',
            !empty($entry['verified']) ? 'yes' : 'NO');
    }
}

$verifiedOffsite = array_values(array_filter($ledger, fn($e) => !empty($e['verified'])
    && ($e['fingerprint'] ?? '') === substr(hash('sha256', ENCRYPTION_KEY), 0, 16)));

// ------------------------------------------------------------------- record
if ($markPath !== null) {
    if (!is_file($markPath)) {
        fwrite(STDERR, "\n  Cannot read $markPath\n  Is the drive connected?\n");
        exit(1);
    }
    $contents = (string)file_get_contents($markPath);
    $hasKey = str_contains(strtolower($contents), strtolower(ENCRYPTION_KEY));

    if (!$hasKey) {
        fwrite(STDERR, "\n  That file does NOT contain the active key. Not recording it.\n");
        exit(1);
    }

    $ledger[] = [
        'recorded_at' => date('c'),
        'label'       => $markPath,
        'fingerprint' => substr(hash('sha256', ENCRYPTION_KEY), 0, 16),
        'verified'    => true,
    ];
    if (!is_dir(dirname($ledgerPath))) @mkdir(dirname($ledgerPath), 0775, true);
    file_put_contents($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT));
    echo "\n  Recorded an off-machine copy at $markPath\n";
    echo "  (the ledger stores only a fingerprint, never the key)\n";
    $verifiedOffsite = array_values(array_filter($ledger, fn($e) => !empty($e['verified'])));
}

// ------------------------------------------------------------------- verdict
echo "\n" . str_repeat('-', 64) . "\n";

if ($verifiedOffsite) {
    echo "\033[32mKey custody: SATISFIED\033[0m\n";
    echo "  A verified copy exists off this machine.\n";
    if ($local) {
        echo "\n  The loose copies in backups/ can now be deleted:\n";
        echo "    del backups\\encryption-key_*.txt\n";
        echo "  Then confirm the app still decrypts: php tools/check-key.php\n";
    }
    exit(0);
}

echo "\033[31mKey custody: NOT SATISFIED\033[0m\n\n";
echo "  The key exists only on this machine. If this disk fails, every\n";
echo "  encrypted patient field is permanently unreadable - a database\n";
echo "  restore will not help, because the backup holds ciphertext.\n\n";
echo "  To fix:\n";
echo "    1. php tools/export-key.php\n";
echo "    2. Copy the file to a USB drive or password manager.\n";
echo "    3. php tools/key-status.php --mark-offsite=E:\\encryption-key_....txt\n";
echo "    4. php tools/check-key.php        (must still say VERIFIED)\n";
echo "    5. del backups\\encryption-key_*.txt\n";
exit(1);
