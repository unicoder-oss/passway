<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\User;

/**
 * Сервис первоначальной настройки системы.
 *
 * Управляет:
 *   - генерацией и верификацией setup_token
 *   - созданием первого администратора
 *   - сохранением deploy_mode
 *   - флагом setup_complete
 *
 * Setup flow:
 *   1. Первый запуск: generateAndStoreSetupToken() → токен в stdout / setup_token.txt
 *   2. Администратор открывает /setup, вводит токен + данные → completeSetup()
 *   3. После completeSetup(): setup_complete='1', токен сгорает
 */
final class SetupService
{
    /** Допустимые режимы развёртывания */
    public const DEPLOY_MODES = ['solo', 'team'];

    public function __construct(
        private readonly HashingService $hashingService,
        private readonly TokenService   $tokenService,
    ) {}

    // ------------------------------------------------------------------ //
    //  Состояние setup                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Проверить, завершён ли setup (setup_complete = '1').
     */
    public function isSetupComplete(): bool
    {
        try {
            $value = Database::getInstance()->fetchColumn(
                "SELECT value FROM system_config WHERE key = 'setup_complete'"
            );
            return $value === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Проверить, сгенерирован ли setup_token (хеш не пустой).
     */
    public function hasSetupToken(): bool
    {
        try {
            $hash = Database::getInstance()->fetchColumn(
                "SELECT value FROM system_config WHERE key = 'setup_token_hash'"
            );
            return \is_string($hash) && $hash !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------ //
    //  Генерация токена                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Сгенерировать setup-токен, сохранить SHA-256 хеш в БД и записать
     * plaintext в storage/setup_token.txt.
     *
     * Вызывается один раз при первом запуске.
     * Если токен уже существует — возвращает null.
     *
     * @return string|null  Plaintext-токен (для вывода в stdout) или null
     */
    public function generateAndStoreSetupToken(): ?string
    {
        if ($this->hasSetupToken()) {
            return null;
        }

        $rawToken  = $this->tokenService->generateSetupToken();
        $tokenHash = $this->hashingService->hashToken($rawToken);

        Database::getInstance()->query(
            "UPDATE system_config SET value = ? WHERE key = 'setup_token_hash'",
            [$tokenHash]
        );

        // Сохраняем в файл для удобства (не обязательно, но полезно в Docker-логах)
        $this->writeSetupTokenFile($rawToken);

        return $rawToken;
    }

    /**
     * Верифицировать setup-токен (timing-safe).
     */
    public function verifySetupToken(string $token): bool
    {
        if (\strlen($token) === 0) {
            return false;
        }

        try {
            $storedHash = Database::getInstance()->fetchColumn(
                "SELECT value FROM system_config WHERE key = 'setup_token_hash'"
            );
        } catch (\Throwable) {
            return false;
        }

        if (!\is_string($storedHash) || $storedHash === '') {
            return false;
        }

        $inputHash = $this->hashingService->hashToken($token);
        return \hash_equals($storedHash, $inputHash);
    }

    // ------------------------------------------------------------------ //
    //  Завершение setup                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Завершить setup: создать admin-пользователя, сохранить deploy_mode,
     * выставить setup_complete='1', аннулировать токен.
     *
     * @throws AuthException при неверном токене или уже завершённом setup
     * @throws \InvalidArgumentException при некорректных входных данных
     */
    public function completeSetup(
        string $setupToken,
        string $email,
        string $password,
        string $deployMode,
    ): User {
        // 1. Нельзя повторить setup
        if ($this->isSetupComplete()) {
            throw new AuthException(__('ui.backend.setup.already_complete'));
        }

        // 2. Проверить токен
        if (!$this->verifySetupToken($setupToken)) {
            throw new AuthException(__('ui.backend.setup.invalid_token'));
        }

        // 3. Валидация входных данных
        $email      = \strtolower(\trim($email));
        $deployMode = \strtolower(\trim($deployMode));

        $this->validateEmail($email);
        $this->validatePassword($password);
        $this->validateDeployMode($deployMode);

        // 4. Создать пользователя в транзакции
        $db = Database::getInstance();

        $db->transaction(function () use ($db, $email, $password, $deployMode): void {
            $now          = now()->format('Y-m-d H:i:s');
            $passwordHash = $this->hashingService->hashPassword($password);

            $db->insert('users', [
                'uuid'           => generate_uuid(),
                'email'          => $email,
                'password_hash'  => $passwordHash,
                'avatar_color'   => generate_avatar_color(),
                'totp_enabled'   => 0,
                'is_active'      => 1,
                'email_verified' => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            // 5. Обновить system_config
            $db->query(
                "UPDATE system_config SET value = '1' WHERE key = 'setup_complete'",
            );
            $db->query(
                "UPDATE system_config SET value = ? WHERE key = 'deploy_mode'",
                [$deployMode]
            );
            // Аннулировать токен
            $db->query(
                "UPDATE system_config SET value = '' WHERE key = 'setup_token_hash'",
            );
        });

        // 6. Удалить файл с токеном
        $this->deleteSetupTokenFile();

        $user = User::findByEmail($email);
        if ($user === null) {
            throw new \RuntimeException(__('ui.backend.setup.failed_create_admin'));
        }

        return $user;
    }

    // ------------------------------------------------------------------ //
    //  Валидация                                                          //
    // ------------------------------------------------------------------ //

    /**
     * @throws \InvalidArgumentException
     */
    public function validateEmail(string $email): void
    {
        if (!\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(__('ui.backend.setup.invalid_email'));
        }
    }

    /**
     * Пароль: минимум 8 символов, хотя бы одна буква и одна цифра.
     *
     * @throws \InvalidArgumentException
     */
    public function validatePassword(string $password): void
    {
        if (\strlen($password) < 8) {
            throw new \InvalidArgumentException(__('ui.backend.setup.password_min_length'));
        }
        if (!\preg_match('/[a-zA-Z]/', $password)) {
            throw new \InvalidArgumentException(__('ui.backend.setup.password_requires_letter'));
        }
        if (!\preg_match('/[0-9]/', $password)) {
            throw new \InvalidArgumentException(__('ui.backend.setup.password_requires_digit'));
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validateDeployMode(string $mode): void
    {
        if (!\in_array($mode, self::DEPLOY_MODES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.setup.invalid_deploy_mode', ['allowed' => \implode(', ', self::DEPLOY_MODES)])
            );
        }
    }

    // ------------------------------------------------------------------ //
    //  Файловые операции                                                  //
    // ------------------------------------------------------------------ //

    private function writeSetupTokenFile(string $rawToken): void
    {
        try {
            $path = $this->setupTokenPath();
            $dir = \dirname($path);
            if (!\is_dir($dir) && !@\mkdir($dir, 0750, true) && !\is_dir($dir)) {
                return;
            }
            @\file_put_contents(
                $path,
                "PASSWAY SETUP TOKEN\n\n{$rawToken}\n\nDelete this file after completing setup.\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // Не критично — токен всё равно выводится в stdout
        }
    }

    private function deleteSetupTokenFile(): void
    {
        try {
            $path = $this->setupTokenPath();
            if (\file_exists($path)) {
                @\unlink($path);
            }
        } catch (\Throwable) {
            // Не критично
        }
    }

    private function setupTokenPath(): string
    {
        $path = $_ENV['SETUP_TOKEN_PATH'] ?? storage_path('setup_token.txt');
        return \is_string($path) && \trim($path) !== ''
            ? \trim($path)
            : storage_path('setup_token.txt');
    }
}
