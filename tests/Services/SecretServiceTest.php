<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Secret;
use Passway\Models\SecretVersion;
use Passway\Services\ApiKeyService;
use Passway\Services\DirectoryService;
use Passway\Services\EncryptionService;
use Passway\Services\GroupService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Services\SecretService;
use Passway\Services\TemplateService;
use Passway\Tests\DatabaseTestCase;

/**
 * Тесты SecretService: создание, список, чтение, обновление, удаление, история.
 *
 * @requires extension pdo_sqlite
 * @requires extension sodium
 */
final class SecretServiceTest extends DatabaseTestCase
{
    private SecretService      $svc;
    private OrganizationService $orgSvc;
    private DirectoryService   $dirSvc;
    private PermissionService  $permSvc;

    public static function setUpBeforeClass(): void
    {
        // Установить MASTER_KEY до инициализации БД
        $_ENV['MASTER_KEY'] = \bin2hex(\random_bytes(32));
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc = new OrganizationService();
        $this->permSvc = new PermissionService($this->orgSvc, new GroupService($this->orgSvc));
        $this->dirSvc = new DirectoryService($this->orgSvc, $this->permSvc);
        $this->svc    = new SecretService($this->orgSvc, new EncryptionService(), $this->permSvc);

        // team-режим — нет ограничений по числу организаций
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  create()                                                           //
    // ------------------------------------------------------------------ //

    public function test_create_static_secret(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Secrets', $owner->id);

        $secret = $this->svc->create($org->id, $dir->uuid, 'DB Password', 'static', 's3cr3t', $owner->id);

        $this->assertInstanceOf(Secret::class, $secret);
        $this->assertSame('DB Password', $secret->name);
        $this->assertSame('static', $secret->type);
        $this->assertSame(1, $secret->version);
        $this->assertSame($dir->id, $secret->directoryId);
        $this->assertSame($org->id, $secret->organizationId);
        $this->assertNotEmpty($secret->encryptedValue);
        $this->assertNotEmpty($secret->nonce);
        $this->assertFalse($secret->requiresApproval);
    }

    public function test_create_template_and_dynamic_types(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $tpl = $this->svc->create($org->id, $dir->uuid, 'Template Secret', 'template', 'val', $owner->id);
        $dyn = $this->svc->create($org->id, $dir->uuid, 'Dynamic Secret', 'dynamic', 'val', $owner->id);

        $this->assertSame('template', $tpl->type);
        $this->assertSame('dynamic', $dyn->type);
    }

    public function test_create_throws_for_empty_name(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($org->id, $dir->uuid, '   ', 'static', 'val', $owner->id);
    }

    public function test_create_throws_for_invalid_type(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($org->id, $dir->uuid, 'Secret', 'unknown_type', 'val', $owner->id);
    }

    public function test_create_throws_for_duplicate_name_in_same_dir(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->svc->create($org->id, $dir->uuid, 'API Key', 'static', 'v1', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->create($org->id, $dir->uuid, 'API Key', 'static', 'v2', $owner->id);
    }

    public function test_create_allows_same_name_in_different_dirs(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir1  = $this->dirSvc->create($org->id, null, 'Dir1', $owner->id);
        $dir2  = $this->dirSvc->create($org->id, null, 'Dir2', $owner->id);

        $s1 = $this->svc->create($org->id, $dir1->uuid, 'Token', 'static', 'v1', $owner->id);
        $s2 = $this->svc->create($org->id, $dir2->uuid, 'Token', 'static', 'v2', $owner->id);

        $this->assertNotSame($s1->uuid, $s2->uuid);
    }

    public function test_create_allows_same_name_after_delete(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $s1 = $this->svc->create($org->id, $dir->uuid, 'Token', 'static', 'old', $owner->id);
        $this->svc->delete($s1->uuid, $org->id, $owner->id);

        // После удаления имя освобождается
        $s2 = $this->svc->create($org->id, $dir->uuid, 'Token', 'static', 'new', $owner->id);
        $this->assertSame('Token', $s2->name);
    }

    public function test_create_throws_if_directory_not_found(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->create($org->id, 'non-existent-uuid', 'Secret', 'static', 'val', $owner->id);
    }

    public function test_create_throws_if_directory_belongs_to_another_org(): void
    {
        $owner = $this->createTestUser();
        $org1  = $this->orgSvc->create('Org1', $owner->id);
        $org2  = $this->orgSvc->create('Org2', $owner->id);
        $dir   = $this->dirSvc->create($org1->id, null, 'Dir', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->create($org2->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);
    }

    public function test_create_throws_for_observer(): void
    {
        $owner    = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $observer->id);
    }

    public function test_create_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('stranger@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $stranger->id);
    }

    // ------------------------------------------------------------------ //
    //  listInDirectory()                                                   //
    // ------------------------------------------------------------------ //

    public function test_list_in_directory_returns_secrets_sorted_by_name(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->svc->create($org->id, $dir->uuid, 'Z Secret', 'static', 'v', $owner->id);
        $this->svc->create($org->id, $dir->uuid, 'A Secret', 'static', 'v', $owner->id);

        $secrets = $this->svc->listInDirectory($dir->uuid, $org->id, $owner->id);

        $this->assertCount(2, $secrets);
        $this->assertSame('A Secret', $secrets[0]->name);
        $this->assertSame('Z Secret', $secrets[1]->name);
    }

    public function test_list_in_directory_excludes_deleted(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'v', $owner->id);
        $this->svc->delete($secret->uuid, $org->id, $owner->id);

        $secrets = $this->svc->listInDirectory($dir->uuid, $org->id, $owner->id);
        $this->assertCount(0, $secrets);
    }

    public function test_list_in_directory_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('s@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->listInDirectory($dir->uuid, $org->id, $stranger->id);
    }

    // ------------------------------------------------------------------ //
    //  get()                                                              //
    // ------------------------------------------------------------------ //

    public function test_get_returns_decrypted_value(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Token', 'static', 'my-secret-value', $owner->id);

        ['secret' => $s, 'value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame($secret->uuid, $s->uuid);
        $this->assertSame('my-secret-value', $value);
    }

    public function test_get_observer_can_read(): void
    {
        $owner    = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Token', 'static', 'value', $owner->id);

        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $observer->id);

        $this->assertSame('value', $value);
    }

    public function test_get_throws_if_secret_belongs_to_another_org(): void
    {
        $owner = $this->createTestUser();
        $org1  = $this->orgSvc->create('Org1', $owner->id);
        $org2  = $this->orgSvc->create('Org2', $owner->id);
        $dir   = $this->dirSvc->create($org1->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org1->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->get($secret->uuid, $org2->id, $owner->id);
    }

    public function test_get_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('s@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret   = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->get($secret->uuid, $org->id, $stranger->id);
    }

    public function test_get_denied_when_directory_read_denied_for_reader(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $this->permSvc->grant('user', $reader->id, 'directory', $dir->id, 'read', true, null, $owner->id, $org->id);

        $this->expectException(AuthException::class);
        $this->svc->get($secret->uuid, $org->id, $reader->id);
    }

    public function test_get_allowed_by_secret_rule_when_directory_read_denied(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $this->permSvc->grant('user', $reader->id, 'directory', $dir->id, 'read', true, null, $owner->id, $org->id);
        $this->permSvc->grant('user', $reader->id, 'secret', $secret->id, 'read', false, null, $owner->id, $org->id);

        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $reader->id);

        $this->assertSame('value', $value);
    }

    public function test_update_allowed_by_secret_write_when_directory_write_denied(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'old', $owner->id);

        $this->permSvc->grant('user', $reader->id, 'directory', $dir->id, 'write', true, null, $owner->id, $org->id);
        $this->permSvc->grant('user', $reader->id, 'secret', $secret->id, 'write', false, null, $owner->id, $org->id);

        $this->svc->update($secret->uuid, $org->id, $reader->id, null, 'new');

        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);
        $this->assertSame('new', $value);
    }

    public function test_secret_owner_can_read_even_with_explicit_secret_deny(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $this->permSvc->grant('user', $owner->id, 'secret', $secret->id, 'read', true, null, $owner->id, $org->id);

        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame('value', $value);
    }

    public function test_secret_owner_can_write_even_with_explicit_secret_deny(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'old', $owner->id);

        $this->permSvc->grant('user', $owner->id, 'secret', $secret->id, 'write', true, null, $owner->id, $org->id);

        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'new');

        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);
        $this->assertSame('new', $value);
    }

    public function test_transfer_ownership_updates_secret_owner(): void
    {
        $owner = $this->createTestUser();
        $newOwner = $this->createTestUser('new-owner@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $newOwner->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $updated = $this->svc->transferOwnership($secret->uuid, $org->id, $newOwner->id, $owner->id);

        $this->assertSame($newOwner->id, $updated->ownerUserId);
    }

    public function test_transfer_ownership_requires_secret_owner(): void
    {
        $owner = $this->createTestUser();
        $editor = $this->createTestUser('editor@example.com');
        $newOwner = $this->createTestUser('new-owner@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);
        $this->orgSvc->addMember($org->id, $newOwner->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->transferOwnership($secret->uuid, $org->id, $newOwner->id, $editor->id);
    }

    public function test_list_acl_requires_secret_owner(): void
    {
        $owner = $this->createTestUser();
        $editor = $this->createTestUser('editor@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->listAcl($secret->uuid, $org->id, $editor->id);
    }

    public function test_replace_acl_stores_exact_secret_rules(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $rules = $this->svc->replaceAcl($secret->uuid, $org->id, $owner->id, [[
            'subject_type' => 'user',
            'subject_id' => $reader->id,
            'read' => 'allow',
            'write' => 'deny',
        ]]);

        $this->assertCount(2, $rules);
        $stored = $this->svc->listAcl($secret->uuid, $org->id, $owner->id);
        $this->assertCount(2, $stored);
    }

    public function test_replace_acl_stores_api_key_subject_rules(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);
        $apiKeyService = new ApiKeyService($this->orgSvc);
        ['key' => $apiKey] = $apiKeyService->create('Deploy key', $org->id, $owner->id);

        $rules = $this->svc->replaceAcl($secret->uuid, $org->id, $owner->id, [[
            'subject_type' => 'api_key',
            'subject_id' => $apiKey->id,
            'read' => 'deny',
            'write' => 'allow',
        ]]);

        $this->assertCount(2, $rules);
        $this->assertSame(['api_key'], array_values(array_unique(array_map(
            static fn($rule) => $rule->subjectType,
            $rules,
        ))));
    }

    public function test_replace_acl_stores_group_subject_rules(): void
    {
        $owner = $this->createTestUser();
        $member = $this->createTestUser('group-member@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);
        $groupService = new GroupService($this->orgSvc);
        $group = $groupService->create($org->id, 'Readers', null, $owner->id);
        $groupService->addMember($group->uuid, $member->id, $owner->id, $org->id);

        $rules = $this->svc->replaceAcl($secret->uuid, $org->id, $owner->id, [[
            'subject_type' => 'group',
            'subject_id' => $group->id,
            'read' => 'allow',
            'write' => 'deny',
        ]]);

        $this->assertCount(2, $rules);
        $this->assertSame(['group'], array_values(array_unique(array_map(
            static fn($rule) => $rule->subjectType,
            $rules,
        ))));
    }

    public function test_update_access_policy_requires_secret_owner(): void
    {
        $owner = $this->createTestUser();
        $editor = $this->createTestUser('editor-policy@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->updateAccessPolicy($secret->uuid, $org->id, $editor->id, 'deny', 'deny');
    }

    public function test_update_access_policy_persists_values(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $policy = $this->svc->updateAccessPolicy($secret->uuid, $org->id, $owner->id, 'deny', 'allow');

        $this->assertSame('deny', $policy['default_read_access']);
        $this->assertSame('allow', $policy['default_write_access']);
        $stored = $this->svc->getAccessPolicy($secret->uuid, $org->id, $owner->id);
        $this->assertSame('deny', $stored['default_read_access']);
        $this->assertSame('allow', $stored['default_write_access']);
    }

    // ------------------------------------------------------------------ //
    //  update()                                                           //
    // ------------------------------------------------------------------ //

    public function test_update_value_saves_version_history(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'old-value', $owner->id);

        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'new-value');

        $versions = SecretVersion::findBySecretId($secret->id);
        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]->version);
        $this->assertSame('manual', $versions[0]->rotationType);
        $this->assertSame('success', $versions[0]->status);
    }

    public function test_update_value_bumps_version(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'v1', $owner->id);

        $updated = $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'v2');

        $this->assertSame(2, $updated->version);
    }

    public function test_update_value_stores_new_encrypted_value(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'old', $owner->id);

        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'new-password');

        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);
        $this->assertSame('new-password', $value);
    }

    public function test_update_name_only_does_not_change_version(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'OldName', 'static', 'val', $owner->id);

        $updated = $this->svc->update($secret->uuid, $org->id, $owner->id, 'NewName', null);

        $this->assertSame('NewName', $updated->name);
        $this->assertSame(1, $updated->version);

        // История не должна содержать записей
        $versions = SecretVersion::findBySecretId($secret->id);
        $this->assertCount(0, $versions);
    }

    public function test_update_both_name_and_value(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'OldName', 'static', 'old', $owner->id);

        $updated = $this->svc->update($secret->uuid, $org->id, $owner->id, 'NewName', 'new');

        $this->assertSame('NewName', $updated->name);
        $this->assertSame(2, $updated->version);
    }

    public function test_update_throws_for_empty_name(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->update($secret->uuid, $org->id, $owner->id, '  ', null);
    }

    public function test_update_throws_for_duplicate_name(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $this->svc->create($org->id, $dir->uuid, 'Taken', 'static', 'v', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Mine', 'static', 'v', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->update($secret->uuid, $org->id, $owner->id, 'Taken', null);
    }

    public function test_update_throws_for_observer(): void
    {
        $owner    = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->update($secret->uuid, $org->id, $observer->id, 'NewName', null);
    }

    public function test_update_template_value_throws(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );
        $secret = $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template secret',
            (string) $template['uuid'],
            $owner->id,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'manual-value');
    }

    public function test_update_prunes_history_to_max_versions(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'v0', $owner->id);

        // 12 обновлений значения — история должна обрезаться до 10
        for ($i = 1; $i <= 12; $i++) {
            $this->svc->update($secret->uuid, $org->id, $owner->id, null, "v{$i}");
        }

        $versions = SecretVersion::findBySecretId($secret->id, 20);
        $this->assertCount(10, $versions);
    }

    // ------------------------------------------------------------------ //
    //  delete()                                                           //
    // ------------------------------------------------------------------ //

    public function test_delete_soft_deletes_secret(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->svc->delete($secret->uuid, $org->id, $owner->id);

        $this->assertNull(Secret::findByUuid($secret->uuid));
    }

    public function test_delete_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('s@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret   = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->delete($secret->uuid, $org->id, $stranger->id);
    }

    public function test_delete_throws_for_not_found(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->delete('non-existent-uuid', $org->id, $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  listVersions()                                                     //
    // ------------------------------------------------------------------ //

    public function test_list_versions_returns_history_sorted_by_version_desc(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'v1', $owner->id);

        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'v2');
        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'v3');

        $versions = $this->svc->listVersions($secret->uuid, $org->id, $owner->id);

        $this->assertCount(2, $versions);
        // Сортировка по убыванию version
        $this->assertSame(2, $versions[0]->version);
        $this->assertSame(1, $versions[1]->version);
    }

    public function test_list_versions_observer_can_read(): void
    {
        $owner    = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);
        $dir    = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'v1', $owner->id);
        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'v2');

        $versions = $this->svc->listVersions($secret->uuid, $org->id, $observer->id);

        $this->assertCount(1, $versions);
    }

    public function test_list_versions_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('s@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret   = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->listVersions($secret->uuid, $org->id, $stranger->id);
    }

    public function test_assert_can_rotate_allows_writer_and_denies_observer(): void
    {
        $owner = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);

        $this->svc->assertCanRotate($secret->uuid, $org->id, $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->assertCanRotate($secret->uuid, $org->id, $observer->id);
    }

    public function test_create_static_with_rotation_config_throws(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id, null, '0 3 * * *');
    }

    public function test_create_template_with_rotation_schedule_throws(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template secret',
            (string) $template['uuid'],
            $owner->id,
            [],
            '0 3 * * *',
        );
    }

    public function test_create_template_persists_overrides_in_metadata(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $secret = $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template secret',
            (string) $template['uuid'],
            $owner->id,
            ['min_length' => 24, 'max_length' => 24, 'use_special' => false],
        );

        $overrides = $this->svc->getTemplateOverrides($secret->uuid, $org->id, $owner->id);

        $this->assertSame(24, $overrides['min_length']);
        $this->assertSame(24, $overrides['max_length']);
        $this->assertFalse($overrides['use_special']);
    }

    public function test_create_template_allows_manual_password_value(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $secret = $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Manual template secret',
            (string) $template['uuid'],
            $owner->id,
            ['min_length' => 24, 'max_length' => 24],
            null,
            'manual-password-value',
        );
        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame('manual-password-value', $value);
    }

    public function test_create_template_rejects_short_manual_password_value(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Manual template secret',
            (string) $template['uuid'],
            $owner->id,
            [],
            null,
            'short',
        );
    }

    public function test_create_template_preserves_manual_ssh_private_key_text(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? AND name = ? LIMIT 1',
            ['ssh_key', 'SSH Key Ed25519']
        );
        $privateKey = (new TemplateService())->generate((string) $template['uuid']);

        $secret = $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Manual SSH secret',
            (string) $template['uuid'],
            $owner->id,
            [],
            null,
            $privateKey,
        );
        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame($privateKey, $value);
    }

    public function test_configure_rotation_rejects_static_secret(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->configureRotation($secret->uuid, $org->id, $owner->id, null, '0 3 * * *');
    }

    public function test_update_dynamic_secret_value_throws(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->svc->create($org->id, $dir->uuid, 'Dynamic Secret', 'dynamic', 'value', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->update($secret->uuid, $org->id, $owner->id, null, 'new-value');
    }

    public function test_preview_template_returns_display_value_and_overrides(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $preview = $this->svc->previewTemplate(
            $org->id,
            $dir->uuid,
            $owner->id,
            (string) $template['uuid'],
            ['length' => 24, 'use_special' => false],
        );

        $this->assertSame(24, $preview['template_overrides']['min_length']);
        $this->assertSame(24, $preview['template_overrides']['max_length']);
        $this->assertFalse($preview['template_overrides']['use_special']);
        $this->assertNotSame('', $preview['display_value']);
    }

    public function test_regenerate_from_template_updates_value_and_persists_overrides(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );
        $secret = $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template secret',
            (string) $template['uuid'],
            $owner->id,
            ['min_length' => 16, 'max_length' => 16],
        );
        ['value' => $before] = $this->svc->get($secret->uuid, $org->id, $owner->id);

        $result = $this->svc->regenerateFromTemplate(
            $secret->uuid,
            $org->id,
            $owner->id,
            ['length' => 24, 'use_special' => false],
        );
        ['value' => $after] = $this->svc->get($secret->uuid, $org->id, $owner->id);
        $overrides = $this->svc->getTemplateOverrides($secret->uuid, $org->id, $owner->id);

        $this->assertNotSame($before, $after);
        $this->assertSame(24, $overrides['min_length']);
        $this->assertSame(24, $overrides['max_length']);
        $this->assertFalse($overrides['use_special']);
        $this->assertSame(2, $result['secret']->version);

        $audit = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'secret.update' AND resource_uuid = ? ORDER BY id DESC LIMIT 1",
            [$secret->uuid]
        );

        $this->assertNotNull($audit);
    }

    public function test_regenerate_from_template_accepts_manual_password_value(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );
        $secret = $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template secret',
            (string) $template['uuid'],
            $owner->id,
        );

        $result = $this->svc->regenerateFromTemplate(
            $secret->uuid,
            $org->id,
            $owner->id,
            [],
            'manual-password-value',
        );
        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame('manual-password-value', $value);
        $this->assertSame('manual-password-value', $result['value']);
    }

    public function test_regenerate_from_template_preserves_manual_ssh_private_key_text(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? AND name = ? LIMIT 1',
            ['ssh_key', 'SSH Key Ed25519']
        );
        $secret = $this->svc->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template SSH secret',
            (string) $template['uuid'],
            $owner->id,
        );
        $privateKey = (new TemplateService())->generate((string) $template['uuid']);

        $this->svc->regenerateFromTemplate(
            $secret->uuid,
            $org->id,
            $owner->id,
            [],
            $privateKey,
        );
        ['value' => $value] = $this->svc->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame($privateKey, $value);
    }

    public function test_create_and_read_write_audit_log_entries(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);

        $secret = $this->svc->create($org->id, $dir->uuid, 'Secret', 'static', 'val', $owner->id);
        $this->svc->get($secret->uuid, $org->id, $owner->id);

        $created = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'secret.create' AND resource_uuid = ? ORDER BY id DESC LIMIT 1",
            [$secret->uuid]
        );
        $read = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'secret.read' AND resource_uuid = ? ORDER BY id DESC LIMIT 1",
            [$secret->uuid]
        );

        $this->assertNotNull($created);
        $this->assertNotNull($read);
    }
}
