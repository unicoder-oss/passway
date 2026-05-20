<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\Database;
use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\ApiKeyPermission;
use Passway\Models\Directory;
use Passway\Models\InviteLink;
use Passway\Models\Organization;
use Passway\Models\OrganizationIntegration;
use Passway\Models\OrganizationMember;
use Passway\Models\Passkey;
use Passway\Models\RotationService as RotationServiceModel;
use Passway\Models\Secret;
use Passway\Models\User;
use Passway\Services\AuditService;
use Passway\Services\ApiKeyService;
use Passway\Services\HashingService;
use Passway\Services\OrganizationIntegrationService;
use Passway\Services\RotationService;
use Passway\Services\RotationRegistryService;
use Passway\Services\DirectoryService;
use Passway\Services\InviteService;
use Passway\Services\OrganizationService;
use Passway\Services\SecretService;
use Passway\Services\TemplateService;
use Passway\Services\TotpService;
use Passway\Services\ViewService;

final class WebController
{
    public function __construct(
        private readonly ViewService $view,
        private readonly OrganizationService $organizationService,
        private readonly DirectoryService $directoryService,
        private readonly SecretService $secretService,
        private readonly RotationService $rotationService,
        private readonly TemplateService $templateService,
        private readonly InviteService $inviteService,
        private readonly AuditService $auditService,
        private readonly TotpService $totpService,
        private readonly HashingService $hashingService,
        private readonly ApiKeyService $apiKeyService,
        private readonly RotationRegistryService $rotationRegistryService,
        private readonly OrganizationIntegrationService $organizationIntegrationService,
    ) {}

    public function home(Request $request): Response
    {
        if (!AuthContext::isAuthenticated()) {
            return Response::redirect('/auth/login');
        }

        $user = AuthContext::requireUser();
        $orgs = $this->organizationService->getForUser($user->id);
        $currentOrg = $this->resolveCurrentOrganization($request, $orgs);
        $directories = [];
        $currentDir = null;
        $secrets = [];
        $error = null;

        if ($currentOrg !== null) {
            try {
                $directories = $this->directoryService->listAll($currentOrg->id, $user->id);
                $dirUuid = $request->query('dir');
                if (\is_string($dirUuid) && $dirUuid !== '') {
                    $currentDir = $this->directoryService->findInOrg($dirUuid, $currentOrg->id, $user->id);
                    $secrets = $this->secretService->listInDirectory($currentDir->uuid, $currentOrg->id, $user->id);
                }
            } catch (AuthException | \RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->html($this->view->render('web/home', [
            'title' => 'Passway',
            'user' => $user,
            'organizations' => $orgs,
            'currentOrg' => $currentOrg,
            'directories' => $directories,
            'currentDir' => $currentDir,
            'secrets' => $secrets,
            'templates' => $currentOrg !== null ? $this->templateService->listAvailable($currentOrg->id) : [],
            'integrations' => $currentOrg !== null ? $this->listActiveIntegrationsForOrg($currentOrg->id) : [],
            'error' => $error,
            'queryError' => $request->query('error'),
        ]));
    }

    public function createOrganization(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $name = \trim((string) ($request->input('name') ?? ''));

        try {
            $org = $this->organizationService->create($name, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/?org=' . \urlencode($org->uuid));
    }

    public function createDirectory(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $name = \trim((string) ($request->input('name') ?? ''));
        $parentUuid = $request->input('parent_uuid');
        $parentUuid = \is_string($parentUuid) && $parentUuid !== '' ? $parentUuid : null;

        try {
            $dir = $this->directoryService->create($org->id, $parentUuid, $name, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/?org=' . \urlencode($org->uuid) . '&error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dir->uuid));
    }

    public function createSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $name = \trim((string) ($request->input('name') ?? ''));
        $type = \trim((string) ($request->input('type') ?? 'static'));
        $value = (string) ($request->input('value') ?? '');
        $templateUuid = \trim((string) ($request->input('template_uuid') ?? ''));
        $rotationIntegrationUuid = \trim((string) ($request->input('rotation_integration_uuid') ?? ''));
        $rotationSchedule = \trim((string) ($request->input('rotation_schedule') ?? ''));

        try {
            if ($type === 'template' && $templateUuid !== '') {
                $this->secretService->createFromTemplate(
                    $org->id,
                    $dirUuid,
                    $name,
                    $templateUuid,
                    $user->id,
                    [],
                    $rotationSchedule !== '' ? $rotationSchedule : null,
                );
            } else {
                $this->secretService->create(
                    $org->id,
                    $dirUuid,
                    $name,
                    $type,
                    $value,
                    $user->id,
                    $rotationIntegrationUuid !== '' ? $rotationIntegrationUuid : null,
                    $rotationSchedule !== '' ? $rotationSchedule : null,
                );
            }
        } catch (\Throwable $e) {
            return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dirUuid) . '&error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dirUuid));
    }

    public function showOrganization(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'observer')) {
            return Response::redirect('/?error=' . \urlencode('Access denied.'));
        }

        $members = $this->organizationService->listMembers($org->id);
        $invites = $this->inviteService->listActive($org->id);

        return $this->html($this->view->render('web/organization_manage', [
            'title' => 'Manage Organization',
            'user' => $user,
            'organization' => $org,
            'members' => $members,
            'invites' => $invites,
            'currentRole' => $this->organizationService->getMemberRole($org->id, $user->id),
            'queryError' => $request->query('error'),
        ]));
    }

    public function updateMemberRole(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $targetUser = $this->findMemberUserOrFail($request, $org->id);
        $role = \trim((string) ($request->input('role') ?? ''));

        try {
            $this->organizationService->updateMemberRole($org->id, $targetUser->id, $role, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage');
    }

    public function removeMember(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $targetUser = $this->findMemberUserOrFail($request, $org->id);

        try {
            $this->organizationService->removeMember($org->id, $targetUser->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage');
    }

    public function createInvite(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $role = \trim((string) ($request->input('role') ?? 'user'));
        $ttl = (int) ($request->input('ttl') ?? OrganizationService::DEFAULT_INVITE_TTL);

        try {
            $this->inviteService->createJoinOrgInvite($org->id, $role, $user->id, $ttl);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage');
    }

    public function revokeInvite(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $inviteUuid = (string) $request->routeParam('invUuid');

        try {
            $this->inviteService->revoke($inviteUuid, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage');
    }

    public function showAudit(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $result = $this->auditService->paginateForOrganization($org->id, $user->id, [
                'action' => $request->query('action'),
                'resource_type' => $request->query('resource_type'),
                'success' => $request->query('success'),
                'search' => $request->query('search'),
                'limit' => $request->query('limit', 50),
                'offset' => $request->query('offset', 0),
            ]);
        } catch (\Throwable $e) {
            return Response::redirect('/?org=' . \urlencode($org->uuid) . '&error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/audit', [
            'title' => 'Audit Log',
            'user' => $user,
            'organization' => $org,
            'entries' => $result['entries'],
            'meta' => [
                'total' => $result['total'],
                'limit' => $result['limit'],
                'offset' => $result['offset'],
                'has_more' => $result['has_more'],
            ],
            'filters' => [
                'action' => (string) ($request->query('action') ?? ''),
                'resource_type' => (string) ($request->query('resource_type') ?? ''),
                'success' => (string) ($request->query('success') ?? ''),
                'search' => (string) ($request->query('search') ?? ''),
            ],
        ]));
    }

    public function showProfile(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $totpSetup = $this->getTotpSetupSession();

        return $this->html($this->view->render('web/profile.php', [
            'title' => 'Profile & Security',
            'user' => $user,
            'passkeys' => Passkey::findByUserId($user->id),
            'totpSetup' => $totpSetup,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
        ], layout: 'layout'));
    }

    public function startTotpSetup(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if ($user->totpEnabled) {
            return Response::redirect('/profile?error=' . \urlencode('TOTP is already enabled.'));
        }

        try {
            $data = $this->totpService->generateSecret();
            $qrCodeUri = $this->totpService->getQrCodeUri($user->email, $data['raw_secret']);
            $this->ensureSessionStarted();
            $_SESSION['totp_setup'] = [
                'encrypted' => $data['totp_secret'],
                'nonce' => $data['totp_nonce'],
                'raw_secret' => $data['raw_secret'],
                'qr_code_uri' => $qrCodeUri,
                'expires' => \time() + 600,
            ];
        } catch (\Throwable $e) {
            return Response::redirect('/profile?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/profile');
    }

    public function enableTotp(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $code = \trim((string) ($request->input('code') ?? ''));

        if ($code === '') {
            return Response::redirect('/profile?error=' . \urlencode('TOTP code is required.'));
        }

        $this->ensureSessionStarted();
        $setup = $_SESSION['totp_setup'] ?? null;

        if (!\is_array($setup) || (($setup['expires'] ?? 0) < \time())) {
            unset($_SESSION['totp_setup']);
            return Response::redirect('/profile?error=' . \urlencode('TOTP setup session expired. Please restart setup.'));
        }

        try {
            $valid = $this->totpService->verifyCode(
                encryptedSecret: (string) $setup['encrypted'],
                nonce: (string) $setup['nonce'],
                code: $code,
            );
        } catch (\Throwable $e) {
            return Response::redirect('/profile?error=' . \urlencode($e->getMessage()));
        }

        if (!$valid) {
            return Response::redirect('/profile?error=' . \urlencode('Invalid TOTP code.'));
        }

        $user->update([
            'totp_secret' => $setup['encrypted'],
            'totp_nonce' => $setup['nonce'],
            'totp_enabled' => 1,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
        unset($_SESSION['totp_setup']);

        return Response::redirect('/profile?success=' . \urlencode('Two-factor authentication enabled.'));
    }

    public function disableTotp(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $password = (string) ($request->input('password') ?? '');

        if ($password === '') {
            return Response::redirect('/profile?error=' . \urlencode('Password is required to disable TOTP.'));
        }
        if ($user->passwordHash === null) {
            return Response::redirect('/profile?error=' . \urlencode('Cannot disable TOTP without a password set.'));
        }
        if (!$this->hashingService->verifyPassword($password, $user->passwordHash)) {
            return Response::redirect('/profile?error=' . \urlencode('Incorrect password.'));
        }

        $user->update([
            'totp_enabled' => 0,
            'totp_secret' => null,
            'totp_nonce' => null,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return Response::redirect('/profile?success=' . \urlencode('Two-factor authentication disabled.'));
    }

    public function deletePasskey(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $passkeyUuid = (string) $request->routeParam('uuid');

        $row = \Passway\Core\Database::getInstance()->fetchOne(
            'SELECT * FROM passkeys WHERE uuid = ? AND user_id = ?',
            [$passkeyUuid, $user->id]
        );

        if ($row === null) {
            return Response::redirect('/profile?error=' . \urlencode('Passkey not found.'));
        }

        if ($user->passwordHash === null) {
            $count = (int) \Passway\Core\Database::getInstance()->fetchColumn(
                'SELECT COUNT(*) FROM passkeys WHERE user_id = ?',
                [$user->id]
            );
            if ($count <= 1) {
                return Response::redirect('/profile?error=' . \urlencode('Cannot remove the last passkey when no password is set.'));
            }
        }

        \Passway\Core\Database::getInstance()->delete('passkeys', ['uuid' => $passkeyUuid, 'user_id' => $user->id]);

        return Response::redirect('/profile?success=' . \urlencode('Passkey removed.'));
    }

    public function showApiKeys(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $keys = $this->apiKeyService->listForOrg($org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/api_keys', [
            'title' => 'API Keys',
            'user' => $user,
            'organization' => $org,
            'keys' => $keys,
            'createdRawKey' => null,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
        ]));
    }

    public function showApiKeyPermissions(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');

        try {
            $apiKey = $this->apiKeyService->get($keyUuid, $org->id, $user->id);
            $permissions = $this->apiKeyService->listPermissions($keyUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/api_key_permissions', [
            'title' => 'API Key Permissions',
            'user' => $user,
            'organization' => $org,
            'apiKey' => $apiKey,
            'owner' => $apiKey->userId !== null ? User::findById($apiKey->userId) : null,
            'permissions' => $permissions,
            'permissionTargets' => $this->buildApiKeyPermissionTargets($org),
            'permissionLabels' => $this->buildApiKeyPermissionLabels($permissions, $org),
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
        ]));
    }

    public function createApiKey(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $name = \trim((string) ($request->input('name') ?? ''));
        $environment = \trim((string) ($request->input('environment') ?? 'production'));
        $expiresAt = \trim((string) ($request->input('expires_at') ?? ''));

        try {
            ['key' => $apiKey, 'raw' => $rawKey] = $this->apiKeyService->create(
                $name,
                $org->id,
                $user->id,
                $environment,
                $expiresAt !== '' ? $expiresAt : null,
            );
            $keys = $this->apiKeyService->listForOrg($org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/api_keys', [
            'title' => 'API Keys',
            'user' => $user,
            'organization' => $org,
            'keys' => $keys,
            'createdRawKey' => $rawKey,
            'createdKeyUuid' => $apiKey->uuid,
            'queryError' => null,
            'querySuccess' => 'API key created. Copy it now; it will not be shown again.',
        ]));
    }

    public function createApiKeyPermission(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');
        $permission = \trim((string) ($request->input('permission') ?? 'read'));
        $target = \trim((string) ($request->input('target') ?? 'organization:*'));

        try {
            [$resourceType, $resourceId] = $this->resolveApiKeyPermissionTarget($target, $org);
            $this->apiKeyService->addPermission($keyUuid, $resourceType, $resourceId, $permission, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys/' . \urlencode($keyUuid) . '/permissions?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys/' . \urlencode($keyUuid) . '/permissions?success=' . \urlencode('Permission added.'));
    }

    public function revokeApiKey(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');

        try {
            $this->apiKeyService->revoke($keyUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?success=' . \urlencode('API key revoked.'));
    }

    public function removeApiKeyPermission(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');
        $permId = (string) $request->routeParam('permId');

        try {
            $this->apiKeyService->removePermission($keyUuid, $permId, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys/' . \urlencode($keyUuid) . '/permissions?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys/' . \urlencode($keyUuid) . '/permissions?success=' . \urlencode('Permission removed.'));
    }

    public function showRotationServices(Request $request): Response
    {
        $user = AuthContext::requireUser();

        return $this->html($this->view->render('web/rotation_services', [
            'title' => 'Rotation Services',
            'user' => $user,
            'services' => $this->rotationRegistryService->listAll(),
            'isSetupAdmin' => $this->isSetupAdministrator($user),
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
        ]));
    }

    public function createRotationService(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $name = \trim((string) ($request->input('name') ?? ''));
        $url = \trim((string) ($request->input('url') ?? ''));

        try {
            $this->rotationRegistryService->create($name, $url, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/rotation-services?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/rotation-services?success=' . \urlencode('Rotation service created.'));
    }

    public function updateRotationService(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $serviceUuid = (string) $request->routeParam('svcUuid');
        $name = \trim((string) ($request->input('name') ?? ''));
        $url = \trim((string) ($request->input('url') ?? ''));

        try {
            $this->rotationRegistryService->update(
                $serviceUuid,
                $user->id,
                $name,
                $url,
                $request->input('is_active') !== null,
            );
        } catch (\Throwable $e) {
            return Response::redirect('/rotation-services?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/rotation-services?success=' . \urlencode('Rotation service updated.'));
    }

    public function verifyRotationService(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $serviceUuid = (string) $request->routeParam('svcUuid');

        try {
            $this->rotationRegistryService->verify($serviceUuid, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/rotation-services?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/rotation-services?success=' . \urlencode('Rotation service verified.'));
    }

    public function deleteRotationService(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $serviceUuid = (string) $request->routeParam('svcUuid');

        try {
            $this->rotationRegistryService->delete($serviceUuid, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/rotation-services?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/rotation-services?success=' . \urlencode('Rotation service deleted.'));
    }

    public function showOrganizationIntegrations(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $integrations = $this->organizationIntegrationService->listForOrg($org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/integrations', [
            'title' => 'Organization Integrations',
            'user' => $user,
            'organization' => $org,
            'integrations' => $integrations,
            'services' => $this->rotationRegistryService->listAll(),
            'serviceMap' => $this->buildRotationServiceMap(),
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
        ]));
    }

    public function createOrganizationIntegration(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $name = \trim((string) ($request->input('name') ?? ''));
        $serviceUuid = \trim((string) ($request->input('rotation_service_uuid') ?? ''));

        try {
            $this->organizationIntegrationService->create(
                $org->id,
                $serviceUuid,
                $name,
                $this->parseJsonObjectInput((string) ($request->input('credentials_json') ?? ''), true),
                $user->id,
            );
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?success=' . \urlencode('Integration created.'));
    }

    public function updateOrganizationIntegration(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $integrationUuid = (string) $request->routeParam('intUuid');
        $name = \trim((string) ($request->input('name') ?? ''));
        $credentialsJson = \trim((string) ($request->input('credentials_json') ?? ''));

        try {
            $this->organizationIntegrationService->update(
                $integrationUuid,
                $org->id,
                $user->id,
                $name,
                $credentialsJson !== '' ? $this->parseJsonObjectInput($credentialsJson, false) : null,
                $request->input('is_active') !== null,
            );
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?success=' . \urlencode('Integration updated.'));
    }

    public function deleteOrganizationIntegration(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $integrationUuid = (string) $request->routeParam('intUuid');

        try {
            $this->organizationIntegrationService->delete($integrationUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?success=' . \urlencode('Integration deleted.'));
    }

    public function showSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $dir = $this->directoryService->findInOrg($dirUuid, $org->id, $user->id);
            ['secret' => $secret, 'value' => $value] = $this->secretService->get($secUuid, $org->id, $user->id);
            $versions = $this->secretService->listVersions($secUuid, $org->id, $user->id);
        } catch (AuthException | \RuntimeException | \Passway\Exceptions\DecryptionException $e) {
            return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dirUuid) . '&error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/secret_show', [
            'title' => 'Secret Details',
            'user' => $user,
            'organization' => $org,
            'directory' => $dir,
            'secret' => $secret,
            'value' => $value,
            'versions' => $versions,
            'integrations' => $this->listActiveIntegrationsForOrg($org->id),
            'selectedIntegration' => $secret->rotationIntegrationId !== null ? OrganizationIntegration::findById($secret->rotationIntegrationId) : null,
            'error' => $request->query('error'),
        ]));
    }

    public function updateSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $secUuid = (string) $request->routeParam('secUuid');

        $name = $request->input('name');
        $value = $request->input('value');
        $rotationIntegrationUuid = $request->input('rotation_integration_uuid');
        $rotationSchedule = $request->input('rotation_schedule');
        $name = \is_string($name) ? $name : null;
        $value = \is_string($value) ? $value : null;
        $rotationIntegrationUuid = \is_string($rotationIntegrationUuid) ? $rotationIntegrationUuid : null;
        $rotationSchedule = \is_string($rotationSchedule) ? $rotationSchedule : null;

        try {
            $this->secretService->update($secUuid, $org->id, $user->id, $name, $value !== '' ? $value : null);
            if ($rotationIntegrationUuid !== null || $rotationSchedule !== null) {
                $this->secretService->configureRotation(
                    $secUuid,
                    $org->id,
                    $user->id,
                    $rotationIntegrationUuid !== null && \trim($rotationIntegrationUuid) !== '' ? $rotationIntegrationUuid : null,
                    $rotationSchedule !== null && \trim($rotationSchedule) !== '' ? $rotationSchedule : null,
                );
            }
        } catch (\Throwable $e) {
            return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid, $e->getMessage()));
        }

        return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid));
    }

    public function rotateSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $this->secretService->assertCanRotate($secUuid, $org->id, $user->id);
            $this->rotationService->rotateSecretNow($secUuid, $org->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid, $e->getMessage()));
        }

        return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid));
    }

    public function deleteSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $this->secretService->delete($secUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid, $e->getMessage()));
        }

        return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dirUuid));
    }

    public function renameDirectory(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $name = \trim((string) ($request->input('name') ?? ''));

        try {
            $this->directoryService->rename($dirUuid, $org->id, $name, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dirUuid) . '&error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dirUuid));
    }

    public function deleteDirectory(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $this->directoryService->delete($dirUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/?org=' . \urlencode($org->uuid) . '&dir=' . \urlencode($dirUuid) . '&error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/?org=' . \urlencode($org->uuid));
    }

    private function resolveCurrentOrganization(Request $request, array $orgs): ?Organization
    {
        $orgUuid = $request->query('org');
        if (\is_string($orgUuid) && $orgUuid !== '') {
            foreach ($orgs as $org) {
                if ($org->uuid === $orgUuid) {
                    return $org;
                }
            }
        }

        return $orgs[0] ?? null;
    }

    /** @return array<int, array{value: string, label: string}> */
    private function buildApiKeyPermissionTargets(Organization $org): array
    {
        $targets = [
            ['value' => 'organization:*', 'label' => 'All organization-level resources'],
            ['value' => 'organization:self', 'label' => 'This organization only'],
            ['value' => 'directory:*', 'label' => 'All directories'],
            ['value' => 'secret:*', 'label' => 'All secrets'],
        ];

        $directories = Directory::findByOrgId($org->id);
        foreach ($directories as $directory) {
            $targets[] = [
                'value' => 'directory:' . $directory->uuid,
                'label' => 'Directory: ' . str_repeat('  ', $directory->depth) . $directory->name,
            ];
        }

        foreach ($directories as $directory) {
            foreach (Secret::findByDirId($directory->id) as $secret) {
                $targets[] = [
                    'value' => 'secret:' . $secret->uuid,
                    'label' => 'Secret: ' . $secret->name . ' (' . $directory->name . ')',
                ];
            }
        }

        return $targets;
    }

    /** @param ApiKeyPermission[] $permissions
     *  @return array<string, string>
     */
    private function buildApiKeyPermissionLabels(array $permissions, Organization $org): array
    {
        $labels = [];
        foreach ($permissions as $permission) {
            $labels[$permission->id] = $this->describeApiKeyPermission($permission, $org);
        }

        return $labels;
    }

    /** @return array{0: string, 1: ?string} */
    private function resolveApiKeyPermissionTarget(string $target, Organization $org): array
    {
        [$resourceType, $resourceRef] = \array_pad(\explode(':', $target, 2), 2, null);
        $resourceType = \is_string($resourceType) ? \trim($resourceType) : '';
        $resourceRef = \is_string($resourceRef) ? \trim($resourceRef) : '';

        if ($resourceType === '' || $resourceRef === '') {
            throw new \InvalidArgumentException('Permission target is required.');
        }

        if ($resourceRef === '*') {
            return [$resourceType, null];
        }

        if ($resourceType === 'organization') {
            if ($resourceRef !== 'self') {
                throw new \InvalidArgumentException('Invalid organization target.');
            }

            return ['organization', $org->id];
        }

        if ($resourceType === 'directory') {
            $directory = Directory::findByUuid($resourceRef);
            if ($directory === null || $directory->organizationId !== $org->id) {
                throw new \RuntimeException('Directory not found.');
            }

            return ['directory', $directory->id];
        }

        if ($resourceType === 'secret') {
            $secret = Secret::findByUuid($resourceRef);
            if ($secret === null || $secret->organizationId !== $org->id) {
                throw new \RuntimeException('Secret not found.');
            }

            return ['secret', $secret->id];
        }

        throw new \InvalidArgumentException('Invalid permission target.');
    }

    private function describeApiKeyPermission(ApiKeyPermission $permission, Organization $org): string
    {
        if ($permission->resourceId === null) {
            return 'All ' . $permission->resourceType . ' resources';
        }

        if ($permission->resourceType === 'organization') {
            return 'Organization: ' . $org->name;
        }

        if ($permission->resourceType === 'directory') {
            $directory = Directory::findById($permission->resourceId);
            return 'Directory: ' . ($directory?->name ?? ('#' . $permission->resourceId));
        }

        if ($permission->resourceType === 'secret') {
            $secret = Secret::findById($permission->resourceId);
            return 'Secret: ' . ($secret?->name ?? ('#' . $permission->resourceId));
        }

        return $permission->resourceType . ': #' . $permission->resourceId;
    }

    /** @return array<string, RotationServiceModel> */
    private function buildRotationServiceMap(): array
    {
        $map = [];
        foreach ($this->rotationRegistryService->listAll() as $service) {
            $map[$service->id] = $service;
        }

        return $map;
    }

    /** @return OrganizationIntegration[] */
    private function listActiveIntegrationsForOrg(string $orgId): array
    {
        return \array_values(\array_filter(
            OrganizationIntegration::findByOrgId($orgId),
            static fn(OrganizationIntegration $integration): bool => $integration->isActive,
        ));
    }

    /** @return array<string, mixed> */
    private function parseJsonObjectInput(string $json, bool $allowEmptyObject): array
    {
        $json = \trim($json);
        if ($json === '') {
            if ($allowEmptyObject) {
                return [];
            }

            throw new \InvalidArgumentException('Credentials JSON cannot be empty.');
        }

        $decoded = \json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('Credentials JSON must decode to an object.');
        }

        return $decoded;
    }

    private function isSetupAdministrator(User $user): bool
    {
        $firstUserId = Database::getInstance()->fetchColumn(
            'SELECT id FROM users ORDER BY id ASC LIMIT 1'
        );

        return (string) $firstUserId === $user->id;
    }

    private function findOrgOrFail(Request $request): Organization
    {
        $org = Organization::findByUuid((string) $request->routeParam('uuid'));
        if ($org === null) {
            throw new \RuntimeException('Organization not found.');
        }

        return $org;
    }

    private function findMemberUserOrFail(Request $request, string $orgId): User
    {
        $userUuid = (string) $request->routeParam('userUuid');
        $user = User::findByUuid($userUuid);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        if (OrganizationMember::findByOrgAndUser($orgId, $user->id) === null) {
            throw new \RuntimeException('User is not a member of this organization.');
        }

        return $user;
    }

    private function html(string $body, int $status = 200): Response
    {
        return Response::make($status)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($body);
    }

    private function secretUrl(string $orgUuid, string $dirUuid, string $secUuid, ?string $error = null): string
    {
        $url = '/organizations/' . \urlencode($orgUuid)
            . '/directories/' . \urlencode($dirUuid)
            . '/secrets/' . \urlencode($secUuid);

        if ($error !== null) {
            $url .= '?error=' . \urlencode($error);
        }

        return $url;
    }

    /** @return array<string, mixed>|null */
    private function getTotpSetupSession(): ?array
    {
        $this->ensureSessionStarted();
        $setup = $_SESSION['totp_setup'] ?? null;
        if (!\is_array($setup) || (($setup['expires'] ?? 0) < \time())) {
            unset($_SESSION['totp_setup']);
            return null;
        }

        return $setup;
    }

    private function ensureSessionStarted(): void
    {
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
    }
}
