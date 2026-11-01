<?php
/**
 * tools/check-key.php - prove the configured key still decrypts live data.
 *
 * Run this:
 *   - before moving the key anywhere, to establish a baseline
 *   - after restoring a backup on another machine, to prove the key matches
 *
 * It is READ-ONLY. It decrypts a sample of encrypted columns and reports how
 * many succeed. It never prints a plaintext value or the key.
 *
 * Usage:
 *   php tools/check-key.php
 *   DB_NAME=clinic_restored php tools/check-key.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encrypt.php';

$checks = [
    ['users',              'phone_number',            'AES-GCM'],
    ['users',              'home_address',            'AES-GCM'],
    ['users',              'date_of_birth',           'AES-GCM'],
    ['users',              'emergency_contact',       'AES-GCM'],
    ['dental_records',     'clinical_notes',          'AES-GCM'],
    ['dental_records',     'patient_contact',         'AES-GCM'],
];

echo "Encryption key check\n";
echo str_repeat('=', 62) . "\n";
printf("  Database : %s\n", getenv('DB_NAME') ?: 'clinic');
printf("  Key      : %s... (not printed in full)\n\n", substr(ENCRYPTION_KEY, 0, 8));

$totalEncrypted = 0;
$totalOk = 0;
$problems = [];

foreach ($checks as [$table, $column, $label]) {
    try {
        $sql = "SELECT `$column` FROM `$table` WHERE `$column` LIKE 'v2:%' LIMIT 20";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        printf("  %-40s skipped (column missing)\n", "$table.$column");
        continue;
    }

    if (!$rows) {
        printf("  %-40s no encrypted rows yet\n", "$table.$column");
        continue;
    }

    $ok = 0;
    foreach ($rows as $value) {
        if (decryptData((string)$value) !== null) {
            $ok++;
        }
    }

    $totalEncrypted += count($rows);
    $totalOk += $ok;

    $status = $ok === count($rows) ? 'OK' : ($ok === 0 ? 'FAILED' : 'PARTIAL');
    printf("  %-40s %2d/%2d decrypted  %s\n", "$table.$column", $ok, count($rows), $status);

    if ($ok !== count($rows)) {
        $problems[] = "$table.$column: $ok of " . count($rows) . " decrypted";
    }
}

echo "\n" . str_repeat('-', 62) . "\n";

if ($totalEncrypted === 0) {
    echo "No encrypted values found. Either the database is empty or nothing has\n";
    echo "been encrypted yet. Nothing to verify.\n";
    exit(0);
}

printf("  %d of %d encrypted values decrypted\n\n", $totalOk, $totalEncrypted);

if ($problems) {
    echo "\033[31mPROBLEMS FOUND\033[0m\n";
    foreach ($problems as $p) echo "  - $p\n";
    echo "\nThe configured key does not fully match this data.\n";
    echo "If you rotated the key, add the old one as 'previous_key' in\n";
    echo "config/secrets.local.php and run this again.\n";
    echo "Do NOT delete any key until this reports every row decrypting.\n";
    exit(1);
}

echo "\033[32mVERIFIED - the configured key decrypts the data in this database.\033[0m\n";
exit(0);
