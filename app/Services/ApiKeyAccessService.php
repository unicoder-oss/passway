<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Models\ApiKey;
use Passway\Models\Directory;
use Passway\Models\Secret;
use Passway\Models\UserPermission;

final class ApiKeyAccessService
{
    public function can(string $apiKeyId, string $permission, string $resourceType, string $resourceId, string $orgId): bool
    {
        $apiKey = ApiKey::findById($apiKeyId);
        if ($apiKey === null || $apiKey->organizationId !== $orgId) {
            return false;
        }

        return match ($resourceType) {
            'organization' => $this->canOrganization($apiKeyId, $orgId, $permission),
            'directory' => $this->canDirectory($apiKeyId, $resourceId, $permission),
            'secret' => $this->canSecret($apiKeyId, $resourceId, $permission),
            default => false,
        };
    }

    public function canOrganization(string $apiKeyId, string $orgId, string $permission): bool
    {
        $apiKey = ApiKey::findById($apiKeyId);
        if ($apiKey === null || $apiKey->organizationId !== $orgId) {
            return false;
        }

        return $this->roleAllowsPermission($apiKey->role, $permission);
    }

    public function canDirectory(string $apiKeyId, string $directoryId, string $permission): bool
    {
        $acl = $this->checkDirectoryAclChain($apiKeyId, $permission, $directoryId);
        return $acl ?? false;
    }

    public function canSecret(string $apiKeyId, string $secretId, string $permission): bool
    {
        $acl = $this->checkSecretAclChain($apiKeyId, $permission, $secretId);
        return $acl ?? false;
    }

    private function checkSecretAclChain(string $apiKeyId, string $permission, string $secretId): ?bool
    {
        $result = $this->evalApiKeyAcl($apiKeyId, $permission, 'secret', $secretId);
        if ($result !== null) {
            return $result;
        }

        $secret = Secret::findById($secretId);
        if ($secret === null) {
            return null;
        }

        return $this->checkDirectoryAclChain($apiKeyId, $permission, $secret->directoryId);
    }

    private function checkDirectoryAclChain(string $apiKeyId, string $permission, string $directoryId): ?bool
    {
        foreach ($this->buildDirectoryChain($directoryId) as $candidateId) {
            $result = $this->evalApiKeyAcl($apiKeyId, $permission, 'directory', $candidateId);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function evalApiKeyAcl(string $apiKeyId, string $permission, string $resourceType, string $resourceId): ?bool
    {
        $perms = \array_filter(
            UserPermission::findForSubject('api_key', $apiKeyId, $resourceType, $resourceId),
            fn(UserPermission $perm): bool => $perm->permission === $permission && $this->isActive($perm)
        );

        foreach ($perms as $perm) {
            if ($perm->isDeny) {
                return false;
            }
        }

        foreach ($perms as $_perm) {
            return true;
        }

        return null;
    }

    private function isActive(UserPermission $perm): bool
    {
        if ($perm->expiresAt === null) {
            return true;
        }

        return \strtotime($perm->expiresAt) > \time();
    }

    /** @return string[] */
    private function buildDirectoryChain(string $directoryId): array
    {
        $chain = [];
        $current = Directory::findById($directoryId);

        while ($current !== null) {
            $chain[] = $current->id;
            $current = $current->parentId !== null ? Directory::findById($current->parentId) : null;
        }

        return $chain;
    }

    private function roleAllowsPermission(string $role, string $permission): bool
    {
        return match ($role) {
            'editor' => \in_array($permission, ['read', 'write', 'delete', 'create_subdirectories'], true),
            'reader' => $permission === 'read',
            default => false,
        };
    }
}
