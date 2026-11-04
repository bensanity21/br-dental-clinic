<?php
/**
 * tools/backup.php - automated, non-destructive database backup.
 *
 * SAFETY DESIGN (why this cannot lose patient data):
 *
 *  1. BACKUP IS READ-ONLY. It runs mysqldump, which only SELECTs. There is no
 *     INSERT, UPDATE or DELETE anywhere in the backup path, so running it can
 *     never change or remove a record.
 *
 *  2. --single-transaction gives a consistent snapshot of all InnoDB tables
 *     without locking them, so the clinic keeps working during the backup and
 *     the dump is not half-written.
 *
 *  3. The dump is written to a temp file first and only renamed into place
 *     after mysqldump reports success and the file passes validation. A failed
 *     or truncated backup never replaces a good one.
 *
 *  4. Retention never deletes the newest backup, and only removes files that
 *     this script created and that are older than the retention window.
 *
 * Usage:
 *   php tools/backup.php                 create a backup, prune old ones
 *   php tools/backup.php --no-prune      create one, keep everything
 *   php tools/backup.php --keep=30       override retention (days)
 *   php tools/backup.php --list          show existing backups, change nothing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$root       = dirname(__DIR__);
$backupDir  = $root . '/backups';
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'clinic';
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASSWORD');

// Same credential rule as the application: environment, then the gitignored
// secrets file. No root fallback, so a misconfigured backup fails loudly
// instead of exporting with server-wide privileges.
$secretsFile = dirname(__DIR__) . '/config/secrets.local.php';
$secrets = is_file($secretsFile) ? (require $secretsFile) : [];
if ($dbUser === false || $dbUser === '') $dbUser = $secrets['db_user'] ?? '';
if ($dbPass === false || $dbPass === '') $dbPass = $secrets['db_password'] ?? '';

if ($dbUser === '' || $dbPass === '') {
    fwrite(STDERR, "Database credentials are not configured. Run: php tools/create-db-user.php\n");
    exit(1);
}

$args       = array_slice($argv, 1);
$noPrune    = in_array('--no-prune', $args, true);
$listOnly   = in_array('--list', $args, true);
$keepDays   = 14;
foreach ($args as $a) {
    if (preg_match('/^--keep=(\d+)$/', $a, $m)) $keepDays = max(1, (int)$m[1]);
}

$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe';
if (!is_file($mysqldump)) {
    $mysqldump = 'mysqldump'; // rely on PATH
}

if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "Cannot create $backupDir\n");
        exit(1);
    }
}

/** Human-readable size. */
function fmtSize(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ---------------------------------------------------------------- list mode

if ($listOnly) {
    $files = glob($backupDir . '/backup_*.sql');
    sort($files);
    if (!$files) {
        echo "No backups yet in $backupDir\n";
        exit(0);
    }
    echo "Backups in $backupDir\n";
    echo str_repeat('-', 70) . "\n";
    foreach ($files as $f) {
        printf(
            "  %-44s %8s  %s\n",
            basename($f),
            fmtSize((int)filesize($f)),
            date('Y-m-d H:i:s', (int)filemtime($f))
        );
    }
    echo str_repeat('-', 70) . "\n";
    printf("  %d backup(s), %s total\n", count($files), fmtSize(array_sum(array_map('filesize', $files))));
    exit(0);
}

// ------------------------------------------------------------------- backup

$stamp   = date('Y-m-d_His');
$final   = $backupDir . "/backup_{$dbName}_{$stamp}.sql";
$temp    = $final . '.part';

echo "BR Dental Clinic - database backup\n";
echo str_repeat('=', 62) . "\n";
echo "  Database : $dbName\n";
echo "  Server   : $dbHost\n";
echo "  Output   : $final\n\n";

// --single-transaction: consistent InnoDB snapshot, no table locks.
// --routines / --triggers: keep stored logic with the data.
// --no-tablespaces: avoids needing the PROCESS privilege on MariaDB.
$cmd = sprintf(
    '"%s" --host=%s --user=%s %s --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 --databases %s > %s 2> %s',
    $mysqldump,
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    $dbPass === '' ? '' : '--password=' . escapeshellarg($dbPass),
    escapeshellarg($dbName),
    escapeshellarg(str_replace('/', '\\', $temp)),
    escapeshellarg(str_replace('/', '\\', $temp . '.err'))
);

$start = microtime(true);
exec($cmd, $out, $code);
$elapsed = microtime(true) - $start;

$errFile = $temp . '.err';
$errText = is_file($errFile) ? trim((string)file_get_contents($errFile)) : '';

if ($code !== 0 || !is_file($temp)) {
    @unlink($temp);
    @unlink($errFile);
    fwrite(STDERR, "\nBACKUP FAILED (mysqldump exit $code)\n");
    if ($errText !== '') fwrite(STDERR, "  $errText\n");
    fwrite(STDERR, "\nNo existing backup was touched.\n");
    exit(1);
}

// ---- Validate before promoting the temp file to a real backup -----------
$size = (int)filesize($temp);
$head = (string)file_get_contents($temp, false, null, 0, 4096);
$tail = '';
$fh = fopen($temp, 'rb');
if ($fh) {
    fseek($fh, max(0, $size - 4096));
    $tail = (string)fread($fh, 4096);
    fclose($fh);
}

$hasCreate = str_contains($head, 'CREATE TABLE') || str_contains($head, 'MySQL dump');
$hasTables = substr_count((string)file_get_contents($temp), 'CREATE TABLE');

$problems = [];
if ($size < 1024)                 $problems[] = 'file is suspiciously small (' . $size . ' bytes)';
if (!$hasCreate)                  $problems[] = 'no CREATE TABLE and no mysqldump header';
if ($hasTables < 10)              $problems[] = "only $hasTables CREATE TABLE statements (expected >= 10)";
if (!str_contains($tail, 'Dump completed') && !str_contains($tail, '-- Dump completed')) {
    // MariaDB appends a completion marker; its absence means truncation.
    $problems[] = 'missing "Dump completed" marker, file may be truncated';
}

@unlink($errFile);

if ($problems) {
    @unlink($temp);
    fwrite(STDERR, "\nBACKUP VALIDATION FAILED - temp file discarded\n");
    foreach ($problems as $p) fwrite(STDERR, "  - $p\n");
    fwrite(STDERR, "\nAny previous backup is untouched.\n");
    exit(1);
}

// Promote atomically.
if (!@rename($temp, $final)) {
    @unlink($temp);
    fwrite(STDERR, "\nCould not move the backup into place.\n");
    exit(1);
}

printf("  Written  : %s in %.1fs\n", fmtSize($size), $elapsed);
printf("  Tables   : %d CREATE TABLE statements\n", $hasTables);
echo "  Verified : size, header, table count and completion marker all present\n";

// ------------------------------------------------------------------ pruning

if (!$noPrune) {
    $cutoff = time() - ($keepDays * 86400);
    $all = glob($backupDir . '/backup_*.sql');
    sort($all); // oldest first
    $newest = end($all);
    $removed = 0;

    foreach ($all as $f) {
        if ($f === $newest) continue;            // never delete the newest
        if ($f === $final) continue;             // never delete what we just made
        if ((int)filemtime($f) >= $cutoff) continue;
        if (@unlink($f)) $removed++;
    }

    if ($removed > 0) {
        echo "  Pruned   : $removed backup(s) older than {$keepDays} days\n";
    }
}

$remaining = glob($backupDir . '/backup_*.sql');
echo "\nBackup complete. " . count($remaining) . " backup(s) on disk.\n";

// Remind about the key, which is NOT in the dump.
echo "\n" . str_repeat('-', 62) . "\n";
echo "REMINDER: this backup does NOT include the AES encryption key.\n";
echo "Encrypted patient fields cannot be decrypted without it.\n";
echo "Back up config/secrets.local.php separately and securely.\n";
echo str_repeat('-', 62) . "\n";

exit(0);
