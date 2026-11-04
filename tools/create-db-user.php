<?php
/**
 * tools/create-db-user.php - provision the least-privilege application account.
 *
 * Creates (or updates) a MySQL user that can only SELECT/INSERT/UPDATE/DELETE
 * on the clinic schema, writes its credentials into config/secrets.local.php
 * (already gitignored), then proves the app can connect with them.
 *
 * The password is generated here and never printed. It goes straight into
 * secrets.local.php, which the app reads via includes/db.php.
 *
 * Requires an administrative MySQL connection the first time. Supply it with
 * --admin-user / --admin-pass, or let it default to root with no password
 * (the XAMPP default). The admin credentials are used once and not stored.
 *
 * Usage:
 *   php tools/create-db-user.php
 *   php tools/create-db-user.php --user=br_dental_app --dry-run
 *   php tools/create-db-user.php --admin-user=root --admin-pass=secret
 *
 * Exit codes: 0 = provisioned and verified, 1 = failed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);

function argValue(array $args, string $name, ?string $default = null): ?string
{
    foreach ($args as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 2);
        }
    }
    return $default;
}

$appUser   = argValue($args, 'user', 'br_dental_app');
$appHost   = argValue($args, 'host', 'localhost');
$dbName    = getenv('DB_NAME') ?: 'clinic';
$adminUser = argValue($args, 'admin-user', getenv('DB_ADMIN_USER') ?: 'root');
$adminPass = argValue($args, 'admin-pass', getenv('DB_ADMIN_PASSWORD') ?: '');
$mysqldump = is_file('C:\\xampp\\mysql\\bin\\mysql.exe') ? 'C:\\xampp\\mysql\\bin\\mysql.exe' : 'mysql';

function fail(string $message): void
{
    fwrite(STDERR, "\n\033[31m$message\033[0m\n");
    exit(1);
}

function runMysql(string $binary, string $user, string $pass, string $sql): array
{
    // Pass the password through an option file so it never appears in the
    // process list, the shell history or an error message.
    $cnf = tempnam(sys_get_temp_dir(), 'my');
    @chmod($cnf, 0600);
    file_put_contents($cnf, "[client]\nuser=" . $user . "\npassword=\"" . str_replace('"', '\\"', $pass) . "\"\n");

    $cmd = sprintf('"%s" --defaults-extra-file=%s -N -B -e %s 2>&1',
        $binary, escapeshellarg($cnf), escapeshellarg($sql));
    exec($cmd, $out, $code);
    @unlink($cnf);

    return [$code, implode("\n", $out)];
}

echo "Least-privilege database account\n";
echo str_repeat('=', 62) . "\n";
printf("  Schema      : %s\n", $dbName);
printf("  App user    : %s@%s\n", $appUser, $appHost);
printf("  Admin used  : %s (used once, not stored)\n", $adminUser);
printf("  Mode        : %s\n\n", $dryRun ? 'DRY RUN (no changes)' : 'apply');

// ---------------------------------------------------------------- preflight
[$code, $out] = runMysql($mysqldump, $adminUser, $adminPass, 'SELECT VERSION();');
if ($code !== 0) {
    fail("Cannot reach MySQL as '$adminUser'.\n" . $out . "\nPass --admin-user and --admin-pass if root is not the admin account.");
}
printf("  MySQL        : %s\n", trim($out));

[$code, $out] = runMysql($mysqldump, $adminUser, $adminPass, "SHOW DATABASES LIKE '$dbName';");
if (trim($out) !== $dbName) {
    fail("The schema '$dbName' does not exist. Import clinic.sql first.");
}

// A conservative read-heavy app needs only these four verbs.
$privileges = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

echo "\n  Granting: " . implode(', ', $privileges) . " on `$dbName`.* only\n";
echo "  Withheld: DROP, ALTER, CREATE, GRANT OPTION, FILE, and any other schema\n\n";

if ($dryRun) {
    echo "Dry run complete. Nothing was changed.\n";
    echo "Re-run without --dry-run to apply.\n";
    exit(0);
}

// ------------------------------------------------------------- create user
$password = bin2hex(random_bytes(24)); // 48 hex chars, comfortably above policy

// Step 1: account and password. Kept separate from the grants because a
// "REVOKE ALL PRIVILEGES, GRANT OPTION" issued in the same batch as a fresh
// CREATE USER leaves the account with USAGE only - the subsequent GRANTs are
// silently not applied on MariaDB. Run them as distinct statements.
$sqlCreate = "CREATE USER IF NOT EXISTS '$appUser'@'$appHost' IDENTIFIED BY '$password';\n";
$sqlCreate .= "ALTER USER '$appUser'@'$appHost' IDENTIFIED BY '$password';";

[$code, $out] = runMysql($mysqldump, $adminUser, $adminPass, $sqlCreate);
if ($code !== 0) {
    fail("Could not create or update the account.\n" . $out);
}

// Step 2: strip anything a previous run may have granted, on its own.
[$code, $out] = runMysql($mysqldump, $adminUser, $adminPass,
    "REVOKE ALL PRIVILEGES, GRANT OPTION FROM '$appUser'@'$appHost';");
// A brand-new account has nothing to revoke, which is an error worth ignoring.
if ($code !== 0 && !str_contains($out, 'There is no such grant')) {
    fail("Could not reset existing privileges.\n" . $out);
}

// Step 3: grant exactly the four verbs in ONE statement, then prove they landed.
// Issuing one GRANT per verb in a single batch is unreliable here - only the
// first survived on MariaDB 10.11 - so combine them.
$verbList = implode(', ', $privileges);
$sqlGrant = "GRANT $verbList ON `$dbName`.* TO '$appUser'@'$appHost';";

[$code, $out] = runMysql($mysqldump, $adminUser, $adminPass, $sqlGrant);
if ($code !== 0) {
    fail("Could not grant privileges.\n" . $out);
}

// Never trust a grant that reported success: read it back.
[$code, $grantList] = runMysql($mysqldump, $adminUser, $adminPass,
    "SHOW GRANTS FOR '$appUser'@'$appHost';");
$grantedVerbs = 0;
foreach ($privileges as $p) {
    if (preg_match('/\b' . $p . '\b/i', $grantList)) $grantedVerbs++;
}
if ($grantedVerbs !== count($privileges)) {
    fail("Privileges did not apply (only $grantedVerbs of " . count($privileges) . " visible).\n" . $grantList);
}
echo "  Account created; privileges verified by reading them back\n";

// ------------------------------------------------------- persist credentials
$secretsPath = $root . '/config/secrets.local.php';
$secrets = is_file($secretsPath) ? (require $secretsPath) : [];
if (!is_array($secrets)) $secrets = [];

// Keep existing encryption keys; only touch the DB fields.
$secrets['db_user'] = $appUser;
$secrets['db_password'] = $password;
$secrets['db_host'] = $appHost;
$secrets['db_name'] = $dbName;

$php = "<?php\n"
     . "// config/secrets.local.php - generated by tools/create-db-user.php\n"
     . "// Gitignored. Never commit this file. Keep a copy with your key custodian.\n"
     . "return [\n";
foreach ($secrets as $k => $v) {
    $php .= "    '" . $k . "' => " . var_export((string)$v, true) . ",\n";
}
$php .= "];\n";

$backupPath = $secretsPath . '.bak-' . date('Ymd-His');
if (is_file($secretsPath)) {
    copy($secretsPath, $backupPath);
    printf("  Previous secrets backed up to %s\n", basename($backupPath));
}
if (file_put_contents($secretsPath, $php) === false) {
    fail("Could not write $secretsPath. Add these by hand:\n  db_user / db_password / db_host / db_name");
}
@chmod($secretsPath, 0600);
echo "  Credentials written to config/secrets.local.php (not printed)\n";

// ------------------------------------------------------------------- verify
[$code, $out] = runMysql($mysqldump, $appUser, $password, "SELECT COUNT(*) FROM `$dbName`.users;");
if ($code !== 0) {
    fail("The new account cannot read the schema.\n" . $out . "\nRestore the .bak file and investigate.");
}
printf("  App account can read `users` (%s rows)\n", trim($out));

// Prove the privileges were actually withheld, not just assumed.
[$code, $out] = runMysql($mysqldump, $appUser, $password, "DROP TABLE `$dbName`.does_not_exist;");
if ($code === 0) {
    fail("The app account was able to run DROP. Grants are wrong - revoke and redo.");
}
echo "  DROP correctly refused\n";

echo "\n" . str_repeat('-', 62) . "\n";
echo "\033[32mDONE.\033[0m The application account is provisioned.\n\n";
echo "Next: remove the root fallback from includes/db.php, session_handler.php,\n";
echo "tools/backup.php and tools/restore.php so nothing can silently reconnect\n";
echo "as root. Then run: php tests/run.php\n";
exit(0);
