<?php
// config/config.php - Application configuration & encryption key
// ============================================================
// Clinic information
define('CLINIC_NAME', 'BR Dental Clinic');
define('CLINIC_PHONE', '09066636112');
define('CLINIC_ADDRESS', '74 Nyugan Road, Paliparan 1, Dasmariñas, Cavite, Philippines');
define('CLINIC_EMAIL', 'admin@brdental.com');
define('CLINIC_HOURS', '8:00 AM - 6:00 PM');

// Keep encryption keys out of source control. Production should provide these
// through the server environment; XAMPP development can use the ignored local file.
$localSecretsPath = __DIR__ . '/secrets.local.php';
$localSecrets = is_file($localSecretsPath) ? require $localSecretsPath : [];
$currentEncryptionKey = getenv('BR_DENTAL_ENCRYPTION_KEY') ?: ($localSecrets['current_key'] ?? '');
$previousEncryptionKey = getenv('BR_DENTAL_ENCRYPTION_KEY_PREVIOUS') ?: ($localSecrets['previous_key'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/i', $currentEncryptionKey)) {
    throw new RuntimeException('A 32-byte BR Dental encryption key must be configured outside source control.');
}
if ($previousEncryptionKey !== '' && !preg_match('/^[a-f0-9]{64}$/i', $previousEncryptionKey)) {
    throw new RuntimeException('The previous BR Dental encryption key is invalid.');
}
define('ENCRYPTION_KEY', $currentEncryptionKey);
define('ENCRYPTION_KEY_PREVIOUS', $previousEncryptionKey);

if ((getenv('APP_ENV') ?: 'development') === 'production'
    && (!getenv('DB_USER') || getenv('DB_PASSWORD') === false || getenv('DB_PASSWORD') === '')) {
    throw new RuntimeException('Production database credentials must be supplied through server environment variables.');
}

// Session settings
define('SESSION_TIMEOUT_MINUTES', 5); // 5 minutes inactivity timeout
define('SESSION_NAME', 'br_dental_session');
