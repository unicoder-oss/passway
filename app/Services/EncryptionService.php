<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Exceptions\DecryptionException;
use RuntimeException;

/**
 * Secret encryption service.
 *
 * Algorithm: XChaCha20-Poly1305 (IETF) - authenticated encryption (AEAD).
 * Implemented through libsodium (ext-sodium, built into PHP 7.2+).
 *
 * Key: 32 bytes (256 bits), passed through the environment variable MASTER_KEY
 *       as 64 hex characters. NEVER stored in the DB.
 *
 * Nonce: 24 bytes of random data, generated for each encryption.
 *        Stored next to the encrypted value (not secret, but unique).
 *
 * Storage format:
 *   encrypted_value — base64(ciphertext + mac)
 *   nonce           - hex(24 bytes) = 48 characters
 *
 * Additional data (AAD): optional, used to bind
 * encrypted data to a specific resource (replay protection).
 */
final class EncryptionService
{
    /** XChaCha20-Poly1305 IETF key length: 32 bytes (fixed by the specification) */
    private const KEY_BYTES   = 32;

    /** Length nonce XChaCha20-Poly1305 IETF: 24 bytes (fixed by the specification) */
    private const NONCE_BYTES = 24;

    private string $masterKey;

    public function __construct()
    {
        if (!\extension_loaded('sodium')) {
            throw new RuntimeException(
                'ext-sodium is required for EncryptionService. Install php-sodium package.'
            );
        }
        $this->masterKey = $this->loadMasterKey();
    }

    // ------------------------------------------------------------------ //
    //  Encryption / Decryption                                           //
    // ------------------------------------------------------------------ //

    /**
     * Encrypt a string.
     *
     * @param string $plaintext   Plaintext
     * @param string $aad         Additional Authenticated Data (optional).
     *                            For example, UUID secret - binds ciphertext to the resource.
     * @return EncryptedData      Encrypted data plus nonce
     */
    public function encrypt(string $plaintext, string $aad = ''): EncryptedData
    {
        $nonce = \random_bytes(self::NONCE_BYTES);

        $ciphertext = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $this->masterKey
        );

        // Wipe plaintext from memory (best-effort - PHP does not guarantee it)
        if (\function_exists('sodium_memzero')) { \sodium_memzero($plaintext); }

        return new EncryptedData(
            value: base64_encode($ciphertext),
            nonce: bin2hex($nonce)
        );
    }

    /**
     * Decrypt a string.
     *
     * @param string $encryptedValue  base64(ciphertext + mac)
     * @param string $nonce           hex(24 bytes)
     * @param string $aad             Same AAD used for encryption
     * @return string                 Plaintext
     * @throws DecryptionException    If the key is wrong or data is corrupted
     */
    public function decrypt(string $encryptedValue, string $nonce, string $aad = ''): string
    {
        $ciphertext = base64_decode($encryptedValue, strict: true);
        if ($ciphertext === false) {
            throw new DecryptionException(__('ui.backend.security.invalid_encrypted_base64'));
        }

        $expectedHexLen = self::NONCE_BYTES * 2; // 48
        if (\strlen($nonce) !== $expectedHexLen || !ctype_xdigit($nonce)) {
            throw new DecryptionException(
                "Invalid nonce: must be {$expectedHexLen} hex characters (" . self::NONCE_BYTES . " bytes)."
            );
        }
        $nonceBytes = hex2bin($nonce);

        $plaintext = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $aad,
            $nonceBytes,
            $this->masterKey
        );

        if ($plaintext === false) {
            // Do not disclose the reason (key or data)
            throw new DecryptionException(
                __('ui.backend.security.decrypt_failed')
            );
        }

        return $plaintext;
    }

    /**
     * Re-encrypt with a new nonce (for example, when updating a secret value).
     *
     * @return EncryptedData New encrypted data
     */
    public function reEncrypt(string $encryptedValue, string $nonce, string $aad = ''): EncryptedData
    {
        $plaintext = $this->decrypt($encryptedValue, $nonce, $aad);
        $result    = $this->encrypt($plaintext, $aad);
        if (\function_exists('sodium_memzero')) { \sodium_memzero($plaintext); }
        return $result;
    }

    // ------------------------------------------------------------------ //
    //  Helper methods                                             //
    // ------------------------------------------------------------------ //

    /**
     * Check master key operability (encrypt + decrypt round trip).
     */
    public function selfTest(): bool
    {
        try {
            $original  = 'passway-self-test-' . \random_bytes(8);
            $encrypted = $this->encrypt($original);
            $decrypted = $this->decrypt($encrypted->value, $encrypted->nonce);
            return \hash_equals($original, $decrypted);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate a new master key.
     * Used in install.php during initial setup.
     *
     * @return string 64 hex-characters (32 bytes)
     */
    public static function generateMasterKey(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }

    /**
     * Check master key format correctness.
     */
    public static function validateMasterKeyFormat(string $hex): bool
    {
        return \strlen($hex) === 64 && ctype_xdigit($hex);
    }

    // ------------------------------------------------------------------ //
    //  Key loading                                                     //
    // ------------------------------------------------------------------ //

    private function loadMasterKey(): string
    {
        $hex = $_ENV['MASTER_KEY'] ?? '';

        if ($hex === '') {
            throw new RuntimeException(
                'MASTER_KEY is not set. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        if (!self::validateMasterKeyFormat($hex)) {
            throw new RuntimeException(
                __('ui.backend.security.master_key_length')
            );
        }

        $key = hex2bin($hex);
        if ($key === false || \strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException(__('ui.backend.security.master_key_decode_failed'));
        }

        return $key;
    }
}
