<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\ApiKey;
use Passway\Models\ApiKeyPermission;
use Passway\Models\Organization;
use Passway\Models\User;

/**
 * Управление API-ключами и rate limiting.
 *
 * Формат ключа: sv_{envPrefix}_{64 random hex chars}
 * В БД хранится только SHA-256 хэш сырого ключа.
 *
 * Rate limiting (скользящее окно):
 *   - bucket 'api':  100 запросов / 60 сек
 *   - bucket 'auth':  20 запросов / 60 сек
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
    //  CRUD API-ключей                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Создаёт новый API-ключ.
     *
     * @return array{key: ApiKey, raw: string}  raw — показывается ОДИН РАЗ
     * @throws AuthException             если не admin+
     * @throws \InvalidArgumentException при некорректных параметрах
     */
    public function create(
        string  $name,
        string  $orgId,
        string  $userId,
        string  $environment = 'production',
        ?string $expiresAt   = null,
    ): array {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Name is required.');
        }

        if (!in_array($environment, ApiKey::VALID_ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException(
                'Invalid environment. Allowed: ' . implode(', ', ApiKey::VALID_ENVIRONMENTS) . '.'
            );
        }

        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException('Only admin or above can create API keys.', 403);
        }

        $envPrefix = ApiKey::ENV_PREFIXES[$environment];
        $random    = bin2hex(random_bytes(32)); // 64 hex chars
        $rawKey    = self::KEY_PREFIX . '_' . $envPrefix . '_' . $random;
        $keyHash   = hash('sha256', $rawKey);
        $keyPrefix = substr($rawKey, 0, 12);
        $uuid      = generate_uuid();

        $db = Database::getInstance();
        $id = $db->insert('api_keys', [
            'uuid'            => $uuid,
            'organization_id' => $orgId,
            'user_id'         => $userId,
            'name'            => trim($name),
            'key_hash'        => $keyHash,
            'key_prefix'      => $keyPrefix,
            'environment'     => $environment,
            'is_active'       => 1,
            'expires_at'      => $expiresAt,
        ]);

        $apiKey = ApiKey::findById($id);
        if ($apiKey === null) {
            throw new \RuntimeException('Failed to retrieve created API key.');
        }

        $this->getAuditService()->record(
            action: 'apikey.create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'api_key',
            resourceId: $apiKey->id,
            resourceUuid: $apiKey->uuid,
            details: ['environment' => $environment],
        );

        return ['key' => $apiKey, 'raw' => $rawKey];
    }

    /**
     * Список API-ключей организации (admin+).
     *
     * @return ApiKey[]
     * @throws AuthException если недостаточно прав
     */
    public function listForOrg(string $orgId, string $userId): array
    {
        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException('Only admin or above can list API keys.', 403);
        }

        return ApiKey::findByOrgId($orgId);
    }

    /**
     * Получить ключ по UUID (admin+ или владелец ключа).
     *
     * @throws AuthException      если нет прав
     * @throws \RuntimeException  если не найден
     */
    public function get(string $keyUuid, string $orgId, string $userId): ApiKey
    {
        $apiKey = $this->findKeyInOrg($keyUuid, $orgId);

        $isAdmin = $this->organizationService->hasPermission($orgId, $userId, 'admin');
        $isOwner = $apiKey->userId === $userId;

        if (!$isAdmin && !$isOwner) {
            throw new AuthException('Access denied.', 403);
        }

        return $apiKey;
    }

    /**
     * Отозвать (деактивировать) API-ключ.
     *
     * @throws AuthException     если нет прав
     * @throws \RuntimeException если не найден
     */
    public function revoke(string $keyUuid, string $orgId, string $userId): void
    {
        $apiKey = $this->findKeyInOrg($keyUuid, $orgId);

        $isAdmin = $this->organizationService->hasPermission($orgId, $userId, 'admin');
        $isOwner = $apiKey->userId === $userId;

        if (!$isAdmin && !$isOwner) {
            throw new AuthException('Only admin or the key owner can revoke an API key.', 403);
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
    //  Права ключа                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Добавить право к API-ключу (admin+).
     *
     * @throws AuthException             если нет прав
     * @throws \InvalidArgumentException при некорректных параметрах
     * @throws \RuntimeException         если ключ не найден или право уже есть
     */
    public function addPermission(
        string  $keyUuid,
        string  $resourceType,
        ?string $resourceId,
        string  $permission,
        string  $orgId,
        string  $userId,
    ): ApiKeyPermission {
        if (!in_array($resourceType, ApiKeyPermission::VALID_RESOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Invalid resource_type. Allowed: ' . implode(', ', ApiKeyPermission::VALID_RESOURCE_TYPES) . '.'
            );
        }

        if (!in_array($permission, ApiKeyPermission::VALID_PERMISSIONS, true)) {
            throw new \InvalidArgumentException(
                'Invalid permission. Allowed: ' . implode(', ', ApiKeyPermission::VALID_PERMISSIONS) . '.'
            );
        }

        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException('Only admin or above can manage API key permissions.', 403);
        }

        $apiKey = $this->findKeyInOrg($keyUuid, $orgId);

        $db = Database::getInstance();

        // Проверяем дубликат
        $existing = $db->fetchOne(
            'SELECT id FROM api_key_permissions
              WHERE api_key_id    = ?
                AND resource_type = ?
                AND permission    = ?
                AND (resource_id IS NULL AND ? IS NULL OR resource_id = ?)',
            [$apiKey->id, $resourceType, $permission, $resourceId, $resourceId]
        );

        if ($existing !== null) {
            throw new \RuntimeException('Permission already exists.');
        }

        $id = $db->insert('api_key_permissions', [
            'api_key_id'    => $apiKey->id,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'permission'    => $permission,
        ]);

        $perms = ApiKeyPermission::findByKeyId($apiKey->id);
        foreach ($perms as $perm) {
            if ($perm->id === (string) $id) {
                $this->getAuditService()->record(
                    action: 'apikey.permission_add',
                    organizationId: $orgId,
                    userId: $userId,
                    resourceType: 'api_key',
                    resourceId: $apiKey->id,
                    resourceUuid: $apiKey->uuid,
                    details: ['resource_type' => $resourceType, 'permission' => $permission],
                );
                return $perm;
            }
        }

        throw new \RuntimeException('Failed to retrieve created permission.');
    }

    /**
     * Список прав API-ключа (admin+ или владелец ключа).
     *
     * @return ApiKeyPermission[]
     * @throws AuthException
     * @throws \RuntimeException
     */
    public function listPermissions(string $keyUuid, string $orgId, string $userId): array
    {
        $apiKey  = $this->findKeyInOrg($keyUuid, $orgId);
        $isAdmin = $this->organizationService->hasPermission($orgId, $userId, 'admin');
        $isOwner = $apiKey->userId === $userId;

        if (!$isAdmin && !$isOwner) {
            throw new AuthException('Access denied.', 403);
        }

        return ApiKeyPermission::findByKeyId($apiKey->id);
    }

    /**
     * Удалить право с API-ключа (admin+).
     *
     * @throws AuthException
     * @throws \RuntimeException
     */
    public function removePermission(
        string $keyUuid,
        string $permId,
        string $orgId,
        string $userId,
    ): void {
        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException('Only admin or above can manage API key permissions.', 403);
        }

        $apiKey = $this->findKeyInOrg($keyUuid, $orgId);

        $db  = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id FROM api_key_permissions WHERE id = ? AND api_key_id = ?',
            [$permId, $apiKey->id]
        );

        if ($row === null) {
            throw new \RuntimeException('Permission not found.');
        }

        $db->delete('api_key_permissions', ['id' => $permId]);

        $this->getAuditService()->record(
            action: 'apikey.permission_remove',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'api_key',
            resourceId: $apiKey->id,
            resourceUuid: $apiKey->uuid,
            details: ['permission_id' => $permId],
        );
    }

    // ------------------------------------------------------------------ //
    //  Аутентификация по ключу                                            //
    // ------------------------------------------------------------------ //

    /**
     * Проверяет сырой API-ключ и возвращает пользователя-владельца.
     * Обновляет last_used_at при успехе.
     */
    public function validate(string $rawKey): ?User
    {
        $hash   = hash('sha256', $rawKey);
        $apiKey = ApiKey::findByHash($hash);

        if ($apiKey === null || !$apiKey->isValid()) {
            return null;
        }

        if ($apiKey->userId === null) {
            return null;
        }

        $user = User::findById($apiKey->userId);
        if ($user === null || !$user->isActive) {
            return null;
        }

        $apiKey->touchLastUsed();

        return $user;
    }

    public function validateForRequest(
        string $rawKey,
        ?string $ip,
        ?string $userAgent,
        ?string $path = null,
    ): ?User {
        $hash   = hash('sha256', $rawKey);
        $apiKey = ApiKey::findByHash($hash);
        $user   = $this->validate($rawKey);

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
     * Проверяет rate limit для IP-адреса и бакета.
     *
     * @param string $ip     IP-адрес клиента
     * @param string $bucket 'api' или 'auth'
     * @return bool  true — запрос разрешён, false — лимит превышен
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
            // Первый запрос с этого IP
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
            // Окно истекло — сбрасываем счётчик
            $db->update('rate_limit_log', [
                'count'        => 1,
                'window_start' => date('Y-m-d H:i:s', $now),
                'updated_at'   => date('Y-m-d H:i:s', $now),
            ], ['ip_address' => $ip, 'bucket' => $bucket]);
            return true;
        }

        // В текущем окне — увеличиваем счётчик
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
     * @throws \RuntimeException если ключ не найден или не принадлежит организации
     */
    private function findKeyInOrg(string $keyUuid, string $orgId): ApiKey
    {
        $apiKey = ApiKey::findByUuid($keyUuid);

        if ($apiKey === null || $apiKey->organizationId !== $orgId) {
            throw new \RuntimeException('API key not found.');
        }

        return $apiKey;
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this->organizationService);
    }
}
