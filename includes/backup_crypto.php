<?php
// Local backup-file encryption. Independent key derivation prevents reuse of
// the patient-field encryption key directly. Format: magic + AES-GCM chunks.
require_once __DIR__ . '/../config/config.php';

const BR_BACKUP_MAGIC = "BRDBAK01";
const BR_BACKUP_CHUNK = 1048576;

function backupKeyCandidates(): array
{
    $keys = [ENCRYPTION_KEY];
    if (ENCRYPTION_KEY_PREVIOUS !== '') $keys[] = ENCRYPTION_KEY_PREVIOUS;
    return array_map(fn($hex) => hash_hkdf('sha256', hex2bin($hex), 32, 'br-dental-backup-file-v1'), $keys);
}

function backupEncryptFile(string $source, string $destination): void
{
    $in = fopen($source, 'rb');
    $out = fopen($destination, 'xb');
    if (!$in || !$out) throw new RuntimeException('Could not open backup encryption files.');
    try {
        $key = backupKeyCandidates()[0];
        if (fwrite($out, BR_BACKUP_MAGIC) !== 8) throw new RuntimeException('Backup write failed.');
        $seq = 0;
        while (!feof($in)) {
            $plain = fread($in, BR_BACKUP_CHUNK);
            if ($plain === false) throw new RuntimeException('Backup read failed.');
            if ($plain === '' && feof($in)) break;
            $len = strlen($plain); $nonce = random_bytes(12); $tag = '';
            $aad = BR_BACKUP_MAGIC . pack('N', $seq) . pack('N', $len);
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
            if ($cipher === false || fwrite($out, pack('N', $len) . $nonce . $tag . $cipher) !== 32 + $len) throw new RuntimeException('Backup encryption/write failed.');
            $seq++;
        }
        $nonce = random_bytes(12); $tag = '';
        if (openssl_encrypt('', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, BR_BACKUP_MAGIC . pack('N', $seq) . 'END', 16) === false
            || fwrite($out, pack('N', 0) . $nonce . $tag) !== 32) throw new RuntimeException('Could not finalize encrypted backup.');
        if (!fflush($out)) throw new RuntimeException('Could not flush encrypted backup.');
    } finally { fclose($in); fclose($out); }
}

function backupDecryptFile(string $source, string $destination): void
{
    $failure = null;
    foreach (backupKeyCandidates() as $key) {
        $in = fopen($source, 'rb'); $out = fopen($destination, 'xb');
        if (!$in || !$out) throw new RuntimeException('Could not open backup decryption files.');
        try {
            if (fread($in, 8) !== BR_BACKUP_MAGIC) throw new RuntimeException('Not a recognized encrypted backup.');
            $seq = 0; $complete = false;
            while (!feof($in)) {
                $head = fread($in, 32);
                if ($head === '' && feof($in)) break;
                if (strlen($head) !== 32) throw new RuntimeException('Encrypted backup is truncated.');
                $len = unpack('N', substr($head, 0, 4))[1]; $nonce = substr($head, 4, 12); $tag = substr($head, 16, 16);
                if ($len > BR_BACKUP_CHUNK) throw new RuntimeException('Invalid encrypted backup chunk size.');
                $cipher = $len ? fread($in, $len) : '';
                if (strlen($cipher) !== $len) throw new RuntimeException('Encrypted backup is truncated.');
                $aad = BR_BACKUP_MAGIC . pack('N', $seq) . ($len ? pack('N', $len) : 'END');
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
                if ($plain === false) throw new RuntimeException('Backup authentication failed (wrong key or damaged file).');
                if ($len === 0) { $complete = true; break; }
                if (fwrite($out, $plain) !== strlen($plain)) throw new RuntimeException('Could not write decrypted backup.');
                $seq++;
            }
            if (!$complete || fread($in, 1) !== '') throw new RuntimeException('Encrypted backup is incomplete or has trailing data.');
            fflush($out); fclose($out); fclose($in); return;
        } catch (Throwable $e) { $failure = $e; fclose($out); fclose($in); @unlink($destination); }
    }
    throw $failure ?? new RuntimeException('Unable to decrypt backup.');
}
