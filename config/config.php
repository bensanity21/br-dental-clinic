<?php
// config/config.php - Application configuration & encryption key
// ============================================================
// Clinic information
define('CLINIC_NAME', 'BR Dental Clinic');
define('CLINIC_PHONE', '09066636112');
define('CLINIC_ADDRESS', '74 Nyugan Road, Paliparan 1, Dasmariñas, Cavite, Philippines');
define('CLINIC_EMAIL', 'admin@brdental.com');
define('CLINIC_HOURS', '8:00 AM - 6:00 PM');

// Encryption key for AES-256-CBC (sensitive patient/medical data)
// Generate a new key: bin2hex(random_bytes(32))
// For thesis defense: explain this is stored outside web root in production
define('ENCRYPTION_KEY', 'c22d14af13bd1418ac148f15c6bada17d86a30e4043b669d2e7eba9c56781735');

// Session settings
define('SESSION_TIMEOUT_MINUTES', 5); // 5 minutes inactivity timeout
define('SESSION_NAME', 'br_dental_session');
