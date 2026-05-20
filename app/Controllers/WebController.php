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
use Passway\Models\Template;
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
    private const ROOT_SECRET_DIRECTORY_NAME = '__passway_root_secrets__';

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
        return $this->renderHome($request, $user);
    }

    public function createOrganization(Request $request): Response
    {
        return Response::redirect('/?error=' . \urlencode(__('ui.home.use_create_org_invite')));
    }

    public function createOrganizationInvite(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if (!$this->isSetupAdministrator($user)) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $ttlHours = (int) ($request->input('ttl') ?? 1);
        if ($ttlHours < 1 || $ttlHours > 168) {
            $ttlHours = 1;
        }
        $ttlSeconds = $ttlHours * 3600;

        try {
            $invite = $this->inviteService->createOrgInvite($user->id, $ttlSeconds);
        } catch (\Throwable $e) {
            return Response::redirect('/?error=' . \urlencode($e->getMessage()));
        }

        return $this->renderHome(
            $request,
            $user,
            success: __('ui.home.organization_invite_created'),
            createdOrganizationInvite: $invite,
        );
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
            return Response::redirect($this->organizationUrl($org->uuid, error: $e->getMessage()));
        }

        return Response::redirect($this->organizationUrl($org->uuid, $dir->uuid));
    }

    public function createSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        return $this->handleSecretCreate($request, $org, $user, $dirUuid, $this->organizationUrl($org->uuid, $dirUuid));
    }

    public function createRootSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $rootDirectory = $this->getOrCreateRootSecretDirectory($org, $user);
        } catch (\Throwable $e) {
            return Response::redirect($this->organizationUrl($org->uuid, error: $e->getMessage()));
        }

        return $this->handleSecretCreate($request, $org, $user, $rootDirectory->uuid, $this->organizationUrl($org->uuid));
    }

    private function handleSecretCreate(Request $request, Organization $org, User $user, string $dirUuid, string $successRedirect): Response
    {
        $name = \trim((string) ($request->input('name') ?? ''));
        $type = \trim((string) ($request->input('type') ?? 'static'));
        $value = (string) ($request->input('value') ?? '');
        $templateUuid = \trim((string) ($request->input('template_uuid') ?? ''));
        $templateOverrides = $this->parseTemplateOverridesRequestInput($request->input('template_overrides'));
        $rotationIntegrationUuid = \trim((string) ($request->input('rotation_integration_uuid') ?? ''));
        $rotationSchedule = \trim((string) ($request->input('rotation_schedule') ?? ''));
        $rotationInput = $request->input('rotation_input');

        try {
            if ($type === 'template' && $templateUuid !== '') {
                $secret = $this->secretService->createFromTemplate(
                    $org->id,
                    $dirUuid,
                    $name,
                    $templateUuid,
                    $user->id,
                    $templateOverrides,
                    $rotationSchedule !== '' ? $rotationSchedule : null,
                    $value,
                );
            } elseif ($type === 'dynamic') {
                $secret = $this->rotationService->provisionDynamicSecret(
                    $org->id,
                    $dirUuid,
                    $name,
                    $rotationIntegrationUuid,
                    $rotationSchedule !== '' ? $rotationSchedule : null,
                    \is_array($rotationInput) ? $rotationInput : [],
                    $user->id,
                );
            } else {
                $secret = $this->secretService->create(
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
            return Response::redirect($this->organizationUrl($org->uuid, $this->isRootSecretDirectoryUuid($org, $dirUuid) ? null : $dirUuid, $e->getMessage()));
        }

        return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secret->uuid));
    }

    public function showOrganization(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'observer')) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $currentDir = null;
        $directories = [];
        $secrets = [];
        $searchDirectories = [];
        $searchSecrets = [];
        $search = \trim((string) ($request->query('q') ?? ''));
        $canManageOrganization = $this->organizationService->hasPermission($org->id, $user->id, 'admin');
        $canViewAudit = $canManageOrganization;
        $canEditContent = $this->organizationService->hasPermission($org->id, $user->id, 'moderator');
        $rootSecretDirectory = $this->findRootSecretDirectory($org->id);

        if ($rootSecretDirectory === null && $canEditContent) {
            try {
                $rootSecretDirectory = $this->getOrCreateRootSecretDirectory($org, $user);
            } catch (\Throwable $e) {
                return Response::redirect($this->organizationUrl($org->uuid, error: $e->getMessage()));
            }
        }

        try {
            $dirUuid = $request->query('dir');
            $dirUuid = \is_string($dirUuid) && $dirUuid !== '' ? $dirUuid : null;

            if ($dirUuid !== null && $this->isRootSecretDirectoryUuid($org, $dirUuid)) {
                $dirUuid = null;
            }

            if ($dirUuid !== null) {
                $currentDir = $this->directoryService->findInOrg($dirUuid, $org->id, $user->id);
                $directories = $this->filterVisibleDirectories($this->directoryService->listChildren($org->id, $currentDir->uuid, $user->id));
                $secrets = $this->secretService->listInDirectory($currentDir->uuid, $org->id, $user->id);
            } else {
                $directories = $this->filterVisibleDirectories($this->directoryService->listChildren($org->id, null, $user->id));
                if ($rootSecretDirectory !== null) {
                    $secrets = $this->secretService->listInDirectory($rootSecretDirectory->uuid, $org->id, $user->id);
                }
            }
        } catch (AuthException | \RuntimeException $e) {
            return Response::redirect($this->organizationUrl($org->uuid, error: $e->getMessage()));
        }

        if ($search !== '') {
            ['directories' => $searchDirectories, 'secrets' => $searchSecrets] = $this->searchOrganizationSubtree(
                $org,
                $currentDir,
                $search,
            );
        }

        $allDirectories = Directory::findByOrgId($org->id);
        $directoryMap = [];
        foreach ($allDirectories as $directory) {
            $directoryMap[$directory->id] = $directory;
        }

        $directoryPaths = [];
        foreach ($directories as $directory) {
            $directoryPaths[$directory->uuid] = $this->humanDirectoryPath($directory, $directoryMap);
        }

        $currentDirPath = $currentDir !== null ? $this->humanDirectoryPath($currentDir, $directoryMap) : null;
        $parentDirectory = $currentDir !== null && $currentDir->parentId !== null
            ? ($directoryMap[$currentDir->parentId] ?? null)
            : null;

        return $this->html($this->view->render('web/organization', [
            'title' => $org->name,
            'user' => $user,
            'organization' => $org,
            'currentDir' => $currentDir,
            'currentDirStats' => $currentDir !== null ? $this->buildCurrentDirectoryStats($currentDir) : null,
            'rootSecretDirectory' => $rootSecretDirectory,
            'directories' => $directories,
            'directoryPaths' => $directoryPaths,
            'secrets' => $secrets,
            'searchDirectories' => $searchDirectories,
            'searchSecrets' => $searchSecrets,
            'parentDirectory' => $parentDirectory,
            'currentDirPath' => $currentDirPath,
            'templates' => $this->templateService->listAvailable($org->id),
            'integrations' => $this->listActiveIntegrationsForOrg($org->id),
            'rotationServiceMap' => $this->buildRotationServiceMap(),
            'canManageOrganization' => $canManageOrganization,
            'canViewAudit' => $canViewAudit,
            'canEditContent' => $canEditContent,
            'queryError' => $request->query('error'),
            'search' => $search,
        ]));
    }

    public function showOrganizationManage(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'observer')) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $members = $this->organizationService->listMembers($org->id);
        $invites = $this->inviteService->listActive($org->id);

        return $this->html($this->view->render('web/organization_manage', [
            'title' => __('ui.titles.manage_organization'),
            'user' => $user,
            'organization' => $org,
            'members' => $members,
            'invites' => $invites,
            'currentRole' => $this->organizationService->getMemberRole($org->id, $user->id),
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'canManageSettings' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
        ]));
    }

    public function updateOrganizationSettings(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'admin')) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $description = $request->input('description');
        $description = \is_string($description) ? \trim($description) : '';
        $avatarData = \trim((string) ($request->input('avatar_data') ?? ''));
        $avatarPath = null;

        try {
            $avatarPath = $this->storeCroppedAvatar($avatarData, 'organizations/avatars', __('ui.organization_manage.avatar_invalid'));

            $update = [
                'description' => $description !== '' ? $description : null,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ];

            if ($avatarPath !== null) {
                $update['avatar_path'] = $avatarPath;
            }

            $org->update($update);

            if ($avatarPath !== null) {
                $this->deleteUploadedFile($org->avatarPath);
            }
        } catch (\Throwable $e) {
            if ($avatarPath !== null) {
                $this->deleteUploadedFile($avatarPath);
            }

            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/manage?success=' . \urlencode(__('ui.organization_manage.settings_saved')));
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
        $ttlHours = (int) ($request->input('ttl') ?? 1);
        if ($ttlHours < 1 || $ttlHours > 168) {
            $ttlHours = 1;
        }
        $ttl = $ttlHours * 3600;

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
            return Response::redirect($this->organizationUrl($org->uuid, error: $e->getMessage()));
        }

        return $this->html($this->view->render('web/audit', [
            'title' => __('ui.titles.audit_log'),
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

        return $this->html($this->view->render('web/profile', [
            'title' => __('ui.titles.profile_security'),
            'user' => $user,
            'passkeys' => Passkey::findByUserId($user->id),
            'totpSetup' => $totpSetup,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
        ], layout: 'layout'));
    }

    public function updateProfile(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $avatarData = \trim((string) ($request->input('avatar_data') ?? ''));
        $avatarPath = null;

        try {
            $avatarPath = $this->storeCroppedAvatar($avatarData, 'users/avatars', __('ui.profile.avatar_invalid'));
            if ($avatarPath === null) {
                throw new \InvalidArgumentException(__('ui.profile.avatar_required'));
            }

            $user->update([
                'avatar_path' => $avatarPath,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);

            $this->deleteUploadedFile($user->avatarPath);
        } catch (\Throwable $e) {
            if ($avatarPath !== null) {
                $this->deleteUploadedFile($avatarPath);
            }

            return Response::redirect('/profile?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/profile?success=' . \urlencode(__('ui.profile.avatar_saved')));
    }

    public function startTotpSetup(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if ($user->totpEnabled) {
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_totp_already_enabled')));
        }

        try {
            $data = $this->totpService->generateSecret();
            $qrCodeUri = $this->totpService->getQrCodeUri($user->email, $data['raw_secret']);
            $qrCodeImage = null;

            try {
                $qrCodeImage = $this->totpService->getQrCodeImageDataUri($user->email, $data['raw_secret']);
            } catch (\Throwable) {
                // Не срываем настройку TOTP, если QR не удалось отрисовать локально.
            }

            $this->ensureSessionStarted();
            $_SESSION['totp_setup'] = [
                'encrypted' => $data['totp_secret'],
                'nonce' => $data['totp_nonce'],
                'raw_secret' => $data['raw_secret'],
                'qr_code_uri' => $qrCodeUri,
                'qr_code_image' => $qrCodeImage,
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
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_totp_code_required')));
        }

        $this->ensureSessionStarted();
        $setup = $_SESSION['totp_setup'] ?? null;

        if (!\is_array($setup) || (($setup['expires'] ?? 0) < \time())) {
            unset($_SESSION['totp_setup']);
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_totp_setup_expired')));
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
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_invalid_totp_code')));
        }

        $user->update([
            'totp_secret' => $setup['encrypted'],
            'totp_nonce' => $setup['nonce'],
            'totp_enabled' => 1,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
        unset($_SESSION['totp_setup']);

        return Response::redirect('/profile?success=' . \urlencode(__('ui.profile.success_totp_enabled')));
    }

    public function disableTotp(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $password = (string) ($request->input('password') ?? '');

        if ($password === '') {
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_disable_totp_password_required')));
        }
        if ($user->passwordHash === null) {
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_disable_totp_password_missing')));
        }
        if (!$this->hashingService->verifyPassword($password, $user->passwordHash)) {
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_incorrect_password')));
        }

        $user->update([
            'totp_enabled' => 0,
            'totp_secret' => null,
            'totp_nonce' => null,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return Response::redirect('/profile?success=' . \urlencode(__('ui.profile.success_totp_disabled')));
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
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_passkey_not_found')));
        }

        if ($user->passwordHash === null) {
            $count = (int) \Passway\Core\Database::getInstance()->fetchColumn(
                'SELECT COUNT(*) FROM passkeys WHERE user_id = ?',
                [$user->id]
            );
            if ($count <= 1) {
                return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_last_passkey_without_password')));
            }
        }

        \Passway\Core\Database::getInstance()->delete('passkeys', ['uuid' => $passkeyUuid, 'user_id' => $user->id]);

        return Response::redirect('/profile?success=' . \urlencode(__('ui.profile.success_passkey_removed')));
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
            'title' => __('ui.titles.api_keys'),
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
            'title' => __('ui.titles.api_key_permissions'),
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
        $expiresAt = \trim((string) ($request->input('expires_at') ?? ''));

        try {
            ['key' => $apiKey, 'raw' => $rawKey] = $this->apiKeyService->create(
                $name,
                $org->id,
                $user->id,
                $expiresAt !== '' ? $expiresAt : null,
            );
            $keys = $this->apiKeyService->listForOrg($org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/api_keys', [
            'title' => __('ui.titles.api_keys'),
            'user' => $user,
            'organization' => $org,
            'keys' => $keys,
            'createdRawKey' => $rawKey,
            'createdKeyUuid' => $apiKey->uuid,
            'queryError' => null,
            'querySuccess' => __('ui.api_keys.created_copy_now'),
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

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys/' . \urlencode($keyUuid) . '/permissions?success=' . \urlencode(__('ui.messages.permission_added')));
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

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?success=' . \urlencode(__('ui.messages.api_key_revoked')));
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

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys/' . \urlencode($keyUuid) . '/permissions?success=' . \urlencode(__('ui.messages.permission_removed')));
    }

    public function showRotationServices(Request $request): Response
    {
        $user = AuthContext::requireUser();

        return $this->html($this->view->render('web/rotation_services', [
            'title' => __('ui.titles.rotation_services'),
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

        return Response::redirect('/rotation-services?success=' . \urlencode(__('ui.messages.rotation_service_created')));
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

        return Response::redirect('/rotation-services?success=' . \urlencode(__('ui.messages.rotation_service_updated')));
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

        return Response::redirect('/rotation-services?success=' . \urlencode(__('ui.messages.rotation_service_verified')));
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

        return Response::redirect('/rotation-services?success=' . \urlencode(__('ui.messages.rotation_service_deleted')));
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
            'title' => __('ui.titles.organization_integrations'),
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
        $credentials = $request->input('credentials');

        try {
            $this->organizationIntegrationService->create(
                $org->id,
                $serviceUuid,
                $name,
                \is_array($credentials)
                    ? (array) $credentials
                    : $this->parseJsonObjectInput((string) ($request->input('credentials_json') ?? ''), true),
                $user->id,
            );
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?success=' . \urlencode(__('ui.messages.integration_created')));
    }

    public function updateOrganizationIntegration(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $integrationUuid = (string) $request->routeParam('intUuid');
        $name = \trim((string) ($request->input('name') ?? ''));
        $credentialsJson = \trim((string) ($request->input('credentials_json') ?? ''));
        $credentials = $request->input('credentials');

        try {
            $this->organizationIntegrationService->update(
                $integrationUuid,
                $org->id,
                $user->id,
                $name,
                \is_array($credentials)
                    ? (array) $credentials
                    : ($credentialsJson !== '' ? $this->parseJsonObjectInput($credentialsJson, false) : null),
                $request->input('is_active') !== null,
            );
        } catch (\Throwable $e) {
            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?success=' . \urlencode(__('ui.messages.integration_updated')));
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

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/integrations?success=' . \urlencode(__('ui.messages.integration_deleted')));
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
            return Response::redirect($this->organizationUrl($org->uuid, $dirUuid, $e->getMessage()));
        }

        $displayValue = $value;
        $templateDetails = null;
        $templateOverrides = [];
        $templateParameterSchema = [];
        $templateExtraFields = [];
        $dynamicRotationView = ['input' => [], 'outputs' => [], 'primary_field' => null, 'service' => null];

        if ($secret->type === 'template' && $secret->templateId !== null) {
            $template = Template::findById($secret->templateId);
            if ($template !== null) {
                $templateOverrides = $this->secretService->getTemplateOverrides($secret->uuid, $org->id, $user->id);
                $templateView = $this->templateService->describeValue($template->uuid, $value, $org->id, $templateOverrides);
                $displayValue = $templateView['display_value'];
                $templateParameterSchema = $templateView['parameter_schema'];
                $templateExtraFields = $templateView['extra_fields'];
                $templateOverrides = $templateView['overrides'];
                $templateDetails = [
                    'uuid' => $template->uuid,
                    'name' => $template->name,
                    'type' => $template->type,
                ];
            }
        } elseif ($secret->type === 'dynamic') {
            $dynamicRotationView = $this->secretService->getDynamicSecretView($secret->uuid, $org->id, $user->id);
        }

        return $this->html($this->view->render('web/secret_show', [
            'title' => __('ui.titles.secret_details'),
            'user' => $user,
            'organization' => $org,
            'directory' => $dir,
            'secret' => $secret,
            'value' => $value,
            'displayValue' => $displayValue,
            'directoryDisplayName' => $this->isHiddenDirectory($dir) ? __('ui.organization.root_level') : $dir->name,
            'directoryBackUrl' => $this->organizationUrl($org->uuid, $this->isHiddenDirectory($dir) ? null : $dir->uuid),
            'versions' => $versions,
            'integrations' => $this->listActiveIntegrationsForOrg($org->id),
            'selectedIntegration' => $secret->rotationIntegrationId !== null ? OrganizationIntegration::findById($secret->rotationIntegrationId) : null,
            'templateDetails' => $templateDetails,
            'templateOverrides' => $templateOverrides,
            'templateParameterSchema' => $templateParameterSchema,
            'templateExtraFields' => $templateExtraFields,
            'dynamicRotationView' => $dynamicRotationView,
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

    public function regenerateTemplateSecret(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $this->secretService->regenerateFromTemplate(
                $secUuid,
                $org->id,
                $user->id,
                $this->parseTemplateOverridesRequestInput($request->input('template_overrides')),
                \is_string($request->input('value')) ? (string) $request->input('value') : null,
            );
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

        return Response::redirect($this->organizationUrl($org->uuid, $this->isRootSecretDirectoryUuid($org, $dirUuid) ? null : $dirUuid));
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
            return Response::redirect($this->organizationUrl($org->uuid, $dirUuid, $e->getMessage()));
        }

        return Response::redirect($this->organizationUrl($org->uuid, $dirUuid));
    }

    public function deleteDirectory(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $this->directoryService->delete($dirUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->organizationUrl($org->uuid, $dirUuid, $e->getMessage()));
        }

        return Response::redirect($this->organizationUrl($org->uuid));
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

    private function renderHome(
        Request $request,
        User $user,
        ?string $success = null,
        ?InviteLink $createdOrganizationInvite = null,
    ): Response {
        $orgs = $this->organizationService->getForUser($user->id);
        $search = \trim((string) ($request->query('q') ?? ''));
        if ($search !== '') {
            $orgs = \array_values(\array_filter(
                $orgs,
                static fn(Organization $org): bool => mb_stripos($org->name, $search, 0, 'UTF-8') !== false,
            ));
        }

        $currentOrg = $this->resolveCurrentOrganization($request, $orgs);

        return $this->html($this->view->render('web/home', [
            'title' => __('ui.titles.home'),
            'user' => $user,
            'organizations' => $orgs,
            'organizationCards' => $this->buildHomeOrganizationCards($orgs),
            'currentOrg' => $currentOrg,
            'queryError' => $request->query('error'),
            'querySuccess' => $success,
            'isSetupAdmin' => $this->isSetupAdministrator($user),
            'createdOrganizationInvite' => $createdOrganizationInvite,
            'search' => $search,
        ]));
    }

    /**
     * @param Organization[] $organizations
     * @return array<int, array{organization: Organization, directories: int, secrets: int, members: int}>
     */
    private function buildHomeOrganizationCards(array $organizations): array
    {
        $cards = [];
        if ($organizations === []) {
            return $cards;
        }

        $orgIds = array_map(static fn(Organization $organization): int => (int) $organization->id, $organizations);
        $placeholders = implode(', ', array_fill(0, count($orgIds), '?'));
        $db = Database::getInstance();

        $directoryCounts = [];
        foreach ($db->fetchAll(
            'SELECT organization_id, COUNT(*) AS total FROM directories WHERE deleted_at IS NULL AND organization_id IN (' . $placeholders . ') GROUP BY organization_id',
            $orgIds,
        ) as $row) {
            $directoryCounts[(string) $row['organization_id']] = (int) $row['total'];
        }

        $secretCounts = [];
        foreach ($db->fetchAll(
            'SELECT organization_id, COUNT(*) AS total FROM secrets WHERE deleted_at IS NULL AND organization_id IN (' . $placeholders . ') GROUP BY organization_id',
            $orgIds,
        ) as $row) {
            $secretCounts[(string) $row['organization_id']] = (int) $row['total'];
        }

        $memberCounts = [];
        foreach ($db->fetchAll(
            'SELECT organization_id, COUNT(*) AS total FROM organization_members WHERE organization_id IN (' . $placeholders . ') GROUP BY organization_id',
            $orgIds,
        ) as $row) {
            $memberCounts[(string) $row['organization_id']] = (int) $row['total'];
        }

        foreach ($organizations as $organization) {
            $cards[] = [
                'organization' => $organization,
                'directories' => max(0, ($directoryCounts[$organization->id] ?? 0) - ($this->findRootSecretDirectory($organization->id) !== null ? 1 : 0)),
                'secrets' => $secretCounts[$organization->id] ?? 0,
                'members' => $memberCounts[$organization->id] ?? 0,
            ];
        }

        return $cards;
    }

    /**
     * @return array{directories: array<int, array{directory: Directory, path: string}>, secrets: array<int, array{secret: Secret, directory: Directory, path: string}>}
     */
    private function searchOrganizationSubtree(Organization $organization, ?Directory $currentDir, string $search): array
    {
        $search = mb_strtolower($search, 'UTF-8');
        $allDirectories = Directory::findByOrgId($organization->id);
        $directoryMap = [];
        foreach ($allDirectories as $directory) {
            $directoryMap[$directory->id] = $directory;
        }

        $basePath = $currentDir?->path;
        $searchDirectories = [];
        $searchSecrets = [];

        foreach ($allDirectories as $directory) {
            if ($basePath !== null && $directory->path !== $basePath && !str_starts_with($directory->path, $basePath . '/')) {
                continue;
            }
            if ($this->isHiddenDirectory($directory)) {
                continue;
            }
            if (mb_stripos($directory->name, $search, 0, 'UTF-8') !== false) {
                $searchDirectories[] = [
                    'directory' => $directory,
                    'path' => $this->humanDirectoryPath($directory, $directoryMap),
                ];
            }
        }

        $secretRows = Database::getInstance()->fetchAll(
            'SELECT s.* FROM secrets s JOIN directories d ON d.id = s.directory_id WHERE s.organization_id = ? AND s.deleted_at IS NULL AND d.deleted_at IS NULL ORDER BY s.name',
            [(int) $organization->id],
        );

        foreach ($secretRows as $row) {
            $secret = Secret::fromRow($row);
            $directory = $directoryMap[$secret->directoryId] ?? null;
            if ($directory === null) {
                continue;
            }
            $isRootSecret = $this->isHiddenDirectory($directory);
            if ($basePath !== null && $directory->path !== $basePath && !str_starts_with($directory->path, $basePath . '/')) {
                continue;
            }
            if (mb_stripos($secret->name, $search, 0, 'UTF-8') !== false) {
                $searchSecrets[] = [
                    'secret' => $secret,
                    'directory' => $directory,
                    'path' => $isRootSecret ? __('ui.organization.root_level') : $this->humanDirectoryPath($directory, $directoryMap),
                ];
            }
        }

        return [
            'directories' => $searchDirectories,
            'secrets' => $searchSecrets,
        ];
    }

    /**
     * @param array<string, Directory> $directoryMap
     */
    private function humanDirectoryPath(Directory $directory, array $directoryMap): string
    {
        $segments = [$directory->name];
        $parentId = $directory->parentId;

        while ($parentId !== null && isset($directoryMap[$parentId])) {
            $parent = $directoryMap[$parentId];
            if ($this->isHiddenDirectory($parent)) {
                break;
            }
            array_unshift($segments, $parent->name);
            $parentId = $parent->parentId;
        }

        return implode(' / ', $segments);
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
            throw new \InvalidArgumentException(__('ui.backend.web.permission_target_required'));
        }

        if ($resourceRef === '*') {
            return [$resourceType, null];
        }

        if ($resourceType === 'organization') {
            if ($resourceRef !== 'self') {
                throw new \InvalidArgumentException(__('ui.backend.web.invalid_organization_target'));
            }

            return ['organization', $org->id];
        }

        if ($resourceType === 'directory') {
            $directory = Directory::findByUuid($resourceRef);
            if ($directory === null || $directory->organizationId !== $org->id) {
                throw new \RuntimeException(__('ui.backend.directory.not_found'));
            }

            return ['directory', $directory->id];
        }

        if ($resourceType === 'secret') {
            $secret = Secret::findByUuid($resourceRef);
            if ($secret === null || $secret->organizationId !== $org->id) {
                throw new \RuntimeException(__('ui.backend.secret.not_found'));
            }

            return ['secret', $secret->id];
        }

        throw new \InvalidArgumentException(__('ui.backend.web.invalid_permission_target'));
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

            throw new \InvalidArgumentException(__('ui.backend.web.credentials_json_empty'));
        }

        $decoded = \json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException(__('ui.backend.web.credentials_json_object'));
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function parseTemplateOverridesRequestInput(mixed $input): array
    {
        if ($input === null) {
            return [];
        }

        if (\is_array($input)) {
            return $input;
        }

        return $this->parseJsonObjectInput((string) $input, true);
    }

    /** @return array{directories: int, secrets: int} */
    private function buildCurrentDirectoryStats(Directory $directory): array
    {
        $descendants = array_values(array_filter(
            Directory::findDescendants($directory->path),
            fn(Directory $item): bool => !$this->isHiddenDirectory($item)
        ));

        $directories = count($descendants);
        $secrets = count(Secret::findByDirId($directory->id));

        foreach ($descendants as $descendant) {
            $secrets += count(Secret::findByDirId($descendant->id));
        }

        return [
            'directories' => $directories,
            'secrets' => $secrets,
        ];
    }

    /** @param Directory[] $directories
     *  @return Directory[]
     */
    private function filterVisibleDirectories(array $directories): array
    {
        return array_values(array_filter(
            $directories,
            fn(Directory $directory): bool => !$this->isHiddenDirectory($directory)
        ));
    }

    private function isHiddenDirectory(Directory $directory): bool
    {
        return $directory->name === self::ROOT_SECRET_DIRECTORY_NAME;
    }

    private function findRootSecretDirectory(string $orgId): ?Directory
    {
        foreach (Directory::findByOrgId($orgId) as $directory) {
            if ($this->isHiddenDirectory($directory)) {
                return $directory;
            }
        }

        return null;
    }

    private function getOrCreateRootSecretDirectory(Organization $organization, User $user): Directory
    {
        $directory = $this->findRootSecretDirectory($organization->id);
        if ($directory !== null) {
            return $directory;
        }

        return $this->directoryService->create($organization->id, null, self::ROOT_SECRET_DIRECTORY_NAME, $user->id);
    }

    private function isRootSecretDirectoryUuid(Organization $organization, string $dirUuid): bool
    {
        $directory = $this->findRootSecretDirectory($organization->id);
        return $directory !== null && $directory->uuid === $dirUuid;
    }

    private function storeCroppedAvatar(string $avatarData, string $subdirectory, string $invalidMessage): ?string
    {
        if ($avatarData === '') {
            return null;
        }

        if (!preg_match('#^data:(image/(png|webp));base64,(.+)$#', $avatarData, $matches)) {
            throw new \InvalidArgumentException($invalidMessage);
        }

        $mimeType = (string) $matches[1];
        $encoded = (string) $matches[3];
        $binary = base64_decode(str_replace(' ', '+', $encoded), true);

        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException($invalidMessage);
        }

        $size = @getimagesizefromstring($binary);
        if (!is_array($size) || ($size[0] ?? 0) !== 256 || ($size[1] ?? 0) !== 256) {
            throw new \InvalidArgumentException(__('ui.invite.organization_avatar_size_invalid'));
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: '';
        if (!in_array($detectedMime, ['image/png', 'image/webp'], true) || $detectedMime !== $mimeType) {
            throw new \InvalidArgumentException($invalidMessage);
        }

        $extension = $mimeType === 'image/webp' ? 'webp' : 'png';
        $directory = public_path('uploads/' . trim($subdirectory, '/'));
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('ui.invite.organization_avatar_store_failed'));
        }

        $filename = generate_uuid() . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
            throw new \RuntimeException(__('ui.invite.organization_avatar_store_failed'));
        }

        return '/uploads/' . trim($subdirectory, '/') . '/' . $filename;
    }

    private function deleteUploadedFile(?string $path): void
    {
        $path = \is_string($path) ? \trim($path) : '';
        if ($path === '' || !str_starts_with($path, '/uploads/')) {
            return;
        }

        $absolutePath = public_path(ltrim($path, '/'));
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
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
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }

        return $org;
    }

    private function findMemberUserOrFail(Request $request, string $orgId): User
    {
        $userUuid = (string) $request->routeParam('userUuid');
        $user = User::findByUuid($userUuid);
        if ($user === null) {
            throw new \RuntimeException(__('ui.backend.common.user_not_found'));
        }

        if (OrganizationMember::findByOrgAndUser($orgId, $user->id) === null) {
            throw new \RuntimeException(__('ui.backend.common.user_not_member_org'));
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

    private function organizationUrl(string $orgUuid, ?string $dirUuid = null, ?string $error = null): string
    {
        $url = '/organizations/' . \urlencode($orgUuid);
        $params = [];

        if ($dirUuid !== null && $dirUuid !== '') {
            $params['dir'] = $dirUuid;
        }
        if ($error !== null && $error !== '') {
            $params['error'] = $error;
        }
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
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
