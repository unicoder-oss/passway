<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\ApprovalRequest;
use Passway\Models\ApprovalReviewer;
use Passway\Models\Secret;

/**
 * Сервис системы одобрений.
 *
 * Жизненный цикл запроса:
 *   1. request()  — создаёт approval_request со статусом pending; назначает ревьювера (owner секрета)
 *   2. approve()  — ревьювер одобряет; генерируется одноразовый токен (TTL 1 ч.)
 *   3. useToken() — запрашивающий предъявляет токен и получает расшифрованное значение секрета
 *
 * Правила:
 *   - requires_approval=true обязателен для создания запроса
 *   - Дублирующий pending-запрос (same user + secret + type) запрещён
 *   - Ревьювер = текущий owner секрета
 *   - Одобрить/отклонить запрос может только owner секрета
 *   - Токен действителен 1 час; одноразовый — после использования статус → expired
 *   - Отозвать (revoke) может: сам запрашивающий (pending), owner секрета или admin+
 *
 * Авторизация доступа к секрету (requires_approval):
 *   - owner секрета — обходит проверку requires_approval (прямой доступ)
 *   - editor+  — обходит проверку requires_approval (прямой доступ)
 *   - reader — обязан пройти workflow одобрения
 */
final class ApprovalService
{
    /** TTL pending-запроса (24 ч) */
    private const REQUEST_TTL_SECONDS = 86_400;

    /** TTL одноразового токена после одобрения (1 ч) */
    private const TOKEN_TTL_SECONDS = 3_600;

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly EncryptionService   $encryptionService,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Создание запроса                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Создать запрос на доступ к секрету с requires_approval=true.
     *
     * @throws AuthException             если нет членства в организации
     * @throws \InvalidArgumentException если тип запроса недопустим или секрет не требует одобрения
     * @throws \RuntimeException         если секрет не найден или уже есть pending-запрос
     */
    public function request(
        string  $secretUuid,
        string  $requestType,
        ?string $reason,
        string  $userId,
        string  $orgId,
    ): ApprovalRequest {
        if (!\in_array($requestType, ApprovalRequest::VALID_REQUEST_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.approval.invalid_request_type', ['allowed' => \implode(', ', ApprovalRequest::VALID_REQUEST_TYPES)])
            );
        }

        // Пользователь должен быть членом организации
        if ($this->organizationService->getMemberRole($orgId, $userId) === null) {
            throw new AuthException(__('ui.backend.organization.not_member'), 403);
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

        if ($secret->ownerUserId === $userId) {
            throw new \InvalidArgumentException(__('ui.backend.approval.secret_direct_access'));
        }

        if (ApprovalRequest::hasPending($secret->id, $userId, $requestType)) {
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

        $db->transaction(function () use ($db, $uuid, $secret, $userId, $requestType, $reason, $expiresAt, $nowStr, $reviewerIds): void {
            $requestId = $db->insert('approval_requests', [
                'uuid'         => $uuid,
                'secret_id'    => (int) $secret->id,
                'requested_by' => (int) $userId,
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
            userId: $userId,
            resourceType: 'approval_request',
            resourceId: $request->id,
            resourceUuid: $request->uuid,
            details: ['request_type' => $requestType],
        );

        return $request;
    }

    // ------------------------------------------------------------------ //
    //  Чтение                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Список собственных запросов пользователя в организации.
     *
     * @return ApprovalRequest[]
     * @throws AuthException если нет членства
     */
    public function listMy(string $userId, string $orgId): array
    {
        if ($this->organizationService->getMemberRole($orgId, $userId) === null) {
            throw new AuthException(__('ui.backend.organization.not_member'), 403);
        }

        // Фильтруем по секретам, принадлежащим организации
        $rows = Database::getInstance()->fetchAll(
            'SELECT ar.* FROM approval_requests ar
             JOIN secrets s ON s.id = ar.secret_id
             WHERE ar.requested_by = ? AND s.organization_id = ?
             ORDER BY ar.created_at DESC',
            [(int) $userId, (int) $orgId]
        );

        return \array_map(fn($r) => ApprovalRequest::fromRow($r), $rows);
    }

    /**
     * Список pending-запросов, где пользователь является ревьювером.
     *
     * @return ApprovalRequest[]
     * @throws AuthException если нет членства
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
     * Просмотр конкретного запроса.
     * Может смотреть: сам запрашивающий или owner секрета.
     *
     * @throws AuthException     если нет прав на просмотр
     * @throws \RuntimeException если не найден
     */
    public function get(string $requestUuid, string $userId, string $orgId): ApprovalRequest
    {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);

        $isRequester = $approvalReq->requestedBy === $userId;
        $isReviewer  = $this->isReviewer($approvalReq, $userId);

        if (!$isRequester && !$isReviewer) {
            throw new AuthException(__('ui.backend.approval.view_request_denied'), 403);
        }

        return $approvalReq;
    }

    // ------------------------------------------------------------------ //
    //  Одобрение / Отклонение                                             //
    // ------------------------------------------------------------------ //

    /**
     * Одобрить запрос.
     * Генерирует одноразовый токен (TTL 1 ч.); токен возвращается открытым ОДИН РАЗ.
     * В БД хранится только SHA-256 хэш.
     *
     * Требуется owner секрета.
     *
     * @return array{request: ApprovalRequest, token: string}
     * @throws AuthException     если нет прав или пытается одобрить свой запрос
     * @throws \RuntimeException если запрос не найден или не в статусе pending
     */
    public function approve(string $requestUuid, string $reviewerId, string $orgId): array
    {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);
        $this->assertReviewer($approvalReq, $reviewerId);

        if ($approvalReq->requestedBy === $reviewerId) {
            throw new AuthException(__('ui.backend.approval.cannot_approve_own'), 403);
        }

        if ($approvalReq->status !== 'pending') {
            throw new \RuntimeException(
                __('ui.backend.approval.cannot_approve_status', ['status' => $approvalReq->status])
            );
        }

        // Генерация одноразового токена (32 байта → 64-символьная hex-строка)
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

        $this->getAuditService()->record(
            action: 'approval.request_approve',
            organizationId: $orgId,
            userId: $reviewerId,
            resourceType: 'approval_request',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
        );

        return ['request' => $updated, 'token' => $rawToken];
    }

    /**
     * Отклонить запрос.
     * Требуется owner секрета.
     *
     * @throws AuthException     если нет прав
     * @throws \RuntimeException если запрос не в статусе pending
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

        $this->getAuditService()->record(
            action: 'approval.request_reject',
            organizationId: $orgId,
            userId: $reviewerId,
            resourceType: 'approval_request',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
        );

        return $updated;
    }

    // ------------------------------------------------------------------ //
    //  Отзыв                                                              //
    // ------------------------------------------------------------------ //

    /**
     * Отозвать запрос.
     *   - Сам запрашивающий может отозвать только pending-запрос.
     *   - owner секрета и admin+ могут отозвать pending или approved.
     *
     * @throws AuthException     если нет прав
     * @throws \RuntimeException если запрос не найден или уже finalized
     */
    public function revoke(string $requestUuid, string $userId, string $orgId): void
    {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);
        $isAdmin     = $this->organizationService->hasPermission($orgId, $userId, 'admin');
        $isRequester = $approvalReq->requestedBy === $userId;
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
    //  Использование токена                                               //
    // ------------------------------------------------------------------ //

    /**
     * Использовать одноразовый токен для получения значения секрета.
     *
     * Только запрашивающий может использовать свой токен.
     * Токен потребляется: после успешного использования статус становится 'expired'.
     *
     * @return array{secret: Secret, value: string}
     * @throws AuthException     если нет прав или токен недействителен
     * @throws \RuntimeException если запрос не найден
     */
    public function useToken(
        string $requestUuid,
        string $token,
        string $userId,
        string $orgId,
    ): array {
        $approvalReq = $this->findRequestInOrg($requestUuid, $orgId);

        if ($approvalReq->requestedBy !== $userId) {
            throw new AuthException(__('ui.backend.approval.not_requester'), 403);
        }

        if ($approvalReq->status !== 'approved') {
            throw new AuthException(
                __('ui.backend.approval.token_invalid_status', ['status' => $approvalReq->status]),
                403
            );
        }

        if ($approvalReq->accessTokenHash === null) {
            throw new AuthException(__('ui.backend.approval.token_already_used'), 403);
        }

        // Проверить срок действия токена
        $expiresAt = new \DateTimeImmutable($approvalReq->expiresAt, new \DateTimeZone('UTC'));
        if ($expiresAt <= now()) {
            // Автоматически перевести в expired
            $approvalReq->update(['status' => 'expired']);
            throw new AuthException(__('ui.backend.approval.token_expired'), 403);
        }

        // Проверить хэш токена
        $providedHash = \hash('sha256', $token);
        if (!\hash_equals($approvalReq->accessTokenHash, $providedHash)) {
            throw new AuthException(__('ui.backend.approval.token_invalid'), 403);
        }

        // Токен валиден — получить секрет
        $secret = Secret::findById($approvalReq->secretId)
            ?? throw new \RuntimeException(__('ui.backend.secret.not_found'));

        $value = $this->encryptionService->decrypt(
            $secret->encryptedValue,
            $secret->nonce,
            $secret->uuid
        );

        // Потребить токен
        $approvalReq->update([
            'status'            => 'expired',
            'access_token_hash' => null,
            'resolved_at'       => now()->format('Y-m-d H:i:s'),
        ]);

        $this->getAuditService()->record(
            action: 'approval.token_use',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'approval_request',
            resourceId: $approvalReq->id,
            resourceUuid: $approvalReq->uuid,
            details: ['secret_uuid' => $secret->uuid],
        );

        return ['secret' => $secret, 'value' => $value];
    }

    // ------------------------------------------------------------------ //
    //  Вспомогательные                                                    //
    // ------------------------------------------------------------------ //

    /**
     * @throws \RuntimeException если секрет не найден или принадлежит другой орг.
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
     * @throws \RuntimeException если запрос не найден или принадлежит другой орг.
     */
    private function findRequestInOrg(string $requestUuid, string $orgId): ApprovalRequest
    {
        $req = ApprovalRequest::findByUuid($requestUuid);
        if ($req === null) {
            throw new \RuntimeException(__('ui.backend.approval.request_not_found'));
        }

        // Проверить что секрет принадлежит организации
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
