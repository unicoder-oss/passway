<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\ApprovalRequest;
use Passway\Models\Organization;
use Passway\Models\Secret;
use Passway\Services\ApprovalService;

/**
 * Контроллер системы одобрений.
 *
 * POST   /api/v1/organizations/:uuid/secrets/:secUuid/approvals         — создать запрос
 * GET    /api/v1/organizations/:uuid/approvals                          — мои запросы
 * GET    /api/v1/organizations/:uuid/approvals/pending                  — pending (admin+)
 * GET    /api/v1/organizations/:uuid/approvals/:aprUuid                 — детали
 * POST   /api/v1/organizations/:uuid/approvals/:aprUuid/approve         — одобрить (admin+)
 * POST   /api/v1/organizations/:uuid/approvals/:aprUuid/reject          — отклонить (admin+)
 * DELETE /api/v1/organizations/:uuid/approvals/:aprUuid                 — отозвать
 * POST   /api/v1/organizations/:uuid/approvals/:aprUuid/use             — использовать токен
 */
final class ApprovalController
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    // ------------------------------------------------------------------ //
    //  POST .../secrets/:secUuid/approvals                               //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        $requestType = \trim((string) ($request->input('request_type') ?? 'read'));
        $reason      = $request->input('reason') !== null
            ? \trim((string) $request->input('reason'))
            : null;

        if (!\in_array($requestType, ApprovalRequest::VALID_REQUEST_TYPES, true)) {
            return Response::validationError(['request_type' => [
                'Invalid request type. Allowed: ' . \implode(', ', ApprovalRequest::VALID_REQUEST_TYPES) . '.',
            ]]);
        }

        try {
            $approvalReq = $this->approvalService->request(
                $secUuid,
                $requestType,
                $reason !== '' ? $reason : null,
                $user->id,
                $org->id
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
    //  GET .../approvals  (мои запросы)                                  //
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
    //  GET .../approvals/pending  (ожидающие, для admin+)               //
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

    // ------------------------------------------------------------------ //
    //  GET .../approvals/:aprUuid                                        //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $aprUuid = (string) $request->routeParam('aprUuid');

        try {
            $approvalReq = $this->approvalService->get($aprUuid, $user->id, $org->id);
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

        // Токен возвращается ОДИН РАЗ в открытом виде
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
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $aprUuid = (string) $request->routeParam('aprUuid');

        $token = \trim((string) ($request->input('token') ?? ''));
        if ($token === '') {
            return Response::validationError(['token' => ['Token is required.']]);
        }

        try {
            ['secret' => $secret, 'value' => $value] =
                $this->approvalService->useToken($aprUuid, $token, $user->id, $org->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeSecretValue($secret, $value));
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function findOrgOrFail(Request $request): Organization
    {
        $uuid = $request->routeParam('uuid');
        $org  = Organization::findByUuid((string) $uuid);
        if ($org === null) {
            throw new \RuntimeException('Organization not found.');
        }
        return $org;
    }

    /** @return array<string, mixed> */
    private function serializeRequest(ApprovalRequest $r): array
    {
        return [
            'uuid'             => $r->uuid,
            'secret_id'        => $r->secretId,
            'requested_by'     => $r->requestedBy,
            'request_type'     => $r->requestType,
            'reason'           => $r->reason,
            'status'           => $r->status,
            'approved_by'      => $r->approvedBy,
            'rejection_reason' => $r->rejectionReason,
            'expires_at'       => $r->expiresAt,
            'created_at'       => $r->createdAt,
            'resolved_at'      => $r->resolvedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSecretValue(Secret $s, string $value): array
    {
        return [
            'uuid'  => $s->uuid,
            'name'  => $s->name,
            'type'  => $s->type,
            'value' => $value,
        ];
    }
}
