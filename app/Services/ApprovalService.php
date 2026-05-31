<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\ApprovalRequest;
use Passway\Models\ApprovalReviewer;
use Passway\Models\ApiKey;
use Passway\Models\Organization;
use Passway\Models\Secret;

/**
 * Approval system service.
 *
 * Request lifecycle:
 *   1. request()  - creates approval_request with pending status; assigns a reviewer (secret owner)
 *   2. approve()  - reviewer approves; a one-time token is generated (TTL 1 h)
 *   3. useToken() - requester presents the token and gets the decrypted secret value
 *
 * Rules:
 *   - requires_approval=true required to create a request
 *   - Duplicate pending-request (same user + secret + type) is forbidden
 *   - Reviewer = current secret owner
 *   - Only the secret owner can approve/reject a request
 *   - Token is valid for 1 hour; single-use - after use status -> expired
 *   - Revoke (revoke) can: the requester (pending), secret owner or admin+
 *
 * Secret access authorization (requires_approval):
 *   - secret owner - bypasses the check requires_approval (direct access)
 *   - editor+  - bypasses the check requires_approval (direct access)
 *   - reader - must pass the approval workflow
 */
final class ApprovalService
{
    /** Pending request TTL (24 h) */
    private const REQUEST_TTL_SECONDS = 86_400;

    /** One-time token TTL after approval (1 h) */
    private const TOKEN_TTL_SECONDS = 3_600;

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly EncryptionService   $encryptionService,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Request creation
    // ------------------------------------------------------------------ //

    /**
     * Create a secret access request with requires_approval=true.
     *
     * @throws AuthException             if membership is missing in the organization
     * @throws \InvalidArgumentException if the request type is invalid or the secret does not require approval
     * @throws \RuntimeException         if the secret is not found or a pending request already exists
     */
    public function request(
        string  $secretUuid,
        string  $requestType,
        ?string $reason,
        string  $userId,
        string  $orgId,
    ): ApprovalRequest {
        return $this->requestForUser($secretUuid, $requestType, $reason, $userId, $orgId);
    }

    public function requestForUser(
        string  $secretUuid,
        string  $requestType,
        ?string $reason,
        string  $userId,
        string  $orgId,
    ): ApprovalRequest {
        if ($this->organizationService->getMemberRole($orgId, $userId) === null) {
            throw new AuthException(__('ui.backend.organization.not_member'), 403);
        }

        return $this->createRequest(
            $secretUuid,
            $requestType,
            $reason,
            ApprovalRequest::REQUESTER_TYPE_USER,
            $userId,
            $orgId,
            $userId,
            null,
            true,
        );
    }

    public function requestForApiKey(
        string  $secretUuid,
        string  $requestType,
        ?string $reason,
        string  $apiKeyId,
        string  $orgId,
    ): ApprovalRequest {
        $apiKey = ApiKey::findById($apiKeyId);
        if ($apiKey === null || $apiKey->organizationId !== $orgId || !$apiKey->isValid()) {
            throw new AuthException(__('ui.messages.access_denied'), 403);
        }
        if ($apiKey->userId === null) {
            throw new AuthException(__('ui.messages.access_denied'), 403);
        }

        return $this->createRequest(
            $secretUuid,
            $requestType,
            $reason,
            ApprovalRequest::REQUESTER_TYPE_API_KEY,
            $apiKeyId,
            $orgId,
            null,
            $apiKeyId,
            false,
            $apiKey->userId,
        );
    }

    private function createRequest(
        string $secretUuid,
        string $requestType,
        ?string $reason,
        string $requesterType,
        string $requesterId,
        string $orgId,
        ?string $auditUserId,
        ?string $auditApiKeyId,
        bool $enforceDirectUserOwnerBlock,
        ?string $legacyRequestedByUserId = null,
    ): ApprovalRequest {
        if (!\in_array($requestType, ApprovalRequest::VALID_REQUEST_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.approval.invalid_request_type', ['allowed' => \implode(', ', ApprovalRequest::VALID_REQUEST_TYPES)])
            );
        }

        if (!\in_array($requesterType, ApprovalRequest::VALID_REQUESTER_TYPES, true)) {
            throw new \InvalidArgumentException(__('ui.backend.common.invalid_requester_type'));
        }

        $secret = $this->findSecretInOrg($secretUuid, $orgId);

        if (!$secret->requiresApproval) {
            throw new \InvalidArgumentException(
                __('ui.backend.approval.secret_direct_access')
            );
        }

        if ($secret->ownerUserId === null) {
            throw new \RuntimeException(__('ui.backend.approval.secret_owner_missing'));
        }

        if ($enforceDirectUserOwnerBlock && $secret->ownerUserId === $requesterId) {
            throw new \InvalidArgumentException(__('ui.backend.approval.secret_direct_access'));
        }

        if (ApprovalRequest::hasPendingForActor($secret->id, $requesterType, $requesterId, $requestType)) {
            throw new \RuntimeException(
                __('ui.backend.approval.pending_exists')
            );
        }

        $reviewerIds = [$secret->ownerUserId];

        $uuid      = generate_uuid();
        $now       = now();
        $expiresAt = $now->modify('+' . self::REQUEST_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $nowStr    = $now->format('Y-m-d H:i:s');

        $db = Database::getInstance();

        $db->transaction(function () use ($db, $uuid, $secret, $requesterType, $requesterId, $requestType, $reason, $expiresAt, $nowStr, $reviewerIds, $legacyRequestedByUserId): void {
            $requestId = $db->insert('approval_requests', [
                'uuid'         => $uuid,
                'secret_id'    => (int) $secret->id,
                'requested_by' => $requesterType === ApprovalRequest::REQUESTER_TYPE_USER
                    ? (int) $requesterId
                    : (int) ($legacyRequestedByUserId ?? 0),
                'requester_type' => $requesterType,
                'requester_id' => (int) $requesterId,
                'request_type' => $requestType,
                'reason'       => $reason,
                'status'       => 'pending',
                'expires_at'   => $expiresAt,
                'created_at'   => $nowStr,
            ]);

            foreach ($reviewerIds as $reviewerId) {
                $db->insert('approval_reviewers', [
                    'approval_request_id' => (int) $requestId,
                    'reviewer_id'         => (int) $reviewerId,
                    'created_at'          => $nowStr,
                ]);
            }
        });

        $request = ApprovalRequest::findByUuid($uuid)
            ?? throw new \RuntimeException(__('ui.backend.approval.failed_load_created'));

        $this->getAuditService()->record(
            action: 'approval.request_create',
            organizationId: $orgId,
            userId: $auditUserId,
            apiKeyId: $auditApiKeyId,
            resourceType: 'approval_request',
            resourceId: $request->id,
            resourceUuid: $request->uuid,
            details: ['request_type' => $requestType, 'requester_type' => $requesterType, 'secret_uuid' => $secret->uuid],
        );

        return $request;
    }

    // ------------------------------------------------------------------ //
    //  Reading                                                             //
    // ------------------------------------------------------------------ //

    /**
     * List the user own requests in the organization.
     *
     * @return ApprovalRequest[]
     * @throws AuthException if membership is missing
     */
    public function listMy(string $userId, string $orgId): array
    {
        if ($this->organizationService->getMemberRole($orgId, $userId) === null) {
            throw new AuthException(__('ui.backend.organization.not_member'), 403);
        }

        // Filter by secrets belonging to the organization
        $rows = Database::getInstance()->fetchAll(
            'SELECT ar.* FROM approval_requests ar
             JOIN secrets s ON s.id = ar.secret_id
             WHERE ar.requested_by = ? AND s.organization_id = ?
             ORDER BY ar.created_at DESC',
            [(int) $userId, (int) $orgId]
        );

        return \array_map(fn($r) => ApprovalRequest::fromRow($r), $rows);
    }

    /** @return ApprovalRequest[] */
    public function listPendingAcrossOrganizations(string $reviewerId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT ar.* FROM approval_requests ar
             JOIN approval_reviewers rv ON rv.approval_request_id = ar.id
             JOIN secrets s ON s.id = ar.secret_id
             WHERE rv.reviewer_id = ? AND ar.status = 'pending' AND s.deleted_at IS NULL
             ORDER BY ar.created_at ASC",
            [(int) $reviewerId]
        );

        return \array_map(fn($r) => ApprovalRequest::fromRow($r), $rows);
    }

    public function countPendingAcrossOrganizations(string $reviewerId): int
    {
        return (int) Database::getInstance()->fetchColumn(
            "SELECT COUNT(*) FROM approval_requests ar
             JOIN approval_reviewers rv ON rv.approval_request_id = ar.id
             JOIN secrets s ON s.id = ar.secret_id
             WHERE rv.reviewer_id = ? AND ar.status = 'pending' AND s.deleted_at IS NULL",
            [(int) $reviewerId]
        );
    }

    public function findPendingReadForUser(string $secretId, string $userId): ?ApprovalRequest
    {
        return ApprovalRequest::findPendingForActor($secretId, ApprovalRequest::REQUESTER_TYPE_USER, $userId, 'read');
    }

    public function findPendingReadForApiKey(string $secretId, string $apiKeyId): ?ApprovalRequest
    {
        return ApprovalRequest::findPendingForActor($secretId, ApprovalRequest::REQUESTER_TYPE_API_KEY, $apiKeyId, 'read');
    }

    /**
     * List pending requests, where the user is a reviewer.
     *
     * @return ApprovalRequest[]
     * @throws AuthException if membership is missing
     */
    public function listPending(string $reviewerId, string $orgId): array
    {
        if ($this->organizationService->getMemberRole($orgId, $reviewerId) === null) {
            throw new AuthException(__('ui.backend.organization.not_member'), 403);
        }

        $rows = Database::getInstance()->fetchAll(
            "SELECT ar.* FROM approval_requests ar
             JOIN approval_reviewers rv ON rv.approval_request_id = ar.id
             JOIN secrets s ON s.id = ar.secret_id
             WHERE rv.reviewer_id = ? AND ar.status = 'pending' AND s.organization_id = ?
             ORDER BY ar.created_at ASC",
            [(int) $reviewerId, (int) $orgId]
        );

        return \array_map(fn($r) => ApprovalRequest::fromRow($r), $rows);
    }

    /**
     * View a specific request.
     * Can view: the requester or secret owner.
     *
     * @throws AuthException     if view permission is missing
     * @throws \RuntimeException if not found
     */
    public function get(string $requestUuid, string $userId, string $orgId): ApprovalRequest
    {
        return $this->getForUser($requestUuid, $userId, $orgId);
    }

    public function getForUser(string $requestUuid, string $userId, string $orgId): ApprovalRequest
    {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);

        $isRequester = $approvalReq->requesterType === ApprovalRequest::REQUESTER_TYPE_USER
            && $approvalReq->requesterId === $userId;
        $isReviewer  = $this->isReviewer($approvalReq, $userId);

        if (!$isRequester && !$isReviewer) {
            throw new AuthException(__('ui.backend.approval.view_request_denied'), 403);
        }

        return $approvalReq;
    }

    public function getForApiKey(string $requestUuid, string $apiKeyId, string $orgId): ApprovalRequest
    {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);

        if ($approvalReq->requesterType !== ApprovalRequest::REQUESTER_TYPE_API_KEY || $approvalReq->requesterId !== $apiKeyId) {
            throw new AuthException(__('ui.backend.approval.view_request_denied'), 403);
        }

        return $approvalReq;
    }

    // ------------------------------------------------------------------ //
    //  Approval / Rejection                                             //
    // ------------------------------------------------------------------ //

    /**
     * Approve a request.
     * Generates a one-time token (TTL 1 h); the token is returned in plaintext ONCE.
     * Only the SHA-256 hash is stored in the DB.
     *
     * Secret owner is required.
     *
     * @return array{request: ApprovalRequest, token: string}
     * @throws AuthException     if permission is missing or trying to approve own request
     * @throws \RuntimeException if the request is not found or not pending
     */
    public function approve(string $requestUuid, string $reviewerId, string $orgId): array
    {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);
        $this->assertReviewer($approvalReq, $reviewerId);

        if ($approvalReq->requesterType === ApprovalRequest::REQUESTER_TYPE_USER && $approvalReq->requesterId === $reviewerId) {
            throw new AuthException(__('ui.backend.approval.cannot_approve_own'), 403);
        }

        if ($approvalReq->status !== 'pending') {
            throw new \RuntimeException(
                __('ui.backend.approval.cannot_approve_status', ['status' => $approvalReq->status])
            );
        }

        // Generate a one-time token (32 bytes -> 64-character hex string)
        $rawToken  = \bin2hex(\random_bytes(32));
        $tokenHash = \hash('sha256', $rawToken);

        $now       = now();
        $expiresAt = $now->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $nowStr    = $now->format('Y-m-d H:i:s');

        $approvalReq->update([
            'status'            => 'approved',
            'approved_by'       => (int) $reviewerId,
            'access_token_hash' => $tokenHash,
            'expires_at'        => $expiresAt,
            'resolved_at'       => $nowStr,
        ]);

        $updated = ApprovalRequest::findByUuid($requestUuid)
            ?? throw new \RuntimeException(__('ui.backend.approval.failed_reload_after_approve'));
        $secret = Secret::findById($updated->secretId);

        $this->getAuditService()->record(
            action: 'approval.request_approve',
            organizationId: $orgId,
            userId: $reviewerId,
            resourceType: 'approval_request',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
            details: $secret !== null ? ['secret_uuid' => $secret->uuid] : [],
        );

        return ['request' => $updated, 'token' => $rawToken];
    }

    /**
     * Reject a request.
     * Secret owner is required.
     *
     * @throws AuthException     if permission is missing
     * @throws \RuntimeException if the request is not pending
     */
    public function reject(
        string  $requestUuid,
        ?string $rejectionReason,
        string  $reviewerId,
        string  $orgId,
    ): ApprovalRequest {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);
        $this->assertReviewer($approvalReq, $reviewerId);

        if ($approvalReq->status !== 'pending') {
            throw new \RuntimeException(
                __('ui.backend.approval.cannot_reject_status', ['status' => $approvalReq->status])
            );
        }

        $now = now()->format('Y-m-d H:i:s');
        $approvalReq->update([
            'status'           => 'rejected',
            'approved_by'      => (int) $reviewerId,
            'rejection_reason' => $rejectionReason,
            'resolved_at'      => $now,
        ]);

        $updated = ApprovalRequest::findByUuid($requestUuid)
            ?? throw new \RuntimeException(__('ui.backend.approval.failed_reload_after_reject'));
        $secret = Secret::findById($updated->secretId);

        $this->getAuditService()->record(
            action: 'approval.request_reject',
            organizationId: $orgId,
            userId: $reviewerId,
            resourceType: 'approval_request',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
            details: $secret !== null ? ['secret_uuid' => $secret->uuid] : [],
        );

        return $updated;
    }

    // ------------------------------------------------------------------ //
    //  Revocation                                                              //
    // ------------------------------------------------------------------ //

    /**
     * Revoke request.
     *   - The requester can revoke only a pending request.
     *   - Secret owner and admin+ can revoke pending or approved requests.
     *
     * @throws AuthException     if permission is missing
     * @throws \RuntimeException if the request is not found or already finalized
     */
    public function revoke(string $requestUuid, string $userId, string $orgId): void
    {
        $this->revokeForUser($requestUuid, $userId, $orgId);
    }

    public function revokeForUser(string $requestUuid, string $userId, string $orgId): void
    {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);
        $isAdmin     = $this->organizationService->hasPermission($orgId, $userId, 'admin');
        $isRequester = $approvalReq->requesterType === ApprovalRequest::REQUESTER_TYPE_USER
            && $approvalReq->requesterId === $userId;
        $isReviewer  = $this->isReviewer($approvalReq, $userId);

        if (!$isAdmin && !$isRequester && !$isReviewer) {
            throw new AuthException(__('ui.backend.approval.cannot_revoke_request'), 403);
        }

        $revokableStatuses = ($isAdmin || $isReviewer) ? ['pending', 'approved'] : ['pending'];

        if (!\in_array($approvalReq->status, $revokableStatuses, true)) {
            throw new \RuntimeException(
                __('ui.backend.approval.cannot_revoke_status', ['status' => $approvalReq->status])
            );
        }

        $approvalReq->update([
            'status'      => 'revoked',
            'resolved_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->getAuditService()->record(
            action: 'approval.request_revoke',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'approval_request',
            resourceId: $approvalReq->id,
            resourceUuid: $approvalReq->uuid,
        );
    }

    // ------------------------------------------------------------------ //
    //  Token use
    // ------------------------------------------------------------------ //

    /**
     * Use a one-time token to get the secret value.
     *
     * Only the requester can use their token.
     * Token is consumed: after successful use, status becomes 'expired'.
     *
     * @return array{secret: Secret, value: string}
     * @throws AuthException     if permission is missing or the token is invalid
     * @throws \RuntimeException if the request is not found
     */
    public function useToken(
        string $requestUuid,
        string $token,
        string $userId,
        string $orgId,
    ): array {
        return $this->useTokenForUser($requestUuid, $token, $userId, $orgId);
    }

    public function useTokenForUser(
        string $requestUuid,
        string $token,
        string $userId,
        string $orgId,
    ): array {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);

        if ($approvalReq->requesterType !== ApprovalRequest::REQUESTER_TYPE_USER || $approvalReq->requesterId !== $userId) {
            throw new AuthException(__('ui.backend.approval.not_requester'), 403);
        }

        return $this->consumeApprovedToken($approvalReq, $token, $orgId, $userId, null);
    }

    public function useTokenForApiKey(
        string $requestUuid,
        string $token,
        string $apiKeyId,
        string $orgId,
    ): array {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);

        if ($approvalReq->requesterType !== ApprovalRequest::REQUESTER_TYPE_API_KEY || $approvalReq->requesterId !== $apiKeyId) {
            throw new AuthException(__('ui.backend.approval.not_requester'), 403);
        }

        return $this->consumeApprovedToken($approvalReq, $token, $orgId, null, $apiKeyId);
    }

    /**
     * @return array{secret: Secret, value: string}
     */
    private function consumeApprovedToken(
        ApprovalRequest $approvalReq,
        string $token,
        string $orgId,
        ?string $auditUserId,
        ?string $auditApiKeyId,
    ): array {

        if ($approvalReq->status !== 'approved') {
            throw new AuthException(
                __('ui.backend.approval.token_invalid_status', ['status' => $approvalReq->status]),
                403
            );
        }

        if ($approvalReq->accessTokenHash === null) {
            throw new AuthException(__('ui.backend.approval.token_already_used'), 403);
        }

        // Check token expiration
        $expiresAt = new \DateTimeImmutable($approvalReq->expiresAt, new \DateTimeZone('UTC'));
        if ($expiresAt <= now()) {
            // Automatically move to expired
            $approvalReq->update(['status' => 'expired']);
            throw new AuthException(__('ui.backend.approval.token_expired'), 403);
        }

        if ($token !== '') {
            // Check the token hash if requester provides it explicitly.
            $providedHash = \hash('sha256', $token);
            if (!\hash_equals($approvalReq->accessTokenHash, $providedHash)) {
                throw new AuthException(__('ui.backend.approval.token_invalid'), 403);
            }
        }

        // Token is valid - get the secret
        $secret = Secret::findById($approvalReq->secretId)
            ?? throw new \RuntimeException(__('ui.backend.secret.not_found'));

        $value = $this->encryptionService->decrypt(
            $secret->encryptedValue,
            $secret->nonce,
            $secret->uuid
        );

        // Consume the token
        $approvalReq->update([
            'status'            => 'expired',
            'access_token_hash' => null,
            'resolved_at'       => now()->format('Y-m-d H:i:s'),
        ]);

        $this->getAuditService()->record(
            action: 'secret.read',
            organizationId: $orgId,
            userId: $auditUserId,
            apiKeyId: $auditApiKeyId,
            resourceType: 'secret',
            resourceId: $secret->id,
            resourceUuid: $secret->uuid,
            details: ['approval_request_uuid' => $approvalReq->uuid],
        );

        return ['secret' => $secret, 'value' => $value];
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    /**
     * @throws \RuntimeException if the secret is not found or belongs to another org
     */
    private function findSecretInOrg(string $secretUuid, string $orgId): Secret
    {
        $secret = Secret::findByUuid($secretUuid);
        if ($secret === null || $secret->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.secret.not_found'));
        }
        return $secret;
    }

    /**
     * @throws \RuntimeException if the request is not found or belongs to another org
     */
    private function findRequestInOrg(string $requestUuid, string $orgId): ApprovalRequest
    {
        $req = ApprovalRequest::findByUuid($requestUuid);
        if ($req === null) {
            throw new \RuntimeException(__('ui.backend.approval.request_not_found'));
        }

        // Check that the secret belongs to the organization
        $secret = Secret::findById($req->secretId);
        if ($secret === null || $secret->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.approval.request_not_found'));
        }

        return $req;
    }

    /**
     * @throws AuthException (code 403)
     */
    private function assertReviewer(ApprovalRequest $approvalReq, string $reviewerId): void
    {
        if (!$this->isReviewer($approvalReq, $reviewerId)) {
            throw new AuthException(__('ui.backend.approval.requires_secret_owner_review'), 403);
        }
    }

    private function isReviewer(ApprovalRequest $approvalReq, string $reviewerId): bool
    {
        return ApprovalReviewer::isReviewer($approvalReq->id, $reviewerId);
    }

    /**
     * @throws AuthException (code 403)
     */
    private function assertHasPermission(string $orgId, string $userId, string $minRole): void
    {
        if (!$this->organizationService->hasPermission($orgId, $userId, $minRole)) {
            throw new AuthException(
                __('ui.backend.approval.requires_role', ['role' => $minRole]),
                403
            );
        }
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this->organizationService);
    }
}
