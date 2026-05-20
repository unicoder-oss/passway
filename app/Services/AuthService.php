<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\User;

/**
 * Сервис аутентификации: email+пароль, TOTP-пендинг, логи.
 *
 * Возвращаемые статусы loginWithPassword():
 *   'success'        → ['status', 'user', 'raw_token']
 *   'totp_required'  → ['status']  (pending user_id сохранён в PHP session)
 */
final class AuthService
{
    /** Максимум неудачных попыток за окно до блокировки */
    private const RATE_LIMIT_MAX     = 5;
    /** Окно rate limiting в секундах (15 минут) */
    private const RATE_LIMIT_WINDOW  = 900;
    /** TTL pending-TOTP сессии в секундах (5 минут) */
    private const TOTP_PENDING_TTL   = 300;

    public function __construct(
        private readonly HashingService $hashingService,
        private readonly SessionService $sessionService,
        private readonly ?AuditService  $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Email + пароль                                                     //
    // ------------------------------------------------------------------ //

    /**
     * Попытка входа по email + паролю.
     *
     * @return array{status: string, user?: User, raw_token?: string}
     * @throws AuthException при неверных данных / rate limit / inactive
     */
    public function loginWithPassword(
        string  $email,
        string  $password,
        ?string $ip,
        ?string $userAgent,
    ): array {
        // 1. Проверка setup_complete
        $this->assertSetupComplete();

        // 2. Rate limiting по IP
        $this->assertRateLimit($ip);

        // 3. Найти пользователя
        $email = \strtolower(\trim($email));
        $user  = User::findByEmail($email);

        if ($user === null || $user->passwordHash === null) {
            // Не раскрываем, что пользователь не существует
            $this->recordFailedAttempt($ip);
            $this->writeAuditLog(null, 'auth.login_fail', $ip, $userAgent, false, [
                'email'  => $email,
                'reason' => 'user_not_found',
            ]);
            throw new AuthException('Invalid email or password');
        }

        // 4. Проверить активность аккаунта
        if (!$user->isActive) {
            $this->recordFailedAttempt($ip);
            $this->writeAuditLog($user->id, 'auth.login_fail', $ip, $userAgent, false, [
                'reason' => 'account_inactive',
            ]);
            throw new AuthException('Account is inactive');
        }

        // 5. Проверить пароль
        if (!$this->hashingService->verifyPassword($password, $user->passwordHash)) {
            $this->recordFailedAttempt($ip);
            $this->writeAuditLog($user->id, 'auth.login_fail', $ip, $userAgent, false, [
                'reason' => 'wrong_password',
            ]);
            throw new AuthException('Invalid email or password');
        }

        // 6. Перехешировать пароль если параметры изменились
        if ($this->hashingService->needsRehash($user->passwordHash)) {
            $newHash = $this->hashingService->hashPassword($password);
            $user->update(['password_hash' => $newHash]);
        }

        // 7. TOTP: если включён — отложить создание сессии до ввода кода
        if ($user->totpEnabled) {
            $this->storeTotpPending($user->id, $ip);
            $this->writeAuditLog($user->id, 'auth.totp_pending', $ip, $userAgent, true);
            return ['status' => 'totp_required'];
        }

        // 8. Создать сессию
        $rawToken = $this->sessionService->create($user->id, $ip, $userAgent);

        // 9. Обновить last_login
        $user->update([
            'last_login_at' => now()->format('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);

        $this->writeAuditLog($user->id, 'auth.login_success', $ip, $userAgent, true, [
            'method' => 'password',
        ]);

        return [
            'status'    => 'success',
            'user'      => $user,
            'raw_token' => $rawToken,
        ];
    }

    // ------------------------------------------------------------------ //
    //  TOTP-pending                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Сохранить "ожидание TOTP" в PHP session после успешного пароля.
     */
    private function storeTotpPending(string $userId, ?string $ip): void
    {
        $this->ensureSessionStarted();
        $_SESSION['totp_pending'] = [
            'user_id'  => $userId,
            'ip'       => $ip,
            'expires'  => \time() + self::TOTP_PENDING_TTL,
        ];
    }

    /**
     * Завершить вход после TOTP-верификации.
     * Вызывается из TotpController::verify().
     *
     * @return array{status: string, user: User, raw_token: string}
     * @throws AuthException
     */
    public function completeTotpLogin(string $userAgent): array
    {
        $this->ensureSessionStarted();

        $pending = $_SESSION['totp_pending'] ?? null;

        if ($pending === null || $pending['expires'] < \time()) {
            unset($_SESSION['totp_pending']);
            throw new AuthException('TOTP session expired. Please log in again.');
        }

        $user = User::findById((int) $pending['user_id']);
        if ($user === null || !$user->isActive) {
            unset($_SESSION['totp_pending']);
            throw new AuthException('User not found or inactive');
        }

        unset($_SESSION['totp_pending']);

        $ip       = $pending['ip'] ?? null;
        $rawToken = $this->sessionService->create($user->id, $ip, $userAgent);

        $user->update([
            'last_login_at' => now()->format('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);

        $this->writeAuditLog($user->id, 'auth.login_success', $ip, $userAgent, true, [
            'method' => 'password+totp',
        ]);

        return [
            'status'    => 'success',
            'user'      => $user,
            'raw_token' => $rawToken,
        ];
    }

    /**
     * Получить user_id из pending-TOTP (без завершения — для TotpService).
     * @throws AuthException если нет pending-сессии
     */
    public function getPendingTotpUserId(): string
    {
        $this->ensureSessionStarted();
        $pending = $_SESSION['totp_pending'] ?? null;

        if ($pending === null || $pending['expires'] < \time()) {
            unset($_SESSION['totp_pending']);
            throw new AuthException('TOTP session expired. Please log in again.');
        }

        return (string) $pending['user_id'];
    }

    // ------------------------------------------------------------------ //
    //  Setup-check                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Проверить что setup завершён (setup_complete = '1').
     * @throws AuthException с кодом 503 если нет
     */
    public function assertSetupComplete(): void
    {
        $value = Database::getInstance()->fetchColumn(
            "SELECT value FROM system_config WHERE key = 'setup_complete'"
        );

        if ($value !== '1') {
            throw new AuthException('Setup not complete. Please run /setup first.', 503);
        }
    }

    // ------------------------------------------------------------------ //
    //  Rate limiting                                                      //
    // ------------------------------------------------------------------ //

    /**
     * Проверить rate limit для данного IP.
     * @throws AuthException 429 если превышен лимит
     */
    private function assertRateLimit(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $windowStart = \date('Y-m-d H:i:s', \time() - self::RATE_LIMIT_WINDOW);

        $db  = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT count, window_start FROM rate_limit_log WHERE ip_address = ? AND bucket = ?',
            [$ip, 'auth']
        );

        if ($row === null) {
            return; // Нет записи — не заблокирован
        }

        // Если окно истекло — запись устарела, не блокируем
        if ($row['window_start'] < $windowStart) {
            return;
        }

        if ((int) $row['count'] >= self::RATE_LIMIT_MAX) {
            throw new AuthException(
                'Too many failed login attempts. Please try again in 15 minutes.',
                429
            );
        }
    }

    /**
     * Зафиксировать неудачную попытку (increment или сброс окна).
     */
    private function recordFailedAttempt(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $db          = Database::getInstance();
        $now         = now()->format('Y-m-d H:i:s');
        $windowStart = \date('Y-m-d H:i:s', \time() - self::RATE_LIMIT_WINDOW);

        $row = $db->fetchOne(
            'SELECT id, count, window_start FROM rate_limit_log WHERE ip_address = ? AND bucket = ?',
            [$ip, 'auth']
        );

        if ($row === null) {
            // Первый неудачный запрос — вставляем
            try {
                $db->insert('rate_limit_log', [
                    'ip_address'   => $ip,
                    'bucket'       => 'auth',
                    'count'        => 1,
                    'window_start' => $now,
                    'updated_at'   => $now,
                ]);
            } catch (\Exception) {
                // Гонка — другой запрос уже вставил, увеличиваем count
                $db->query(
                    'UPDATE rate_limit_log SET count = count + 1, updated_at = ? WHERE ip_address = ? AND bucket = ?',
                    [$now, $ip, 'auth']
                );
            }
        } elseif ($row['window_start'] < $windowStart) {
            // Окно истекло — сбрасываем счётчик
            $db->update(
                'rate_limit_log',
                ['count' => 1, 'window_start' => $now, 'updated_at' => $now],
                ['ip_address' => $ip, 'bucket' => 'auth']
            );
        } else {
            // В пределах окна — увеличиваем
            $db->query(
                'UPDATE rate_limit_log SET count = count + 1, updated_at = ? WHERE ip_address = ? AND bucket = ?',
                [$now, $ip, 'auth']
            );
        }
    }

    // ------------------------------------------------------------------ //
    //  Audit log                                                          //
    // ------------------------------------------------------------------ //

    /**
     * @param array<string, mixed> $details
     */
    public function writeAuditLog(
        ?string $userId,
        string  $action,
        ?string $ip,
        ?string $userAgent = null,
        bool    $success = true,
        array   $details = [],
    ): void {
        $this->getAuditService()->record(
            action: $action,
            userId: $userId,
            ipAddress: $ip,
            userAgent: $userAgent,
            details: $details,
            success: $success,
        );
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function ensureSessionStarted(): void
    {
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService());
    }
}
