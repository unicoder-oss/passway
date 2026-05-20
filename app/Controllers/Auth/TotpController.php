<?php

declare(strict_types=1);

namespace Passway\Controllers\Auth;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\User;
use Passway\Services\AuthService;
use Passway\Services\SessionService;
use Passway\Services\TotpService;
use Passway\Services\ViewService;

/**
 * Контроллер TOTP (2FA).
 *
 * POST /auth/totp/verify   — ввод кода после password-login (pending сессия)
 * GET  /auth/totp/setup    — получить secret + QR URI для настройки (требует auth)
 * POST /auth/totp/enable   — подтвердить и включить TOTP (требует auth + код)
 * POST /auth/totp/disable  — отключить TOTP (требует auth + пароль)
 */
final class TotpController
{
    public function __construct(
        private readonly TotpService    $totpService,
        private readonly AuthService    $authService,
        private readonly SessionService $sessionService,
        private readonly ViewService    $viewService,
    ) {}

    public function showVerify(Request $request): Response
    {
        $returnTo = null;
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
        if (isset($_SESSION['auth_return_to']) && \is_string($_SESSION['auth_return_to'])) {
            $returnTo = $_SESSION['auth_return_to'];
        }

        return Response::make(200)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($this->viewService->render('auth/totp', [
                'title' => __('ui.titles.totp_verify'),
                'error' => $request->query('error'),
                'returnTo' => $returnTo,
            ]));
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/totp/verify                                             //
    // ------------------------------------------------------------------ //

    /**
     * Проверить TOTP-код в процессе входа (после успешного пароля).
     * Pending user_id берётся из PHP session (хранит AuthService::loginWithPassword).
     */
    public function verify(Request $request): Response
    {
        $code = \trim((string) ($request->input('code') ?? ''));

        if ($code === '') {
            if (!$request->expectsJson() && !$request->isApi()) {
                return Response::make(422)
                    ->withContentType('text/html; charset=utf-8')
                    ->withBody($this->viewService->render('auth/totp', [
                        'title' => __('ui.titles.totp_verify'),
                        'error' => __('ui.auth.totp.code_required'),
                    ]));
            }
            return Response::validationError(['code' => [__('ui.auth.totp.code_required')]]);
        }

        // Получить user_id из pending-сессии
        try {
            $userId = $this->authService->getPendingTotpUserId();
        } catch (AuthException $e) {
            if (!$request->expectsJson() && !$request->isApi()) {
                return Response::redirect('/auth/login?error=' . \urlencode($e->getMessage()));
            }
            return Response::error($e->getMessage(), 401);
        }

        $user = User::findById((int) $userId);
        if ($user === null || !$user->totpEnabled || $user->totpSecret === null || $user->totpNonce === null) {
            return Response::error(__('ui.auth.totp.not_configured'), 400);
        }

        // Верифицировать код
        try {
            $valid = $this->totpService->verifyCode(
                encryptedSecret: $user->totpSecret,
                nonce:           $user->totpNonce,
                code:            $code,
            );
        } catch (\Passway\Exceptions\DecryptionException) {
            return Response::error(__('ui.auth.totp.verify_failed'), 500);
        }

        if (!$valid) {
            if (!$request->expectsJson() && !$request->isApi()) {
                return Response::make(401)
                    ->withContentType('text/html; charset=utf-8')
                    ->withBody($this->viewService->render('auth/totp', [
                        'title' => __('ui.titles.totp_verify'),
                        'error' => __('ui.auth.totp.invalid_code'),
                    ]));
            }
            return Response::error(__('ui.auth.totp.invalid_code'), 401);
        }

        // Завершить вход
        try {
            $result = $this->authService->completeTotpLogin(
                userAgent: $request->header('User-Agent') ?? ''
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), 401);
        }

        $this->sessionService->setCookie($result['raw_token']);

        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
        $returnTo = null;
        if (isset($_SESSION['auth_return_to']) && \is_string($_SESSION['auth_return_to'])) {
            $returnTo = $_SESSION['auth_return_to'];
        }
        unset($_SESSION['auth_return_to']);

        if (!$request->expectsJson() && !$request->isApi()) {
            return Response::redirect($returnTo !== null && str_starts_with($returnTo, '/') ? $returnTo : '/');
        }

        return Response::success([
            'status' => 'success',
            'user'   => [
                'uuid'  => $result['user']->uuid,
                'email' => $result['user']->email,
            ],
        ]);
    }

    // ------------------------------------------------------------------ //
    //  GET /auth/totp/setup                                               //
    // ------------------------------------------------------------------ //

    /**
     * Начать настройку TOTP: вернуть новый secret и QR URI.
     * Пользователь должен отсканировать QR в authenticator-приложении,
     * затем подтвердить кодом через POST /auth/totp/enable.
     *
     * Требует AuthMiddleware.
     */
    public function setup(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if ($user->totpEnabled) {
            return Response::error(__('ui.auth.totp.already_enabled'), 400);
        }

        // Генерируем новый secret (raw, для QR) и шифруем для хранения в session
        $data      = $this->totpService->generateSecret();
        $qrCodeUri = $this->totpService->getQrCodeUri($user->email, $data['raw_secret']);

        // Временно храним зашифрованный secret в PHP session до подтверждения
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION['totp_setup'] = [
            'encrypted' => $data['totp_secret'],
            'nonce'     => $data['totp_nonce'],
            'expires'   => \time() + 600, // 10 минут
        ];

        return Response::success([
            'qr_code_uri' => $qrCodeUri,
            'manual_entry_key' => $data['raw_secret'], // для ручного ввода
            'message' => __('ui.auth.totp.setup_scan_message'),
        ]);
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/totp/enable                                             //
    // ------------------------------------------------------------------ //

    /**
     * Включить TOTP после сканирования QR.
     * Тело: { "code": "123456" }
     *
     * Требует AuthMiddleware.
     */
    public function enable(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if ($user->totpEnabled) {
            return Response::error(__('ui.auth.totp.already_enabled'), 400);
        }

        $code = \trim((string) ($request->input('code') ?? ''));
        if ($code === '') {
            return Response::validationError(['code' => [__('ui.auth.totp.code_required')]]);
        }

        // Получить pending secret из session
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
        $setup = $_SESSION['totp_setup'] ?? null;

        if ($setup === null || $setup['expires'] < \time()) {
            unset($_SESSION['totp_setup']);
            return Response::error(__('ui.auth.totp.setup_expired'), 400);
        }

        // Верифицировать код против pending secret
        try {
            $valid = $this->totpService->verifyCode(
                encryptedSecret: $setup['encrypted'],
                nonce:           $setup['nonce'],
                code:            $code,
            );
        } catch (\Passway\Exceptions\DecryptionException) {
            return Response::error(__('ui.auth.totp.internal_verify_error'), 500);
        }

        if (!$valid) {
            return Response::error(__('ui.auth.totp.invalid_code_with_time_hint'), 401);
        }

        // Сохранить в БД и включить TOTP
        $user->update([
            'totp_secret'  => $setup['encrypted'],
            'totp_nonce'   => $setup['nonce'],
            'totp_enabled' => 1,
            'updated_at'   => now()->format('Y-m-d H:i:s'),
        ]);

        unset($_SESSION['totp_setup']);

        $this->authService->writeAuditLog($user->id, 'auth.totp_enabled', $request->ip(), null, true);

        return Response::success(['message' => __('ui.auth.totp.enabled_success')]);
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/totp/disable                                            //
    // ------------------------------------------------------------------ //

    /**
     * Отключить TOTP.
     * Тело: { "password": "..." }  — подтверждение текущего пароля.
     *
     * Требует AuthMiddleware.
     */
    public function disable(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if (!$user->totpEnabled) {
            return Response::error(__('ui.auth.totp.not_enabled'), 400);
        }

        $password = (string) ($request->input('password') ?? '');
        if ($password === '') {
            return Response::validationError(['password' => [__('ui.auth.totp.disable_password_required')]]);
        }

        if ($user->passwordHash === null) {
            return Response::error(__('ui.auth.totp.disable_password_not_set'), 400);
        }

        // Verify password using HashingService — получим из контейнера
        $hashingService = \Passway\Core\Application::getInstance()->getContainer()
            ->make(\Passway\Services\HashingService::class);

        if (!$hashingService->verifyPassword($password, $user->passwordHash)) {
            return Response::error(__('ui.profile.error_incorrect_password'), 401);
        }

        $user->update([
            'totp_enabled' => 0,
            'totp_secret'  => null,
            'totp_nonce'   => null,
            'updated_at'   => now()->format('Y-m-d H:i:s'),
        ]);

        $this->authService->writeAuditLog($user->id, 'auth.totp_disabled', $request->ip(), null, true);

        return Response::success(['message' => __('ui.auth.totp.disabled_success')]);
    }
}
