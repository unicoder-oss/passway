<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Models\ApiKey;
use Passway\Models\ApiKeyPermission;
use Passway\Models\Directory;

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

        return ApiKeyPermission::canDo($apiKeyId, $permission, 'organization', $orgId);
    }

    public function canDirectory(string $apiKeyId, string $directoryId, string $permission): bool
    {
        foreach ($this->buildDirectoryChain($directoryId) as $candidateId) {
            if (ApiKeyPermission::canDo($apiKeyId, $permission, 'directory', $candidateId)) {
                return true;
            }
        }

        return false;
    }

    public function canSecret(string $apiKeyId, string $secretId, string $permission): bool
    {
        return ApiKeyPermission::canDo($apiKeyId, $permission, 'secret', $secretId);
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
}
