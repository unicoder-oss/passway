<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\InviteLink;
use Passway\Models\Organization;
use Passway\Models\OrganizationMember;

/**
 * Service invite links.
 *
 * Invite types:
 *   join_org   - join an existing organization
 *   create_org - create a new organization (team mode only)
 *
 * Security:
 *   - 64-hex token (32 random bytes), stored as plaintext (short-lived, single-use)
 *   - Default lifetime: 1 hour (OrganizationService::DEFAULT_INVITE_TTL)
 *   - After use: used_at is filled, cannot be reused
 */
final class InviteService
{
    public function __construct(
        private readonly TokenService        $tokenService,
        private readonly OrganizationService $organizationService,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Invite creation
    // ------------------------------------------------------------------ //

    /**
     * Create an invite link for joining an organization.
     *
     * @throws AuthException if the creator lacks admin+ role
     * @throws \InvalidArgumentException on invalid role
     */
    public function createJoinOrgInvite(
        string $orgId,
        string $role,
        string $createdBy,
        int    $ttlSeconds = OrganizationService::DEFAULT_INVITE_TTL,
    ): InviteLink {
        if (DeployMode::isSolo()) {
            throw new AuthException(__('ui.backend.invite.join_org_not_in_solo'), 403);
        }

        $this->assertValidInviteRole($role);

        // Only admin+ can create invites
        if (!$this->organizationService->hasPermission($orgId, $createdBy, 'admin')) {
            throw new AuthException(__('ui.backend.invite.requires_admin_create'), 403);
        }

        // Only owner can create an invite with admin role
        if ($role === 'admin' && !$this->organizationService->hasPermission($orgId, $createdBy, 'owner')) {
            throw new AuthException(__('ui.backend.invite.only_owner_create_admin'), 403);
        }

        return $this->insertInvite(
            type:           InviteLink::TYPE_JOIN_ORG,
            orgId:          $orgId,
            role:           $role,
            createdBy:      $createdBy,
            ttlSeconds:     $ttlSeconds,
        );
    }

    /**
     * Create an invite for registration and new organization creation.
     * Available only in team mode.
     *
     * @throws \RuntimeException in solo mode
     */
    public function createOrgInvite(
        string $createdBy,
        int    $ttlSeconds = OrganizationService::DEFAULT_INVITE_TTL,
    ): InviteLink {
        if (DeployMode::isSolo()) {
            throw new \RuntimeException(__('ui.backend.invite.create_org_not_in_solo'));
        }

        return $this->insertInvite(
            type:       InviteLink::TYPE_CREATE_ORG,
            orgId:      null,
            role:       'owner',
            createdBy:  $createdBy,
            ttlSeconds: $ttlSeconds,
        );
    }

    // ------------------------------------------------------------------ //
    //  Invite lookup                                                  //
    // ------------------------------------------------------------------ //

    /**
     * Find a valid (not expired, unused) invite by token.
     *
     * @throws AuthException if the invite is not found / expired / used
     */
    public function findValid(string $token): InviteLink
    {
        $invite = InviteLink::findByToken($token);

        if ($invite === null) {
            throw new AuthException(__('ui.backend.invite.link_not_found'));
        }
        if ($invite->isExpired()) {
            throw new AuthException(__('ui.backend.invite.link_expired'));
        }
        if ($invite->isUsed()) {
            throw new AuthException(__('ui.backend.invite.link_already_used'));
        }

        return $invite;
    }

    /**
     * Active organization invites.
     *
     * @return InviteLink[]
     */
    public function listActive(string $orgId): array
    {
        return InviteLink::findActiveByOrgId($orgId);
    }

    // ------------------------------------------------------------------ //
    //  Invite acceptance                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Accept a join_org invite: add acceptorUserId to the organization.
     *
     * @throws AuthException if the invite is invalid
     * @throws \RuntimeException if the user is already in the org.
     * @return Organization - organization the user joined
     */
    public function acceptJoinOrg(string $token, string $acceptorUserId): Organization
    {
        if (DeployMode::isSolo()) {
            throw new AuthException(__('ui.backend.invite.join_org_not_in_solo'), 403);
        }

        $invite = $this->findValid($token);

        if ($invite->type !== InviteLink::TYPE_JOIN_ORG) {
            throw new AuthException(__('ui.backend.invite.wrong_type_join_org'));
        }
        if ($invite->organizationId === null) {
            throw new \RuntimeException(__('ui.backend.invite.missing_org_reference'));
        }

        $org = Organization::findById($invite->organizationId);
        if ($org === null || !$org->isActive) {
            throw new \RuntimeException(__('ui.backend.invite.org_not_found_or_inactive'));
        }

        Database::getInstance()->transaction(function () use ($invite, $org, $acceptorUserId): void {
            $this->organizationService->addMember(
                orgId:     $org->id,
                userId:    $acceptorUserId,
                role:      $invite->role,
                invitedBy: $invite->createdBy,
            );

            $this->markUsed($invite->id, $acceptorUserId);
        });

        $this->getAuditService()->record(
            action: 'invite.accept',
            organizationId: $org->id,
            userId: $acceptorUserId,
            resourceType: 'invite',
            resourceId: $invite->id,
            resourceUuid: $invite->uuid,
        );

        return $org;
    }

    public function acceptCreateOrg(string $token, string $acceptorUserId, string $name): Organization
    {
        $invite = $this->findValid($token);

        if ($invite->type !== InviteLink::TYPE_CREATE_ORG) {
            throw new AuthException(__('ui.backend.invite.wrong_type_create_org'));
        }

        $org = $this->organizationService->create($name, $acceptorUserId);
        $this->markUsed($invite->id, $acceptorUserId);

        if (!$org instanceof Organization) {
            throw new \RuntimeException(__('ui.backend.organization.failed_load_created'));
        }

        $this->getAuditService()->record(
            action: 'invite.accept',
            organizationId: $org->id,
            userId: $acceptorUserId,
            resourceType: 'invite',
            resourceId: $invite->id,
            resourceUuid: $invite->uuid,
            details: ['type' => InviteLink::TYPE_CREATE_ORG],
        );

        return $org;
    }

    // ------------------------------------------------------------------ //
    //  Invite revocation
    // ------------------------------------------------------------------ //

    /**
     * Invalidate an invite (mark as expired).
     *
     * @throws AuthException if requesterId lacks admin+ permission
     */
    public function revoke(string $inviteUuid, string $requesterId): void
    {
        $invite = InviteLink::findByUuid($inviteUuid);
        if ($invite === null) {
            throw new \RuntimeException(__('ui.backend.invite.invite_not_found'));
        }
        if ($invite->isUsed()) {
            throw new \RuntimeException(__('ui.backend.invite.cannot_revoke_used'));
        }

        // Permission check: only organization admin+ or invite creator
        if ($invite->organizationId !== null) {
            if (!$this->organizationService->hasPermission($invite->organizationId, $requesterId, 'admin')) {
                throw new AuthException(__('ui.backend.invite.requires_admin_revoke'), 403);
            }
        }

        // Mark as expired (expires_at = now)
        Database::getInstance()->update(
            'invite_links',
            ['expires_at' => now()->format('Y-m-d H:i:s')],
            ['id' => $invite->id]
        );

        $this->getAuditService()->record(
            action: 'invite.revoke',
            organizationId: $invite->organizationId,
            userId: $requesterId,
            resourceType: 'invite',
            resourceId: $invite->id,
            resourceUuid: $invite->uuid,
        );
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    private function insertInvite(
        string  $type,
        ?string $orgId,
        string  $role,
        string  $createdBy,
        int     $ttlSeconds,
    ): InviteLink {
        $token     = $this->tokenService->generateInviteToken();
        $now       = now()->format('Y-m-d H:i:s');
        $expiresAt = \date('Y-m-d H:i:s', \time() + $ttlSeconds);

        $id = Database::getInstance()->insert('invite_links', [
            'uuid'            => generate_uuid(),
            'token'           => $token,
            'type'            => $type,
            'organization_id' => $orgId !== null ? (int) $orgId : null,
            'role'            => $role,
            'created_by'      => (int) $createdBy,
            'used_by'         => null,
            'expires_at'      => $expiresAt,
            'used_at'         => null,
            'created_at'      => $now,
        ]);

        $invite = InviteLink::findByToken($token)
            ?? throw new \RuntimeException(__('ui.backend.invite.failed_load_created'));

        $this->getAuditService()->record(
            action: 'invite.create',
            organizationId: $orgId,
            userId: $createdBy,
            resourceType: 'invite',
            resourceId: $invite->id,
            resourceUuid: $invite->uuid,
            details: ['type' => $type, 'role' => $role],
        );

        return $invite;
    }

    private function markUsed(string $inviteId, string $userId): void
    {
        Database::getInstance()->update(
            'invite_links',
            [
                'used_by' => (int) $userId,
                'used_at' => now()->format('Y-m-d H:i:s'),
            ],
            ['id' => (int) $inviteId]
        );
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidInviteRole(string $role): void
    {
        // owner is not granted through an invite - ownership is transferred separately
        $allowed = \array_filter(
            OrganizationMember::ROLES,
            fn($r) => $r !== 'owner'
        );
        if (!\in_array($role, $allowed, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.invite.invalid_role', ['allowed' => \implode(', ', $allowed)])
            );
        }
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this->organizationService);
    }
}
