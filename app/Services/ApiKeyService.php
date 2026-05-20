<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\ApiKey;
use Passway\Models\Organization;
use Passway\Models\User;

/**
 * API key management and rate limiting.
 *
 * Key format: sv_{envPrefix}_{64 random hex chars}
 * Only the SHA-256 hash of the raw key is stored in the DB.
 *
 * Rate limiting (sliding window):
 *   - bucket 'api':  100 requests / 60 sec
 *   - bucket 'auth':  20 requests / 60 sec
 */
final class ApiKeyService
{
    public const KEY_PREFIX = 'sv';

    // Rate limiting
    public const RATE_LIMIT_API_WINDOW  = 60;   // секунды
    public const RATE_LIMIT_API_MAX     = 100;  // запросов за окно
    public const RATE_LIMIT_AUTH_WINDOW = 60;
    public const RATE_LIMIT_AUTH_MAX    = 20;

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  API key CRUD                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Creates a new API key.
     *
     * @return array{key: ApiKey, raw: string}  raw - is shown ONCE
     * @throws AuthException             if not admin+
     * @throws \InvalidArgumentException on invalid parameters
     */
    public function create(
        string  $name,
        string  $orgId,
        string  $userId,
        string  $role = 'reader',
        ?string $expiresAt   = null,
    ): array {
        if (trim($name) === '') {
            throw new \InvalidArgumentException(__('ui.backend.apikey.name_required'));
        }

        $this->assertValidRole($role);

        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException(__('ui.backend.apikey.requires_admin_create'), 403);
        }

        $random    = bin2hex(random_bytes(32)); // 64 hex chars
        $rawKey    = self::KEY_PREFIX . '_' . $random;
        $keyHash   = hash('sha256', $rawKey);
        $keyPrefix = substr($rawKey, 0, 12);
        $uuid      = generate_uuid();

        $db = Database::getInstance();
        $id = $db->insert('api_keys', [
            'uuid'            => $uuid,
            'organization_id' => $orgId,
            'user_id'         => $userId,
            'name'            => trim($name),
            'role'            => $role,
            'key_hash'        => $keyHash,
            'key_prefix'      => $keyPrefix,
            'is_active'       => 1,
            'expires_at'      => $expiresAt,
        ]);

        $apiKey = ApiKey::findById($id);
        if ($apiKey === null) {
            throw new \RuntimeException(__('ui.backend.apikey.failed_load_created_key'));
        }

        $this->getAuditService()->record(
            action: 'apikey.create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'api_key',
            resourceId: $apiKey->id,
            resourceUuid: $apiKey->uuid,
        );

        return ['key' => $apiKey, 'raw' => $rawKey];
    }

    /**
     * List organization API keys (admin+).
     *
     * @return ApiKey[]
     * @throws AuthException if permissions are insufficient
     */
    public function listForOrg(string $orgId, string $userId): array
    {
        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException(__('ui.backend.apikey.requires_admin_list'), 403);
        }

        return ApiKey::findByOrgId($orgId);
    }

    /**
     * Get a key by UUID (admin+ or key owner).
     *
     * @throws AuthException      if permission is missing
     * @throws \RuntimeException  if not found
     */
    public function get(string $keyUuid, string $orgId, string $userId): ApiKey
    {
        $apiKey = $this->findKeyInOrg($keyUuid, $orgId);

        $isAdmin = $this->organizationService->hasPermission($orgId, $userId, 'admin');
        $isOwner = $apiKey->userId === $userId;

        if (!$isAdmin && !$isOwner) {
            throw new AuthException(__('ui.backend.apikey.access_denied'), 403);
        }

        return $apiKey;
    }

    /**
     * Change API key role (admin+).
     *
     * @throws AuthException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function updateRole(string $keyUuid, string $role, string $orgId, string $userId): ApiKey
    {
        $this->assertValidRole($role);

        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException(__('ui.backend.apikey.requires_admin_permissions'), 403);
        }

        $apiKey = $this->findKeyInOrg($keyUuid, $orgId);
        if ($apiKey->role === $role) {
            return $apiKey;
        }

        Database::getInstance()->update('api_keys', ['role' => $role], ['id' => $apiKey->id]);

        $updated = ApiKey::findById($apiKey->id);
        if ($updated === null) {
            throw new \RuntimeException(__('ui.backend.apikey.key_not_found'));
        }

        $this->getAuditService()->record(
            action: 'apikey.role_update',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'api_key',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
            details: ['role' => $role],
        );

        return $updated;
    }

    /**
     * Revoke (deactivate) API-key.
     *
     * @throws AuthException     if permission is missing
     * @throws \RuntimeException if not found
     */
    public function revoke(string $keyUuid, string $orgId, string $userId): void
    {
        $apiKey = $this->findKeyInOrg($keyUuid, $orgId);

        $isAdmin = $this->organizationService->hasPermission($orgId, $userId, 'admin');
        $isOwner = $apiKey->userId === $userId;

        if (!$isAdmin && !$isOwner) {
            throw new AuthException(__('ui.backend.apikey.revoke_requires_admin_or_owner'), 403);
        }

        Database::getInstance()->update(
            'api_keys',
            ['is_active' => 0],
            ['id' => $apiKey->id]
        );

        $this->getAuditService()->record(
            action: 'apikey.revoke',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'api_key',
            resourceId: $apiKey->id,
            resourceUuid: $apiKey->uuid,
        );
    }

    // ------------------------------------------------------------------ //
    //  Key authentication                                            //
    // ------------------------------------------------------------------ //

    /**
     * Checks the raw API key and returns the owner user.
     * Updates last_used_at on success.
     */
    public function validate(string $rawKey): ?User
    {
        $apiKey = $this->findValidApiKey($rawKey);

        if ($apiKey === null) {
            return null;
        }

        $user = $this->findActiveOwner($apiKey);
        if ($user === null) {
            return null;
        }

        $apiKey->touchLastUsed();

        return $user;
    }

    public function findValidApiKey(string $rawKey): ?ApiKey
    {
        $hash   = hash('sha256', $rawKey);
        $apiKey = ApiKey::findByHash($hash);

        if ($apiKey === null || !$apiKey->isValid()) {
            return null;
        }

        if ($this->findActiveOwner($apiKey) === null) {
            return null;
        }

        return $apiKey;
    }

    public function findOwner(ApiKey $apiKey): ?User
    {
        if ($apiKey->userId === null) {
            return null;
        }

        $user = User::findById($apiKey->userId);
        return $user;
    }

    private function findActiveOwner(ApiKey $apiKey): ?User
    {
        $user = $this->findOwner($apiKey);
        if ($user === null || !$user->isActive) {
            return null;
        }

        return $user;
    }

    public function validateForRequest(
        string $rawKey,
        ?string $ip,
        ?string $userAgent,
        ?string $path = null,
    ): ?User {
        $apiKey = $this->findValidApiKey($rawKey);
        $user   = $apiKey !== null ? $this->findActiveOwner($apiKey) : null;

        if ($user === null || $apiKey === null) {
            $this->getAuditService()->record(
                action: 'auth.api_key_fail',
                ipAddress: $ip,
                userAgent: $userAgent,
                details: ['path' => $path],
                success: false,
            );
            return null;
        }

        $apiKey->touchLastUsed();

        $this->getAuditService()->record(
            action: 'auth.api_key_success',
            organizationId: $apiKey->organizationId,
            userId: $user->id,
            apiKeyId: $apiKey->id,
            resourceType: 'api_key',
            resourceId: $apiKey->id,
            resourceUuid: $apiKey->uuid,
            ipAddress: $ip,
            userAgent: $userAgent,
            details: ['path' => $path],
        );

        return $user;
    }

    // ------------------------------------------------------------------ //
    //  Rate limiting                                                      //
    // ------------------------------------------------------------------ //

    /**
     * Checks rate limit for an IP address and bucket.
     *
     * @param string $ip     Client IP address
     * @param string $bucket 'api' or 'auth'
     * @return bool  true - request allowed, false - limit exceeded
     */
    public function checkRateLimit(string $ip, string $bucket): bool
    {
        [$windowSeconds, $maxRequests] = $bucket === 'auth'
            ? [self::RATE_LIMIT_AUTH_WINDOW, self::RATE_LIMIT_AUTH_MAX]
            : [self::RATE_LIMIT_API_WINDOW, self::RATE_LIMIT_API_MAX];

        $db  = Database::getInstance();
        $now = time();
        $row = $db->fetchOne(
            'SELECT * FROM rate_limit_log WHERE ip_address = ? AND bucket = ?',
            [$ip, $bucket]
        );

        if ($row === null) {
            // First request from this IP
            $db->insert('rate_limit_log', [
                'ip_address'   => $ip,
                'bucket'       => $bucket,
                'count'        => 1,
                'window_start' => date('Y-m-d H:i:s', $now),
                'updated_at'   => date('Y-m-d H:i:s', $now),
            ]);
            return true;
        }

        $windowStart = strtotime((string) $row['window_start']);

        if ($now - $windowStart >= $windowSeconds) {
            // Window expired - reset the counter
            $db->update('rate_limit_log', [
                'count'        => 1,
                'window_start' => date('Y-m-d H:i:s', $now),
                'updated_at'   => date('Y-m-d H:i:s', $now),
            ], ['ip_address' => $ip, 'bucket' => $bucket]);
            return true;
        }

        // In the current window, increment the counter
        $newCount = (int) $row['count'] + 1;
        $db->update('rate_limit_log', [
            'count'      => $newCount,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ], ['ip_address' => $ip, 'bucket' => $bucket]);

        return $newCount <= $maxRequests;
    }

    // ------------------------------------------------------------------ //
    //  Internal helpers                                                   //
    // ------------------------------------------------------------------ //

    /**
     * @throws \RuntimeException if the key is not found or does not belong to the organization
     */
    private function findKeyInOrg(string $keyUuid, string $orgId): ApiKey
    {
        $apiKey = ApiKey::findByUuid($keyUuid);

        if ($apiKey === null || $apiKey->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.apikey.key_not_found'));
        }

        return $apiKey;
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this->organizationService);
    }

    private function assertValidRole(string $role): void
    {
        if (!\in_array($role, ApiKey::VALID_ROLES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.organization.invalid_role', ['allowed' => \implode(', ', ApiKey::VALID_ROLES)])
            );
        }
    }
}
