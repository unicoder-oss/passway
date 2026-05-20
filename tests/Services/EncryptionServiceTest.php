<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Exceptions\DecryptionException;
use Passway\Services\EncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension sodium
 */
final class EncryptionServiceTest extends TestCase
{
    private EncryptionService $svc;

    protected function setUp(): void
    {
        // Устанавливаем тестовый master key (32 байта = 64 hex)
        $_ENV['MASTER_KEY'] = str_repeat('ab', 32); // "ababab...ab" × 32 = 64 символа
        $this->svc = new EncryptionService();
    }

    public function test_encrypt_returns_non_empty_value_and_nonce(): void
    {
        $result = $this->svc->encrypt('hello-secret');

        $this->assertNotEmpty($result->value);
        $this->assertNotEmpty($result->nonce);
        $this->assertSame(48, strlen($result->nonce)); // 24 bytes * 2 hex chars
    }

    public function test_decrypt_round_trip(): void
    {
        $original  = 'my-super-secret-password-1234!';
        $encrypted = $this->svc->encrypt($original);
        $decrypted = $this->svc->decrypt($encrypted->value, $encrypted->nonce);

        $this->assertSame($original, $decrypted);
    }

    public function test_encrypt_produces_different_ciphertext_each_time(): void
    {
        $plaintext = 'same-plaintext';
        $enc1 = $this->svc->encrypt($plaintext);
        $enc2 = $this->svc->encrypt($plaintext);

        // Разные nonce → разные ciphertext (семантическая безопасность)
        $this->assertNotSame($enc1->value, $enc2->value);
        $this->assertNotSame($enc1->nonce, $enc2->nonce);

        // Но расшифровка даёт одинаковый результат
        $this->assertSame($plaintext, $this->svc->decrypt($enc1->value, $enc1->nonce));
        $this->assertSame($plaintext, $this->svc->decrypt($enc2->value, $enc2->nonce));
    }

    public function test_decrypt_with_aad_succeeds_when_aad_matches(): void
    {
        $aad       = 'secret-uuid-1234';
        $encrypted = $this->svc->encrypt('secret-value', $aad);
        $decrypted = $this->svc->decrypt($encrypted->value, $encrypted->nonce, $aad);

        $this->assertSame('secret-value', $decrypted);
    }

    public function test_decrypt_fails_when_aad_mismatches(): void
    {
        $encrypted = $this->svc->encrypt('secret-value', 'correct-aad');

        $this->expectException(DecryptionException::class);
        $this->svc->decrypt($encrypted->value, $encrypted->nonce, 'wrong-aad');
    }

    public function test_decrypt_fails_with_wrong_nonce(): void
    {
        $encrypted = $this->svc->encrypt('secret-value');
        $wrongNonce = str_repeat('ff', 24); // другой nonce

        $this->expectException(DecryptionException::class);
        $this->svc->decrypt($encrypted->value, $wrongNonce);
    }

    public function test_decrypt_fails_with_tampered_ciphertext(): void
    {
        $encrypted = $this->svc->encrypt('secret-value');
        // Меняем один символ в base64
        $tampered = base64_encode(str_repeat('X', 50));

        $this->expectException(DecryptionException::class);
        $this->svc->decrypt($tampered, $encrypted->nonce);
    }

    public function test_decrypt_fails_with_invalid_base64(): void
    {
        $encrypted = $this->svc->encrypt('test');

        $this->expectException(DecryptionException::class);
        $this->svc->decrypt('not-valid-base64!!!', $encrypted->nonce);
    }

    public function test_decrypt_fails_with_invalid_nonce_length(): void
    {
        $encrypted = $this->svc->encrypt('test');

        $this->expectException(DecryptionException::class);
        $this->svc->decrypt($encrypted->value, 'tooshort');
    }

    public function test_self_test_passes(): void
    {
        $this->assertTrue($this->svc->selfTest());
    }

    public function test_generate_master_key_returns_64_hex_chars(): void
    {
        $key = EncryptionService::generateMasterKey();

        $this->assertSame(64, strlen($key));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    public function test_generate_master_key_is_unique(): void
    {
        $this->assertNotSame(
            EncryptionService::generateMasterKey(),
            EncryptionService::generateMasterKey()
        );
    }

    public function test_validate_master_key_format(): void
    {
        $this->assertTrue(EncryptionService::validateMasterKeyFormat(str_repeat('ab', 32)));
        $this->assertFalse(EncryptionService::validateMasterKeyFormat('tooshort'));
        $this->assertFalse(EncryptionService::validateMasterKeyFormat(str_repeat('zz', 32))); // не hex
        $this->assertFalse(EncryptionService::validateMasterKeyFormat('')); // пустой
    }

    public function test_encrypts_unicode_and_binary_content(): void
    {
        $unicodeText = 'Пароль: €100 & <script>alert("xss")</script>';
        $enc         = $this->svc->encrypt($unicodeText);
        $this->assertSame($unicodeText, $this->svc->decrypt($enc->value, $enc->nonce));

        // Бинарные данные (SSH private key и т.п.)
        $binaryLike = base64_encode(random_bytes(512));
        $enc2       = $this->svc->encrypt($binaryLike);
        $this->assertSame($binaryLike, $this->svc->decrypt($enc2->value, $enc2->nonce));
    }

    public function test_constructor_throws_if_master_key_missing(): void
    {
        $_ENV['MASTER_KEY'] = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MASTER_KEY/');
        new EncryptionService();
    }

    public function test_constructor_throws_if_master_key_invalid_length(): void
    {
        $_ENV['MASTER_KEY'] = 'tooshort';

        $this->expectException(\RuntimeException::class);
        new EncryptionService();
    }
}
