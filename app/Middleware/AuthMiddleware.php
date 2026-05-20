<?php

declare(strict_types=1);

namespace Passway\Middleware;

use Closure;
use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Models\ApiKey;
use Passway\Services\AuditService;
use Passway\Services\ApiKeyService;
use Passway\Services\SessionService;

/**
 * Middleware аутентификации + rate limiting.
 *
 * Порядок:
 *   1. Rate limiting по IP (429 если превышен лимит)
 *   2. Cookie SESSION_COOKIE_NAME → SessionService::validate()
 *   3. Header X-Api-Key          → ApiKeyService::validate()
 *
 * Бакеты rate limiting:
 *   - 'auth' для /api/v1/auth/* (20 req/min)
 *   - 'api'  для всех остальных (100 req/min)
 *
 * При успехе: AuthContext::setUser($user)
 * При отказе: 401 Unauthorized
 */
final class AuthMiddleware
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly ApiKeyService  $apiKeyService,
        private readonly AuditService   $auditService,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ---- 1. Rate limiting ----
        $bucket = str_starts_with($request->path(), '/api/v1/auth/') ? 'auth' : 'api';

        if (!$this->apiKeyService->checkRateLimit($request->ip(), $bucket)) {
            $this->auditService->record(
                action: $bucket === 'auth' ? 'auth.rate_limit_denied' : 'api.rate_limit_denied',
                ipAddress: $request->ip(),
                userAgent: $request->header('User-Agent'),
                details: ['path' => $request->path(), 'bucket' => $bucket],
                success: false,
            );
            return Response::json(
                ['success' => false, 'error' => 'Too Many Requests'],
                429
            );
        }

        // ---- 2. Session cookie ----
        $rawToken = $this->sessionService->getTokenFromCookie();

        if ($rawToken !== null) {
            $user = $this->sessionService->validate($rawToken);
            if ($user !== null && $user->isActive) {
                AuthContext::setApiKey(null);
                AuthContext::setUser($user);
                return $next($request);
            }
            // Cookie есть, но сессия невалидна → очищаем
            $this->auditService->record(
                action: 'auth.session_fail',
                ipAddress: $request->ip(),
                userAgent: $request->header('User-Agent'),
                details: ['path' => $request->path()],
                success: false,
            );
            $this->sessionService->clearCookie();
        }

        // ---- 3. API Key (X-Api-Key header) ----
        $apiKey = $request->header('X-Api-Key') ?? $request->apiKey();

        if ($apiKey !== null && \is_string($apiKey) && $apiKey !== '') {
            $authenticatedKey = $this->apiKeyService->findValidApiKey($apiKey);
            $user = $this->apiKeyService->validateForRequest(
                $apiKey,
                $request->ip(),
                $request->header('User-Agent'),
                $request->path(),
            );
            if ($user !== null && $user->isActive && $authenticatedKey !== null) {
                if (!$this->isApiKeyRouteAllowed($request, $authenticatedKey)) {
                    return Response::forbidden(__('ui.backend.apikey.route_not_allowed'));
                }

                AuthContext::setApiKey($authenticatedKey);
                AuthContext::setUser($user);
                return $next($request);
            }
        }

        // ---- 401 ----
        AuthContext::setUser(null);
        AuthContext::setApiKey(null);

        $this->auditService->record(
            action: 'auth.unauthorized',
            ipAddress: $request->ip(),
            userAgent: $request->header('User-Agent'),
            details: ['path' => $request->path()],
            success: false,
        );

        if ($request->expectsJson() || $request->isApi()) {
            return Response::unauthorized();
        }

        return Response::redirect('/auth/login');
    }

    private function isApiKeyRouteAllowed(Request $request, ApiKey $apiKey): bool
    {
        if (!$request->isApi()) {
            return false;
        }

        $path = $request->path();

        if (preg_match('#^/api/v1/organizations/[^/]+$#', $path) === 1) {
            return true;
        }

        if (preg_match('#^/api/v1/organizations/[^/]+/directories(?:/[^/]+(?:/secrets(?:/template-preview|/[^/]+(?:/(?:regenerate|rotate|versions))?)?)?)?$#', $path) === 1) {
            return true;
        }

        return false;
    }
}
