<?php

declare(strict_types=1);

namespace Passway\Controllers\Auth;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Services\AuthService;
use Passway\Services\SessionService;
use Passway\Services\ViewService;

/**
 * Контроллер входа/выхода.
 *
 * POST /auth/login   — email+пароль, возвращает токен или totp_required
 * GET  /auth/logout  — инвалидирует сессию, очищает cookie
 * GET  /auth/me      — текущий пользователь (требует AuthMiddleware)
 */
final class LoginController
{
    public function __construct(
        private readonly AuthService    $authService,
        private readonly SessionService $sessionService,
        private readonly ViewService    $viewService,
    ) {}

    public function show(Request $request): Response
    {
        if (AuthContext::isAuthenticated()) {
            return Response::redirect('/');
        }

        return Response::make(200)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($this->viewService->render('auth/login', [
                'title' => __('ui.titles.login'),
                'error' => $request->query('error'),
                'success' => $request->query('success'),
                'email' => $request->query('email', ''),
            ]));
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/login                                                   //
    // ------------------------------------------------------------------ //

    public function login(Request $request): Response
    {
        // Проверить setup
        try {
            $this->authService->assertSetupComplete();
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 503);
        }

        $email    = \trim((string) ($request->input('email') ?? ''));
        $password = (string) ($request->input('password') ?? '');

        if ($email === '' || $password === '') {
            return Response::validationError([
                'email'    => $email === '' ? ['Email is required'] : [],
                'password' => $password === '' ? ['Password is required'] : [],
            ]);
        }

        try {
            $result = $this->authService->loginWithPassword(
                email:     $email,
                password:  $password,
                ip:        $request->ip(),
                userAgent: $request->header('User-Agent'),
            );
        } catch (AuthException $e) {
            $code = $e->getCode();
            if (!$request->expectsJson() && !$request->isApi()) {
                return Response::make(\in_array($code, [401, 429, 503], true) ? $code : 401)
                    ->withContentType('text/html; charset=utf-8')
                    ->withBody($this->viewService->render('auth/login', [
                        'title' => __('ui.titles.login'),
                        'error' => $e->getMessage(),
                        'email' => $email,
                    ]));
            }
            return Response::error($e->getMessage(), \in_array($code, [401, 429, 503]) ? $code : 401);
        }

        // TOTP требуется — клиент перенаправит на /auth/totp/verify
        if ($result['status'] === 'totp_required') {
            if (!$request->expectsJson() && !$request->isApi()) {
                return Response::redirect('/auth/totp/verify');
            }
            return Response::json([
                'success' => true,
                'status'  => 'totp_required',
                'message' => __('ui.auth.totp.provide_code'),
            ]);
        }

        // Успешный вход — устанавливаем cookie
        $this->sessionService->setCookie($result['raw_token']);

        $user = $result['user'];

        if (!$request->expectsJson() && !$request->isApi()) {
            return Response::redirect('/');
        }

        return Response::success([
            'status' => 'success',
            'user'   => [
                'uuid'          => $user->uuid,
                'email'         => $user->email,
                'totp_enabled'  => $user->totpEnabled,
                'email_verified'=> $user->emailVerified,
            ],
        ]);
    }

    // ------------------------------------------------------------------ //
    //  GET /auth/logout                                                   //
    // ------------------------------------------------------------------ //

    public function logout(Request $request): Response
    {
        $rawToken = $this->sessionService->getTokenFromCookie();

        if ($rawToken !== null) {
            $this->sessionService->invalidate($rawToken);
        }

        if (AuthContext::isAuthenticated()) {
            $user = AuthContext::requireUser();
            $this->authService->writeAuditLog(
                $user->id,
                'auth.logout',
                $request->ip(),
                $request->header('User-Agent'),
                true,
            );
        }

        $this->sessionService->clearCookie();

        if ($request->expectsJson()) {
            return Response::success(['message' => __('ui.auth.logout_success')]);
        }

        // Браузерный редирект на страницу входа (заглушка — будет заменена в Шаге 13)
        return Response::redirect('/');
    }

    // ------------------------------------------------------------------ //
    //  GET /auth/me                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Информация о текущем пользователе.
     * Требует AuthMiddleware (заполняет AuthContext).
     */
    public function me(Request $request): Response
    {
        $user = AuthContext::requireUser();

        return Response::success([
            'user' => [
                'uuid'           => $user->uuid,
                'email'          => $user->email,
                'totp_enabled'   => $user->totpEnabled,
                'email_verified' => $user->emailVerified,
                'last_login_at'  => $user->lastLoginAt,
                'last_login_ip'  => $user->lastLoginIp,
                'created_at'     => $user->createdAt,
            ],
        ]);
    }
}
