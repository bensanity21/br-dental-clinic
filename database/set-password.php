<?php
/**
 * database/set-password.php - set a password for an existing account.
 *
 * This is the supported way to give a seeded account its first password, and
 * also works for resetting one later. Passwords are never stored in SQL files
 * or in this repository.
 *
 * Usage (command line only):
 *
 *   php database/set-password.php admin@brdental.com
 *   php database/set-password.php admin@brdental.com --generate
 *
 * With --generate a strong random password is created and printed once. With
 * no flag you are prompted, and the typing is hidden.
 */

if (PHP_SAPI !== 'cli') {
    // Never reachable over HTTP.
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../connectdb.php';

$args = array_slice($argv, 1);
$generate = in_array('--generate', $args, true);

// --password=... allows scripted use; it is still refused for known defaults.
$inlinePassword = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--password=')) {
        $inlinePassword = substr($arg, strlen('--password='));
    }
}
$args = array_values(array_filter(
    $args,
    static fn($a) => $a !== '--generate' && !str_starts_with($a, '--password=')
));

$email = filter_var($args[0] ?? '', FILTER_VALIDATE_EMAIL);

if (!$email) {
    fwrite(STDERR, "usage: php database/set-password.php <email> [--generate]\n");
    exit(1);
}

$stmt = $pdo->prepare("SELECT id, full_name, role, password_hash FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, "No account with that email address.\n");
    exit(1);
}

/** Read a line from the terminal without echoing it. */
function readHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: shell out to PowerShell for hidden input.
        $script = 'powershell -NoProfile -Command "$p=Read-Host -AsSecureString; ' .
                  '$b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); ' .
                  '[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)"';
        $value = (string)shell_exec($script);
        return trim($value);
    }

    shell_exec('stty -echo');
    $value = (string)fgets(STDIN);
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
    return trim($value);
}

if ($generate) {
    // 24 random bytes, base64url: strong and easy to paste.
    $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
} elseif ($inlinePassword !== null) {
    $password = $inlinePassword;
} else {
    $password = readHidden('New password (not shown): ');
    $confirm = readHidden('Confirm password: ');

    if ($password !== $confirm) {
        fwrite(STDERR, "The passwords do not match. Nothing was changed.\n");
        exit(1);
    }
}

// Refuse the passwords that have historically been published in this repo.
// Checked before the length rule so the message names the actual problem.
$banned = ['admin123!', 'dentist123!', 'patient123!', 'password', 'changeme', 'admin'];
$isBanned = in_array(strtolower($password), $banned, true);
foreach ($banned as $bad) {
    if ($bad !== '' && str_contains(strtolower($password), $bad)) {
        $isBanned = true;
        break;
    }
}
if ($isBanned) {
    fwrite(STDERR, "That password is a known default and is refused. Nothing was changed.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Use at least 12 characters. Nothing was changed.\n");
    exit(1);
}

$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
    ->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);

// Any session opened before the change is no longer trustworthy.
$pdo->prepare("DELETE FROM sessions WHERE data LIKE ?")
    ->execute(['%user_id|i:' . (int)$user['id'] . ';%']);

fwrite(STDOUT, "\nPassword updated.\n");
fwrite(STDOUT, "  Account: {$user['full_name']} <{$email}>\n");
fwrite(STDOUT, "  Role:    {$user['role']}\n");

if ($generate) {
    fwrite(STDOUT, "  Password: {$password}\n\n");
    fwrite(STDOUT, "This password is shown once and is not stored anywhere in plain text.\n");
    fwrite(STDOUT, "Save it now, then sign in and change it from Account Settings.\n");
} else {
    fwrite(STDOUT, "  Sessions for this account have been signed out.\n");
}
