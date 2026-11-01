<?php
/**
 * includes/phone.php - encrypt patient phone numbers while keeping them matchable.
 *
 * THE PROBLEM
 * A patient's phone number is personal data, so it should not sit in the
 * database in plain text. But it is also used to *find* a patient: when staff
 * add a clinical record they identify the account by name + phone.
 *
 * Encrypting the number breaks that, because encryption uses a fresh random
 * nonce every time - the same number encrypts to different ciphertext on each
 * save, so "WHERE phone_number = ?" would never match again.
 *
 * THE SOLUTION: a blind index
 * Two columns instead of one:
 *
 *   phone_number      AES-256-GCM ciphertext ("v2:..."), for display only
 *   phone_number_hash HMAC-SHA256 of the normalised number, for matching only
 *
 * The hash is deterministic, so equality lookups still work. It is an HMAC
 * keyed with a secret, so the hash cannot be brute-forced back into a phone
 * number by someone who steals the database alone - they would need the key
 * as well. And the ciphertext is what is actually shown to a human.
 *
 * NORMALISATION
 * The same number typed as "0917 123 4567", "09171234567" or "+639171234567"
 * must produce the same hash, so the number is normalised to Philippine
 * MSISDN form (+63XXXXXXXXXX) before hashing. Anything that is not a
 * recognisable phone number returns null and is simply not indexed, which keeps
 * the old name-only matching path working.
 */

require_once __DIR__ . '/encrypt.php';

/** Column that holds the blind index. */
const PHONE_HASH_COLUMN = 'phone_number_hash';

/**
 * Reduce a phone number to a canonical form so formatting differences do not
 * break matching.
 *
 * Philippine numbers are normalised to +63XXXXXXXXXX:
 *   09171234567      -> +639171234567
 *   0917 123 4567    -> +639171234567
 *   0917-123-4567    -> +639171234567
 *   +639171234567    -> +639171234567
 *   639171234567     -> +639171234567
 *
 * Returns null when the input cannot be interpreted as a phone number, so the
 * caller can fall back to not indexing it rather than storing a wrong hash.
 */
function normalizePhone(?string $raw): ?string
{
    if ($raw === null) return null;

    // Keep digits and a single leading +.
    $digits = preg_replace('/[^0-9+]/', '', trim($raw));
    if ($digits === null || $digits === '') return null;

    // Only one leading plus is meaningful.
    $plus = str_starts_with($digits, '+');
    $digits = str_replace('+', '', $digits);

    // Too short to be a phone number, or absurdly long.
    if (strlen($digits) < 7 || strlen($digits) > 15) return null;

    if ($plus) {
        // Already international. Philippine (+63) is the only dialling code we
        // canonicalise; other countries are kept as-is.
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        return '+' . $digits;
    }

    // Local Philippine mobile: 09XXXXXXXXX (11 digits).
    if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
        return '+63' . substr($digits, 1);
    }

    // Mobile typed without the trunk zero: 9XXXXXXXXX (10 digits starting 9).
    if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        return '+63' . $digits;
    }

    // Landline without the trunk 0: 9 digits starting 2-8.
    if (strlen($digits) === 9 && preg_match('/^[2-8]/', $digits)) {
        return '+63' . $digits;
    }

    // 639XXXXXXXXX without the plus.
    if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
        return '+' . $digits;
    }

    // Anything else: hash exactly as dialled, so it still matches itself.
    return '+' . $digits;
}

/**
 * Deterministic, keyed index for a phone number.
 *
 * Returns null when the number cannot be normalised, in which case the caller
 * stores no index for it.
 *
 * The HMAC key is derived from the application encryption key with a domain
 * separator, so this index cannot be reused to attack the ciphertext key.
 */
function phoneBlindIndex(?string $raw): ?string
{
    $normalized = normalizePhone($raw);
    if ($normalized === null) return null;

    $key = hash_hmac('sha256', 'br-dental-phone-index-v1', hex2bin(ENCRYPTION_KEY), true);

    return hash_hmac('sha256', $normalized, $key);
}

/**
 * Prepare a phone number for storage.
 *
 * Returns ['cipher' => ?string, 'hash' => ?string]. Both are null when the input
 * is empty, which clears the field.
 */
function phoneForStorage(?string $raw): array
{
    $trimmed = trim((string)$raw);
    if ($trimmed === '') {
        return ['cipher' => null, 'hash' => null];
    }

    return [
        'cipher' => encryptData($trimmed),
        'hash'   => phoneBlindIndex($trimmed),
    ];
}

/**
 * Read a stored phone number back for display.
 *
 * Tolerates three states that exist in real data:
 *   - "v2:..."  encrypted by this application      -> decrypt
 *   - NULL/''   not provided                        -> null
 *   - anything  legacy plain text from before this  -> return unchanged
 */
function phoneForDisplay(?string $stored): ?string
{
    if ($stored === null) return null;

    $stored = (string)$stored;
    if ($stored === '') return null;

    if (str_starts_with($stored, 'v2:')) {
        return decryptData($stored);
    }

    // Legacy plain text: return as-is rather than losing the value.
    return $stored;
}
