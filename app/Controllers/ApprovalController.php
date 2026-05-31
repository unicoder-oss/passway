<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\ApprovalRequest;
use Passway\Models\ApiKey;
use Passway\Models\Directory;
use Passway\Models\Organization;
use Passway\Models\Secret;
use Passway\Models\User;
use Passway\Services\ApprovalService;
use Passway\Services\SecretService;

/**
 * Approval system controller.
 *
 * POST   /api/v1/organizations/:uuid/secrets/:secUuid/approvals         - create request
 * GET    /api/v1/organizations/:uuid/approvals                          - my requests
 * GET    /api/v1/organizations/:uuid/approvals/pending                  — pending (admin+)
 * GET    /api/v1/organizations/:uuid/approvals/:aprUuid                 - details
 * POST   /api/v1/organizations/:uuid/approvals/:aprUuid/approve         - approve (admin+)
 * POST   /api/v1/organizations/:uuid/approvals/:aprUuid/reject          - reject (admin+)
 * DELETE /api/v1/organizations/:uuid/approvals/:aprUuid                 - revoke
 * POST   /api/v1/organizations/:uuid/approvals/:aprUuid/use             - use token
 */
final class ApprovalController
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly SecretService $secretService,
    ) {}

    // ------------------------------------------------------------------ //
    //  POST .../secrets/:secUuid/approvals                               //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $org     = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        $requestType = \trim((string) ($request->input('request_type') ?? 'read'));
        $reason      = $request->input('reason') !== null
            ? \trim((string) $request->input('reason'))
            : null;

        if (!\in_array($requestType, ApprovalRequest::VALID_REQUEST_TYPES, true)) {
            return Response::validationError(['request_type' => [
                __('ui.backend.approval.invalid_request_type', ['allowed' => \implode(', ', ApprovalRequest::VALID_REQUEST_TYPES)]),
            ]]);
        }

        try {
            $approvalReq = AuthContext::isApiKeyRequest()
                ? $this->approvalService->requestForApiKey(
                    $secUuid,
                    $requestType,
                    $reason !== '' ? $reason : null,
                    AuthContext::getApiKey()?->id ?? '',
                    $org->id,
                )
                : $this->approvalService->requestForUser(
                    $secUuid,
                    $requestType,
                    $reason !== '' ? $reason : null,
                    AuthContext::requireUser()->id,
                    $org->id,
                );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['request_type' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeRequest($approvalReq), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET .../approvals  (my requests)                                  //
    // ------------------------------------------------------------------ //

    public function listMy(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        try {
            $requests = $this->approvalService->listMy($user->id, $org->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(\array_map(fn($r) => $this->serializeRequest($r), $requests));
    }

    // ------------------------------------------------------------------ //
    //  GET .../approvals/pending  (pending, for admin+)               //
    // ------------------------------------------------------------------ //

    public function listPending(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        try {
            $requests = $this->approvalService->listPending($user->id, $org->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(\array_map(fn($r) => $this->serializeRequest($r), $requests));
    }

    public function listPendingGlobal(Request $request): Response
    {
        $user = AuthContext::requireUser();

        try {
            $requests = $this->approvalService->listPendingAcrossOrganizations($user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(\array_map(fn($r) => $this->serializeRequest($r), $requests));
    }

    public function pendingSummaryGlobal(Request $request): Response
    {
        $user = AuthContext::requireUser();

        return Response::success([
            'count' => $this->approvalService->countPendingAcrossOrganizations($user->id),
        ]);
    }

    // ------------------------------------------------------------------ //
    //  GET .../approvals/:aprUuid                                        //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $org     = $this->findOrgOrFail($request);
        $aprUuid = (string) $request->routeParam('aprUuid');

        try {
            $approvalReq = AuthContext::isApiKeyRequest()
                ? $this->approvalService->getForApiKey($aprUuid, AuthContext::getApiKey()?->id ?? '', $org->id)
                : $this->approvalService->getForUser($aprUuid, AuthContext::requireUser()->id, $org->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeRequest($approvalReq));
    }

    // ------------------------------------------------------------------ //
    //  POST .../approvals/:aprUuid/approve                               //
    // ------------------------------------------------------------------ //

    public function approve(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $aprUuid = (string) $request->routeParam('aprUuid');

        try {
            ['request' => $approvalReq, 'token' => $token] =
                $this->approvalService->approve($aprUuid, $user->id, $org->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        // The token is returned ONCE in plaintext
        return Response::success(\array_merge(
            $this->serializeRequest($approvalReq),
            ['access_token' => $token]
        ));
    }

    // ------------------------------------------------------------------ //
    //  POST .../approvals/:aprUuid/reject                                //
    // ------------------------------------------------------------------ //

    public function reject(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $aprUuid = (string) $request->routeParam('aprUuid');

        $reason = $request->input('reason') !== null
            ? \trim((string) $request->input('reason'))
            : null;

        try {
            $approvalReq = $this->approvalService->reject(
                $aprUuid,
                $reason !== '' ? $reason : null,
                $user->id,
                $org->id
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeRequest($approvalReq));
    }

    // ------------------------------------------------------------------ //
    //  DELETE .../approvals/:aprUuid                                     //
    // ------------------------------------------------------------------ //

    public function revoke(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $aprUuid = (string) $request->routeParam('aprUuid');

        try {
            $this->approvalService->revoke($aprUuid, $user->id, $org->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  POST .../approvals/:aprUuid/use                                   //
    // ------------------------------------------------------------------ //

    public function use(Request $request): Response
    {
        $org     = $this->findOrgOrFail($request);
        $aprUuid = (string) $request->routeParam('aprUuid');

        $token = \trim((string) ($request->input('token') ?? ''));

        try {
            ['secret' => $secret, 'value' => $value] =
                (AuthContext::isApiKeyRequest()
                    ? $this->approvalService->useTokenForApiKey($aprUuid, $token, AuthContext::getApiKey()?->id ?? '', $org->id)
                    : $this->approvalService->useTokenForUser($aprUuid, $token, AuthContext::requireUser()->id, $org->id));
            $dynamicView = [];
            if (!AuthContext::isApiKeyRequest() && $secret->type === 'dynamic') {
                $dynamicView = $this->secretService->getDynamicSecretView($secret->uuid, $org->id, AuthContext::requireUser()->id);
            }
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeSecretValue($secret, $value, $dynamicView));
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function findOrgOrFail(Request $request): Organization
    {
        $uuid = $request->routeParam('uuid');
        $org  = Organization::findByUuid((string) $uuid);
        if ($org === null) {
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }
        return $org;
    }

    /** @return array<string, mixed> */
    private function serializeRequest(ApprovalRequest $r): array
    {
        $secret = Secret::findById($r->secretId);
        $organization = $secret !== null ? Organization::findById($secret->organizationId) : null;
        $directory = $secret !== null ? Directory::findById($secret->directoryId) : null;

        return [
            'uuid' => $r->uuid,
            'requested_by' => $r->requestedBy,
            'requester_type' => $r->requesterType,
            'requester_id' => $r->requesterId,
            'request_type' => $r->requestType,
            'reason' => $r->reason,
            'status' => $r->status,
            'approved_by' => $r->approvedBy,
            'rejection_reason' => $r->rejectionReason,
            'expires_at' => $r->expiresAt,
            'created_at' => $r->createdAt,
            'resolved_at' => $r->resolvedAt,
            'requester' => $this->serializeRequester($r),
            'organization' => [
                'uuid' => $organization?->uuid,
                'name' => $organization?->name,
            ],
            'secret' => [
                'id' => $r->secretId,
                'uuid' => $secret?->uuid,
                'name' => $secret?->name,
                'type' => $secret?->type,
                'requires_approval' => $secret?->requiresApproval,
                'link' => ($organization !== null && $directory !== null && $secret !== null)
                    ? '/organizations/' . rawurlencode($organization->uuid) . '/directories/' . rawurlencode($directory->uuid) . '/secrets/' . rawurlencode($secret->uuid)
                    : null,
            ],
            'directory' => [
                'uuid' => $directory?->uuid,
                'path' => $directory !== null ? $this->directoryPath($directory) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRequester(ApprovalRequest $request): array
    {
        if ($request->requesterType === ApprovalRequest::REQUESTER_TYPE_API_KEY) {
            $apiKey = ApiKey::findById($request->requesterId);

            return [
                'type' => ApprovalRequest::REQUESTER_TYPE_API_KEY,
                'id' => $request->requesterId,
                'uuid' => $apiKey?->uuid,
                'name' => $apiKey?->name,
                'email' => null,
                'display_name' => $apiKey !== null ? $apiKey->name : __('ui.app.unknown'),
            ];
        }

        $user = $request->requestedBy !== null ? User::findById($request->requestedBy) : null;

        return [
            'type' => ApprovalRequest::REQUESTER_TYPE_USER,
            'id' => $request->requestedBy,
            'uuid' => $user?->uuid,
            'name' => $user !== null ? display_name_for_user($user) : __('ui.app.unknown'),
            'email' => $user?->email,
            'display_name' => $user !== null ? display_name_for_user($user) . ' <' . $user->email . '>' : __('ui.app.unknown'),
        ];
    }

    private function directoryPath(Directory $directory): string
    {
        if ($directory->name === '__passway_root_secrets__') {
            return __('ui.organization.root_level');
        }

        $segments = [$directory->name];
        $parentId = $directory->parentId;

        while ($parentId !== null) {
            $parent = Directory::findById($parentId);
            if ($parent === null) {
                break;
            }
            if ($parent->name === '__passway_root_secrets__') {
                break;
            }
            $segments[] = $parent->name;
            $parentId = $parent->parentId;
        }

        return implode(' / ', array_reverse($segments));
    }

    /** @return array<string, mixed> */
    private function serializeSecretValue(Secret $s, string $value, array $dynamicView = []): array
    {
        $service = $dynamicView['service'] ?? null;

        return [
            'uuid'  => $s->uuid,
            'name'  => $s->name,
            'type'  => $s->type,
            'value' => $value,
            'display_value' => $value,
            'dynamic_outputs' => $dynamicView['outputs'] ?? [],
            'dynamic_primary_field' => $dynamicView['primary_field'] ?? null,
            'dynamic_output_fields' => $service instanceof \Passway\Models\RotationService ? $service->outputFields() : [],
        ];
    }
}
