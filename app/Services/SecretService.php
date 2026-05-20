<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;
use Passway\Models\OrganizationIntegration;
use Passway\Models\RotationService;
use Passway\Models\Secret;
use Passway\Models\SecretMetadata;
use Passway\Models\SecretVersion;
use Passway\Models\Template;

/**
 * Сервис управления секретами.
 *
 * Авторизация (через PermissionService, включает org-level и fine-grained):
 *   read   — list, get, listVersions  (reader+ или явное право)
 *   write  — create, update           (editor+ или явное право)
 *   delete — delete                   (editor+ или явное право)
 *
 * Права проверяются на каталоге, которому принадлежит секрет.
 * Шифрование: XChaCha20-Poly1305, AAD = uuid секрета.
 *
 * requires_approval:
 *   Если у секрета флаг requires_approval=true, пользователи ниже editor
 *   обязаны пройти workflow одобрения (ApprovalService) и получить одноразовый
 *   токен. Вместо get() используется ApprovalService::useToken().
 *   Editor+ обходят проверку и читают секрет напрямую.
 */
final class SecretService
{
    /** Допустимые типы секретов */
    public const VALID_TYPES = ['static', 'template', 'dynamic'];

    /** Максимальное число хранимых версий на секрет */
    private const MAX_VERSIONS = 10;

    private const TEMPLATE_OVERRIDES_METADATA_KEY = 'template_overrides';
    private const DYNAMIC_ROTATION_INPUT_METADATA_KEY = 'rotation_input';
    private const DYNAMIC_ROTATION_OUTPUTS_METADATA_KEY = 'rotation_outputs';
    private const DYNAMIC_ROTATION_PRIMARY_FIELD_METADATA_KEY = 'rotation_primary_field';

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly EncryptionService   $encryptionService,
        private readonly PermissionService   $permissionService,
        private readonly ?TemplateService    $templateService = null,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Создание                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Создать секрет в каталоге организации.
     *
     * @param string $orgId    ID организации
     * @param string $dirUuid  UUID каталога
     * @param string $name     Имя секрета
     * @param string $type     Тип: static|template|dynamic
     * @param string $value    Открытое значение (будет зашифровано)
     * @param string $userId   ID создателя
     *
     * @throws AuthException             если нет прав (требуется editor+)
     * @throws \InvalidArgumentException при невалидном имени или типе
     * @throws \RuntimeException         если каталог не найден или имя занято
     */
    public function create(
        string $orgId,
        string $dirUuid,
        string $name,
        string $type,
        string $value,
        string $userId,
        ?string $rotationIntegrationUuid = null,
        ?string $rotationSchedule = null,
        string $defaultReadAccess = 'inherit',
        string $defaultWriteAccess = 'inherit',
        bool $requiresApproval = false,
    ): Secret {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.secret.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.secret.name_too_long'));
        }
        if (!\in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.secret.invalid_type', ['allowed' => \implode(', ', self::VALID_TYPES)])
            );
        }

        $defaultReadAccess = $this->normalizeAccessPolicy($defaultReadAccess);
        $defaultWriteAccess = $this->normalizeAccessPolicy($defaultWriteAccess);

        $rotationIntegrationUuid = $rotationIntegrationUuid !== null && \trim($rotationIntegrationUuid) !== ''
            ? \trim($rotationIntegrationUuid)
            : null;
        $rotationSchedule = $rotationSchedule !== null && \trim($rotationSchedule) !== ''
            ? $rotationSchedule
            : null;

        if ($type !== 'dynamic' && ($rotationIntegrationUuid !== null || $rotationSchedule !== null)) {
            throw new \InvalidArgumentException(__('ui.backend.secret.rotation_supported_for_dynamic_only'));
        }

        $dir = $this->findDirInOrg($dirUuid, $orgId);
        $this->assertCan('write', $userId, 'directory', $dir->id, $orgId);
        $this->assertNameUnique($dir->id, $name);
        $rotationIntegrationId = $this->resolveRotationIntegrationId($rotationIntegrationUuid, $orgId);
        $rotationSchedule = $this->normalizeRotationSchedule($rotationSchedule);

        $uuid      = generate_uuid();
        $encrypted = $this->encryptionService->encrypt($value, $uuid);
        $now       = now()->format('Y-m-d H:i:s');

        Database::getInstance()->insert('secrets', [
            'uuid'             => $uuid,
            'directory_id'     => (int) $dir->id,
            'organization_id'  => (int) $orgId,
            'name'             => $name,
            'type'             => $type,
            'encrypted_value'  => $encrypted->value,
            'nonce'            => $encrypted->nonce,
            'requires_approval' => $requiresApproval ? 1 : 0,
            'rotation_integration_id' => $rotationIntegrationId !== null ? (int) $rotationIntegrationId : null,
            'rotation_schedule' => $rotationSchedule,
            'version'          => 1,
            'created_by'       => (int) $userId,
            'owner_user_id'    => (int) $userId,
            'default_read_access' => $defaultReadAccess,
            'default_write_access' => $defaultWriteAccess,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $secret = Secret::findByUuid($uuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.failed_load_created'));

        $this->getAuditService()->record(
            action: 'secret.create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'secret',
            resourceId: $secret->id,
            resourceUuid: $secret->uuid,
            details: ['type' => $type],
        );

        return $secret;
    }

    /**
     * Создать секрет типа `template` и сразу сгенерировать значение по шаблону.
     *
     * @param array<string, mixed> $overrides
     */
    public function createFromTemplate(
        string $orgId,
        string $dirUuid,
        string $name,
        string $templateUuid,
        string $userId,
        array $overrides = [],
        ?string $rotationSchedule = null,
        ?string $providedValue = null,
        string $defaultReadAccess = 'inherit',
        string $defaultWriteAccess = 'inherit',
        bool $requiresApproval = false,
    ): Secret {
        if ($rotationSchedule !== null && \trim($rotationSchedule) !== '') {
            throw new \InvalidArgumentException(__('ui.backend.secret.template_rotation_not_supported'));
        }

        $template = Template::findByUuid($templateUuid);
        if ($template === null) {
            throw new \RuntimeException(__('ui.backend.secret.template_not_found'));
        }
        if ($template->organizationId !== null && $template->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.secret.template_wrong_org'));
        }

        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.secret.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.secret.name_too_long'));
        }

        $defaultReadAccess = $this->normalizeAccessPolicy($defaultReadAccess);
        $defaultWriteAccess = $this->normalizeAccessPolicy($defaultWriteAccess);

        $preview = $this->getTemplateService()->preview($templateUuid, $orgId, $overrides);
        $describedValue = $providedValue !== null
            ? $this->getTemplateService()->describeProvidedValue($templateUuid, $providedValue, $orgId, $preview['overrides'])
            : $preview;
        $value = $describedValue['value'];
        $overrides = $preview['overrides'];
        $rotationSchedule = $this->normalizeRotationSchedule($rotationSchedule);

        $dir = $this->findDirInOrg($dirUuid, $orgId);
        $this->assertCan('write', $userId, 'directory', $dir->id, $orgId);
        $this->assertNameUnique($dir->id, $name);

        $uuid      = generate_uuid();
        $encrypted = $this->encryptionService->encrypt($value, $uuid);
        $now       = now()->format('Y-m-d H:i:s');

        Database::getInstance()->insert('secrets', [
            'uuid'                  => $uuid,
            'directory_id'          => (int) $dir->id,
            'organization_id'       => (int) $orgId,
            'name'                  => $name,
            'type'                  => 'template',
            'encrypted_value'       => $encrypted->value,
            'nonce'                 => $encrypted->nonce,
            'template_id'           => (int) $template->id,
            'requires_approval'     => $requiresApproval ? 1 : 0,
            'rotation_schedule'     => $rotationSchedule,
            'rotation_integration_id' => null,
            'version'               => 1,
            'created_by'            => (int) $userId,
            'owner_user_id'         => (int) $userId,
            'default_read_access'   => $defaultReadAccess,
            'default_write_access'  => $defaultWriteAccess,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        $secret = Secret::findByUuid($uuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.failed_load_created'));

        $this->storeTemplateOverrides($secret->id, $overrides);

        $this->getAuditService()->record(
            action: 'secret.create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'secret',
            resourceId: $secret->id,
            resourceUuid: $secret->uuid,
            details: ['type' => 'template', 'template_uuid' => $templateUuid],
        );

        return $secret;
    }

    /**
     * @param array<string, mixed> $rotationInput
     * @param array<string, mixed> $outputs
     */
    public function createProvisionedDynamicSecret(
        string $orgId,
        string $dirUuid,
        string $name,
        string $rotationIntegrationUuid,
        ?string $rotationSchedule,
        array $rotationInput,
        array $outputs,
        string $primaryField,
        string $userId,
        ?string $secretUuid = null,
        string $defaultReadAccess = 'inherit',
        string $defaultWriteAccess = 'inherit',
        bool $requiresApproval = false,
    ): Secret {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.secret.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.secret.name_too_long'));
        }

        $defaultReadAccess = $this->normalizeAccessPolicy($defaultReadAccess);
        $defaultWriteAccess = $this->normalizeAccessPolicy($defaultWriteAccess);

        $rotationSchedule = $this->normalizeRotationSchedule($rotationSchedule);
        $primaryField = \trim($primaryField);
        if ($primaryField === '') {
            throw new \RuntimeException(__('ui.backend.secret.dynamic_primary_field_missing'));
        }

        $primaryValue = $outputs[$primaryField] ?? null;
        if (!\is_string($primaryValue) || $primaryValue === '') {
            throw new \RuntimeException(__('ui.backend.secret.dynamic_primary_value_missing', ['field' => $primaryField]));
        }

        $dir = $this->findDirInOrg($dirUuid, $orgId);
        $this->assertCan('write', $userId, 'directory', $dir->id, $orgId);
        $this->assertNameUnique($dir->id, $name);
        $rotationIntegrationId = $this->resolveRotationIntegrationId($rotationIntegrationUuid, $orgId)
            ?? throw new \RuntimeException(__('ui.backend.secret.rotation_integration_not_found'));

        $uuid = $secretUuid !== null && \trim($secretUuid) !== '' ? \trim($secretUuid) : generate_uuid();
        $encrypted = $this->encryptionService->encrypt($primaryValue, $uuid);
        $now = now()->format('Y-m-d H:i:s');

        Database::getInstance()->insert('secrets', [
            'uuid'                    => $uuid,
            'directory_id'            => (int) $dir->id,
            'organization_id'         => (int) $orgId,
            'name'                    => $name,
            'type'                    => 'dynamic',
            'encrypted_value'         => $encrypted->value,
            'nonce'                   => $encrypted->nonce,
            'requires_approval'       => $requiresApproval ? 1 : 0,
            'rotation_integration_id' => (int) $rotationIntegrationId,
            'rotation_schedule'       => $rotationSchedule,
            'version'                 => 1,
            'created_by'              => (int) $userId,
            'owner_user_id'           => (int) $userId,
            'default_read_access'     => $defaultReadAccess,
            'default_write_access'    => $defaultWriteAccess,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        $secret = Secret::findByUuid($uuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.failed_load_created'));

        $this->storeDynamicRotationState($secret->id, $rotationInput, $outputs, $primaryField);

        $this->getAuditService()->record(
            action: 'secret.create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'secret',
            resourceId: $secret->id,
            resourceUuid: $secret->uuid,
            details: ['type' => 'dynamic'],
        );

        return $secret;
    }

    // ------------------------------------------------------------------ //
    //  Чтение                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Список секретов в каталоге (без расшифровки значений).
     *
     * @return Secret[]
     * @throws AuthException     если нет прав (требуется reader+)
     * @throws \RuntimeException если каталог не найден
     */
    public function listInDirectory(string $dirUuid, string $orgId, string $userId): array
    {
        $dir = $this->findDirInOrg($dirUuid, $orgId);
        $this->assertCan('read', $userId, 'directory', $dir->id, $orgId);
        return Secret::findByDirId($dir->id);
    }

    public function getMeta(string $secretUuid, string $orgId, string $userId): Secret
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('read', $userId, 'secret', $secret->id, $orgId);

        return $secret;
    }

    /**
     * Получить секрет с расшифрованным значением.
     *
     * Если у секрета requires_approval=true и пользователь ниже editor,
     * бросается AuthException с кодом 403 и инструкцией использовать approval workflow.
     *
     * @return array{secret: Secret, value: string}
     * @throws AuthException     если нет прав (требуется reader+) или требуется одобрение
     * @throws \RuntimeException если не найден
     */
    public function get(string $secretUuid, string $orgId, string $userId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('read', $userId, 'secret', $secret->id, $orgId);

        // requires_approval: пользователи ниже editor должны использовать ApprovalService::useToken()
        if ($secret->requiresApproval
            && !AuthContext::isApiKeyRequest()
            && $secret->ownerUserId !== $userId
            && !$this->organizationService->hasPermission($orgId, $userId, 'editor')
        ) {
            throw new AuthException(
                __('ui.backend.secret.requires_approval'),
                403
            );
        }

        if ($secret->requiresApproval && AuthContext::isApiKeyRequest()) {
            throw new AuthException(
                __('ui.backend.secret.requires_approval'),
                403
            );
        }

        $value = $this->encryptionService->decrypt(
            $secret->encryptedValue,
            $secret->nonce,
            $secret->uuid
        );

        $this->getAuditService()->record(
            action: 'secret.read',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'secret',
            resourceId: $secret->id,
            resourceUuid: $secret->uuid,
        );

        return ['secret' => $secret, 'value' => $value];
    }

    /** @return array<string, mixed> */
    public function getTemplateOverrides(string $secretUuid, string $orgId, string $userId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('read', $userId, 'secret', $secret->id, $orgId);

        return $this->loadTemplateOverrides($secret->id);
    }

    /** @return array<string, mixed> */
    public function getDynamicSecretView(string $secretUuid, string $orgId, string $userId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('read', $userId, 'secret', $secret->id, $orgId);

        if ($secret->type !== 'dynamic') {
            return ['input' => [], 'outputs' => [], 'primary_field' => null, 'service' => null];
        }

        $integration = $secret->rotationIntegrationId !== null
            ? OrganizationIntegration::findById($secret->rotationIntegrationId)
            : null;
        $service = $integration !== null ? RotationService::findById($integration->rotationServiceId) : null;

        return [
            'input' => $this->loadDynamicRotationInput($secret->id),
            'outputs' => $this->loadDynamicRotationOutputs($secret->id),
            'primary_field' => $this->loadDynamicRotationPrimaryField($secret->id),
            'service' => $service,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function previewTemplate(
        string $orgId,
        string $dirUuid,
        string $userId,
        string $templateUuid,
        array $overrides = [],
        ?string $providedValue = null,
        bool $normalizeProvidedValue = false,
    ): array {
        $dir = $this->findDirInOrg($dirUuid, $orgId);
        $this->assertCan('write', $userId, 'directory', $dir->id, $orgId);

        $preview = $providedValue !== null
            ? ($normalizeProvidedValue
                ? $this->getTemplateService()->describeUploadedValue($templateUuid, $providedValue, $orgId, $overrides)
                : $this->getTemplateService()->describeProvidedValue($templateUuid, $providedValue, $orgId, $overrides))
            : $this->getTemplateService()->preview($templateUuid, $orgId, $overrides);

        return $this->formatTemplatePreview($preview);
    }

    /**
     * @param array<string, mixed>|null $overrides
     * @return array<string, mixed>
     */
    public function regenerateFromTemplate(
        string $secretUuid,
        string $orgId,
        string $userId,
        ?array $overrides = null,
        ?string $providedValue = null,
    ): array {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('write', $userId, 'secret', $secret->id, $orgId);

        if ($secret->type !== 'template') {
            throw new \InvalidArgumentException(__('ui.backend.secret.template_regenerate_only'));
        }
        if ($secret->templateId === null) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.template_missing_id'));
        }

        $template = Template::findById($secret->templateId)
            ?? throw new \RuntimeException(__('ui.backend.secret.template_not_found'));
        $storedOverrides = $this->loadTemplateOverrides($secret->id);
        $preview = $this->getTemplateService()->preview(
            $template->uuid,
            $orgId,
            $overrides ?? $storedOverrides
        );
        $target = $providedValue !== null
            ? $this->getTemplateService()->describeProvidedValue($template->uuid, $providedValue, $orgId, $preview['overrides'])
            : $preview;
        $targetValue = $target['value'];
        $currentValue = $this->encryptionService->decrypt($secret->encryptedValue, $secret->nonce, $secret->uuid);

        if ($targetValue === $currentValue && $preview['overrides'] === $storedOverrides) {
            return \array_merge(
                ['secret' => $secret],
                $this->formatTemplatePreview(
                    $this->getTemplateService()->describeValue($template->uuid, $currentValue, $orgId, $storedOverrides)
                )
            );
        }

        $this->storeTemplateOverrides($secret->id, $preview['overrides']);
        $updated = $this->rotateValue(
            $secretUuid,
            $orgId,
            $targetValue,
            $userId,
            'manual',
            'secret.update',
            ['renamed' => false, 'rotated' => true, 'type' => 'template']
        );

        return \array_merge(
            ['secret' => $updated],
            $this->formatTemplatePreview($target)
        );
    }

    /**
     * История версий секрета (зашифрованные записи, без расшифровки).
     *
     * @return SecretVersion[]
     * @throws AuthException     если нет прав (требуется reader+)
     * @throws \RuntimeException если не найден
     */
    public function listVersions(string $secretUuid, string $orgId, string $userId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('read', $userId, 'secret', $secret->id, $orgId);
        return SecretVersion::findBySecretId($secret->id);
    }

    // ------------------------------------------------------------------ //
    //  Обновление                                                         //
    // ------------------------------------------------------------------ //

    /**
     * Обновить имя и/или значение секрета.
     * При смене значения предыдущая версия сохраняется в историю, version++.
     *
     * @throws AuthException             если нет прав (требуется editor+)
     * @throws \InvalidArgumentException при пустом имени
     * @throws \RuntimeException         если не найден или новое имя занято
     */
    public function update(
        string  $secretUuid,
        string  $orgId,
        string  $userId,
        ?string $newName  = null,
        ?string $newValue = null,
    ): Secret {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('write', $userId, 'secret', $secret->id, $orgId);
        $now    = now()->format('Y-m-d H:i:s');
        $data   = ['updated_at' => $now];

        if ($newName !== null) {
            $newName = \trim($newName);
            if ($newName === '') {
                throw new \InvalidArgumentException(__('ui.backend.secret.name_empty'));
            }
            if (\strlen($newName) > 255) {
                throw new \InvalidArgumentException(__('ui.backend.secret.name_too_long'));
            }
            // Проверить уникальность, исключая текущий секрет
            $this->assertNameUnique($secret->directoryId, $newName, $secret->id);
            $data['name'] = $newName;
        }

        if ($newValue !== null) {
            if ($secret->type === 'template') {
                throw new \InvalidArgumentException(__('ui.backend.secret.template_manual_value_not_supported'));
            }
            if ($secret->type === 'dynamic') {
                throw new \InvalidArgumentException(__('ui.backend.secret.dynamic_manual_value_not_supported'));
            }

            // Сохранить текущую версию в историю перед перезаписью
            $this->saveVersionHistory($secret, $userId);

            $encrypted       = $this->encryptionService->encrypt($newValue, $secret->uuid);
            $data['encrypted_value'] = $encrypted->value;
            $data['nonce']           = $encrypted->nonce;
            $data['version']         = $secret->version + 1;
        }

        $secret->update($data);

        $updated = Secret::findByUuid($secretUuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.failed_reload_after_update'));

        $this->getAuditService()->record(
            action: 'secret.update',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'secret',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
            details: ['renamed' => $newName !== null, 'rotated' => $newValue !== null],
        );

        return $updated;
    }

    // ------------------------------------------------------------------ //
    //  Удаление                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Мягкое удаление секрета (устанавливает deleted_at).
     *
     * @throws AuthException     если нет прав (требуется editor+)
     * @throws \RuntimeException если не найден
     */
    public function delete(string $secretUuid, string $orgId, string $userId): void
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('write', $userId, 'secret', $secret->id, $orgId);
        $secret->update(['deleted_at' => now()->format('Y-m-d H:i:s')]);

        $this->getAuditService()->record(
            action: 'secret.delete',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'secret',
            resourceId: $secret->id,
            resourceUuid: $secret->uuid,
        );
    }

    public function transferOwnership(string $secretUuid, string $orgId, string $newOwnerId, string $requesterId): Secret
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertOwnedBy($secret, $requesterId, 'ui.backend.secret.owner_transfer_required');

        if ($this->organizationService->getMemberRole($orgId, $newOwnerId) === null) {
            throw new \RuntimeException(__('ui.backend.secret.new_owner_must_be_member'));
        }

        if ($secret->ownerUserId === $newOwnerId) {
            return $secret;
        }

        $secret->update([
            'owner_user_id' => (int) $newOwnerId,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $updated = Secret::findByUuid($secretUuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.not_found'));

        $this->getAuditService()->record(
            action: 'secret.transfer_ownership',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'secret',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
            details: ['new_owner_id' => $newOwnerId],
        );

        return $updated;
    }

    /** @return \Passway\Models\UserPermission[] */
    public function listAcl(string $secretUuid, string $orgId, string $requesterId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertOwnedBy($secret, $requesterId, 'ui.backend.secret.owner_acl_required');

        return $this->permissionService->listForResource('secret', $secret->id);
    }

    /**
     * @param array<int, array{subject_type:string,subject_id:string,read:?string,write:?string}> $rules
     * @return \Passway\Models\UserPermission[]
     */
    public function replaceAcl(string $secretUuid, string $orgId, string $requesterId, array $rules): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertOwnedBy($secret, $requesterId, 'ui.backend.secret.owner_acl_required');

        return $this->permissionService->replaceForResource('secret', $secret->id, $rules, $requesterId);
    }

    /** @return array{default_read_access:string,default_write_access:string} */
    public function getAccessPolicy(string $secretUuid, string $orgId, string $requesterId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertOwnedBy($secret, $requesterId, 'ui.backend.secret.owner_acl_required');

        return [
            'default_read_access' => $secret->defaultReadAccess,
            'default_write_access' => $secret->defaultWriteAccess,
        ];
    }

    /** @return array{default_read_access:string,default_write_access:string} */
    public function updateAccessPolicy(
        string $secretUuid,
        string $orgId,
        string $requesterId,
        string $defaultReadAccess,
        string $defaultWriteAccess,
    ): array {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertOwnedBy($secret, $requesterId, 'ui.backend.secret.owner_acl_required');
        $defaultReadAccess = $this->normalizeAccessPolicy($defaultReadAccess);
        $defaultWriteAccess = $this->normalizeAccessPolicy($defaultWriteAccess);

        $secret->update([
            'default_read_access' => $defaultReadAccess,
            'default_write_access' => $defaultWriteAccess,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $updated = Secret::findByUuid($secretUuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.not_found'));

        return [
            'default_read_access' => $updated->defaultReadAccess,
            'default_write_access' => $updated->defaultWriteAccess,
        ];
    }

    /** @return array{requires_approval:bool} */
    public function getApprovalSettings(string $secretUuid, string $orgId, string $requesterId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertOwnedBy($secret, $requesterId, 'ui.backend.secret.owner_acl_required');

        return [
            'requires_approval' => $secret->requiresApproval,
        ];
    }

    /** @return array{requires_approval:bool} */
    public function updateApprovalSettings(string $secretUuid, string $orgId, string $requesterId, bool $requiresApproval): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertOwnedBy($secret, $requesterId, 'ui.backend.secret.owner_acl_required');

        $secret->update([
            'requires_approval' => $requiresApproval ? 1 : 0,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $updated = Secret::findByUuid($secretUuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.not_found'));

        return [
            'requires_approval' => $updated->requiresApproval,
        ];
    }

    /**
     * Внутреннее обновление значения секрета для автоматической или внешней ротации.
     */
    public function rotateValue(
        string $secretUuid,
        string $orgId,
        string $newValue,
        ?string $rotatedBy = null,
        string $rotationType = 'scheduled',
        string $auditAction = 'rotation.secret_rotated',
        array $auditDetails = [],
    ): Secret {
        if (!\in_array($rotationType, ['scheduled', 'api', 'manual'], true)) {
            throw new \InvalidArgumentException(__('ui.backend.secret.invalid_rotation_type'));
        }

        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->saveVersionHistory($secret, $rotatedBy, $rotationType, 'success');

        $encrypted = $this->encryptionService->encrypt($newValue, $secret->uuid);
        $now       = now()->format('Y-m-d H:i:s');

        $secret->update([
            'encrypted_value' => $encrypted->value,
            'nonce'           => $encrypted->nonce,
            'version'         => $secret->version + 1,
            'last_rotated_at' => $now,
            'updated_at'      => $now,
        ]);

        $updated = Secret::findByUuid($secretUuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.failed_reload_rotated'));

        $this->getAuditService()->record(
            action: $auditAction,
            organizationId: $orgId,
            userId: $rotatedBy,
            resourceType: 'secret',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
            details: $auditDetails !== [] ? $auditDetails : ['rotation_type' => $rotationType],
        );

        return $updated;
    }

    /**
     * Обновить параметры ротации уже существующего секрета.
     */
    public function configureRotation(
        string $secretUuid,
        string $orgId,
        string $userId,
        ?string $rotationIntegrationUuid,
        ?string $rotationSchedule,
    ): Secret {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('write', $userId, 'secret', $secret->id, $orgId);

        if ($secret->type !== 'dynamic') {
            throw new \InvalidArgumentException(__('ui.backend.secret.rotation_supported_for_dynamic_only'));
        }

        $secret->update([
            'rotation_integration_id' => ($id = $this->resolveRotationIntegrationId($rotationIntegrationUuid, $orgId)) !== null
                ? (int) $id
                : null,
            'rotation_schedule' => $this->normalizeRotationSchedule($rotationSchedule),
            'updated_at'        => now()->format('Y-m-d H:i:s'),
        ]);

        return Secret::findByUuid($secretUuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.failed_reload_after_rotation_config'));
    }

    /** @return array{secret: Secret, input: array<string, mixed>, outputs: array<string, mixed>, primary_field: string} */
    public function getDynamicRotationState(string $secretUuid, string $orgId): array
    {
        ['secret' => $secret, 'value' => $value] = $this->getForRotation($secretUuid, $orgId);

        if ($secret->type !== 'dynamic') {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.dynamic_only_external'));
        }

        $primaryField = $this->loadDynamicRotationPrimaryField($secret->id);
        if ($primaryField === null || $primaryField === '') {
            throw new \RuntimeException(__('ui.backend.secret.dynamic_primary_field_missing'));
        }

        $outputs = $this->loadDynamicRotationOutputs($secret->id);
        $outputs[$primaryField] = $value;

        return [
            'secret' => $secret,
            'input' => $this->loadDynamicRotationInput($secret->id),
            'outputs' => $outputs,
            'primary_field' => $primaryField,
        ];
    }

    /**
     * @param array<string, mixed> $outputs
     */
    public function updateDynamicRotationOutputs(string $secretUuid, string $orgId, array $outputs, string $primaryField): void
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->storeDynamicRotationOutputs($secret->id, $outputs, $primaryField);
    }

    /** @return array{secret: Secret, value: string} */
    public function getForRotation(string $secretUuid, string $orgId): array
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $value = $this->encryptionService->decrypt($secret->encryptedValue, $secret->nonce, $secret->uuid);

        return ['secret' => $secret, 'value' => $value];
    }

    public function recordRotationFailure(
        string $secretUuid,
        string $orgId,
        string $rotationType,
        string $errorMessage,
    ): void {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->saveVersionHistory($secret, null, $rotationType, 'failed', $errorMessage);

        $this->getAuditService()->record(
            action: 'rotation.secret_rotate_failed',
            organizationId: $orgId,
            resourceType: 'secret',
            resourceId: $secret->id,
            resourceUuid: $secret->uuid,
            details: ['rotation_type' => $rotationType, 'error' => $errorMessage],
            success: false,
        );
    }

    public function assertCanRotate(string $secretUuid, string $orgId, string $userId): void
    {
        $secret = $this->findSecretInOrg($secretUuid, $orgId);
        $this->assertCan('write', $userId, 'secret', $secret->id, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  Вспомогательные                                                    //
    // ------------------------------------------------------------------ //

    /**
     * @throws \RuntimeException если каталог не найден или принадлежит другой организации
     */
    private function findDirInOrg(string $dirUuid, string $orgId): Directory
    {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }
        return $dir;
    }

    /**
     * @throws \RuntimeException если секрет не найден или принадлежит другой организации
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
     * @param string|null $excludeId ID секрета, который нужно исключить из проверки (при обновлении).
     * @throws \RuntimeException если имя уже занято в каталоге
     */
    private function assertNameUnique(string $dirId, string $name, ?string $excludeId = null): void
    {
        $sql    = 'SELECT COUNT(*) FROM secrets WHERE directory_id = ? AND name = ? AND deleted_at IS NULL';
        $params = [(int) $dirId, $name];

        if ($excludeId !== null) {
            $sql   .= ' AND id != ?';
            $params[] = (int) $excludeId;
        }

        $count = (int) Database::getInstance()->fetchColumn($sql, $params);
        if ($count > 0) {
            throw new \RuntimeException(__('ui.backend.secret.duplicate_name'));
        }
    }

    /**
     * Сохранить текущую версию секрета в secret_rotation_history.
     * Автоматически удаляет записи сверх лимита MAX_VERSIONS (самые старые).
     */
    private function saveVersionHistory(
        Secret $secret,
        ?string $userId,
        string $rotationType = 'manual',
        string $status = 'success',
        ?string $errorMessage = null,
    ): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $db  = Database::getInstance();

        $db->insert('secret_rotation_history', [
            'secret_id'       => (int) $secret->id,
            'encrypted_value' => $secret->encryptedValue,
            'nonce'           => $secret->nonce,
            'version'         => $secret->version,
            'rotated_by'      => $userId !== null ? (int) $userId : null,
            'rotation_type'   => $rotationType,
            'status'          => $status,
            'error_message'   => $errorMessage,
            'created_at'      => $now,
        ]);

        // Удалить самые старые версии, оставив не более MAX_VERSIONS
        $count = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM secret_rotation_history WHERE secret_id = ?',
            [(int) $secret->id]
        );

        if ($count > self::MAX_VERSIONS) {
            $toDelete = $count - self::MAX_VERSIONS;
            $db->query(
                'DELETE FROM secret_rotation_history WHERE id IN (
                    SELECT id FROM secret_rotation_history
                    WHERE secret_id = ?
                    ORDER BY version ASC
                    LIMIT ?
                )',
                [(int) $secret->id, $toDelete]
            );
        }
    }

    /**
     * @throws AuthException (code 403)
     */
    private function assertCan(
        string $permission,
        string $userId,
        string $resourceType,
        string $resourceId,
        string $orgId,
    ): void {
        if ($resourceType === 'secret' && $this->isSecretOwnerById($resourceId, $userId)) {
            return;
        }

        if (!$this->permissionService->can($permission, $userId, $resourceType, $resourceId, $orgId)) {
            throw new AuthException(
                __('ui.backend.secret.access_permission_required', ['permission' => $permission]),
                403
            );
        }
    }

    private function isSecretOwnerById(string $secretId, string $userId): bool
    {
        $secret = Secret::findById($secretId);
        return $secret !== null && $secret->ownerUserId === $userId;
    }

    /**
     * @throws AuthException (code 403)
     */
    private function assertOwnedBy(Secret $secret, string $userId, string $messageKey): void
    {
        if ($secret->ownerUserId !== $userId) {
            throw new AuthException(__( $messageKey), 403);
        }
    }

    private function getTemplateService(): TemplateService
    {
        return $this->templateService ?? new TemplateService();
    }

    /**
     * @param array{
     *   value:string,
     *   display_value:string,
     *   extra_fields:array<int, array{key:string, label:string, value:string}>,
     *   parameter_schema:array<int, array<string, mixed>>,
     *   overrides:array<string, mixed>,
     *   template:Template
     * } $preview
     * @return array<string, mixed>
     */
    private function formatTemplatePreview(array $preview): array
    {
        /** @var Template $template */
        $template = $preview['template'];

        return [
            'template_uuid' => $template->uuid,
            'template_name' => $template->displayName(),
            'template_type' => $template->type,
            'value' => $preview['value'],
            'display_value' => $preview['display_value'],
            'extra_fields' => $preview['extra_fields'],
            'parameter_schema' => $preview['parameter_schema'],
            'template_overrides' => $preview['overrides'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function storeTemplateOverrides(string $secretId, array $overrides): void
    {
        $payload = $this->encodeJsonObject($overrides);
        SecretMetadata::upsert($secretId, self::TEMPLATE_OVERRIDES_METADATA_KEY, $payload);
    }

    /** @param array<string, mixed> $input */
    private function storeDynamicRotationInput(string $secretId, array $input): void
    {
        SecretMetadata::upsert(
            $secretId,
            self::DYNAMIC_ROTATION_INPUT_METADATA_KEY,
            $this->encodeJsonObject($input)
        );
    }

    /**
     * @param array<string, mixed> $outputs
     */
    private function storeDynamicRotationOutputs(string $secretId, array $outputs, string $primaryField): void
    {
        $primaryField = \trim($primaryField);
        if ($primaryField === '') {
            throw new \RuntimeException(__('ui.backend.secret.dynamic_primary_field_missing'));
        }

        $publicOutputs = $outputs;
        unset($publicOutputs[$primaryField]);

        SecretMetadata::upsert(
            $secretId,
            self::DYNAMIC_ROTATION_OUTPUTS_METADATA_KEY,
            $this->encodeJsonObject($publicOutputs)
        );
        SecretMetadata::upsert(
            $secretId,
            self::DYNAMIC_ROTATION_PRIMARY_FIELD_METADATA_KEY,
            $primaryField
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $outputs
     */
    private function storeDynamicRotationState(string $secretId, array $input, array $outputs, string $primaryField): void
    {
        $this->storeDynamicRotationInput($secretId, $input);
        $this->storeDynamicRotationOutputs($secretId, $outputs, $primaryField);
    }

    /** @return array<string, mixed> */
    private function loadTemplateOverrides(string $secretId): array
    {
        $metadata = SecretMetadata::findBySecretIdAndKey($secretId, self::TEMPLATE_OVERRIDES_METADATA_KEY);
        if ($metadata === null || $metadata->value === null || \trim($metadata->value) === '') {
            return [];
        }

        $decoded = \json_decode($metadata->value, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function loadDynamicRotationInput(string $secretId): array
    {
        return $this->loadJsonMetadata(self::DYNAMIC_ROTATION_INPUT_METADATA_KEY, $secretId);
    }

    /** @return array<string, mixed> */
    private function loadDynamicRotationOutputs(string $secretId): array
    {
        return $this->loadJsonMetadata(self::DYNAMIC_ROTATION_OUTPUTS_METADATA_KEY, $secretId);
    }

    private function loadDynamicRotationPrimaryField(string $secretId): ?string
    {
        $metadata = SecretMetadata::findBySecretIdAndKey($secretId, self::DYNAMIC_ROTATION_PRIMARY_FIELD_METADATA_KEY);
        if ($metadata === null || $metadata->value === null) {
            return null;
        }

        $value = \trim($metadata->value);
        return $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function loadJsonMetadata(string $key, string $secretId): array
    {
        $metadata = SecretMetadata::findBySecretIdAndKey($secretId, $key);
        if ($metadata === null || $metadata->value === null || \trim($metadata->value) === '') {
            return [];
        }

        $decoded = \json_decode($metadata->value, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function encodeJsonObject(array $payload): string
    {
        $encoded = \json_encode($payload, \JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException(__('ui.backend.secret.metadata_encode_failed'));
        }

        return $encoded;
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService());
    }

    private function resolveRotationIntegrationId(?string $integrationUuid, string $orgId): ?string
    {
        if ($integrationUuid === null || \trim($integrationUuid) === '') {
            return null;
        }

        $integration = OrganizationIntegration::findByUuid(\trim($integrationUuid));
        if ($integration === null || $integration->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.secret.rotation_integration_not_found'));
        }
        if (!$integration->isActive) {
            throw new \RuntimeException(__('ui.backend.secret.rotation_integration_inactive'));
        }

        return $integration->id;
    }

    private function normalizeRotationSchedule(?string $rotationSchedule): ?string
    {
        if ($rotationSchedule === null) {
            return null;
        }

        $rotationSchedule = \trim($rotationSchedule);
        if ($rotationSchedule === '') {
            return null;
        }

        $parts = \preg_split('/\s+/', $rotationSchedule) ?: [];
        if (\count($parts) !== 5) {
            throw new \InvalidArgumentException(__('ui.backend.secret.rotation_schedule_invalid'));
        }

        return \implode(' ', $parts);
    }

    private function normalizeAccessPolicy(string $value): string
    {
        $value = \trim($value);
        if (!\in_array($value, PermissionService::VALID_ACCESS_POLICIES, true)) {
            throw new \InvalidArgumentException(__('ui.backend.permission.invalid_access_policy'));
        }

        return $value;
    }
}
