<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\Database;
use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\ApprovalRequest;
use Passway\Models\ApiKey;
use Passway\Models\AuditLog;
use Passway\Models\Directory;
use Passway\Models\Group;
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
use Passway\Services\ApprovalService;
use Passway\Services\HashingService;
use Passway\Services\OrganizationIntegrationService;
use Passway\Services\RotationService;
use Passway\Services\RotationRegistryService;
use Passway\Services\DirectoryService;
use Passway\Services\GroupService;
use Passway\Services\InviteService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Services\SecretService;
use Passway\Services\SessionService;
use Passway\Services\SetupService;
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
        private readonly PermissionService $permissionService,
        private readonly RotationService $rotationService,
        private readonly TemplateService $templateService,
        private readonly InviteService $inviteService,
        private readonly AuditService $auditService,
        private readonly TotpService $totpService,
        private readonly HashingService $hashingService,
        private readonly SetupService $setupService,
        private readonly SessionService $sessionService,
        private readonly ApiKeyService $apiKeyService,
        private readonly GroupService $groupService,
        private readonly RotationRegistryService $rotationRegistryService,
        private readonly OrganizationIntegrationService $organizationIntegrationService,
        private readonly ApprovalService $approvalService,
    ) {}

    public function home(Request $request): Response
    {
        if (!AuthContext::isAuthenticated()) {
            return Response::redirect('/auth/login');
        }

        $user = AuthContext::requireUser();
        return $this->renderHome($request, $user);
    }

    public function homeOrganizationsPartial(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $search = \trim((string) ($request->query('q') ?? ''));
        $organizations = $this->filterHomeOrganizations($this->organizationService->getForUser($user->id), $search);

        return $this->html($this->view->render('web/partials/home_organization_results', [
            'organizationCards' => $this->buildHomeOrganizationCards($organizations),
            'search' => $search,
        ], layout: null));
    }

    public function createOrganization(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if (!is_solo_mode()) {
            return Response::redirect('/?error=' . \urlencode(__('ui.home.use_create_org_invite')));
        }

        if (!$this->isSetupAdministrator($user)) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $name = \trim((string) ($request->input('name') ?? ''));

        try {
            $org = $this->organizationService->create($name, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect('/?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid));
    }

    public function createOrganizationInvite(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if (is_solo_mode()) {
            return Response::redirect('/?error=' . \urlencode(__('ui.backend.invite.create_org_not_in_solo')));
        }

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
        $defaultReadAccess = \trim((string) ($request->input('default_read_access') ?? 'inherit'));
        $defaultWriteAccess = \trim((string) ($request->input('default_write_access') ?? 'inherit'));

        try {
            $dir = $this->directoryService->create(
                $org->id,
                $parentUuid,
                $name,
                $user->id,
                $defaultReadAccess,
                $defaultWriteAccess,
            );
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
        $defaultReadAccess = \trim((string) ($request->input('default_read_access') ?? 'inherit'));
        $defaultWriteAccess = \trim((string) ($request->input('default_write_access') ?? 'inherit'));
        $requiresApproval = $this->parseBooleanRequestInput($request->input('requires_approval'));

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
                    $defaultReadAccess,
                    $defaultWriteAccess,
                    $requiresApproval,
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
                    $defaultReadAccess,
                    $defaultWriteAccess,
                    $requiresApproval,
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
                    $defaultReadAccess,
                    $defaultWriteAccess,
                    $requiresApproval,
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

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'reader')) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $search = \trim((string) ($request->query('q') ?? ''));
        $canManageOrganization = $this->organizationService->hasPermission($org->id, $user->id, 'admin');
        $canViewAudit = $canManageOrganization;
        $canEditContent = $this->organizationService->hasPermission($org->id, $user->id, 'editor');
        try {
            [
                'currentDir' => $currentDir,
                'directories' => $directories,
                'secrets' => $secrets,
                'searchDirectories' => $searchDirectories,
                'searchSecrets' => $searchSecrets,
                'rootSecretDirectory' => $rootSecretDirectory,
            ] = $this->buildOrganizationBrowseData($org, $user, $search, $canEditContent, $request);
        } catch (AuthException | \RuntimeException $e) {
            return Response::redirect($this->organizationUrl($org->uuid, error: $e->getMessage()));
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
        $organizationMembers = $this->serializeOrganizationMembers($org->id);
        $organizationGroups = $this->serializeOrganizationGroups($org->id);
        $canWriteCurrentDirectory = $currentDir !== null
            ? $this->permissionService->can('write', $user->id, 'directory', $currentDir->id, $org->id)
            : false;

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
            'organizationMembers' => $organizationMembers,
            'organizationGroups' => $organizationGroups,
            'organizationApiKeys' => $currentDir !== null && $currentDir->ownerUserId === $user->id
                ? $this->serializeOrganizationApiKeys($org->id)
                : [],
            'isSoloMode' => is_solo_mode(),
            'canManageOrganization' => $canManageOrganization,
            'canViewAudit' => $canViewAudit,
            'canEditContent' => $canEditContent,
            'canWriteCurrentDirectory' => $canWriteCurrentDirectory,
            'canManageCurrentDirectoryAcl' => $currentDir !== null && $currentDir->ownerUserId === $user->id,
            'queryError' => $request->query('error'),
            'search' => $search,
        ]));
    }

    public function organizationSearchPartial(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'reader')) {
            return Response::forbidden(__('ui.messages.access_denied'));
        }

        $search = \trim((string) ($request->query('q') ?? ''));
        $canEditContent = $this->organizationService->hasPermission($org->id, $user->id, 'editor');

        try {
            $browseData = $this->buildOrganizationBrowseData($org, $user, $search, $canEditContent, $request);
        } catch (AuthException | \RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return $this->html($this->view->render('web/partials/organization_search_results', [
            'organization' => $org,
            'search' => $search,
            'searchDirectories' => $browseData['searchDirectories'],
            'searchSecrets' => $browseData['searchSecrets'],
        ], layout: null));
    }

    public function showOrganizationManage(Request $request): Response
    {
        $org = $this->findOrgOrFail($request);

        return Response::redirect($this->settingsSectionUrl($org->uuid, 'settings'));
    }

    public function showOrganizationSettings(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'reader')) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        return $this->html($this->renderOrganizationSettingsView($request, 'web/organization_settings', [
            'title' => __('ui.titles.manage_organization'),
            'user' => $user,
            'organization' => $org,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'canManageSettings' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
            'canDeleteOrganization' => $this->organizationService->hasPermission($org->id, $user->id, 'owner'),
            'organizationDeletionStats' => $this->organizationService->deletionStats($org->id),
            'activeSettingsSection' => 'settings',
        ]));
    }

    public function showOrganizationMembers(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.organization.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'reader')) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $members = $this->organizationService->listMembers($org->id);
        $memberUsers = User::findByIds(array_map(static fn(OrganizationMember $member): string => $member->userId, $members));
        $memberRows = [];
        foreach ($members as $member) {
            $memberRows[] = [
                'member' => $member,
                'user' => $memberUsers[$member->userId] ?? null,
            ];
        }

        return $this->html($this->renderOrganizationSettingsView($request, 'web/organization_members', [
            'title' => __('ui.titles.manage_organization'),
            'user' => $user,
            'organization' => $org,
            'memberRows' => $memberRows,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'canManageSettings' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
            'activeSettingsSection' => 'members',
        ]));
    }

    public function showOrganizationInvites(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.organization.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'reader')) {
            return Response::redirect('/?error=' . \urlencode(__('ui.messages.access_denied')));
        }

        $invites = $this->inviteService->listActive($org->id);

        return $this->html($this->renderOrganizationSettingsView($request, 'web/organization_invites', [
            'title' => __('ui.titles.manage_organization'),
            'user' => $user,
            'organization' => $org,
            'invites' => $invites,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'canManageSettings' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
            'activeSettingsSection' => 'invites',
        ]));
    }

    public function updateOrganizationSettings(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'admin')) {
            if ($request->isAjax()) {
                return $this->renderOrganizationSettingsResponse($request, $org, $user, error: __('ui.messages.access_denied'));
            }

            return Response::redirect($this->settingsSectionUrl($org->uuid, 'settings', error: __('ui.messages.access_denied')));
        }

        $name = \trim((string) ($request->input('name') ?? $org->name));
        $description = $request->input('description');
        $description = \is_string($description) ? \trim($description) : '';
        $avatarData = \trim((string) ($request->input('avatar_data') ?? ''));
        $removeAvatar = $this->parseBooleanRequestInput($request->input('remove_avatar')) && $avatarData === '';
        $avatarPath = null;
        $oldAvatarPath = $org->avatarPath;

        try {
            $org = $this->organizationService->rename($org->id, $name, $user->id);
            $avatarPath = $this->storeCroppedAvatar($avatarData, 'organizations/avatars', __('ui.organization_manage.avatar_invalid'));

            $update = [
                'description' => $description !== '' ? $description : null,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ];

            if ($avatarPath !== null) {
                $update['avatar_path'] = $avatarPath;
            } elseif ($removeAvatar) {
                $update['avatar_path'] = null;
            }

            $org->update($update);

            if ($avatarPath !== null || $removeAvatar) {
                $this->deleteUploadedFile($oldAvatarPath);
            }
        } catch (\Throwable $e) {
            if ($avatarPath !== null) {
                $this->deleteUploadedFile($avatarPath);
            }

            if ($request->isAjax()) {
                return $this->renderOrganizationSettingsResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect($this->settingsSectionUrl($org->uuid, 'settings', error: $e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderOrganizationSettingsResponse(
                $request,
                Organization::findById($org->id) ?? $org,
                $user,
                success: __('ui.organization_manage.settings_saved'),
            );
        }

        return Response::redirect($this->settingsSectionUrl($org->uuid, 'settings', success: __('ui.organization_manage.settings_saved')));
    }

    public function deleteOrganization(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $avatarPath = $org->avatarPath;

        try {
            $this->organizationService->delete($org->id, $user->id);
            $this->deleteUploadedFile($avatarPath);
        } catch (\Throwable $e) {
            return Response::redirect($this->settingsSectionUrl($org->uuid, 'settings', error: $e->getMessage()));
        }

        return Response::redirect('/?success=' . \urlencode(__('ui.organization_manage.organization_deleted')));
    }

    public function showOrganizationGroups(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.group.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $groupCards = $this->buildOrganizationGroupCards($org, $user);
        } catch (AuthException $e) {
            return Response::redirect('/?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->renderOrganizationSettingsView($request, 'web/groups', [
            'title' => __('ui.titles.organization_groups'),
            'user' => $user,
            'organization' => $org,
            'groups' => $groupCards,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'canManageGroups' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
            'activeSettingsSection' => 'groups',
        ]));
    }

    public function showOrganizationGroup(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.group.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');

        try {
            $group = $this->groupService->findInOrg($grpUuid, $org->id, $user->id);
            $members = $this->groupService->listMembers($grpUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::redirect('/?error=' . \urlencode($e->getMessage()));
        } catch (\RuntimeException $e) {
            return Response::redirect($this->groupsUrl($org->uuid, error: $e->getMessage()));
        }

        [$memberRows, $candidateRows] = $this->buildGroupMemberManagementRows($org, $members);

        return $this->html($this->view->render('web/group_show', [
            'title' => __('ui.titles.organization_group_details'),
            'user' => $user,
            'organization' => $org,
            'group' => $group,
            'members' => $memberRows,
            'candidates' => $candidateRows,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'canManageGroups' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
        ]));
    }

    public function createGroup(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.group.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $name = \trim((string) ($request->input('name') ?? ''));
        $description = $request->input('description');
        $description = \is_string($description) ? \trim($description) : null;

        try {
            $this->groupService->create($org->id, $name, $description !== '' ? $description : null, $user->id);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                return $this->renderOrganizationGroupsResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect($this->groupsUrl($org->uuid, error: $e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderOrganizationGroupsResponse($request, $org, $user, success: __('ui.groups.group_created'));
        }

        return Response::redirect($this->groupsUrl($org->uuid, success: __('ui.groups.group_created')));
    }

    public function deleteGroup(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.group.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');

        try {
            $this->groupService->delete($grpUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                return $this->renderOrganizationGroupsResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect($this->groupsUrl($org->uuid, error: $e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderOrganizationGroupsResponse($request, $org, $user, success: __('ui.groups.group_deleted'));
        }

        return Response::redirect($this->groupsUrl($org->uuid, success: __('ui.groups.group_deleted')));
    }

    public function addGroupMember(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.group.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');
        $userUuid = \trim((string) ($request->input('user_uuid') ?? ''));

        $targetUser = User::findByUuid($userUuid);
        if ($targetUser === null) {
            return Response::redirect($this->groupUrl($org->uuid, $grpUuid, __('ui.backend.common.user_not_found')));
        }

        try {
            $this->groupService->addMember($grpUuid, $targetUser->id, $user->id, $org->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->groupUrl($org->uuid, $grpUuid, $e->getMessage()));
        }

        return Response::redirect($this->groupUrl($org->uuid, $grpUuid, success: __('ui.groups.member_added')));
    }

    public function removeGroupMember(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.group.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');

        try {
            $targetUser = $this->findMemberUserOrFail($request, $org->id);
            $this->groupService->removeMember($grpUuid, $targetUser->id, $user->id, $org->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->groupUrl($org->uuid, $grpUuid, $e->getMessage()));
        }

        return Response::redirect($this->groupUrl($org->uuid, $grpUuid, success: __('ui.groups.member_removed')));
    }

    public function updateMemberRole(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.organization.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $targetUser = $this->findMemberUserOrFail($request, $org->id);
        $role = \trim((string) ($request->input('role') ?? ''));

        try {
            $this->organizationService->updateMemberRole($org->id, $targetUser->id, $role, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->settingsSectionUrl($org->uuid, 'members', error: $e->getMessage()));
        }

        return Response::redirect($this->settingsSectionUrl($org->uuid, 'members'));
    }

    public function removeMember(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.organization.team_mode_required')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $targetUser = $this->findMemberUserOrFail($request, $org->id);

        try {
            $this->organizationService->removeMember($org->id, $targetUser->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->settingsSectionUrl($org->uuid, 'members', error: $e->getMessage()));
        }

        return Response::redirect($this->settingsSectionUrl($org->uuid, 'members'));
    }

    public function createInvite(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.invite.join_org_not_in_solo')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $role = \trim((string) ($request->input('role') ?? 'reader'));
        $ttlHours = (int) ($request->input('ttl') ?? 1);
        if ($ttlHours < 1 || $ttlHours > 168) {
            $ttlHours = 1;
        }
        $ttl = $ttlHours * 3600;

        try {
            $this->inviteService->createJoinOrgInvite($org->id, $role, $user->id, $ttl);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                return $this->renderOrganizationInvitesResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect($this->settingsSectionUrl($org->uuid, 'invites', error: $e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderOrganizationInvitesResponse($request, $org, $user);
        }

        return Response::redirect($this->settingsSectionUrl($org->uuid, 'invites'));
    }

    public function revokeInvite(Request $request): Response
    {
        if (is_solo_mode()) {
            return Response::redirect($this->settingsSectionUrl((string) $request->routeParam('uuid'), 'settings', error: __('ui.backend.invite.join_org_not_in_solo')));
        }

        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $inviteUuid = (string) $request->routeParam('invUuid');

        try {
            $this->inviteService->revoke($inviteUuid, $user->id);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                return $this->renderOrganizationInvitesResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect($this->settingsSectionUrl($org->uuid, 'invites', error: $e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderOrganizationInvitesResponse($request, $org, $user);
        }

        return Response::redirect($this->settingsSectionUrl($org->uuid, 'invites'));
    }

    public function showAudit(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $filters = $this->buildAuditFilters($request, $org);
        $filterState = $this->buildAuditFilterState($request);

        try {
            $result = $this->auditService->paginateForOrganization($org->id, $user->id, $filters);
        } catch (\Throwable $e) {
            return Response::redirect($this->organizationUrl($org->uuid, error: $e->getMessage()));
        }

        return $this->html($this->view->render('web/audit', [
            'title' => __('ui.titles.audit_log'),
            'user' => $user,
            'organization' => $org,
            'entries' => $this->buildAuditViewEntries($org, $result['entries']),
            'meta' => [
                'total' => $result['total'],
                'limit' => $result['limit'],
                'offset' => $result['offset'],
                'has_more' => $result['has_more'],
            ],
            'filters' => $filterState,
            'filterOptions' => $this->buildAuditFilterOptions($org, $filterState),
        ]));
    }

    public function showInstanceAudit(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $filters = $this->buildInstanceAuditFilters($request);
        $filterState = $this->buildInstanceAuditFilterState($request);

        try {
            $result = $this->auditService->paginateForInstanceAdmin($user->id, $filters);
        } catch (AuthException $e) {
            return Response::redirect('/?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->view->render('web/instance_audit', [
            'title' => __('ui.titles.instance_audit'),
            'user' => $user,
            'entries' => $this->buildInstanceAuditViewEntries($result['entries']),
            'meta' => [
                'total' => $result['total'],
                'limit' => $result['limit'],
                'offset' => $result['offset'],
                'has_more' => $result['has_more'],
            ],
            'filters' => $filterState,
            'filterOptions' => [
                'actions' => [
                    ['value' => 'org.create', 'label' => $this->translateAuditAction('org.create')],
                    ['value' => 'org.delete', 'label' => $this->translateAuditAction('org.delete')],
                ],
                'actorKinds' => [
                    ['value' => '', 'label' => __('ui.audit.any')],
                    ['value' => 'user', 'label' => __('ui.audit.actor_kinds.user')],
                    ['value' => 'system', 'label' => __('ui.audit.actor_kinds.system')],
                ],
            ],
        ]));
    }

    /** @return array<string, mixed> */
    private function buildInstanceAuditFilters(Request $request): array
    {
        $filters = [
            'action' => $request->query('action'),
            'success' => $request->query('success'),
            'actor_kind' => $request->query('actor_kind'),
            'ip_address' => $request->query('ip_address'),
            'search' => $request->query('search'),
            'limit' => $request->query('limit', 50),
            'offset' => $request->query('offset', 0),
        ];

        $actorUserEmail = \trim((string) ($request->query('actor_user_email') ?? ''));
        if ($actorUserEmail !== '') {
            $actorUser = User::findByEmail($actorUserEmail);
            if ($actorUser !== null) {
                $filters['user_id'] = $actorUser->id;
            }
        }

        $fromDate = \trim((string) ($request->query('from_date') ?? ''));
        if ($fromDate !== '') {
            $filters['from'] = $fromDate . ' 00:00:00';
        }

        $toDate = \trim((string) ($request->query('to_date') ?? ''));
        if ($toDate !== '') {
            $filters['to'] = $toDate . ' 23:59:59';
        }

        return $filters;
    }

    /** @return array<string, string> */
    private function buildInstanceAuditFilterState(Request $request): array
    {
        return [
            'action' => (string) ($request->query('action') ?? ''),
            'actor_kind' => (string) ($request->query('actor_kind') ?? ''),
            'actor_user_email' => (string) ($request->query('actor_user_email') ?? ''),
            'success' => (string) ($request->query('success') ?? ''),
            'from_date' => (string) ($request->query('from_date') ?? ''),
            'to_date' => (string) ($request->query('to_date') ?? ''),
            'ip_address' => (string) ($request->query('ip_address') ?? ''),
            'search' => (string) ($request->query('search') ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function buildAuditFilters(Request $request, Organization $organization): array
    {
        $filters = [
            'action' => $request->query('action'),
            'success' => $request->query('success'),
            'actor_kind' => $request->query('actor_kind'),
            'ip_address' => $request->query('ip_address'),
            'limit' => $request->query('limit', 50),
            'offset' => $request->query('offset', 0),
        ];

        $actorUserEmail = \trim((string) ($request->query('actor_user_email') ?? ''));
        if ($actorUserEmail !== '') {
            $actorUser = $this->findOrganizationUserByEmail($organization->id, $actorUserEmail);
            if ($actorUser !== null) {
                $filters['user_id'] = $actorUser->id;
            }
        }

        $actorApiKeyUuid = \trim((string) ($request->query('actor_api_key_uuid') ?? ''));
        if ($actorApiKeyUuid !== '') {
            $apiKey = ApiKey::findByUuid($actorApiKeyUuid);
            if ($apiKey !== null && $apiKey->organizationId === $organization->id) {
                $filters['api_key_id'] = $apiKey->id;
            }
        }

        $targetUserEmail = \trim((string) ($request->query('target_user_email') ?? ''));
        if ($targetUserEmail !== '') {
            $targetUser = $this->findOrganizationUserByEmail($organization->id, $targetUserEmail);
            if ($targetUser !== null) {
                $filters['target_user_id'] = $targetUser->id;
            }
        }

        foreach (['group_uuid', 'secret_uuid', 'api_key_uuid', 'integration_uuid', 'rotation_service_uuid', 'role', 'invite_type'] as $filterKey) {
            $value = \trim((string) ($request->query($filterKey) ?? ''));
            if ($value !== '') {
                $filters[$filterKey] = $value;
            }
        }

        $fromDate = \trim((string) ($request->query('from_date') ?? ''));
        if ($fromDate !== '') {
            $filters['from'] = $fromDate . ' 00:00:00';
        }

        $toDate = \trim((string) ($request->query('to_date') ?? ''));
        if ($toDate !== '') {
            $filters['to'] = $toDate . ' 23:59:59';
        }

        return $filters;
    }

    /** @return array<string, string> */
    private function buildAuditFilterState(Request $request): array
    {
        return [
            'action' => (string) ($request->query('action') ?? ''),
            'actor_kind' => (string) ($request->query('actor_kind') ?? ''),
            'actor_user_email' => (string) ($request->query('actor_user_email') ?? ''),
            'actor_api_key_uuid' => (string) ($request->query('actor_api_key_uuid') ?? ''),
            'target_user_email' => (string) ($request->query('target_user_email') ?? ''),
            'group_uuid' => (string) ($request->query('group_uuid') ?? ''),
            'secret_uuid' => (string) ($request->query('secret_uuid') ?? ''),
            'api_key_uuid' => (string) ($request->query('api_key_uuid') ?? ''),
            'integration_uuid' => (string) ($request->query('integration_uuid') ?? ''),
            'rotation_service_uuid' => (string) ($request->query('rotation_service_uuid') ?? ''),
            'role' => (string) ($request->query('role') ?? ''),
            'invite_type' => (string) ($request->query('invite_type') ?? ''),
            'success' => (string) ($request->query('success') ?? ''),
            'from_date' => (string) ($request->query('from_date') ?? ''),
            'to_date' => (string) ($request->query('to_date') ?? ''),
            'ip_address' => (string) ($request->query('ip_address') ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function buildAuditFilterOptions(Organization $organization, array $filterState): array
    {
        $definitions = $this->auditActionDefinitions();
        $groups = [];

        foreach ($definitions as $code => $definition) {
            $groupKey = $definition['group'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'label' => __('ui.audit.filter_groups.' . $groupKey),
                    'actions' => [],
                ];
            }

            $groups[$groupKey]['actions'][] = [
                'value' => $code,
                'label' => $this->translateAuditAction($code),
            ];
        }

        return [
            'actionGroups' => \array_values($groups),
            'actionMeta' => \array_map(
                static fn(array $definition): array => ['fields' => $definition['fields']],
                $definitions,
            ),
            'members' => $this->serializeOrganizationMembers($organization->id),
            'groups' => $this->serializeOrganizationGroups($organization->id),
            'apiKeys' => $this->serializeOrganizationApiKeys($organization->id),
            'integrations' => $this->serializeOrganizationIntegrations($organization->id),
            'rotationServices' => $this->serializeAuditRotationServices(),
            'roles' => $this->auditRoleOptions(),
            'inviteTypes' => $this->auditInviteTypeOptions(),
            'actorKinds' => $this->auditActorKindOptions(),
            'secretSearchUrl' => '/api/v1/organizations/' . \urlencode($organization->uuid) . '/audit/secrets',
            'selectedSecret' => $this->resolveAuditSelectedSecret($organization->id, $filterState['secret_uuid'] ?? ''),
        ];
    }

    /** @return array<string, array{group:string, fields:string[]}> */
    private function auditActionDefinitions(): array
    {
        return [
            'org.create' => ['group' => 'users_access', 'fields' => []],
            'org.member_add' => ['group' => 'users_access', 'fields' => ['target_user_email', 'role']],
            'org.member_remove' => ['group' => 'users_access', 'fields' => ['target_user_email']],
            'org.member_role_update' => ['group' => 'users_access', 'fields' => ['target_user_email', 'role']],
            'org.transfer_ownership' => ['group' => 'users_access', 'fields' => ['target_user_email']],
            'permission.grant' => ['group' => 'users_access', 'fields' => ['role']],
            'permission.revoke' => ['group' => 'users_access', 'fields' => []],
            'group.create' => ['group' => 'groups', 'fields' => ['group_uuid']],
            'group.delete' => ['group' => 'groups', 'fields' => ['group_uuid']],
            'group.member_add' => ['group' => 'groups', 'fields' => ['target_user_email', 'group_uuid']],
            'group.member_remove' => ['group' => 'groups', 'fields' => ['target_user_email', 'group_uuid']],
            'secret.create' => ['group' => 'secrets', 'fields' => ['secret_uuid']],
            'secret.read' => ['group' => 'secrets', 'fields' => ['secret_uuid']],
            'secret.update' => ['group' => 'secrets', 'fields' => ['secret_uuid']],
            'secret.delete' => ['group' => 'secrets', 'fields' => ['secret_uuid']],
            'secret.transfer_ownership' => ['group' => 'secrets', 'fields' => ['secret_uuid']],
            'rotation.secret_rotated' => ['group' => 'secrets', 'fields' => ['secret_uuid']],
            'rotation.secret_rotate_failed' => ['group' => 'secrets', 'fields' => ['secret_uuid']],
            'invite.create' => ['group' => 'invites', 'fields' => ['invite_type', 'role']],
            'invite.revoke' => ['group' => 'invites', 'fields' => ['invite_type']],
            'invite.accept' => ['group' => 'invites', 'fields' => ['invite_type']],
            'apikey.create' => ['group' => 'api_keys', 'fields' => ['api_key_uuid']],
            'apikey.role_update' => ['group' => 'api_keys', 'fields' => ['api_key_uuid', 'role']],
            'apikey.revoke' => ['group' => 'api_keys', 'fields' => ['api_key_uuid']],
            'auth.api_key_success' => ['group' => 'api_keys', 'fields' => ['actor_api_key_uuid', 'api_key_uuid']],
            'auth.api_key_fail' => ['group' => 'api_keys', 'fields' => ['ip_address']],
            'approval.request_create' => ['group' => 'approvals', 'fields' => ['secret_uuid']],
            'approval.request_approve' => ['group' => 'approvals', 'fields' => ['secret_uuid']],
            'approval.request_reject' => ['group' => 'approvals', 'fields' => ['secret_uuid']],
            'approval.request_revoke' => ['group' => 'approvals', 'fields' => ['secret_uuid']],
            'approval.token_use' => ['group' => 'approvals', 'fields' => ['secret_uuid']],
            'rotation.integration_create' => ['group' => 'integrations', 'fields' => ['integration_uuid']],
            'rotation.integration_update' => ['group' => 'integrations', 'fields' => ['integration_uuid']],
            'rotation.integration_delete' => ['group' => 'integrations', 'fields' => ['integration_uuid']],
            'rotation.service_create' => ['group' => 'integrations', 'fields' => ['rotation_service_uuid']],
            'rotation.service_update' => ['group' => 'integrations', 'fields' => ['rotation_service_uuid']],
            'rotation.service_delete' => ['group' => 'integrations', 'fields' => ['rotation_service_uuid']],
            'rotation.service_verify' => ['group' => 'integrations', 'fields' => ['rotation_service_uuid']],
            'auth.session_fail' => ['group' => 'system_security', 'fields' => ['ip_address']],
            'auth.unauthorized' => ['group' => 'system_security', 'fields' => ['ip_address']],
            'auth.rate_limit_denied' => ['group' => 'system_security', 'fields' => ['ip_address']],
            'api.rate_limit_denied' => ['group' => 'system_security', 'fields' => ['ip_address']],
        ];
    }

    /** @return array<int, array{value:string,label:string}> */
    private function auditRoleOptions(): array
    {
        return \array_map(fn(string $role): array => [
            'value' => $role,
            'label' => $this->translateAuditRole($role),
        ], ['owner', 'admin', 'editor', 'reader']);
    }

    /** @return array<int, array{value:string,label:string}> */
    private function auditInviteTypeOptions(): array
    {
        return \array_map(fn(string $type): array => [
            'value' => $type,
            'label' => $this->translateAuditType($type),
        ], [InviteLink::TYPE_JOIN_ORG, InviteLink::TYPE_CREATE_ORG]);
    }

    /** @return array<int, array{value:string,label:string}> */
    private function auditActorKindOptions(): array
    {
        return [
            ['value' => '', 'label' => __('ui.audit.any')],
            ['value' => 'user', 'label' => __('ui.audit.actor_kinds.user')],
            ['value' => 'api_key', 'label' => __('ui.audit.actor_kinds.api_key')],
            ['value' => 'system', 'label' => __('ui.audit.actor_kinds.system')],
        ];
    }

    /**
     * @param AuditLog[] $entries
     * @return array<int, array<string, mixed>>
     */
    private function buildAuditViewEntries(Organization $organization, array $entries): array
    {
        $lookup = [
            'users' => [],
            'api_keys' => [],
            'organizations' => [],
            'groups' => [],
            'directories' => [],
            'secrets' => [],
            'integrations' => [],
            'invites' => [],
            'approval_requests' => [],
            'rotation_services' => [],
        ];

        $presented = [];
        foreach ($entries as $entry) {
            $presented[] = $this->presentAuditEntry($organization, $entry, $lookup);
        }

        return $presented;
    }

    /**
     * @param AuditLog[] $entries
     * @return array<int, array<string, mixed>>
     */
    private function buildInstanceAuditViewEntries(array $entries): array
    {
        $lookup = [
            'users' => [],
            'api_keys' => [],
            'organizations' => [],
            'groups' => [],
            'directories' => [],
            'secrets' => [],
            'integrations' => [],
            'invites' => [],
            'approval_requests' => [],
            'rotation_services' => [],
        ];

        $presented = [];
        foreach ($entries as $entry) {
            $actor = $this->resolveAuditActor($entry, $lookup);
            $details = $entry->details();
            $organizationName = $this->scalarToString($details['organization_name'] ?? null)
                ?? $this->scalarToString($entry->resourceUuid)
                ?? __('ui.audit.resource_deleted');

            $presented[] = [
                'title_parts' => $this->auditSentenceParts([
                    ['text' => $this->translateAuditAction($entry->action) . ' '],
                    ['text' => $organizationName, 'accent' => true],
                ]),
                'timestamp_html' => local_datetime($entry->createdAt),
                'status' => $entry->success ? __('ui.app.success') : __('ui.app.failed'),
                'actor_label' => $actor['label'],
                'actor_href' => $actor['href'],
                'details' => $this->buildInstanceAuditDetailLines($entry),
                'ip_address' => $entry->ipAddress,
            ];
        }

        return $presented;
    }

    /** @return string[] */
    private function buildInstanceAuditDetailLines(AuditLog $entry): array
    {
        $details = $entry->details();
        $lines = [];

        if (isset($details['organization_uuid']) && \is_scalar($details['organization_uuid'])) {
            $lines[] = __('ui.audit.detail.organization_uuid', ['uuid' => (string) $details['organization_uuid']]);
        }
        if (isset($details['directories_count']) && \is_scalar($details['directories_count'])) {
            $lines[] = __('ui.audit.detail.directories_count', ['count' => (string) $details['directories_count']]);
        }
        if (isset($details['secrets_count']) && \is_scalar($details['secrets_count'])) {
            $lines[] = __('ui.audit.detail.secrets_count', ['count' => (string) $details['secrets_count']]);
        }
        if (isset($details['api_keys_total']) && \is_scalar($details['api_keys_total'])) {
            $lines[] = __('ui.audit.detail.api_keys_total', ['count' => (string) $details['api_keys_total']]);
        }
        if (isset($details['api_keys_active']) && \is_scalar($details['api_keys_active'])) {
            $lines[] = __('ui.audit.detail.api_keys_active', ['count' => (string) $details['api_keys_active']]);
        }

        return $lines;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array<string, mixed>
     */
    private function presentAuditEntry(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        $actor = $this->resolveAuditActor($entry, $lookup);
        $resource = $this->resolveAuditResource($organization, $entry, $lookup);
        $title = $this->buildAuditTitleParts($entry, $resource, $lookup);

        return [
            'title_parts' => $title,
            'timestamp_html' => local_datetime($entry->createdAt),
            'status' => $entry->success ? __('ui.app.success') : __('ui.app.failed'),
            'actor_label' => $actor['label'],
            'actor_href' => $actor['href'],
            'details' => $this->buildAuditDetailLines($organization, $entry, $lookup),
            'ip_address' => $entry->ipAddress,
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:string, href:?string}
     */
    private function resolveAuditActor(AuditLog $entry, array &$lookup): array
    {
        if ($entry->userId !== null) {
            $user = $this->auditLookupUser($entry->userId, $lookup);
            if ($user !== null) {
                return ['label' => user_label_with_email($user), 'href' => null];
            }

            return ['label' => __('ui.audit.unknown_user'), 'href' => null];
        }

        if ($entry->apiKeyId !== null) {
            $apiKey = $this->auditLookupApiKey($entry->apiKeyId, $lookup);
            if ($apiKey !== null) {
                return [
                    'label' => __('ui.audit.api_key_actor', ['name' => $apiKey->name]),
                    'href' => null,
                ];
            }

            return ['label' => __('ui.audit.api_key_actor_unknown'), 'href' => null];
        }

        return ['label' => __('ui.audit.system_actor'), 'href' => null];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditResource(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        $resourceType = $entry->resourceType;
        if ($resourceType === null) {
            return ['label' => null, 'href' => null];
        }

        return match ($resourceType) {
            'organization' => $this->resolveAuditOrganizationResource($entry, $lookup),
            'user' => $this->resolveAuditUserResource($entry, $lookup),
            'group' => $this->resolveAuditGroupResource($organization, $entry, $lookup),
            'directory' => $this->resolveAuditDirectoryResource($organization, $entry, $lookup),
            'secret' => $this->resolveAuditSecretResource($organization, $entry, $lookup),
            'api_key' => $this->resolveAuditApiKeyResource($organization, $entry, $lookup),
            'integration' => $this->resolveAuditIntegrationResource($organization, $entry, $lookup),
            'invite' => $this->resolveAuditInviteResource($organization, $entry, $lookup),
            'approval_request' => $this->resolveAuditApprovalRequestResource($entry, $lookup),
            'rotation_service' => $this->resolveAuditRotationServiceResource($entry, $lookup),
            default => ['label' => $this->formatAuditResourceFallback($resourceType, $entry), 'href' => null],
        };
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return string[]
     */
    private function buildAuditDetailLines(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        $details = $entry->details();
        $lines = [];

        if ($entry->apiKeyId !== null) {
            $apiKey = $this->auditLookupApiKey($entry->apiKeyId, $lookup);
            if ($apiKey !== null) {
                $lines[] = __('ui.audit.via_api_key', ['name' => $apiKey->name]);
            }
        }

        if (isset($details['role']) && \is_string($details['role']) && $details['role'] !== ''
            && !\in_array($entry->action, ['invite.create', 'org.member_role_update'], true)) {
            $lines[] = __('ui.audit.detail.role', ['role' => $this->translateAuditRole($details['role'])]);
        }

        if (isset($details['type']) && \is_string($details['type']) && $details['type'] !== ''
            && !\in_array($entry->action, ['invite.create', 'invite.revoke', 'invite.accept'], true)) {
            $type = $this->translateAuditType($details['type']);
            if (str_starts_with($entry->action, 'invite.')) {
                $lines[] = __('ui.audit.detail.invite_type', ['type' => $type]);
            } else {
                $lines[] = __('ui.audit.detail.type', ['type' => $type]);
            }
        }

        if (isset($details['request_type']) && \is_string($details['request_type']) && $details['request_type'] !== ''
            && !\in_array($entry->action, ['approval.request_create'], true)) {
            $lines[] = __('ui.audit.detail.request_type', [
                'type' => $this->translateAuditRequestType($details['request_type']),
            ]);
        }

        $targetUserId = $details['target_user_id'] ?? null;
        if (\is_scalar($targetUserId) && (string) $targetUserId !== ''
            && !\in_array($entry->action, ['group.member_add', 'group.member_remove'], true)) {
            $user = $this->auditLookupUser((string) $targetUserId, $lookup);
            $lines[] = __('ui.audit.detail.target_user', [
                'user' => $user !== null ? user_label_with_email($user) : __('ui.audit.unknown_user'),
            ]);
        }

        $newOwnerId = $details['new_owner_id'] ?? null;
        if (\is_scalar($newOwnerId) && (string) $newOwnerId !== ''
            && !\in_array($entry->action, ['org.transfer_ownership'], true)) {
            $user = $this->auditLookupUser((string) $newOwnerId, $lookup);
            $lines[] = __('ui.audit.detail.new_owner', [
                'user' => $user !== null ? user_label_with_email($user) : __('ui.audit.unknown_user'),
            ]);
        }

        if (isset($details['permission']) && \is_string($details['permission']) && $details['permission'] !== '') {
            $effect = !empty($details['is_deny'])
                ? __('ui.audit.permission_effect_deny')
                : __('ui.audit.permission_effect_allow');
            $lines[] = __('ui.audit.detail.permission', [
                'permission' => $this->translateAuditPermission($details['permission']),
                'effect' => $effect,
            ]);
        }

        if (isset($details['subject_type']) && \is_string($details['subject_type']) && isset($details['subject_id']) && \is_scalar($details['subject_id'])) {
            $lines[] = __('ui.audit.detail.subject', [
                'subject' => $this->resolveAuditSubjectLabel((string) $details['subject_type'], (string) $details['subject_id'], $lookup),
            ]);
        }

        if (isset($details['path']) && \is_string($details['path']) && $details['path'] !== '') {
            $lines[] = __('ui.audit.detail.path', ['path' => $details['path']]);
        }

        if (isset($details['bucket']) && \is_string($details['bucket']) && $details['bucket'] !== '') {
            $lines[] = __('ui.audit.detail.bucket', ['bucket' => $this->translateAuditBucket($details['bucket'])]);
        }

        if (\array_key_exists('verified', $details)) {
            $lines[] = !empty($details['verified'])
                ? __('ui.audit.detail.verified_yes')
                : __('ui.audit.detail.verified_no');
        }

        if (isset($details['rotation_service_uuid']) && \is_string($details['rotation_service_uuid']) && $details['rotation_service_uuid'] !== '') {
            $rotationService = $this->auditLookupRotationServiceByUuid($details['rotation_service_uuid'], $lookup);
            if ($rotationService !== null) {
                $lines[] = __('ui.audit.detail.rotation_service', ['name' => $rotationService->name]);
            }
        }

        if (isset($details['secret_uuid']) && \is_string($details['secret_uuid']) && $details['secret_uuid'] !== '') {
            $secret = Secret::findByUuid($details['secret_uuid']);
            if ($secret !== null) {
                $lines[] = __('ui.audit.detail.related_secret', ['secret' => $secret->name]);
            }
        }

        return $lines;
    }

    /**
     * @param array{label:?string, href:?string} $resource
     * @param array<string, array<string, object|null>> $lookup
     * @return array<int, array{text:string, href:?string, accent:bool}>
     */
    private function buildAuditTitleParts(AuditLog $entry, array $resource, array &$lookup): array
    {
        $details = $entry->details();

        return match ($entry->action) {
            'org.member_remove' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.org_member_remove_before')],
                $this->auditUserPart($entry->resourceId, $lookup),
                ['text' => __('ui.audit.templates.org_member_remove_after')],
            ]),
            'org.member_add' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.org_member_add_before')],
                $this->auditUserPart($entry->resourceId, $lookup),
                ['text' => __('ui.audit.templates.org_member_add_after')],
            ]),
            'org.member_role_update' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.org_member_role_update_before')],
                $this->auditUserPart($entry->resourceId, $lookup),
                ['text' => __('ui.audit.templates.org_member_role_update_after', [
                    'role' => $this->translateAuditRole((string) ($details['role'] ?? '')),
                ])],
            ]),
            'group.create' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.group_create_before')],
                $this->auditResourcePart($resource),
            ]),
            'group.delete' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.group_delete_before')],
                $this->auditResourcePart($resource),
            ]),
            'group.member_add' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.group_member_add_before')],
                $this->auditUserPart($this->scalarToString($details['target_user_id'] ?? null), $lookup),
                ['text' => __('ui.audit.templates.group_member_add_middle')],
                $this->auditResourcePart($resource),
            ]),
            'group.member_remove' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.group_member_remove_before')],
                $this->auditUserPart($this->scalarToString($details['target_user_id'] ?? null), $lookup),
                ['text' => __('ui.audit.templates.group_member_remove_middle')],
                $this->auditResourcePart($resource),
            ]),
            'secret.read' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.secret_read_before')],
                $this->auditResourcePart($resource),
            ]),
            'secret.create' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.secret_create_before')],
                $this->auditResourcePart($resource),
            ]),
            'secret.update' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.secret_update_before')],
                $this->auditResourcePart($resource),
            ]),
            'secret.delete' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.secret_delete_before')],
                $this->auditResourcePart($resource),
            ]),
            'invite.create' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.invite_create', [
                    'type' => $this->translateAuditType((string) ($details['type'] ?? 'join_org')),
                    'role' => $this->translateAuditRole((string) ($details['role'] ?? 'reader')),
                ])],
            ]),
            'invite.revoke' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.invite_revoke', [
                    'type' => $this->translateAuditType((string) ($details['type'] ?? 'join_org')),
                ])],
            ]),
            'invite.accept' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.invite_accept', [
                    'type' => $this->translateAuditType((string) ($details['type'] ?? 'join_org')),
                ])],
            ]),
            'org.transfer_ownership' => $this->auditSentenceParts([
                ['text' => __('ui.audit.templates.org_transfer_ownership_before')],
                $this->auditUserPart($this->scalarToString($details['new_owner_id'] ?? null), $lookup),
            ]),
            default => $this->auditDefaultTitleParts($entry, $resource),
        };
    }

    /**
     * @param array<int, array{text:string, href:?string, accent:bool}> $parts
     * @return array<int, array{text:string, href:?string, accent:bool}>
     */
    private function auditSentenceParts(array $parts): array
    {
        $normalized = [];

        foreach ($parts as $part) {
            $text = (string) ($part['text'] ?? '');
            if ($text === '') {
                continue;
            }

            $normalized[] = [
                'text' => $text,
                'href' => isset($part['href']) && \is_string($part['href']) && $part['href'] !== '' ? $part['href'] : null,
                'accent' => !empty($part['accent']),
            ];
        }

        return $normalized;
    }

    /**
     * @param array{label:?string, href:?string} $resource
     * @return array{text:string, href:?string, accent:bool}
     */
    private function auditResourcePart(array $resource): array
    {
        return [
            'text' => (string) ($resource['label'] ?? ''),
            'href' => $resource['href'],
            'accent' => true,
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{text:string, href:?string, accent:bool}
     */
    private function auditUserPart(?string $userId, array &$lookup): array
    {
        if ($userId === null || $userId === '') {
            return ['text' => __('ui.audit.unknown_user'), 'href' => null, 'accent' => true];
        }

        $user = $this->auditLookupUser($userId, $lookup);
        return [
            'text' => $user !== null ? user_label_with_email($user) : __('ui.audit.unknown_user'),
            'href' => null,
            'accent' => true,
        ];
    }

    /**
     * @param array{label:?string, href:?string} $resource
     * @return array<int, array{text:string, href:?string, accent:bool}>
     */
    private function auditDefaultTitleParts(AuditLog $entry, array $resource): array
    {
        $parts = [[
            'text' => $this->translateAuditAction($entry->action),
            'href' => null,
            'accent' => false,
        ]];

        if (($resource['label'] ?? null) !== null) {
            $parts[] = ['text' => ' ', 'href' => null, 'accent' => false];
            $parts[] = $this->auditResourcePart($resource);
        }

        return $parts;
    }

    private function scalarToString(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = (string) $value;
        return $value !== '' ? $value : null;
    }

    private function translateAuditAction(string $action): string
    {
        $key = 'ui.audit.actions.' . \str_replace(['.', '-'], '_', $action);
        $translated = __($key);
        return $translated !== $key ? $translated : $action;
    }

    private function translateAuditRole(string $role): string
    {
        $key = 'ui.audit.roles.' . $role;
        $translated = __($key);
        return $translated !== $key ? $translated : $role;
    }

    private function translateAuditType(string $type): string
    {
        $key = 'ui.audit.types.' . $type;
        $translated = __($key);
        return $translated !== $key ? $translated : $type;
    }

    private function translateAuditRequestType(string $type): string
    {
        $key = 'ui.audit.request_types.' . $type;
        $translated = __($key);
        return $translated !== $key ? $translated : $type;
    }

    private function translateAuditPermission(string $permission): string
    {
        $key = 'ui.audit.permissions.' . $permission;
        $translated = __($key);
        return $translated !== $key ? $translated : $permission;
    }

    private function translateAuditBucket(string $bucket): string
    {
        $key = 'ui.audit.buckets.' . $bucket;
        $translated = __($key);
        return $translated !== $key ? $translated : $bucket;
    }

    private function translateAuditResourceType(string $resourceType): string
    {
        $key = 'ui.audit.resource_types.' . $resourceType;
        $translated = __($key);
        return $translated !== $key ? $translated : $resourceType;
    }

    private function formatAuditResourceFallback(string $resourceType, AuditLog $entry): string
    {
        $identifier = $entry->resourceUuid ?? $entry->resourceId ?? __('ui.audit.resource_deleted');
        return __('ui.audit.resource_fallback', [
            'type' => $this->translateAuditResourceType($resourceType),
            'identifier' => $identifier,
        ]);
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditOrganizationResource(AuditLog $entry, array &$lookup): array
    {
        $organizationId = $entry->resourceId ?? $entry->organizationId;
        if ($organizationId === null) {
            return ['label' => $this->formatAuditResourceFallback('organization', $entry), 'href' => null];
        }

        $organization = $this->auditLookupOrganization($organizationId, $lookup);
        if ($organization === null) {
            return ['label' => $this->formatAuditResourceFallback('organization', $entry), 'href' => null];
        }

        return [
            'label' => $organization->name,
            'href' => '/organizations/' . \urlencode($organization->uuid),
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditUserResource(AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('user', $entry), 'href' => null];
        }

        $user = $this->auditLookupUser($entry->resourceId, $lookup);
        if ($user === null) {
            return ['label' => $this->formatAuditResourceFallback('user', $entry), 'href' => null];
        }

        return ['label' => user_label_with_email($user), 'href' => null];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditGroupResource(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('group', $entry), 'href' => null];
        }

        $group = $this->auditLookupGroup($entry->resourceId, $lookup);
        if ($group === null) {
            return ['label' => $this->formatAuditResourceFallback('group', $entry), 'href' => null];
        }

        return [
            'label' => $group->name,
            'href' => '/organizations/' . \urlencode($organization->uuid) . '/groups/' . \urlencode($group->uuid),
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditDirectoryResource(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('directory', $entry), 'href' => null];
        }

        $directory = $this->auditLookupDirectory($entry->resourceId, $lookup);
        if ($directory === null) {
            return ['label' => $this->formatAuditResourceFallback('directory', $entry), 'href' => null];
        }

        $isRootSecretsDir = $this->isRootSecretDirectoryUuid($organization, $directory->uuid);

        return [
            'label' => $isRootSecretsDir ? __('ui.organization.root_level') : $directory->name,
            'href' => $this->organizationUrl($organization->uuid, $isRootSecretsDir ? null : $directory->uuid),
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditSecretResource(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('secret', $entry), 'href' => null];
        }

        $secret = $this->auditLookupSecret($entry->resourceId, $lookup);
        if ($secret === null) {
            return ['label' => $this->formatAuditResourceFallback('secret', $entry), 'href' => null];
        }

        $directory = $this->auditLookupDirectory($secret->directoryId, $lookup);
        if ($directory === null) {
            return ['label' => $secret->name, 'href' => null];
        }

        return [
            'label' => $secret->name,
            'href' => $this->secretUrl($organization->uuid, $directory->uuid, $secret->uuid),
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditApiKeyResource(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        $apiKeyId = $entry->resourceId ?? $entry->apiKeyId;
        if ($apiKeyId === null) {
            return ['label' => $this->formatAuditResourceFallback('api_key', $entry), 'href' => null];
        }

        $apiKey = $this->auditLookupApiKey($apiKeyId, $lookup);
        if ($apiKey === null) {
            return ['label' => $this->formatAuditResourceFallback('api_key', $entry), 'href' => null];
        }

        return [
            'label' => $apiKey->name,
            'href' => '/organizations/' . \urlencode($organization->uuid) . '/api-keys',
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditIntegrationResource(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('integration', $entry), 'href' => null];
        }

        $integration = $this->auditLookupIntegration($entry->resourceId, $lookup);
        if ($integration === null) {
            return ['label' => $this->formatAuditResourceFallback('integration', $entry), 'href' => null];
        }

        return [
            'label' => $integration->name,
            'href' => '/organizations/' . \urlencode($organization->uuid) . '/integrations',
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditInviteResource(Organization $organization, AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('invite', $entry), 'href' => null];
        }

        $invite = $this->auditLookupInvite($entry->resourceId, $lookup);
        if ($invite === null) {
            return ['label' => $this->formatAuditResourceFallback('invite', $entry), 'href' => null];
        }

        return [
            'label' => __('ui.audit.invite_label', ['type' => $this->translateAuditType($invite->type)]),
            'href' => '/organizations/' . \urlencode($organization->uuid) . '/manage/invites',
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditApprovalRequestResource(AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('approval_request', $entry), 'href' => null];
        }

        $request = $this->auditLookupApprovalRequest($entry->resourceId, $lookup);
        if ($request === null) {
            return ['label' => $this->formatAuditResourceFallback('approval_request', $entry), 'href' => null];
        }

        return [
            'label' => __('ui.audit.approval_request_label', [
                'type' => $this->translateAuditRequestType($request->requestType),
            ]),
            'href' => null,
        ];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     * @return array{label:?string, href:?string}
     */
    private function resolveAuditRotationServiceResource(AuditLog $entry, array &$lookup): array
    {
        if ($entry->resourceId === null) {
            return ['label' => $this->formatAuditResourceFallback('rotation_service', $entry), 'href' => null];
        }

        $rotationService = $this->auditLookupRotationService($entry->resourceId, $lookup);
        if ($rotationService === null) {
            return ['label' => $this->formatAuditResourceFallback('rotation_service', $entry), 'href' => null];
        }

        return ['label' => $rotationService->name, 'href' => null];
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function resolveAuditSubjectLabel(string $subjectType, string $subjectId, array &$lookup): string
    {
        if ($subjectType === 'user') {
            $user = $this->auditLookupUser($subjectId, $lookup);
            return $user !== null ? user_label_with_email($user) : __('ui.audit.unknown_user');
        }

        if ($subjectType === 'group') {
            $group = $this->auditLookupGroup($subjectId, $lookup);
            return $group !== null ? $group->name : __('ui.audit.unknown_group');
        }

        return __('ui.audit.resource_fallback', [
            'type' => $this->translateAuditResourceType($subjectType),
            'identifier' => $subjectId,
        ]);
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupUser(string $userId, array &$lookup): ?User
    {
        if (!\array_key_exists($userId, $lookup['users'])) {
            $lookup['users'][$userId] = User::findById($userId);
        }

        $user = $lookup['users'][$userId];
        return $user instanceof User ? $user : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupApiKey(string $apiKeyId, array &$lookup): ?ApiKey
    {
        if (!\array_key_exists($apiKeyId, $lookup['api_keys'])) {
            $lookup['api_keys'][$apiKeyId] = ApiKey::findById($apiKeyId);
        }

        $apiKey = $lookup['api_keys'][$apiKeyId];
        return $apiKey instanceof ApiKey ? $apiKey : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupOrganization(string $organizationId, array &$lookup): ?Organization
    {
        if (!\array_key_exists($organizationId, $lookup['organizations'])) {
            $lookup['organizations'][$organizationId] = Organization::findById($organizationId);
        }

        $organization = $lookup['organizations'][$organizationId];
        return $organization instanceof Organization ? $organization : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupGroup(string $groupId, array &$lookup): ?Group
    {
        if (!\array_key_exists($groupId, $lookup['groups'])) {
            $lookup['groups'][$groupId] = Group::findById($groupId);
        }

        $group = $lookup['groups'][$groupId];
        return $group instanceof Group ? $group : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupDirectory(string $directoryId, array &$lookup): ?Directory
    {
        if (!\array_key_exists($directoryId, $lookup['directories'])) {
            $lookup['directories'][$directoryId] = Directory::findById($directoryId);
        }

        $directory = $lookup['directories'][$directoryId];
        return $directory instanceof Directory ? $directory : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupSecret(string $secretId, array &$lookup): ?Secret
    {
        if (!\array_key_exists($secretId, $lookup['secrets'])) {
            $lookup['secrets'][$secretId] = Secret::findById($secretId);
        }

        $secret = $lookup['secrets'][$secretId];
        return $secret instanceof Secret ? $secret : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupIntegration(string $integrationId, array &$lookup): ?OrganizationIntegration
    {
        if (!\array_key_exists($integrationId, $lookup['integrations'])) {
            $lookup['integrations'][$integrationId] = OrganizationIntegration::findById($integrationId);
        }

        $integration = $lookup['integrations'][$integrationId];
        return $integration instanceof OrganizationIntegration ? $integration : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupInvite(string $inviteId, array &$lookup): ?InviteLink
    {
        if (!\array_key_exists($inviteId, $lookup['invites'])) {
            $invite = InviteLink::findByUuid($inviteId);
            if ($invite === null && ctype_digit($inviteId)) {
                $invite = Database::getInstance()->fetchOne('SELECT * FROM invite_links WHERE id = ?', [(int) $inviteId]);
                $invite = \is_array($invite) ? InviteLink::fromRow($invite) : null;
            }
            $lookup['invites'][$inviteId] = $invite;
        }

        $invite = $lookup['invites'][$inviteId];
        return $invite instanceof InviteLink ? $invite : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupApprovalRequest(string $requestId, array &$lookup): ?ApprovalRequest
    {
        if (!\array_key_exists($requestId, $lookup['approval_requests'])) {
            $lookup['approval_requests'][$requestId] = ApprovalRequest::findById($requestId);
        }

        $request = $lookup['approval_requests'][$requestId];
        return $request instanceof ApprovalRequest ? $request : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupRotationService(string $rotationServiceId, array &$lookup): ?RotationServiceModel
    {
        if (!\array_key_exists($rotationServiceId, $lookup['rotation_services'])) {
            $lookup['rotation_services'][$rotationServiceId] = RotationServiceModel::findById($rotationServiceId);
        }

        $rotationService = $lookup['rotation_services'][$rotationServiceId];
        return $rotationService instanceof RotationServiceModel ? $rotationService : null;
    }

    /**
     * @param array<string, array<string, object|null>> $lookup
     */
    private function auditLookupRotationServiceByUuid(string $uuid, array &$lookup): ?RotationServiceModel
    {
        foreach ($lookup['rotation_services'] as $rotationService) {
            if ($rotationService instanceof RotationServiceModel && $rotationService->uuid === $uuid) {
                return $rotationService;
            }
        }

        $rotationService = RotationServiceModel::findByUuid($uuid);
        if ($rotationService !== null) {
            $lookup['rotation_services'][$rotationService->id] = $rotationService;
        }

        return $rotationService;
    }

    public function showProfile(Request $request): Response
    {
        return $this->renderProfileSection($request, 'basic');
    }

    public function showProfileSecurity(Request $request): Response
    {
        return $this->renderProfileSection($request, 'security');
    }

    public function showProfileInterface(Request $request): Response
    {
        return $this->renderProfileSection($request, 'interface');
    }

    private function renderProfileSection(Request $request, string $section): Response
    {
        $user = AuthContext::requireUser();

        return $this->renderProfileSettingsResponse(
            $request,
            $section,
            $user,
            error: $request->query('error') !== null ? (string) $request->query('error') : null,
            success: $request->query('success') !== null ? (string) $request->query('success') : null,
        );
    }

    public function updateProfile(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $nickname = \trim((string) ($request->input('nickname') ?? ''));
        $avatarData = \trim((string) ($request->input('avatar_data') ?? ''));
        $removeAvatar = $this->parseBooleanRequestInput($request->input('remove_avatar')) && $avatarData === '';
        $avatarPath = null;
        $oldAvatarPath = $user->avatarPath;

        try {
            $avatarPath = $this->storeCroppedAvatar($avatarData, 'users/avatars', __('ui.profile.avatar_invalid'));

            $update = [
                'nickname' => $nickname !== '' ? $nickname : null,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ];

            if ($avatarPath !== null) {
                $update['avatar_path'] = $avatarPath;
            } elseif ($removeAvatar) {
                $update['avatar_path'] = null;
            }

            $user->update($update);

            if ($avatarPath !== null || $removeAvatar) {
                $this->deleteUploadedFile($oldAvatarPath);
            }
        } catch (\Throwable $e) {
            if ($avatarPath !== null) {
                $this->deleteUploadedFile($avatarPath);
            }

            if ($request->isAjax()) {
                return $this->renderProfileSettingsResponse($request, 'basic', User::findById($user->id) ?? $user, error: $e->getMessage());
            }

            return Response::redirect('/profile?error=' . \urlencode($e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderProfileSettingsResponse($request, 'basic', User::findById($user->id) ?? $user, success: __('ui.profile.basic_saved'));
        }

        return Response::redirect('/profile?success=' . \urlencode(__('ui.profile.basic_saved')));
    }

    public function updateProfileEmail(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $email = \strtolower(\trim((string) ($request->input('email') ?? '')));
        $password = (string) ($request->input('password') ?? '');

        if ($user->passwordHash === null || $password === '' || !$this->hashingService->verifyPassword($password, $user->passwordHash)) {
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_incorrect_password')));
        }

        if (!\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::redirect('/profile?error=' . \urlencode(__('ui.backend.setup.invalid_email')));
        }

        $existing = User::findByEmail($email);
        if ($existing !== null && $existing->id !== $user->id) {
            return Response::redirect('/profile?error=' . \urlencode(__('ui.profile.error_email_taken')));
        }

        $user->update([
            'email' => $email,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return Response::redirect('/profile?success=' . \urlencode(__('ui.profile.email_saved')));
    }

    public function updateProfilePassword(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $currentPassword = (string) ($request->input('current_password') ?? '');
        $password = (string) ($request->input('password') ?? '');
        $passwordConfirm = (string) ($request->input('password_confirm') ?? '');

        if ($user->passwordHash !== null && ($currentPassword === '' || !$this->hashingService->verifyPassword($currentPassword, $user->passwordHash))) {
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_incorrect_password')));
        }

        try {
            $this->setupService->validatePassword($password);
            if ($password !== $passwordConfirm) {
                throw new \InvalidArgumentException(__('ui.profile.error_passwords_do_not_match'));
            }

            $user->update([
                'password_hash' => $this->hashingService->hashPassword($password),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);

            $this->sessionService->invalidateAll($user->id);
            $rawToken = $this->sessionService->create($user->id, $request->ip(), $request->header('User-Agent'));
            $this->sessionService->setCookie($rawToken);
        } catch (\Throwable $e) {
            return Response::redirect('/profile/security?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/profile/security?success=' . \urlencode(__('ui.profile.password_saved')));
    }

    public function deleteProfileAvatar(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $oldAvatarPath = $user->avatarPath;

        $user->update([
            'avatar_path' => null,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
        $this->deleteUploadedFile($oldAvatarPath);

        return Response::redirect('/profile?success=' . \urlencode(__('ui.profile.avatar_removed')));
    }

    public function updateProfileInterface(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $locale = \trim((string) ($request->input('locale_preference') ?? 'system'));
        $theme = normalize_theme_preference((string) ($request->input('theme_preference') ?? 'system'));

        if ($locale !== 'system') {
            $locale = normalize_locale_code($locale) ?? '';
            if ($locale === '') {
                if ($request->isAjax()) {
                    return $this->renderProfileSettingsResponse($request, 'interface', $user, error: __('ui.profile.error_invalid_locale'));
                }

                return Response::redirect('/profile/interface?error=' . \urlencode(__('ui.profile.error_invalid_locale')));
            }
        }

        $user->update([
            'locale_preference' => $locale,
            'theme_preference' => $theme,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        set_request_theme($theme);
        if ($locale !== 'system') {
            set_request_locale($locale);
        } else {
            set_request_locale(resolve_browser_locale($request->header('Accept-Language')) ?? default_ui_locale());
        }

        if ($request->isAjax()) {
            return $this->renderProfileSettingsResponse($request, 'interface', User::findById($user->id) ?? $user, success: __('ui.profile.interface_saved'));
        }

        return Response::redirect('/profile/interface?success=' . \urlencode(__('ui.profile.interface_saved')));
    }

    public function startTotpSetup(Request $request): Response
    {
        $user = AuthContext::requireUser();

        if ($user->totpEnabled) {
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_totp_already_enabled')));
        }

        try {
            $data = $this->totpService->generateSecret();
            $qrCodeUri = $this->totpService->getQrCodeUri($user->email, $data['raw_secret']);
            $qrCodeImage = null;

            try {
                $qrCodeImage = $this->totpService->getQrCodeImageDataUri($user->email, $data['raw_secret']);
            } catch (\Throwable) {
                // Do not break TOTP setup if QR rendering failed locally.
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
            return Response::redirect('/profile/security?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/profile/security');
    }

    public function enableTotp(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $code = \trim((string) ($request->input('code') ?? ''));

        if ($code === '') {
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_totp_code_required')));
        }

        $this->ensureSessionStarted();
        $setup = $_SESSION['totp_setup'] ?? null;

        if (!\is_array($setup) || (($setup['expires'] ?? 0) < \time())) {
            unset($_SESSION['totp_setup']);
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_totp_setup_expired')));
        }

        try {
            $valid = $this->totpService->verifyCode(
                encryptedSecret: (string) $setup['encrypted'],
                nonce: (string) $setup['nonce'],
                code: $code,
            );
        } catch (\Throwable $e) {
            return Response::redirect('/profile/security?error=' . \urlencode($e->getMessage()));
        }

        if (!$valid) {
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_invalid_totp_code')));
        }

        $user->update([
            'totp_secret' => $setup['encrypted'],
            'totp_nonce' => $setup['nonce'],
            'totp_enabled' => 1,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
        unset($_SESSION['totp_setup']);

        return Response::redirect('/profile/security?success=' . \urlencode(__('ui.profile.success_totp_enabled')));
    }

    public function disableTotp(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $password = (string) ($request->input('password') ?? '');

        if ($password === '') {
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_disable_totp_password_required')));
        }
        if ($user->passwordHash === null) {
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_disable_totp_password_missing')));
        }
        if (!$this->hashingService->verifyPassword($password, $user->passwordHash)) {
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_incorrect_password')));
        }

        $user->update([
            'totp_enabled' => 0,
            'totp_secret' => null,
            'totp_nonce' => null,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return Response::redirect('/profile/security?success=' . \urlencode(__('ui.profile.success_totp_disabled')));
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
            return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_passkey_not_found')));
        }

        if ($user->passwordHash === null) {
            $count = (int) \Passway\Core\Database::getInstance()->fetchColumn(
                'SELECT COUNT(*) FROM passkeys WHERE user_id = ?',
                [$user->id]
            );
            if ($count <= 1) {
                return Response::redirect('/profile/security?error=' . \urlencode(__('ui.profile.error_last_passkey_without_password')));
            }
        }

        \Passway\Core\Database::getInstance()->delete('passkeys', ['uuid' => $passkeyUuid, 'user_id' => $user->id]);

        return Response::redirect('/profile/security?success=' . \urlencode(__('ui.profile.success_passkey_removed')));
    }

    public function showApiKeys(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $keys = $this->apiKeyService->listForOrg($org->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->settingsSectionUrl($org->uuid, 'api-keys', error: $e->getMessage()));
        }

        return $this->html($this->renderOrganizationSettingsView($request, 'web/api_keys', [
            'title' => __('ui.titles.api_keys'),
            'user' => $user,
            'organization' => $org,
            'keys' => $keys,
            'createdRawKey' => null,
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'activeSettingsSection' => 'api-keys',
        ]));
    }

    public function createApiKey(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $name = \trim((string) ($request->input('name') ?? ''));
        $role = \trim((string) ($request->input('role') ?? 'reader'));
        $expiresAtInput = $request->input('expires_at') ?? $request->input('expires_at_local') ?? '';
        $expiresAt = $this->normalizeApiKeyExpiresAt(\trim((string) $expiresAtInput));

        try {
            ['key' => $apiKey, 'raw' => $rawKey] = $this->apiKeyService->create(
                $name,
                $org->id,
                $user->id,
                $role,
                $expiresAt,
            );
            $keys = $this->apiKeyService->listForOrg($org->id, $user->id);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                return $this->renderApiKeysResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?error=' . \urlencode($e->getMessage()));
        }

        return $this->html($this->renderOrganizationSettingsView($request, 'web/api_keys', [
            'title' => __('ui.titles.api_keys'),
            'user' => $user,
            'organization' => $org,
            'keys' => $keys,
            'createdRawKey' => $rawKey,
            'createdKeyUuid' => $apiKey->uuid,
            'queryError' => null,
            'querySuccess' => __('ui.api_keys.created_copy_now'),
            'activeSettingsSection' => 'api-keys',
        ]));
    }

    public function updateApiKeyRole(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');
        $role = \trim((string) ($request->input('role') ?? ''));

        try {
            $this->apiKeyService->updateRole($keyUuid, $role, $org->id, $user->id);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                return $this->renderApiKeysResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?error=' . \urlencode($e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderApiKeysResponse($request, $org, $user, success: __('ui.messages.api_key_role_updated'));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?success=' . \urlencode(__('ui.messages.api_key_role_updated')));
    }

    public function revokeApiKey(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');

        try {
            $this->apiKeyService->revoke($keyUuid, $org->id, $user->id);
        } catch (\Throwable $e) {
            if ($request->isAjax()) {
                return $this->renderApiKeysResponse($request, $org, $user, error: $e->getMessage());
            }

            return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?error=' . \urlencode($e->getMessage()));
        }

        if ($request->isAjax()) {
            return $this->renderApiKeysResponse($request, $org, $user, success: __('ui.messages.api_key_revoked'));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid) . '/api-keys?success=' . \urlencode(__('ui.messages.api_key_revoked')));
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

    public function showApiDocs(Request $request): Response
    {
        $user = AuthContext::requireUser();

        return $this->html($this->view->render('web/api', [
            'title' => __('ui.titles.api_docs'),
            'user' => $user,
            'sections' => require base_path('resources/docs/api.php'),
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
            $services = $this->rotationRegistryService->listAll();
        } catch (\Throwable $e) {
            return Response::redirect($this->settingsSectionUrl($org->uuid, 'integrations', error: $e->getMessage()));
        }

        return $this->html($this->renderOrganizationSettingsView($request, 'web/integrations', [
            'title' => __('ui.titles.organization_integrations'),
            'user' => $user,
            'organization' => $org,
            'integrations' => $integrations,
            'services' => $services,
            'serviceMap' => $this->buildRotationServiceMap($services),
            'queryError' => $request->query('error'),
            'querySuccess' => $request->query('success'),
            'activeSettingsSection' => 'integrations',
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
            $secret = $this->secretService->getMeta($secUuid, $org->id, $user->id);
            $versions = $this->secretService->listVersions($secUuid, $org->id, $user->id);
            $dir = Directory::findById($secret->directoryId);
            if ($dir === null || $dir->organizationId !== $org->id) {
                throw new \RuntimeException(__('ui.backend.directory.not_found'));
            }

            $canDirectRead = !$secret->requiresApproval
                || $secret->ownerUserId === $user->id
                || $this->organizationService->hasPermission($org->id, $user->id, 'editor');
            $value = null;
            if ($canDirectRead) {
                ['value' => $value] = $this->secretService->get($secUuid, $org->id, $user->id);
            }
        } catch (AuthException | \RuntimeException | \Passway\Exceptions\DecryptionException $e) {
            return Response::redirect($this->organizationUrl($org->uuid, $dirUuid, $e->getMessage()));
        }

        $displayValue = $value ?? '';
        $templateDetails = null;
        $templateOverrides = [];
        $templateParameterSchema = [];
        $templateExtraFields = [];
        $dynamicRotationView = ['input' => [], 'outputs' => [], 'primary_field' => null, 'service' => null];
        $pendingApprovalRequest = null;

        if ($canDirectRead && $secret->type === 'template' && $secret->templateId !== null) {
            $template = Template::findById($secret->templateId);
            if ($template !== null) {
                $templateOverrides = $this->secretService->getTemplateOverrides($secret->uuid, $org->id, $user->id);
                $templateView = $this->templateService->describeValue($template->uuid, (string) $value, $org->id, $templateOverrides);
                $displayValue = $templateView['display_value'];
                $templateParameterSchema = $templateView['parameter_schema'];
                $templateExtraFields = $templateView['extra_fields'];
                $templateOverrides = $templateView['overrides'];
                $templateDetails = [
                    'uuid' => $template->uuid,
                    'name' => $template->displayName(),
                    'type' => $template->type,
                ];
            }
        } elseif ($canDirectRead && $secret->type === 'dynamic') {
            $dynamicRotationView = $this->secretService->getDynamicSecretView($secret->uuid, $org->id, $user->id);
        } elseif (!$canDirectRead && $secret->requiresApproval) {
            $pendingApprovalRequest = $this->approvalService->findPendingReadForUser($secret->id, $user->id);
        }

        $canWriteSecret = $secret->ownerUserId === $user->id
            || $this->permissionService->can('write', $user->id, 'secret', $secret->id, $org->id);

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
            'organizationMembers' => $this->serializeOrganizationMembers($org->id),
            'organizationGroups' => $this->serializeOrganizationGroups($org->id),
            'organizationApiKeys' => $secret->ownerUserId === $user->id
                ? $this->serializeOrganizationApiKeys($org->id)
                : [],
            'isSoloMode' => is_solo_mode(),
            'canDirectReadSecret' => $canDirectRead,
            'pendingApprovalRequest' => $pendingApprovalRequest,
            'canWriteSecret' => $canWriteSecret,
            'canManageSecretAcl' => $secret->ownerUserId === $user->id,
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

    public function transferSecretOwnership(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $secUuid = (string) $request->routeParam('secUuid');
        $userUuid = \trim((string) ($request->input('user_uuid') ?? ''));

        $targetUser = User::findByUuid($userUuid);
        if ($targetUser === null) {
            return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid, __('ui.backend.common.user_not_found')));
        }

        try {
            $this->secretService->transferOwnership($secUuid, $org->id, $targetUser->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid, $e->getMessage()));
        }

        return Response::redirect($this->secretUrl($org->uuid, $dirUuid, $secUuid));
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

    public function transferDirectoryOwnership(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $userUuid = \trim((string) ($request->input('user_uuid') ?? ''));

        $targetUser = User::findByUuid($userUuid);
        if ($targetUser === null) {
            return Response::redirect($this->organizationUrl($org->uuid, $dirUuid, __('ui.backend.common.user_not_found')));
        }

        try {
            $this->directoryService->transferOwnership($dirUuid, $org->id, $targetUser->id, $user->id);
        } catch (\Throwable $e) {
            return Response::redirect($this->organizationUrl($org->uuid, $dirUuid, $e->getMessage()));
        }

        return Response::redirect($this->organizationUrl($org->uuid, $dirUuid));
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
        $search = \trim((string) ($request->query('q') ?? ''));
        $orgs = $this->filterHomeOrganizations($this->organizationService->getForUser($user->id), $search);

        $currentOrg = $this->resolveCurrentOrganization($request, $orgs);

        return $this->html($this->view->render('web/home', [
            'title' => __('ui.titles.home'),
            'user' => $user,
            'organizations' => $orgs,
            'organizationCards' => $this->buildHomeOrganizationCards($orgs),
            'currentOrg' => $currentOrg,
            'queryError' => $request->query('error'),
            'querySuccess' => $success,
            'isSoloMode' => is_solo_mode(),
            'isSetupAdmin' => $this->isSetupAdministrator($user),
            'createdOrganizationInvite' => $createdOrganizationInvite,
            'search' => $search,
        ]));
    }

    /**
     * @param Organization[] $organizations
     * @return Organization[]
     */
    private function filterHomeOrganizations(array $organizations, string $search): array
    {
        if ($search === '') {
            return $organizations;
        }

        return \array_values(\array_filter(
            $organizations,
            static fn(Organization $org): bool => mb_stripos($org->name, $search, 0, 'UTF-8') !== false,
        ));
    }

    /** @return array{currentDir:?Directory,directories:array<int,Directory>,secrets:array<int,Secret>,searchDirectories:array,searchSecrets:array,rootSecretDirectory:?Directory} */
    private function buildOrganizationBrowseData(Organization $org, User $user, string $search, bool $canEditContent, Request $request): array
    {
        $currentDir = null;
        $directories = [];
        $secrets = [];
        $searchDirectories = [];
        $searchSecrets = [];
        $rootSecretDirectory = $this->findRootSecretDirectory($org->id);

        if ($rootSecretDirectory === null && $canEditContent) {
            $rootSecretDirectory = $this->getOrCreateRootSecretDirectory($org, $user);
        }

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

        if ($search !== '') {
            ['directories' => $searchDirectories, 'secrets' => $searchSecrets] = $this->searchOrganizationSubtree(
                $org,
                $user,
                $currentDir,
                $search,
            );
        }

        return [
            'currentDir' => $currentDir,
            'directories' => $directories,
            'secrets' => $secrets,
            'searchDirectories' => $searchDirectories,
            'searchSecrets' => $searchSecrets,
            'rootSecretDirectory' => $rootSecretDirectory,
        ];
    }

    private function findOrganizationUserByEmail(string $orgId, string $email): ?User
    {
        $email = \strtolower(\trim($email));
        if ($email === '') {
            return null;
        }

        foreach ($this->organizationService->listMembers($orgId) as $member) {
            $memberUser = User::findById($member->userId);
            if ($memberUser !== null && \strtolower($memberUser->email) === $email) {
                return $memberUser;
            }
        }

        return null;
    }

    /** @return array<int, array{uuid:string,name:string,email:string,display_label:string,role:string}> */
    private function serializeOrganizationMembers(string $orgId): array
    {
        $members = [];

        foreach ($this->organizationService->listMembers($orgId) as $member) {
            $memberUser = User::findById($member->userId);
            if ($memberUser === null) {
                continue;
            }

            $members[] = [
                'uuid' => $memberUser->uuid,
                'name' => display_name_for_user($memberUser),
                'email' => $memberUser->email,
                'display_label' => user_label_with_email($memberUser),
                'role' => $member->role,
            ];
        }

        return $members;
    }

    /** @return array<int, array{uuid:string,name:string,key_prefix:string,is_active:bool}> */
    private function serializeOrganizationApiKeys(string $orgId): array
    {
        $keys = [];

        foreach (ApiKey::findByOrgId($orgId) as $apiKey) {
            if (!$apiKey->isActive) {
                continue;
            }

            $keys[] = [
                'uuid' => $apiKey->uuid,
                'name' => $apiKey->name,
                'key_prefix' => $apiKey->keyPrefix,
                'is_active' => $apiKey->isActive,
            ];
        }

        return $keys;
    }

    /** @return array<int, array{uuid:string,name:string,description:?string}> */
    private function serializeOrganizationGroups(string $orgId): array
    {
        $groups = [];

        foreach (Group::findByOrgId($orgId) as $group) {
            $groups[] = [
                'uuid' => $group->uuid,
                'name' => $group->name,
                'description' => $group->description,
            ];
        }

        return $groups;
    }

    /** @return array<int, array{uuid:string,name:string}> */
    private function serializeOrganizationSecrets(string $orgId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT uuid, name FROM secrets WHERE organization_id = ? AND deleted_at IS NULL ORDER BY name ASC',
            [(int) $orgId]
        );

        return \array_map(static fn(array $row): array => [
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    /** @return array{uuid:string,name:string}|null */
    private function resolveAuditSelectedSecret(string $orgId, string $secretUuid): ?array
    {
        $secretUuid = \trim($secretUuid);
        if ($secretUuid === '') {
            return null;
        }

        $row = Database::getInstance()->fetchOne(
            'SELECT uuid, name FROM secrets WHERE organization_id = ? AND uuid = ? AND deleted_at IS NULL',
            [(int) $orgId, $secretUuid]
        );

        if (!\is_array($row)) {
            return null;
        }

        return [
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
        ];
    }

    /** @return array<int, array{uuid:string,name:string}> */
    private function serializeOrganizationIntegrations(string $orgId): array
    {
        return \array_map(static fn(OrganizationIntegration $integration): array => [
            'uuid' => $integration->uuid,
            'name' => $integration->name,
        ], OrganizationIntegration::findByOrgId($orgId));
    }

    /** @return array<int, array{uuid:string,name:string}> */
    private function serializeAuditRotationServices(): array
    {
        $rows = Database::getInstance()->fetchAll('SELECT uuid, name FROM rotation_services ORDER BY name ASC');

        return \array_map(static fn(array $row): array => [
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    /** @return string */
    private function groupsUrl(string $orgUuid, ?string $error = null, ?string $success = null): string
    {
        $url = '/organizations/' . \urlencode($orgUuid) . '/groups';
        $params = [];

        if ($error !== null && $error !== '') {
            $params['error'] = $error;
        }
        if ($success !== null && $success !== '') {
            $params['success'] = $success;
        }
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    private function groupUrl(string $orgUuid, string $grpUuid, ?string $error = null, ?string $success = null): string
    {
        $url = '/organizations/' . \urlencode($orgUuid) . '/groups/' . \urlencode($grpUuid);
        $params = [];

        if ($error !== null && $error !== '') {
            $params['error'] = $error;
        }
        if ($success !== null && $success !== '') {
            $params['success'] = $success;
        }
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    private function settingsSectionUrl(string $orgUuid, string $section, ?string $error = null, ?string $success = null): string
    {
        $basePath = match ($section) {
            'settings' => '/organizations/' . \urlencode($orgUuid) . '/manage/settings',
            'members' => '/organizations/' . \urlencode($orgUuid) . '/manage/members',
            'invites' => '/organizations/' . \urlencode($orgUuid) . '/manage/invites',
            'groups' => '/organizations/' . \urlencode($orgUuid) . '/groups',
            'api-keys' => '/organizations/' . \urlencode($orgUuid) . '/api-keys',
            'integrations' => '/organizations/' . \urlencode($orgUuid) . '/integrations',
            default => '/organizations/' . \urlencode($orgUuid) . '/manage/settings',
        };

        $params = [];
        if ($error !== null && $error !== '') {
            $params['error'] = $error;
        }
        if ($success !== null && $success !== '') {
            $params['success'] = $success;
        }

        if ($params === []) {
            return $basePath;
        }

        return $basePath . '?' . http_build_query($params);
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
    private function searchOrganizationSubtree(Organization $organization, User $user, ?Directory $currentDir, string $search): array
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
            if (!$this->permissionService->can('read', $user->id, 'directory', $directory->id, $organization->id)) {
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
            if (!$this->permissionService->can('read', $user->id, 'secret', $secret->id, $organization->id)) {
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

    /**
     * @param RotationServiceModel[]|null $services
     * @return array<string, RotationServiceModel>
     */
    private function buildRotationServiceMap(?array $services = null): array
    {
        $map = [];
        foreach (($services ?? $this->rotationRegistryService->listAll()) as $service) {
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

    private function parseBooleanRequestInput(mixed $input): bool
    {
        if (\is_bool($input)) {
            return $input;
        }

        if (\is_int($input)) {
            return $input === 1;
        }

        if (\is_string($input)) {
            return \in_array(\strtolower(\trim($input)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
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

    /** @param array<string, mixed> $data */
    private function renderOrganizationSettingsView(Request $request, string $view, array $data): string
    {
        $isPartial = $request->isAjax();

        return $this->view->render(
            $view,
            array_merge($data, ['organizationSettingsPartial' => $isPartial]),
            layout: $isPartial ? null : 'layout',
        );
    }

    private function renderOrganizationSettingsResponse(
        Request $request,
        Organization $org,
        User $user,
        ?string $error = null,
        ?string $success = null,
    ): Response {
        return $this->html($this->renderOrganizationSettingsView($request, 'web/organization_settings', [
            'title' => __('ui.titles.manage_organization'),
            'user' => $user,
            'organization' => $org,
            'queryError' => $error,
            'querySuccess' => $success,
            'canManageSettings' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
            'canDeleteOrganization' => $this->organizationService->hasPermission($org->id, $user->id, 'owner'),
            'organizationDeletionStats' => $this->organizationService->deletionStats($org->id),
            'activeSettingsSection' => 'settings',
        ]));
    }

    private function renderProfileSettingsResponse(
        Request $request,
        string $section,
        User $user,
        ?string $error = null,
        ?string $success = null,
    ): Response {
        return $this->html($this->view->render('web/profile', [
            'title' => __('ui.titles.profile_security'),
            'user' => $user,
            'passkeys' => Passkey::findByUserId($user->id),
            'totpSetup' => $this->getTotpSetupSession(),
            'activeProfileSection' => $section,
            'queryError' => $error,
            'querySuccess' => $success,
            'profileSettingsPartial' => $request->isAjax(),
        ], layout: $request->isAjax() ? null : 'layout'));
    }

    private function renderOrganizationInvitesResponse(
        Request $request,
        Organization $org,
        User $user,
        ?string $error = null,
        ?string $success = null,
    ): Response {
        return $this->html($this->renderOrganizationSettingsView($request, 'web/organization_invites', [
            'title' => __('ui.titles.manage_organization'),
            'user' => $user,
            'organization' => $org,
            'invites' => $this->inviteService->listActive($org->id),
            'queryError' => $error,
            'querySuccess' => $success,
            'canManageSettings' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
            'activeSettingsSection' => 'invites',
        ]));
    }

    private function renderOrganizationGroupsResponse(
        Request $request,
        Organization $org,
        User $user,
        ?string $error = null,
        ?string $success = null,
    ): Response {
        return $this->html($this->renderOrganizationSettingsView($request, 'web/groups', [
            'title' => __('ui.titles.organization_groups'),
            'user' => $user,
            'organization' => $org,
            'groups' => $this->buildOrganizationGroupCards($org, $user),
            'queryError' => $error,
            'querySuccess' => $success,
            'canManageGroups' => $this->organizationService->hasPermission($org->id, $user->id, 'admin'),
            'activeSettingsSection' => 'groups',
        ]));
    }

    private function renderApiKeysResponse(
        Request $request,
        Organization $org,
        User $user,
        ?string $error = null,
        ?string $success = null,
        ?string $createdRawKey = null,
    ): Response {
        return $this->html($this->renderOrganizationSettingsView($request, 'web/api_keys', [
            'title' => __('ui.titles.api_keys'),
            'user' => $user,
            'organization' => $org,
            'keys' => $this->apiKeyService->listForOrg($org->id, $user->id),
            'createdRawKey' => $createdRawKey,
            'queryError' => $error,
            'querySuccess' => $success,
            'activeSettingsSection' => 'api-keys',
        ]));
    }

    private function normalizeApiKeyExpiresAt(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $normalized = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized) === 1) {
            $normalized .= ':00';
        }

        return $normalized;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildOrganizationGroupCards(Organization $org, User $user): array
    {
        $groupCards = $this->groupService->listWithMemberCounts($org->id, $user->id);

        foreach ($groupCards as &$groupCard) {
            $group = $groupCard['group'];
            $members = $this->groupService->listMembers($group->uuid, $org->id, $user->id);
            [$memberRows, $candidateRows] = $this->buildGroupMemberManagementRows($org, $members);
            $groupCard['members'] = $memberRows;
            $groupCard['candidates'] = $candidateRows;
        }
        unset($groupCard);

        return $groupCards;
    }

    /**
     * @param array<int, mixed> $members
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, string>>}
     */
    private function buildGroupMemberManagementRows(Organization $org, array $members): array
    {
        $memberRows = [];
        $memberIds = [];
        foreach ($members as $member) {
            $memberUser = User::findById($member->userId);
            if ($memberUser === null) {
                continue;
            }

            $memberIds[$memberUser->id] = true;
            $memberRows[] = [
                'user_uuid' => $memberUser->uuid,
                'name' => display_name_for_user($memberUser),
                'email' => $memberUser->email,
                'role_label' => ($role = $this->organizationService->getMemberRole($org->id, $memberUser->id)) !== null
                    ? __('ui.organization_manage.roles.' . $role)
                    : __('ui.app.unknown'),
                'added_at' => $member->addedAt,
            ];
        }

        $candidateRows = [];
        foreach ($this->organizationService->listMembers($org->id) as $orgMember) {
            if (isset($memberIds[$orgMember->userId])) {
                continue;
            }

            $candidateUser = User::findById($orgMember->userId);
            if ($candidateUser === null) {
                continue;
            }

            $candidateRows[] = [
                'uuid' => $candidateUser->uuid,
                'name' => display_name_for_user($candidateUser),
                'email' => $candidateUser->email,
                'role_label' => __('ui.organization_manage.roles.' . $orgMember->role),
            ];
        }

        usort($candidateRows, static function (array $left, array $right): int {
            return strcasecmp($left['email'], $right['email']);
        });

        return [$memberRows, $candidateRows];
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
