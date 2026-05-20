<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\AuditLog;
use Passway\Models\Organization;
use Passway\Services\AuditService;

final class AuditController
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $result = $this->auditService->paginateForOrganization($org->id, $user->id, [
                'action'        => $request->query('action'),
                'resource_type' => $request->query('resource_type'),
                'resource_uuid' => $request->query('resource_uuid'),
                'success'       => $request->query('success'),
                'ip_address'    => $request->query('ip_address'),
                'user_id'       => $request->query('user_id'),
                'from'          => $request->query('from'),
                'to'            => $request->query('to'),
                'search'        => $request->query('search'),
                'limit'         => $request->query('limit', 100),
                'offset'        => $request->query('offset', 0),
            ]);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success([
            'items' => \array_map(fn($entry) => $this->serialize($entry), $result['entries']),
            'meta'  => [
                'total'    => $result['total'],
                'limit'    => $result['limit'],
                'offset'   => $result['offset'],
                'has_more' => $result['has_more'],
            ],
        ]);
    }

    private function findOrgOrFail(Request $request): Organization
    {
        $org = Organization::findByUuid((string) $request->routeParam('uuid'));
        if ($org === null) {
            throw new \RuntimeException('Organization not found.');
        }

        return $org;
    }

    /** @return array<string, mixed> */
    private function serialize(AuditLog $entry): array
    {
        return [
            'id'            => $entry->id,
            'action'        => $entry->action,
            'user_id'       => $entry->userId,
            'api_key_id'    => $entry->apiKeyId,
            'resource_type' => $entry->resourceType,
            'resource_id'   => $entry->resourceId,
            'resource_uuid' => $entry->resourceUuid,
            'ip_address'    => $entry->ipAddress,
            'user_agent'    => $entry->userAgent,
            'details'       => $entry->details(),
            'success'       => $entry->success,
            'created_at'    => $entry->createdAt,
        ];
    }
}
