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
 * Controller login/logout.
 *
 * POST /auth/login   - email+password, returns token or totp_required
 * GET  /auth/logout  - invalidates the session, clears the cookie
 * GET  /auth/me      - current user (requires AuthMiddleware)
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
                'returnTo' => $request->query('return_to', ''),
            ]));
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/login                                                   //
    // ------------------------------------------------------------------ //

    public function login(Request $request): Response
    {
        // Check setup
        try {
            $this->authService->assertSetupComplete();
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 503);
        }

        $email    = \trim((string) ($request->input('email') ?? ''));
        $password = (string) ($request->input('password') ?? '');
        $returnTo = $this->normalizeReturnTo((string) ($request->input('return_to') ?? ''));

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

        // TOTP required - client will redirect to /auth/totp/verify
        if ($result['status'] === 'totp_required') {
            $this->storeReturnTo($returnTo);
            if (!$request->expectsJson() && !$request->isApi()) {
                return Response::redirect('/auth/totp/verify');
            }
            return Response::json([
                'success' => true,
                'status'  => 'totp_required',
                'message' => __('ui.auth.totp.provide_code'),
            ]);
        }

        // Successful login - set the cookie
        $this->sessionService->setCookie($result['raw_token']);

        $user = $result['user'];

        if (!$request->expectsJson() && !$request->isApi()) {
            return Response::redirect($returnTo !== null ? $returnTo : '/');
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

    private function normalizeReturnTo(string $returnTo): ?string
    {
        $returnTo = trim($returnTo);
        if ($returnTo === '' || !str_starts_with($returnTo, '/')) {
            return null;
        }

        return $returnTo;
    }

    private function storeReturnTo(?string $returnTo): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($returnTo === null) {
            unset($_SESSION['auth_return_to']);
            return;
        }

        $_SESSION['auth_return_to'] = $returnTo;
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

        // Browser redirect to the login page (placeholder - will be replaced in Step 13)
        return Response::redirect('/');
    }

    // ------------------------------------------------------------------ //
    //  GET /auth/me                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Current user information.
     * Requires AuthMiddleware (fills AuthContext).
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
