<?php
// includes/encrypt.php - AES-256-CBC encryption for sensitive data
// ============================================================
// Encrypt: patient phone, address, birthdate, medical history, allergies, treatment notes
// Decrypt only when showing to authorized roles

require_once __DIR__ . '/../config/config.php';

if (!function_exists('encryptData')) {
    function encryptData(string $plaintext): string {
        $key = hex2bin(ENCRYPTION_KEY);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new Exception('Encryption failed');
        }
        return base64_encode($iv . $cipher);
    }
}

if (!function_exists('decryptData')) {
    function decryptData(string $encrypted): string {
        $key = hex2bin(ENCRYPTION_KEY);
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            return $encrypted; // return as-is if not valid base64 (legacy plain text)
        }
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            return $encrypted;
        }
        return $plain;
    }
}
