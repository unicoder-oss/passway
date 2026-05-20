<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Exceptions\DecryptionException;
use RuntimeException;

/**
 * Сервис шифрования секретов.
 *
 * Алгоритм: XChaCha20-Poly1305 (IETF) — authenticated encryption (AEAD).
 * Реализован через libsodium (ext-sodium, встроен в PHP 7.2+).
 *
 * Ключ: 32 байта (256 бит), передаётся через переменную окружения MASTER_KEY
 *       в виде 64 hex-символов. НИКОГДА не хранится в БД.
 *
 * Nonce: 24 байта случайных данных, генерируется для каждого шифрования.
 *        Хранится рядом с зашифрованным значением (не секрет, но уникален).
 *
 * Формат хранения:
 *   encrypted_value — base64(ciphertext + mac)
 *   nonce           — hex(24 bytes) = 48 символов
 *
 * Additional data (AAD): опциональны, используются для привязки
 * зашифрованных данных к конкретному ресурсу (защита от replay).
 */
final class EncryptionService
{
    /** Длина ключа XChaCha20-Poly1305 IETF: 32 байта (фиксировано спецификацией) */
    private const KEY_BYTES   = 32;

    /** Длина nonce XChaCha20-Poly1305 IETF: 24 байта (фиксировано спецификацией) */
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
    //  Шифрование / Расшифровка                                           //
    // ------------------------------------------------------------------ //

    /**
     * Зашифровать строку.
     *
     * @param string $plaintext   Открытый текст
     * @param string $aad         Additional Authenticated Data (опционально).
     *                            Например, UUID секрета — привязывает ciphertext к ресурсу.
     * @return EncryptedData      Зашифрованные данные + nonce
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

        // Затираем plaintext в памяти (best-effort — PHP не гарантирует)
        if (\function_exists('sodium_memzero')) { \sodium_memzero($plaintext); }

        return new EncryptedData(
            value: base64_encode($ciphertext),
            nonce: bin2hex($nonce)
        );
    }

    /**
     * Расшифровать строку.
     *
     * @param string $encryptedValue  base64(ciphertext + mac)
     * @param string $nonce           hex(24 байта)
     * @param string $aad             Те же AAD, что использовались при шифровании
     * @return string                 Открытый текст
     * @throws DecryptionException    Если ключ неверен или данные повреждены
     */
    public function decrypt(string $encryptedValue, string $nonce, string $aad = ''): string
    {
        $ciphertext = base64_decode($encryptedValue, strict: true);
        if ($ciphertext === false) {
            throw new DecryptionException('Invalid base64 encoding of encrypted value.');
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
            // Не раскрываем причину (ключ или данные)
            throw new DecryptionException(
                'Decryption failed: authentication tag mismatch or invalid key.'
            );
        }

        return $plaintext;
    }

    /**
     * Перешифровать с новым nonce (например, при обновлении значения секрета).
     *
     * @return EncryptedData Новые зашифрованные данные
     */
    public function reEncrypt(string $encryptedValue, string $nonce, string $aad = ''): EncryptedData
    {
        $plaintext = $this->decrypt($encryptedValue, $nonce, $aad);
        $result    = $this->encrypt($plaintext, $aad);
        if (\function_exists('sodium_memzero')) { \sodium_memzero($plaintext); }
        return $result;
    }

    // ------------------------------------------------------------------ //
    //  Вспомогательные методы                                             //
    // ------------------------------------------------------------------ //

    /**
     * Проверить работоспособность master key (encrypt + decrypt round trip).
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
     * Сгенерировать новый master key.
     * Используется в install.php при первоначальной настройке.
     *
     * @return string 64 hex-символа (32 байта)
     */
    public static function generateMasterKey(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }

    /**
     * Проверить корректность формата master key.
     */
    public static function validateMasterKeyFormat(string $hex): bool
    {
        return \strlen($hex) === 64 && ctype_xdigit($hex);
    }

    // ------------------------------------------------------------------ //
    //  Загрузка ключа                                                     //
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
                'MASTER_KEY must be exactly 64 hex characters (32 bytes).'
            );
        }

        $key = hex2bin($hex);
        if ($key === false || \strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('Failed to decode MASTER_KEY from hex.');
        }

        return $key;
    }
}
