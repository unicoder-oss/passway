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
 * Controller TOTP (2FA).
 *
 * POST /auth/totp/verify   - code entry after password login (pending session)
 * GET  /auth/totp/setup    - get secret plus QR URI for setup (requires auth)
 * POST /auth/totp/enable   - confirm and enable TOTP (requires auth + code)
 * POST /auth/totp/disable  - disable TOTP (requires auth + password)
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
     * Check the TOTP code during login (after a successful password).
     * Pending user_id is taken from the PHP session (stored by AuthService::loginWithPassword).
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

        // Get user_id from the pending session
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

        // Verify the code
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

        // Complete login
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
     * Start setup TOTP: return a new secret and QR URI.
     * The user must scan the QR in an authenticator app,
     * then confirm with a code through POST /auth/totp/enable.
     *
     * Requires AuthMiddleware.
     */
    public function setup(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if ($user->totpEnabled) {
            return Response::error(__('ui.auth.totp.already_enabled'), 400);
        }

        // Generate a new secret (raw, for QR) and encrypt it for session storage
        $data      = $this->totpService->generateSecret();
        $qrCodeUri = $this->totpService->getQrCodeUri($user->email, $data['raw_secret']);

        // Temporarily store the encrypted secret in PHP session until confirmation
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
     * Enable TOTP after scanning the QR.
     * Body: { "code": "123456" }
     *
     * Requires AuthMiddleware.
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

        // Get the pending secret from the session
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
        $setup = $_SESSION['totp_setup'] ?? null;

        if ($setup === null || $setup['expires'] < \time()) {
            unset($_SESSION['totp_setup']);
            return Response::error(__('ui.auth.totp.setup_expired'), 400);
        }

        // Verify the code against the pending secret
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

        // Save to DB and enable TOTP
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
     * Disable TOTP.
     * Body: { "password": "..." }  - current password confirmation.
     *
     * Requires AuthMiddleware.
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

        // Verify password using HashingService - get it from the container
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
