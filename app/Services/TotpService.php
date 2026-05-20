<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Exceptions\DecryptionException;
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\TwoFactorAuthException;

/**
 * TOTP (Time-based One-Time Password) сервис на базе robthree/twofactorauth.
 *
 * Хранение секрета:
 *   - raw Base32 secret генерируется библиотекой
 *   - шифруется через EncryptionService (XChaCha20-Poly1305)
 *   - в БД: users.totp_secret (encrypted_value), users.totp_nonce
 *
 * НИКОГДА не хранит plaintext secret в БД или логах.
 */
final class TotpService
{
    private readonly TwoFactorAuth $tfa;

    public function __construct(
        private readonly EncryptionService $encryptionService,
    ) {
        // Issuer — отображается в TOTP-приложении (Google Authenticator, Authy и т.д.)
        $issuer = (string) ($_ENV['APP_NAME'] ?? 'Passway');
        $this->tfa = new TwoFactorAuth(issuer: $issuer);
    }

    // ------------------------------------------------------------------ //
    //  Генерация секрета                                                  //
    // ------------------------------------------------------------------ //

    /**
     * Сгенерировать новый TOTP-секрет и зашифровать его.
     *
     * Возвращает массив для записи в users:
     *   ['totp_secret' => string, 'totp_nonce' => string]
     *
     * @return array{totp_secret: string, totp_nonce: string, raw_secret: string}
     * @throws TwoFactorAuthException
     */
    public function generateSecret(): array
    {
        // 160 бит (32 Base32-символа) — TOTP RFC рекомендует >= 128 бит
        $rawSecret = $this->tfa->createSecret(160);

        $encrypted = $this->encryptionService->encrypt($rawSecret);

        return [
            'totp_secret' => $encrypted->value,
            'totp_nonce'  => $encrypted->nonce,
            'raw_secret'  => $rawSecret, // возвращаем для QR-кода, НЕ хранить
        ];
    }

    // ------------------------------------------------------------------ //
    //  QR-код                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Вернуть otpauth:// URI для генерации QR-кода клиентом.
     *
     * @param string $email       Email пользователя (label в URI)
     * @param string $rawSecret   Plaintext Base32 secret (из generateSecret())
     */
    public function getQrCodeUri(string $email, string $rawSecret): string
    {
        // Формат: otpauth://totp/{issuer}:{email}?secret={secret}&issuer={issuer}
        return $this->tfa->getQRText($email, $rawSecret);
    }

    /**
     * Расшифровать секрет и вернуть QR URI.
     * Используется когда нужно снова показать QR после регистрации.
     *
     * @throws DecryptionException
     */
    public function getQrCodeUriFromDb(string $email, string $encryptedSecret, string $nonce): string
    {
        $rawSecret = $this->encryptionService->decrypt($encryptedSecret, $nonce);
        return $this->getQrCodeUri($email, $rawSecret);
    }

    // ------------------------------------------------------------------ //
    //  Верификация кода                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Верифицировать TOTP-код против зашифрованного секрета из БД.
     *
     * @param string $encryptedSecret users.totp_secret
     * @param string $nonce           users.totp_nonce
     * @param string $code            Введённый пользователем 6-значный код
     * @param int    $discrepancy     Допустимое отклонение в периодах (default: 1 = ±30 сек)
     *
     * @throws DecryptionException если секрет не расшифровать
     */
    public function verifyCode(
        string $encryptedSecret,
        string $nonce,
        string $code,
        int    $discrepancy = 1,
    ): bool {
        // Санитизация кода — только цифры
        $code = \preg_replace('/\D/', '', $code) ?? '';
        if (\strlen($code) !== 6) {
            return false;
        }

        $rawSecret = $this->encryptionService->decrypt($encryptedSecret, $nonce);

        try {
            return $this->tfa->verifyCode($rawSecret, $code, $discrepancy);
        } catch (TwoFactorAuthException) {
            return false;
        } finally {
            // Зачистить расшифрованный секрет из памяти
            \sodium_memzero($rawSecret);
        }
    }

    /**
     * Верифицировать код по raw-секрету (используется при первом включении 2FA).
     */
    public function verifyCodeRaw(string $rawSecret, string $code, int $discrepancy = 1): bool
    {
        $code = \preg_replace('/\D/', '', $code) ?? '';
        if (\strlen($code) !== 6) {
            return false;
        }

        try {
            return $this->tfa->verifyCode($rawSecret, $code, $discrepancy);
        } catch (TwoFactorAuthException) {
            return false;
        }
    }
}
