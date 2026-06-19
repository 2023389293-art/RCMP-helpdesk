<?php
// encryption_helper.php
// AES-256-CBC encryption for PDPA-sensitive fields.
// ENCRYPTION_KEY must be exactly 32 bytes (256-bit).
// Store this key securely — outside the web root in production.

define('ENCRYPTION_KEY', 'YOUR_32_BYTE_SECRET_KEY_HERE!!!');  // ← change this
define('ENCRYPTION_CIPHER', 'aes-256-cbc');

function encryptField(?string $value): ?string {
    if ($value === null || $value === '') return $value;
    $iv         = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_CIPHER));
    $encrypted  = openssl_encrypt($value, ENCRYPTION_CIPHER, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function decryptField(?string $value): ?string {
    if ($value === null || $value === '') return $value;
    $decoded = base64_decode($value);
    if ($decoded === false || strpos($decoded, '::') === false) return $value; // not encrypted
    [$iv, $encrypted] = explode('::', $decoded, 2);
    $decrypted = openssl_decrypt($encrypted, ENCRYPTION_CIPHER, ENCRYPTION_KEY, 0, $iv);
    return $decrypted === false ? $value : $decrypted;
}