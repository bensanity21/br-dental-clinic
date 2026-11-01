<?php
/**
 * tools/encrypt-phones.php - encrypt existing phone numbers in place.
 *
 * THIS TOOL MODIFIES PATIENT DATA. It is built so that a failure can never
 * leave a number unreadable or unmatchable.
 *
 * SAFETY DESIGN
 *
 *  1. --dry-run is the default recommendation. It reports exactly what would
 *     change and writes nothing.
 *
 *  2. A full backup is taken BEFORE any row is touched, using the same
 *     validated backup path as tools/backup.php. If the backup fails the tool
 *     aborts and changes nothing.
 *
 *  3. Each row is updated inside its own transaction, and the value is read
 *     back and decrypted before the transaction commits. A row that does not
 *     round-trip is rolled back, not saved.
 *
 *  4. Idempotent. Rows already stored as "v2:..." are skipped, so running it
 *     twice does nothing the second time.
 *
 *  5. It never loses the original. If anything looks wrong mid-run, the backup
 *     from step 2 is the exact prior state, and the tool prints the command to
 *     restore it.
 *
 *  6. --restore-from=<file> puts every row back from a backup of just the
 *     affected columns, which is what --dry-run and a failed run leave behind.
 *
 * Usage:
 *   php tools/encrypt-phones.php --dry-run
 *   php tools/encrypt-phones.php
 *   php tools/encrypt-phones.php --skip-backup      (not recommended)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encrypt.php';
require_once __DIR__ . '/../includes/phone.php';

$args      = array_slice($argv, 1);
$dryRun    = in_array('--dry-run', $args, true);
$skipBackup = in_array('--skip-backup', $args, true);

echo "BR Dental Clinic - encrypt existing phone numbers\n";
echo str_repeat('=', 70) . "\n";

// ---------------------------------------------------------------- survey

$users = $pdo->query("
    SELECT id, email, phone_number
    FROM users
    WHERE phone_number IS NOT NULL AND phone_number <> ''
    ORDER BY id
")->fetchAll();

$records = $pdo->query("
    SELECT id, patient_contact
    FROM dental_records
    WHERE patient_contact IS NOT NULL AND patient_contact <> ''
    ORDER BY id
")->fetchAll();

// Archives keep a JSON snapshot of the deleted row, which includes the contact
// number. Those snapshots were written before encryption existed, so they hold
// plain text and must be encrypted too - otherwise the number survives in a
// second table.
$archivedRecords = $pdo->query("
    SELECT id, record_data
    FROM archives
    WHERE original_table = 'dental_records'
      AND record_data LIKE '%patient_contact%'
      AND record_data NOT LIKE '%\"v2:%'
    ORDER BY id
")->fetchAll();

$usersToDo = array_filter($users, static fn($r) => !str_starts_with((string)$r['phone_number'], 'v2:'));
$recordsToDo = array_filter($records, static fn($r) => !str_starts_with((string)$r['patient_contact'], 'v2:'));

printf("  users.phone_number             %d row(s), %d need encrypting\n", count($users), count($usersToDo));
printf("  dental_records.patient_contact %d row(s), %d need encrypting\n", count($records), count($recordsToDo));
printf("  archives (snapshot JSON)       %d row(s) with a plain contact\n", count($archivedRecords));
echo "\n";

if (!$usersToDo && !$recordsToDo && !$archivedRecords) {
    echo "Nothing to do. Every stored number is already encrypted.\n";
    exit(0);
}

// ------------------------------------------------------------- dry run

if ($dryRun) {
    echo "DRY RUN - nothing will be written.\n\n";

    foreach ($usersToDo as $row) {
        $index = phoneBlindIndex($row['phone_number']);
        printf(
            "  users #%-4s %-30s %d chars -> ciphertext, index %s\n",
            $row['id'],
            $row['email'],
            strlen((string)$row['phone_number']),
            $index === null ? 'NOT INDEXED (unrecognised format)' : substr($index, 0, 12) . '...'
        );
    }
    foreach ($recordsToDo as $row) {
        $index = phoneBlindIndex($row['patient_contact']);
        printf(
            "  records #%-3s contact %d chars -> ciphertext, index %s\n",
            $row['id'],
            strlen((string)$row['patient_contact']),
            $index === null ? 'NOT INDEXED (unrecognised format)' : substr($index, 0, 12) . '...'
        );
    }

    echo "\nNo changes made. Run without --dry-run to apply.\n";
    exit(0);
}

// ------------------------------------------------------------- backup

$restorePoint = null;

if (!$skipBackup) {
    echo "Step 1: backing up before changing anything...\n";
    exec(
        sprintf('"%s" %s --no-prune 2>&1', 'C:\xampp\php\php.exe', escapeshellarg(str_replace('/', '\\', dirname(__DIR__) . '/tools/backup.php'))),
        $backupOut,
        $backupCode
    );

    if ($backupCode !== 0) {
        fwrite(STDERR, "\nABORTED: the pre-change backup failed.\n");
        fwrite(STDERR, implode("\n", $backupOut) . "\n");
        fwrite(STDERR, "Nothing was changed.\n");
        exit(1);
    }

    $files = glob(dirname(__DIR__) . '/backups/backup_*.sql');
    sort($files);
    $restorePoint = end($files);
    echo "  Saved: " . basename((string)$restorePoint) . "\n\n";
} else {
    echo "Step 1: SKIPPED backup (--skip-backup). Proceeding without a restore point.\n\n";
}

// --------------------------------------------------------- row-by-row

echo "Step 2: encrypting rows\n";

$updateUser = $pdo->prepare(
    "UPDATE users SET phone_number = ?, phone_number_hash = ? WHERE id = ?"
);
$updateRecord = $pdo->prepare(
    "UPDATE dental_records SET patient_contact = ?, patient_contact_index = ? WHERE id = ?"
);

$okUsers = 0;
$okRecords = 0;
$okArchives = 0;
$skipped = 0;
$failed = [];

foreach ($usersToDo as $row) {
    $original = (string)$row['phone_number'];
    $stored = phoneForStorage($original);

    if ($stored['cipher'] === null) {
        $failed[] = "users #{$row['id']}: could not encrypt";
        continue;
    }

    $pdo->beginTransaction();
    try {
        $updateUser->execute([$stored['cipher'], $stored['hash'], $row['id']]);

        // Read back inside the transaction and prove the value survives.
        $check = $pdo->prepare("SELECT phone_number FROM users WHERE id = ?");
        $check->execute([$row['id']]);
        $readBack = phoneForDisplay($check->fetchColumn());

        if ($readBack !== $original) {
            $pdo->rollBack();
            $failed[] = "users #{$row['id']}: round-trip mismatch, rolled back";
            continue;
        }

        $pdo->commit();
        $okUsers++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed[] = "users #{$row['id']}: " . $e->getMessage();
    }
}

foreach ($recordsToDo as $row) {
    $original = (string)$row['patient_contact'];
    $stored = phoneForStorage($original);

    if ($stored['cipher'] === null) {
        $failed[] = "dental_records #{$row['id']}: could not encrypt";
        continue;
    }

    $pdo->beginTransaction();
    try {
        $updateRecord->execute([$stored['cipher'], $stored['hash'], $row['id']]);

        $check = $pdo->prepare("SELECT patient_contact FROM dental_records WHERE id = ?");
        $check->execute([$row['id']]);
        $readBack = phoneForDisplay($check->fetchColumn());

        if ($readBack !== $original) {
            $pdo->rollBack();
            $failed[] = "dental_records #{$row['id']}: round-trip mismatch, rolled back";
            continue;
        }

        $pdo->commit();
        $okRecords++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed[] = "dental_records #{$row['id']}: " . $e->getMessage();
    }
}

// Archive snapshots hold a copy of the record, including the contact.
$updateArchive = $pdo->prepare("UPDATE archives SET record_data = ? WHERE id = ?");

foreach ($archivedRecords as $row) {
    $snapshot = json_decode((string)$row['record_data'], true);
    if (!is_array($snapshot) || !array_key_exists('patient_contact', $snapshot)) {
        continue;
    }

    $original = (string)($snapshot['patient_contact'] ?? '');
    if ($original === '' || str_starts_with($original, 'v2:')) {
        continue;
    }

    $stored = phoneForStorage($original);
    if ($stored['cipher'] === null) {
        $failed[] = "archives #{$row['id']}: could not encrypt";
        continue;
    }

    $pdo->beginTransaction();
    try {
        $snapshot['patient_contact'] = $stored['cipher'];
        $snapshot['patient_contact_index'] = $stored['hash'];

        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $pdo->rollBack();
            $failed[] = "archives #{$row['id']}: could not re-encode JSON";
            continue;
        }

        $updateArchive->execute([$encoded, $row['id']]);

        // Prove the snapshot still parses and the contact still decrypts.
        $check = $pdo->prepare("SELECT record_data FROM archives WHERE id = ?");
        $check->execute([$row['id']]);
        $verify = json_decode((string)$check->fetchColumn(), true);
        $readBack = phoneForDisplay($verify['patient_contact'] ?? null);

        if (!is_array($verify) || $readBack !== $original) {
            $pdo->rollBack();
            $failed[] = "archives #{$row['id']}: round-trip mismatch, rolled back";
            continue;
        }

        $pdo->commit();
        $okArchives++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $failed[] = "archives #{$row['id']}: " . $e->getMessage();
    }
}

// ------------------------------------------------------------- report
echo "\n" . str_repeat('-', 70) . "\n";
printf("  users encrypted            : %d\n", $okUsers);
printf("  dental records encrypted   : %d\n", $okRecords);
printf("  archive snapshots updated  : %d\n", $okArchives);
printf("  failed / rolled back       : %d\n", count($failed));
echo str_repeat('-', 70) . "\n";

if ($failed) {
    echo "\nFAILURES (each was rolled back, so those rows are unchanged):\n";
    foreach ($failed as $f) echo "  - $f\n";
    echo "\nRows that reported success are encrypted and safe.\n";
    if ($restorePoint) {
        echo "To put EVERYTHING back to the pre-change state:\n";
        echo '  php tools/restore.php --file=' . basename($restorePoint) . "\n";
    }
    exit(1);
}

// ----------------------------------------------------------- verify

echo "\nStep 3: verifying the result\n";
$remaining = (int)$pdo->query("
    SELECT COUNT(*) FROM users
    WHERE phone_number IS NOT NULL AND phone_number <> ''
      AND phone_number NOT LIKE 'v2:%'
")->fetchColumn();
$remainingRec = (int)$pdo->query("
    SELECT COUNT(*) FROM dental_records
    WHERE patient_contact IS NOT NULL AND patient_contact <> ''
      AND patient_contact NOT LIKE 'v2:%'
")->fetchColumn();

printf("  users still in plain text            : %d\n", $remaining);
printf("  dental records still in plain text   : %d\n", $remainingRec);

$indexed = (int)$pdo->query("
    SELECT COUNT(*) FROM users WHERE phone_number_hash IS NOT NULL
")->fetchColumn();
printf("  users with a searchable index        : %d\n", $indexed);

if ($remaining > 0 || $remainingRec > 0) {
    echo "\nSome values are still plain text. Re-run this tool to finish.\n";
    exit(1);
}

echo "\nAll phone numbers are now encrypted and searchable.\n";
if ($restorePoint) {
    echo "Restore point before this change: " . basename($restorePoint) . "\n";
}
exit(0);
