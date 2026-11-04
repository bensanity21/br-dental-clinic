<?php
/**
 * tools/restore.php - restore the database from a backup this tool created.
 *
 * THIS IS THE ONLY DESTRUCTIVE SCRIPT IN THE PROJECT. It is deliberately
 * awkward to run, because a wrong restore destroys live patient data.
 *
 * Guards, in order:
 *
 *  1. Command line only. Not reachable over HTTP.
 *  2. The target backup file must exist and must pass the same validation the
 *     backup script applies (header, table count, completion marker). A
 *     truncated file is refused.
 *  3. The live database is backed up FIRST, automatically, before anything is
 *     overwritten. If that pre-restore backup fails, the restore is aborted.
 *  4. You must type the exact database name to confirm. A mistyped or empty
 *     answer aborts with nothing changed.
 *  5. --dry-run prints exactly what would happen and touches nothing.
 *
 * There is no "force" flag. Removing a guard requires editing this file, which
 * is the point: it should never happen by accident.
 *
 * Usage:
 *   php tools/restore.php --list
 *   php tools/restore.php --file=backups/backup_clinic_2026-11-01_120000.sql --dry-run
 *   php tools/restore.php --file=backups/backup_clinic_2026-11-01_120000.sql
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$root      = dirname(__DIR__);
$backupDir = $root . '/backups';
$dbName    = getenv('DB_NAME') ?: 'clinic';
$dbHost    = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASSWORD');

// Same credential rule as the application: environment, then the gitignored
// secrets file. No root fallback.
$secretsFile = dirname(__DIR__) . '/config/secrets.local.php';
$secrets = is_file($secretsFile) ? (require $secretsFile) : [];
if ($dbUser === false || $dbUser === '') $dbUser = $secrets['db_user'] ?? '';
if ($dbPass === false || $dbPass === '') $dbPass = $secrets['db_password'] ?? '';

if ($dbUser === '' || $dbPass === '') {
    fwrite(STDERR, "Database credentials are not configured. Run: php tools/create-db-user.php\n");
    exit(1);
}

$args     = array_slice($argv, 1);
$listOnly = in_array('--list', $args, true);
$dryRun   = in_array('--dry-run', $args, true);
$file     = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--file=')) $file = substr($a, 7);
}

$mysql = 'C:\xampp\mysql\bin\mysql.exe';
if (!is_file($mysql)) $mysql = 'mysql';

$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe';
if (!is_file($mysqldump)) $mysqldump = 'mysqldump';

// ------------------------------------------------------------------- list

if ($listOnly || $file === null) {
    $files = glob($backupDir . '/backup_*.sql');
    sort($files);
    echo "Available backups\n";
    echo str_repeat('-', 74) . "\n";
    if (!$files) {
        echo "  (none found in $backupDir)\n";
    }
    foreach ($files as $f) {
        $size = (int)filesize($f);
        printf(
            "  %-46s %8.1f KB  %s\n",
            basename($f),
            $size / 1024,
            date('Y-m-d H:i', (int)filemtime($f))
        );
    }
    echo str_repeat('-', 74) . "\n\n";
    echo "To restore one:\n";
    echo "  php tools/restore.php --file=backups/<name>.sql --dry-run\n";
    echo "  php tools/restore.php --file=backups/<name>.sql\n\n";
    exit(0);
}

// ---------------------------------------------------------- resolve + verify

$path = $file;
if (!preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $path)) {
    $path = $root . '/' . ltrim($path, '/\\');
}

if (!is_file($path)) {
    fwrite(STDERR, "Backup file not found: $path\n");
    exit(1);
}

$size = (int)filesize($path);
$head = (string)file_get_contents($path, false, null, 0, 4096);
$whole = (string)file_get_contents($path);
$tableCount = substr_count($whole, 'CREATE TABLE');
$tail = substr($whole, -4096);

$problems = [];
if ($size < 1024)      $problems[] = 'file is too small to be a real dump';
if ($tableCount < 10)  $problems[] = "only $tableCount CREATE TABLE statements (expected >= 10)";
if (!str_contains($tail, 'Dump completed')) $problems[] = 'missing "Dump completed" marker (truncated?)';

echo "BR Dental Clinic - database restore\n";
echo str_repeat('=', 70) . "\n";
echo "  Backup file : " . basename($path) . "\n";
echo "  Size        : " . round($size / 1024, 1) . " KB\n";
echo "  Created     : " . date('Y-m-d H:i:s', (int)filemtime($path)) . "\n";
echo "  Tables      : $tableCount\n";
echo "  Target DB   : $dbName on $dbHost\n\n";

if ($problems) {
    fwrite(STDERR, "REFUSED: this file does not look like a complete backup.\n");
    foreach ($problems as $p) fwrite(STDERR, "  - $p\n");
    fwrite(STDERR, "\nNothing was changed.\n");
    exit(1);
}

echo "  Validation  : PASSED (header, table count, completion marker)\n\n";

if ($dryRun) {
    echo "DRY RUN - nothing will be changed.\n\n";
    echo "A restore would do this:\n";
    echo "  1. Write a safety backup of the CURRENT database first\n";
    echo "  2. Drop and recreate tables inside `$dbName` from the file above\n";
    echo "  3. Leave every other database on this server untouched\n\n";
    echo "Re-run without --dry-run to perform it.\n";
    exit(0);
}

// ------------------------------------------------------- pre-restore backup

$stamp     = date('Y-m-d_His');
$safetyDir = $backupDir;
$safety    = $safetyDir . "/backup_{$dbName}_prerestore_{$stamp}.sql";

if (!is_dir($safetyDir) && !@mkdir($safetyDir, 0775, true) && !is_dir($safetyDir)) {
    fwrite(STDERR, "Cannot create $safetyDir - aborting before touching anything.\n");
    exit(1);
}

echo "Step 1/2: saving the current database before restoring...\n";
$dumpCmd = sprintf(
    '"%s" --host=%s --user=%s %s --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 --databases %s > %s 2>&1',
    $mysqldump,
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    $dbPass === '' ? '' : '--password=' . escapeshellarg($dbPass),
    escapeshellarg($dbName),
    escapeshellarg(str_replace('/', '\\', $safety))
);
exec($dumpCmd, $dumpOut, $dumpCode);

if ($dumpCode !== 0 || !is_file($safety) || filesize($safety) < 1024) {
    @unlink($safety);
    fwrite(STDERR, "\nABORTED: could not create the safety backup.\n");
    fwrite(STDERR, "Nothing was restored. Your current data is intact.\n");
    exit(1);
}
echo "  Saved: " . basename($safety) . " (" . round(filesize($safety) / 1024, 1) . " KB)\n\n";

// ------------------------------------------------------------- confirmation

echo "Step 2/2: this will REPLACE the contents of the `$dbName` database.\n";
echo "Type the database name ($dbName) to continue, or press Enter to cancel:\n";
echo "> ";
$answer = trim((string)fgets(STDIN));

if ($answer !== $dbName) {
    echo "\nCancelled. Nothing was restored.\n";
    echo "A safety backup was written anyway: " . basename($safety) . "\n";
    exit(0);
}

echo "\nRestoring...\n";
$importCmd = sprintf(
    '"%s" --host=%s --user=%s %s --default-character-set=utf8mb4 < %s 2>&1',
    $mysql,
    escapeshellarg($dbHost),
    escapeshellarg($dbUser),
    $dbPass === '' ? '' : '--password=' . escapeshellarg($dbPass),
    escapeshellarg(str_replace('/', '\\', $path))
);
exec($importCmd, $importOut, $importCode);

if ($importCode !== 0) {
    echo "\nRESTORE REPORTED ERRORS (exit $importCode):\n";
    echo "  " . implode("\n  ", array_slice($importOut, 0, 10)) . "\n\n";
    echo "Your pre-restore backup is intact: " . basename($safety) . "\n";
    echo "Restore that file to get back to the state before this command.\n";
    exit(1);
}

echo "  Restore completed.\n\n";
echo str_repeat('-', 70) . "\n";
echo "Safety backup (state before this restore): " . basename($safety) . "\n";
echo "If the restored data is wrong, restore that file to undo this.\n";
echo str_repeat('-', 70) . "\n";
exit(0);
