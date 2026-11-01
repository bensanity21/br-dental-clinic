<?php
/**
 * tools/reindex-phones.php - rebuild the phone blind indexes.
 *
 * WHEN THIS IS NEEDED
 * phone_number_hash is an HMAC keyed with the encryption key. If you rotate
 * current_key without keeping the old key as previous_key, every existing
 * index stops matching and patients can no longer be found by phone.
 *
 * This rebuilds every index from the still-decryptable ciphertext, so a key
 * rotation can be completed cleanly instead of silently breaking matching.
 *
 * Run it AFTER the new key is in place and the old rows still decrypt.
 *
 * SAFETY
 *  - Read-only with respect to phone_number: it never re-encrypts, only
 *    recomputes the hash column.
 *  - Refuses to touch a row whose ciphertext cannot be decrypted with the
 *    current key, because it would then have to guess the number. Those rows
 *    are reported instead so you can add the old key as previous_key.
 *  - --dry-run reports without writing.
 *
 * Usage:
 *   php tools/reindex-phones.php --dry-run
 *   php tools/reindex-phones.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encrypt.php';
require_once __DIR__ . '/../includes/phone.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

echo "BR Dental Clinic - rebuild phone blind indexes\n";
echo str_repeat('=', 66) . "\n";

if ($dryRun) {
    echo "DRY RUN - nothing will be written.\n\n";
}

$plan = [];
$unreadable = [];

// ---- users --------------------------------------------------------------
foreach ($pdo->query("SELECT id, email, phone_number, phone_number_hash FROM users WHERE phone_number IS NOT NULL AND phone_number <> ''") as $row) {
    $plain = phoneForDisplay($row['phone_number']);
    if ($plain === null) {
        $unreadable[] = "users #{$row['id']} ({$row['email']}): ciphertext cannot be decrypted with this key";
        continue;
    }
    $new = phoneBlindIndex($plain);
    if ($new !== $row['phone_number_hash']) {
        $plan[] = ['table' => 'users', 'id' => (int)$row['id'], 'hash' => $new,
                   'label' => $row['email'], 'old' => $row['phone_number_hash']];
    }
}

// ---- dental records -----------------------------------------------------
foreach ($pdo->query("SELECT id, patient_contact, patient_contact_index FROM dental_records WHERE patient_contact IS NOT NULL AND patient_contact <> ''") as $row) {
    $plain = phoneForDisplay($row['patient_contact']);
    if ($plain === null) {
        $unreadable[] = "dental_records #{$row['id']}: ciphertext cannot be decrypted with this key";
        continue;
    }
    $new = phoneBlindIndex($plain);
    if ($new !== $row['patient_contact_index']) {
        $plan[] = ['table' => 'dental_records', 'id' => (int)$row['id'], 'hash' => $new,
                   'label' => 'record ' . $row['id'], 'old' => $row['patient_contact_index']];
    }
}

printf("  rows needing a new index : %d\n", count($plan));
printf("  rows that cannot decrypt : %d\n", count($unreadable));
echo "\n";

if ($unreadable) {
    fwrite(STDERR, "These rows cannot be indexed because the current key does not decrypt them.\n");
    fwrite(STDERR, "Add the old key to config/secrets.local.php as 'previous_key', then run again.\n\n");
    foreach ($unreadable as $u) fwrite(STDERR, "  - $u\n");
    echo "\nNothing was changed.\n";
    exit(1);
}

if (!$plan) {
    echo "Every index already matches the current key. Nothing to do.\n";
    exit(0);
}

foreach ($plan as $item) {
    printf(
        "  %-15s #%-4d %-32s %s -> %s\n",
        $item['table'],
        $item['id'],
        $item['label'],
        $item['old'] === null ? '(none)' : substr((string)$item['old'], 0, 10) . '...',
        substr((string)$item['hash'], 0, 10) . '...'
    );
}

if ($dryRun) {
    echo "\nNo changes made. Run without --dry-run to apply.\n";
    exit(0);
}

$done = 0;
foreach ($plan as $item) {
    $column = $item['table'] === 'users' ? 'phone_number_hash' : 'patient_contact_index';
    $stmt = $pdo->prepare("UPDATE {$item['table']} SET {$column} = ? WHERE id = ?");
    $stmt->execute([$item['hash'], $item['id']]);
    $done++;
}

echo "\nUpdated $done row(s).\n";
echo "Verify by searching for a patient by phone in the staff portal.\n";
exit(0);
