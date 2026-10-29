<?php
// One-time CLI migration from legacy AES-CBC notes to versioned AES-GCM notes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encrypt.php';

if (ENCRYPTION_KEY_PREVIOUS === '') {
    fwrite(STDERR, "Previous encryption key is not configured; nothing was changed.\n");
    exit(1);
}

try {
    $pdo->beginTransaction();
    $rows = $pdo->query("SELECT id, clinical_notes FROM dental_records WHERE clinical_notes IS NOT NULL")->fetchAll();
    $update = $pdo->prepare('UPDATE dental_records SET clinical_notes = ? WHERE id = ?');
    $migrated = 0;

    foreach ($rows as $row) {
        $stored = (string)$row['clinical_notes'];
        if (strncmp($stored, 'v2:', 3) === 0) continue;

        $plain = decryptData($stored);
        if ($plain === null) {
            throw new RuntimeException('Could not verify/decrypt a legacy note. Transaction rolled back.');
        }
        $update->execute([encryptData($plain), $row['id']]);
        $migrated++;
    }

    $pdo->commit();
    fwrite(STDOUT, "Re-encrypted $migrated clinical note(s).\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Migration failed; changes were rolled back. Check the database backup and key configuration.\n");
    exit(1);
}
