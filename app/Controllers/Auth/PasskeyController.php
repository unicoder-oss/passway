<?php

declare(strict_types=1);

namespace Passway\Controllers\Auth;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\Passkey;
use Passway\Services\AuthService;
use Passway\Services\PasskeyService;
use Passway\Services\SessionService;

/**
 * Controller WebAuthn / Passkey.
 *
 * Registration (requires auth):
 *   POST /auth/passkey/register/start   - create PublicKeyCredentialCreationOptions
 *   POST /auth/passkey/register/finish  - save passkey in DB
 *
 * Authentication (public):
 *   POST /auth/passkey/authenticate/start   - create PublicKeyCredentialRequestOptions
 *   POST /auth/passkey/authenticate/finish  - verify and issue a session
 */
final class PasskeyController
{
    public function __construct(
        private readonly PasskeyService $passkeyService,
        private readonly SessionService $sessionService,
        private readonly AuthService    $authService,
    ) {}

    // ------------------------------------------------------------------ //
    //  POST /auth/passkey/register/start                                  //
    // ------------------------------------------------------------------ //

    /**
     * Requires AuthMiddleware.
     */
    public function registerStart(Request $request): Response
    {
        $user = AuthContext::requireUser();

        $options = $this->passkeyService->startRegistration($user);

        return Response::json([
            'success' => true,
            'options' => $options,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/passkey/register/finish                                 //
    // ------------------------------------------------------------------ //

    /**
     * Body: { "credential": {...}, "name": "My Passkey" }
     * Requires AuthMiddleware.
     */
    public function registerFinish(Request $request): Response
    {
        $user = AuthContext::requireUser();

        $credentialData = $request->json('credential');
        if (!\is_array($credentialData)) {
            return Response::validationError(['credential' => [__('ui.backend.passkey.invalid_credential_data')]]);
        }

        $name = \trim((string) ($request->input('name') ?? 'Passkey'));
        if ($name === '') {
            $name = 'Passkey';
        }

        try {
            $passkey = $this->passkeyService->finishRegistration($user, $credentialData, $name);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), 400);
        }

        $this->authService->writeAuditLog($user->id, 'auth.passkey_registered', $request->ip(), null, true, [
            'passkey_uuid' => $passkey->uuid,
            'name'         => $passkey->name,
        ]);

        return Response::json([
            'success' => true,
            'passkey' => [
                'uuid'       => $passkey->uuid,
                'name'       => $passkey->name,
                'created_at' => $passkey->createdAt,
            ],
        ], 201);
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/passkey/authenticate/start                              //
    // ------------------------------------------------------------------ //

    /**
     * Body (optional): { "email": "user@example.com" }
     * If email is not provided - discoverable credentials (resident key).
     */
    public function authenticateStart(Request $request): Response
    {
        $email = $request->input('email');
        $email = \is_string($email) ? \strtolower(\trim($email)) : null;
        if ($email === '') {
            $email = null;
        }

        $options = $this->passkeyService->startAuthentication($email);

        return Response::json([
            'success' => true,
            'options' => $options,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  POST /auth/passkey/authenticate/finish                             //
    // ------------------------------------------------------------------ //

    /**
     * Body: { "credential": {...} }
     */
    public function authenticateFinish(Request $request): Response
    {
        $credentialData = $request->json('credential');
        if (!\is_array($credentialData)) {
            return Response::validationError(['credential' => [__('ui.backend.passkey.invalid_credential_data')]]);
        }

        try {
            $user = $this->passkeyService->finishAuthentication($credentialData);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), 401);
        }

        if (!$user->isActive) {
            return Response::error(__('ui.backend.passkey.account_inactive'), 403);
        }

        $rawToken = $this->sessionService->create($user->id, $request->ip(), $request->header('User-Agent'));
        $this->sessionService->setCookie($rawToken);

        $user->update([
            'last_login_at' => now()->format('Y-m-d H:i:s'),
            'last_login_ip' => $request->ip(),
        ]);

        $this->authService->writeAuditLog($user->id, 'auth.login_success', $request->ip(),
            $request->header('User-Agent'), true, ['method' => 'passkey']);

        return Response::success([
            'status' => 'success',
            'user'   => [
                'uuid'  => $user->uuid,
                'email' => $user->email,
            ],
        ]);
    }

    // ------------------------------------------------------------------ //
    //  GET /auth/passkeys                                                 //
    // ------------------------------------------------------------------ //

    /**
     * List the current user passkeys.
     * Requires AuthMiddleware.
     */
    public function list(Request $request): Response
    {
        $user     = AuthContext::requireUser();
        $passkeys = Passkey::findByUserId($user->id);

        return Response::success([
            'passkeys' => \array_map(fn(Passkey $pk) => [
                'uuid'         => $pk->uuid,
                'name'         => $pk->name,
                'aaguid'       => $pk->aaguid,
                'created_at'   => $pk->createdAt,
                'last_used_at' => $pk->lastUsedAt,
            ], $passkeys),
        ]);
    }

    // ------------------------------------------------------------------ //
    //  DELETE /auth/passkeys/:uuid                                        //
    // ------------------------------------------------------------------ //

    /**
     * Delete a passkey by UUID.
     * Requires AuthMiddleware.
     */
    public function delete(Request $request): Response
    {
        $user       = AuthContext::requireUser();
        $passkeyUuid = $request->routeParam('uuid');

        if (!\is_string($passkeyUuid)) {
            return Response::notFound();
        }

        $row = \Passway\Core\Database::getInstance()->fetchOne(
            'SELECT * FROM passkeys WHERE uuid = ? AND user_id = ?',
            [$passkeyUuid, $user->id]
        );

        if ($row === null) {
            return Response::notFound();
        }

        // Do not delete the last passkey if there is no password
        if ($user->passwordHash === null) {
            $count = (int) \Passway\Core\Database::getInstance()->fetchColumn(
                'SELECT COUNT(*) FROM passkeys WHERE user_id = ?',
                [$user->id]
            );
            if ($count <= 1) {
                return Response::error(
                    'Cannot remove the last passkey when no password is set.',
                    400
                );
            }
        }

        \Passway\Core\Database::getInstance()->delete('passkeys', ['uuid' => $passkeyUuid, 'user_id' => $user->id]);

        $this->authService->writeAuditLog($user->id, 'auth.passkey_deleted', $request->ip(), null, true, [
            'passkey_uuid' => $passkeyUuid,
        ]);

        return Response::success(['message' => 'Passkey removed.']);
    }
}
