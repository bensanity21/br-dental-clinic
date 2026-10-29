<?php
// includes/encrypt.php - Authenticated encryption for sensitive clinical notes
// ============================================================
// Encrypt: patient phone, address, birthdate, medical history, allergies, treatment notes
// Decrypt only when showing to authorized roles

require_once __DIR__ . '/../config/config.php';

if (!function_exists('encryptData')) {
    function encryptData(string $plaintext): string {
        $key = hex2bin(ENCRYPTION_KEY);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new Exception('Encryption failed');
        }
        return 'v2:' . base64_encode($nonce . $tag . $cipher);
    }
}

if (!function_exists('decryptData')) {
    function decryptData(string $encrypted): ?string {
        if (strncmp($encrypted, 'v2:', 3) === 0) {
            $data = base64_decode(substr($encrypted, 3), true);
            if ($data === false || strlen($data) < 28) return null;
            $nonce = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $cipher = substr($data, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin(ENCRYPTION_KEY), OPENSSL_RAW_DATA, $nonce, $tag);
            return $plain === false ? null : $plain;
        }

        $key = hex2bin(ENCRYPTION_KEY);
        if (ENCRYPTION_KEY_PREVIOUS !== '') $key = hex2bin(ENCRYPTION_KEY_PREVIOUS);
        $data = base64_decode($encrypted, true);
        if ($data === false) {
            return $encrypted; // return as-is if not valid base64 (legacy plain text)
        }
        if (strlen($data) < 17) return $encrypted;
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            return null;
        }
        return $plain;
    }
}
