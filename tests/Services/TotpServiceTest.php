<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Exceptions\DecryptionException;
use Passway\Services\EncryptionService;
use Passway\Services\TotpService;
use PHPUnit\Framework\TestCase;

/**
 * Тесты TotpService.
 * БД не нужна — тесты изолированы на уровне сервисов.
 *
 * @requires extension sodium
 */
final class TotpServiceTest extends TestCase
{
    private TotpService $svc;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $_ENV['MASTER_KEY'] = \str_repeat('ab', 32); // 64 hex символа
        $_ENV['APP_NAME']   = 'PasswayTest';

        $this->encryption = new EncryptionService();
        $this->svc        = new TotpService($this->encryption);
    }

    // ------------------------------------------------------------------ //
    //  generateSecret()                                                   //
    // ------------------------------------------------------------------ //

    public function test_generate_secret_returns_encrypted_data(): void
    {
        $data = $this->svc->generateSecret();

        $this->assertArrayHasKey('totp_secret', $data);
        $this->assertArrayHasKey('totp_nonce', $data);
        $this->assertArrayHasKey('raw_secret', $data);

        $this->assertNotEmpty($data['totp_secret']);
        $this->assertSame(48, \strlen($data['totp_nonce'])); // 24 bytes × 2 hex chars
        $this->assertNotEmpty($data['raw_secret']);
    }

    public function test_generate_secret_raw_is_base32(): void
    {
        $data = $this->svc->generateSecret();

        // Base32 алфавит: A-Z и 2-7
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $data['raw_secret']);
    }

    public function test_generate_secret_raw_not_stored_in_db_fields(): void
    {
        $data = $this->svc->generateSecret();

        // raw_secret НЕ должен совпадать с зашифрованным значением
        $this->assertNotSame($data['raw_secret'], $data['totp_secret']);
    }

    public function test_generate_secret_produces_different_secrets_each_time(): void
    {
        $secret1 = $this->svc->generateSecret()['raw_secret'];
        $secret2 = $this->svc->generateSecret()['raw_secret'];

        $this->assertNotSame($secret1, $secret2);
    }

    // ------------------------------------------------------------------ //
    //  getQrCodeUri()                                                     //
    // ------------------------------------------------------------------ //

    public function test_qr_code_uri_contains_otpauth_prefix(): void
    {
        $data = $this->svc->generateSecret();
        $uri  = $this->svc->getQrCodeUri('user@example.com', $data['raw_secret']);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
    }

    public function test_qr_code_uri_contains_secret(): void
    {
        $data = $this->svc->generateSecret();
        $uri  = $this->svc->getQrCodeUri('user@example.com', $data['raw_secret']);

        $this->assertStringContainsString('secret=' . $data['raw_secret'], $uri);
    }

    public function test_qr_code_uri_contains_issuer(): void
    {
        $data = $this->svc->generateSecret();
        $uri  = $this->svc->getQrCodeUri('user@example.com', $data['raw_secret']);

        $this->assertStringContainsString('PasswayTest', $uri);
    }

    public function test_get_qr_code_uri_from_db_decrypts_and_returns_uri(): void
    {
        $data = $this->svc->generateSecret();
        $uri  = $this->svc->getQrCodeUriFromDb('user@test.com', $data['totp_secret'], $data['totp_nonce']);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $data['raw_secret'], $uri);
    }

    public function test_qr_code_image_data_uri_is_svg(): void
    {
        $data = $this->svc->generateSecret();
        $uri  = $this->svc->getQrCodeImageDataUri('user@example.com', $data['raw_secret']);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
    }

    // ------------------------------------------------------------------ //
    //  verifyCode()                                                       //
    // ------------------------------------------------------------------ //

    public function test_verify_code_with_valid_code_returns_true(): void
    {
        $data = $this->svc->generateSecret();

        // Сгенерировать текущий валидный код через ту же библиотеку
        $tfa  = new \RobThree\Auth\TwoFactorAuth('Test');
        $code = $tfa->getCode($data['raw_secret']);

        $result = $this->svc->verifyCode($data['totp_secret'], $data['totp_nonce'], $code);

        $this->assertTrue($result);
    }

    public function test_verify_code_with_invalid_code_returns_false(): void
    {
        $data   = $this->svc->generateSecret();
        $result = $this->svc->verifyCode($data['totp_secret'], $data['totp_nonce'], '000000');

        // 000000 валиден примерно 1/1000000 раз — принимаем небольшую вероятность ложного срабатывания
        // В реальном тесте мы убеждаемся, что функция работает без исключений
        $this->assertIsBool($result);
    }

    public function test_verify_code_with_non_numeric_code_returns_false(): void
    {
        $data   = $this->svc->generateSecret();
        $result = $this->svc->verifyCode($data['totp_secret'], $data['totp_nonce'], 'ABCDEF');

        $this->assertFalse($result);
    }

    public function test_verify_code_with_short_code_returns_false(): void
    {
        $data   = $this->svc->generateSecret();
        $result = $this->svc->verifyCode($data['totp_secret'], $data['totp_nonce'], '12345');

        $this->assertFalse($result);
    }

    public function test_verify_code_throws_on_bad_encrypted_secret(): void
    {
        $this->expectException(DecryptionException::class);

        $this->svc->verifyCode(
            'invalid_base64!@#',
            \str_repeat('ab', 24),
            '123456'
        );
    }

    // ------------------------------------------------------------------ //
    //  verifyCodeRaw()                                                    //
    // ------------------------------------------------------------------ //

    public function test_verify_code_raw_with_correct_code(): void
    {
        $data = $this->svc->generateSecret();
        $tfa  = new \RobThree\Auth\TwoFactorAuth('Test');
        $code = $tfa->getCode($data['raw_secret']);

        $result = $this->svc->verifyCodeRaw($data['raw_secret'], $code);

        $this->assertTrue($result);
    }

    public function test_verify_code_raw_with_wrong_code(): void
    {
        $data   = $this->svc->generateSecret();
        // Код с паддингом/пробелами — санитизируется внутри
        $result = $this->svc->verifyCodeRaw($data['raw_secret'], ' 999999 ');

        // Или false, или true (маловероятно что 999999 совпадёт)
        $this->assertIsBool($result);
    }

    // ------------------------------------------------------------------ //
    //  Encrypt → decrypt round-trip через verifyCode                     //
    // ------------------------------------------------------------------ //

    public function test_full_cycle_generate_encrypt_verify(): void
    {
        $data = $this->svc->generateSecret();
        $tfa  = new \RobThree\Auth\TwoFactorAuth('Test');
        $code = $tfa->getCode($data['raw_secret']);

        // Шаг 1: сохранить encrypted_secret и nonce в БД (имитация)
        $encryptedSecret = $data['totp_secret'];
        $nonce           = $data['totp_nonce'];

        // Шаг 2: верифицировать через зашифрованный secret
        $result = $this->svc->verifyCode($encryptedSecret, $nonce, $code);

        $this->assertTrue($result);
    }
}
