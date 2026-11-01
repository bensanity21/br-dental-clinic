<?php
/**
 * tools/export-key.php - write the encryption key to a file for offline custody.
 *
 * WHY THIS EXISTS
 * The database backup does NOT contain the AES key. If the key is only in
 * config/secrets.local.php on this one machine and the disk dies, every
 * encrypted patient field (home address, date of birth, emergency contact,
 * medical history, allergies, clinical notes) becomes permanently unreadable.
 *
 * This writes the key to a separate file you store somewhere else - a USB
 * drive in a locked drawer, a password manager's secure note, or printed and
 * sealed. Restoring the database without the key gives you a clinic full of
 * unreadable records.
 *
 * Usage:
 *   php tools/export-key.php                 write to a new file in backups/
 *   php tools/export-key.php --print         print to the terminal instead
 *   php tools/export-key.php --to=D:\key.txt write to a specific path
 *
 * The output file is NOT in the web root and is gitignored.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$args    = array_slice($argv, 1);
$toStdout = in_array('--print', $args, true);
$target  = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--to=')) $target = substr($a, 5);
}

$current  = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '';
$previous = defined('ENCRYPTION_KEY_PREVIOUS') ? ENCRYPTION_KEY_PREVIOUS : '';

if (!preg_match('/^[a-f0-9]{64}$/i', $current)) {
    fwrite(STDERR, "No valid encryption key is configured. Nothing written.\n");
    exit(1);
}

$stamp = date('Y-m-d_His');
$body  = "";
$body .= "BR Dental Clinic - AES-256 encryption key\n";
$body .= "Exported: " . date('Y-m-d H:i:s') . "\n";
$body .= str_repeat('=', 60) . "\n\n";
$body .= "STORE THIS SEPARATELY FROM THE DATABASE BACKUP.\n";
$body .= "Without it, encrypted patient fields cannot be recovered.\n\n";
$body .= "Active key (current_key):\n";
$body .= "  $current\n\n";

if ($previous !== '' && preg_match('/^[a-f0-9]{64}$/i', $previous)) {
    $body .= "Retired key (previous_key) - still needed for older rows:\n";
    $body .= "  $previous\n\n";
}

$body .= "To use this key on a restored system, create config/secrets.local.php:\n\n";
$body .= "<?php\nreturn [\n";
$body .= "    'current_key'  => '$current',\n";
if ($previous !== '') {
    $body .= "    'previous_key' => '$previous',\n";
}
$body .= "];\n";
$body .= str_repeat('=', 60) . "\n";

if ($toStdout) {
    echo $body;
    exit(0);
}

if ($target === null) {
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create $dir\n");
        exit(1);
    }
    $target = $dir . "/encryption-key_{$stamp}.txt";
}

if (@file_put_contents($target, $body) === false) {
    fwrite(STDERR, "Could not write $target\n");
    exit(1);
}

echo "Encryption key exported.\n\n";
echo "  File: $target\n\n";
echo "NEXT STEPS - the key is only safe once it is off this machine:\n";
echo "  1. Copy it to a USB drive or into a password manager secure note.\n";
echo "  2. Confirm the copy opens and shows the same key.\n";
echo "  3. Delete this local file, or keep it only if backups/ is itself backed up\n";
echo "     to a different machine.\n\n";
echo "Anyone with this file can decrypt every patient record in the database.\n";
echo "Treat it like the keys to the clinic.\n";
