<?php
/**
 * tests/tools/phone-index.php - verify the blind index behaviour.
 *
 * Covers the property that matters for clinical safety: the same phone number
 * typed in different formats must produce the SAME index, so patient matching
 * keeps working after encryption.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/phone.php';

$pass = 0;
$fail = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $label\n"; }
    else { $fail++; echo "  \033[31mFAIL\033[0m $label" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

echo "Phone normalisation and blind index\n";
echo str_repeat('=', 68) . "\n\n";

// ---- normalisation -------------------------------------------------------
echo "1. Normalisation to a canonical form\n";
$normCases = [
    '09171234567'   => '+639171234567',
    '0917 123 4567' => '+639171234567',
    '0917-123-4567' => '+639171234567',
    '(0917) 123 4567' => '+639171234567',
    '+639171234567' => '+639171234567',
    '639171234567'  => '+639171234567',
    '9171234567'    => '+639171234567',
    '0917.123.4567' => '+639171234567',
];
foreach ($normCases as $input => $expected) {
    check(normalizePhone($input) === $expected, "normalises '$input'");
}

check(normalizePhone('') === null, 'empty string -> null');
check(normalizePhone(null) === null, 'null -> null');
check(normalizePhone('abc') === null, 'letters -> null');
check(normalizePhone('123') === null, 'too short -> null');
check(normalizePhone(str_repeat('9', 20)) === null, 'absurdly long -> null');

// ---- the property that matters ------------------------------------------
echo "\n2. Every format of one number produces the SAME index\n";
$formats = ['09171234567', '0917 123 4567', '0917-123-4567', '+639171234567', '639171234567', '9171234567'];
$indexes = array_values(array_unique(array_map('phoneBlindIndex', $formats)));
check(count($indexes) === 1, 'all 6 formats hash identically', 'distinct indexes: ' . count($indexes));
check(strlen((string)$indexes[0]) === 64, 'index is a 64-char hex digest');

// ---- different numbers must not collide ---------------------------------
echo "\n3. Different numbers produce different indexes\n";
$one = phoneBlindIndex('09171234567');
$two = phoneBlindIndex('09171234568');
check($one !== $two, 'two neighbouring numbers hash differently');

// ---- storage round trip --------------------------------------------------
echo "\n4. Storage round trip\n";
$stored = phoneForStorage('0917 123 4567');
check($stored['cipher'] !== null && str_starts_with($stored['cipher'], 'v2:'), 'ciphertext has the v2 prefix');
check(!str_contains((string)$stored['cipher'], '0917'), 'ciphertext does not contain the number');
check($stored['hash'] === phoneBlindIndex('09171234567'), 'index matches the number');
check(phoneForDisplay($stored['cipher']) === '0917 123 4567', 'display returns the original formatting');

$cleared = phoneForStorage('');
check($cleared['cipher'] === null && $cleared['hash'] === null, 'empty input clears both columns');

$cleared2 = phoneForStorage('   ');
check($cleared2['cipher'] === null && $cleared2['hash'] === null, 'whitespace clears both columns');

// ---- encryption is non-deterministic, the index is not -------------------
echo "\n5. Ciphertext differs per save, index does not\n";
$a = phoneForStorage('09171234567');
$b = phoneForStorage('09171234567');
check($a['cipher'] !== $b['cipher'], 'same number encrypts differently each time (random nonce)');
check($a['hash'] === $b['hash'], 'index is identical for the same number (matchable)');
check(phoneForDisplay($a['cipher']) === phoneForDisplay($b['cipher']), 'both ciphertexts decrypt to the same number');

// ---- legacy tolerance ----------------------------------------------------
echo "\n6. Legacy plain text is still readable\n";
check(phoneForDisplay('09171234567') === '09171234567', 'plain text from before encryption is returned as-is');
check(phoneForDisplay(null) === null, 'null stays null');
check(phoneForDisplay('') === null, 'empty stays null');

// ---- the index must not be reversible from the database alone ------------
echo "\n7. Index is keyed, not a bare hash\n";
$bare = hash('sha256', '+639171234567');
check(phoneBlindIndex('09171234567') !== $bare, 'index is an HMAC, not a plain SHA-256 of the number');

echo "\n" . str_repeat('-', 68) . "\n";
if ($fail === 0) {
    echo "\033[32mALL PHONE INDEX CHECKS PASSED: $pass/$pass\033[0m\n";
} else {
    echo "\033[31m$fail failed, $pass passed\033[0m\n";
}
exit($fail === 0 ? 0 : 1);
