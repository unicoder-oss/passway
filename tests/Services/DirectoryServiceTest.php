<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Controllers\DirectoryController;
use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Core\Request;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;
use Passway\Services\ApiKeyService;
use Passway\Services\DirectoryService;
use Passway\Services\GroupService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Tests\DatabaseTestCase;

/**
 * DirectoryService tests: creation, listing, rename, move, delete.
 *
 * @requires extension pdo_sqlite
 */
final class DirectoryServiceTest extends DatabaseTestCase
{
    private DirectoryService   $svc;
    private OrganizationService $orgSvc;
    private PermissionService  $permSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc = new OrganizationService();
        $this->permSvc = new PermissionService($this->orgSvc, new GroupService($this->orgSvc));
        $this->svc    = new DirectoryService($this->orgSvc, $this->permSvc);

        // team mode has no limit on the number of organizations
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    protected function tearDown(): void
    {
        AuthContext::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ //
    //  create()                                                           //
    // ------------------------------------------------------------------ //

    public function test_create_root_directory(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $dir = $this->svc->create($org->id, null, 'Documents', $owner->id);

        $this->assertInstanceOf(Directory::class, $dir);
        $this->assertSame('Documents', $dir->name);
        $this->assertSame(0, $dir->depth);
        $this->assertNull($dir->parentId);
        $this->assertSame('/' . $dir->uuid, $dir->path);
        $this->assertSame($org->id, $dir->organizationId);
    }

    public function test_create_nested_directory(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $parent = $this->svc->create($org->id, null, 'Parent', $owner->id);

        $child = $this->svc->create($org->id, $parent->uuid, 'Child', $owner->id);

        $this->assertSame(1, $child->depth);
        $this->assertSame($parent->id, $child->parentId);
        $this->assertSame($parent->path . '/' . $child->uuid, $child->path);
    }

    public function test_create_deeply_nested_directory(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $current = $this->svc->create($org->id, null, 'Level 0', $owner->id);
        for ($i = 1; $i <= DirectoryService::MAX_DEPTH; $i++) {
            $current = $this->svc->create($org->id, $current->uuid, "Level {$i}", $owner->id);
        }

        $this->assertSame(DirectoryService::MAX_DEPTH, $current->depth);
    }

    public function test_create_throws_when_max_depth_exceeded(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $current = $this->svc->create($org->id, null, 'Root', $owner->id);
        for ($i = 1; $i <= DirectoryService::MAX_DEPTH; $i++) {
            $current = $this->svc->create($org->id, $current->uuid, "L{$i}", $owner->id);
        }

        $this->expectException(\RuntimeException::class);
        $this->svc->create($org->id, $current->uuid, 'Too Deep', $owner->id);
    }

    public function test_create_throws_for_empty_name(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($org->id, null, '   ', $owner->id);
    }

    public function test_create_throws_for_non_existent_parent(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->create($org->id, 'non-existent-uuid', 'Dir', $owner->id);
    }

    public function test_create_throws_if_parent_belongs_to_another_org(): void
    {
        $owner  = $this->createTestUser();
        $org1   = $this->orgSvc->create('Org1', $owner->id);
        $org2   = $this->orgSvc->create('Org2', $owner->id);
        $parent = $this->svc->create($org1->id, null, 'Parent', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->create($org2->id, $parent->uuid, 'Child', $owner->id);
    }

    public function test_create_throws_for_observer(): void
    {
        $owner    = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, null, 'Dir', $observer->id);
    }

    public function test_create_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('stranger@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, null, 'Dir', $stranger->id);
    }

    // ------------------------------------------------------------------ //
    //  listAll() / listChildren()                                         //
    // ------------------------------------------------------------------ //

    public function test_list_all_returns_all_dirs_sorted(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $root1 = $this->svc->create($org->id, null, 'A Root', $owner->id);
        $root2 = $this->svc->create($org->id, null, 'B Root', $owner->id);
        $this->svc->create($org->id, $root1->uuid, 'Child', $owner->id);

        $dirs = $this->svc->listAll($org->id, $owner->id);

        $this->assertCount(3, $dirs);
        // The first two are root directories (depth=0)
        $this->assertSame(0, $dirs[0]->depth);
        $this->assertSame(0, $dirs[1]->depth);
        // The third is a child directory (depth=1)
        $this->assertSame(1, $dirs[2]->depth);
    }

    public function test_list_all_excludes_deleted(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);
        $this->svc->delete($dir->uuid, $org->id, $owner->id);

        $dirs = $this->svc->listAll($org->id, $owner->id);
        $this->assertCount(0, $dirs);
    }

    public function test_list_all_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('s@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->listAll($org->id, $stranger->id);
    }

    public function test_list_children_returns_only_direct_children(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $root  = $this->svc->create($org->id, null, 'Root', $owner->id);
        $child = $this->svc->create($org->id, $root->uuid, 'Child', $owner->id);
        $this->svc->create($org->id, $child->uuid, 'Grandchild', $owner->id);

        $children = $this->svc->listChildren($org->id, $root->uuid, $owner->id);

        $this->assertCount(1, $children);
        $this->assertSame($child->id, $children[0]->id);
    }

    public function test_list_children_null_returns_root_dirs(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $root  = $this->svc->create($org->id, null, 'Root', $owner->id);
        $this->svc->create($org->id, $root->uuid, 'Child', $owner->id);

        $roots = $this->svc->listChildren($org->id, null, $owner->id);

        $this->assertCount(1, $roots);
        $this->assertSame($root->id, $roots[0]->id);
    }

    public function test_list_children_hides_read_denied_directories(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader-list-children@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $visible = $this->svc->create($org->id, null, 'Visible', $owner->id);
        $hidden = $this->svc->create($org->id, null, 'Hidden', $owner->id);

        $this->permSvc->grant('user', $reader->id, 'directory', $hidden->id, 'read', true, null, $owner->id, $org->id);

        $roots = $this->svc->listChildren($org->id, null, $reader->id);

        $this->assertSame([$visible->id], array_map(static fn(Directory $dir): string => $dir->id, $roots));
    }

    public function test_list_all_hides_read_denied_directories(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader-list-all@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $visible = $this->svc->create($org->id, null, 'Visible', $owner->id);
        $hidden = $this->svc->create($org->id, null, 'Hidden', $owner->id);

        $this->permSvc->grant('user', $reader->id, 'directory', $hidden->id, 'read', true, null, $owner->id, $org->id);

        $directories = $this->svc->listAll($org->id, $reader->id);

        $this->assertSame([$visible->id], array_map(static fn(Directory $dir): string => $dir->id, $directories));
    }

    // ------------------------------------------------------------------ //
    //  findInOrg()                                                        //
    // ------------------------------------------------------------------ //

    public function test_find_in_org_returns_directory(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $found = $this->svc->findInOrg($dir->uuid, $org->id, $owner->id);

        $this->assertSame($dir->id, $found->id);
    }

    public function test_find_in_org_throws_if_dir_in_another_org(): void
    {
        $owner = $this->createTestUser();
        $org1  = $this->orgSvc->create('Org1', $owner->id);
        $org2  = $this->orgSvc->create('Org2', $owner->id);
        $dir   = $this->svc->create($org1->id, null, 'Dir', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->findInOrg($dir->uuid, $org2->id, $owner->id);
    }

    public function test_find_in_org_allows_child_when_parent_read_denied_but_child_allowed(): void
    {
        $owner = $this->createTestUser();
        $user  = $this->createTestUser('reader@example.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);
        $parent = $this->svc->create($org->id, null, 'Parent', $owner->id);
        $child = $this->svc->create($org->id, $parent->uuid, 'Child', $owner->id);

        $this->permSvc->grant('user', $user->id, 'directory', $parent->id, 'read', true, null, $owner->id, $org->id);
        $this->permSvc->grant('user', $user->id, 'directory', $child->id, 'read', false, null, $owner->id, $org->id);

        $found = $this->svc->findInOrg($child->uuid, $org->id, $user->id);

        $this->assertSame($child->id, $found->id);
    }

    // ------------------------------------------------------------------ //
    //  rename()                                                           //
    // ------------------------------------------------------------------ //

    public function test_rename_updates_name(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->svc->create($org->id, null, 'OldName', $owner->id);

        $renamed = $this->svc->rename($dir->uuid, $org->id, 'NewName', $owner->id);

        $this->assertSame('NewName', $renamed->name);
    }

    public function test_rename_throws_for_empty_name(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->rename($dir->uuid, $org->id, '', $owner->id);
    }

    public function test_rename_throws_for_observer(): void
    {
        $owner    = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->rename($dir->uuid, $org->id, 'New', $observer->id);
    }

    // ------------------------------------------------------------------ //
    //  move()                                                             //
    // ------------------------------------------------------------------ //

    public function test_move_to_new_parent(): void
    {
        $owner   = $this->createTestUser();
        $org     = $this->orgSvc->create('Org', $owner->id);
        $rootA   = $this->svc->create($org->id, null, 'A', $owner->id);
        $rootB   = $this->svc->create($org->id, null, 'B', $owner->id);
        $child   = $this->svc->create($org->id, $rootA->uuid, 'Child', $owner->id);

        $this->svc->move($child->uuid, $org->id, $rootB->uuid, $owner->id);

        $moved = Directory::findByUuid($child->uuid);
        $this->assertNotNull($moved);
        $this->assertSame($rootB->id, $moved->parentId);
        $this->assertSame(1, $moved->depth);
        $this->assertSame($rootB->path . '/' . $child->uuid, $moved->path);
    }

    public function test_move_to_root(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $root   = $this->svc->create($org->id, null, 'Root', $owner->id);
        $child  = $this->svc->create($org->id, $root->uuid, 'Child', $owner->id);

        $this->svc->move($child->uuid, $org->id, null, $owner->id);

        $moved = Directory::findByUuid($child->uuid);
        $this->assertNotNull($moved);
        $this->assertNull($moved->parentId);
        $this->assertSame(0, $moved->depth);
        $this->assertSame('/' . $child->uuid, $moved->path);
    }

    public function test_move_updates_descendants_path_and_depth(): void
    {
        $owner     = $this->createTestUser();
        $org       = $this->orgSvc->create('Org', $owner->id);
        $rootA     = $this->svc->create($org->id, null, 'A', $owner->id);
        $rootB     = $this->svc->create($org->id, null, 'B', $owner->id);
        $child     = $this->svc->create($org->id, $rootA->uuid, 'Child', $owner->id);
        $grandchild = $this->svc->create($org->id, $child->uuid, 'Grandchild', $owner->id);

        $this->svc->move($child->uuid, $org->id, $rootB->uuid, $owner->id);

        $gc = Directory::findByUuid($grandchild->uuid);
        $this->assertNotNull($gc);
        $this->assertSame(2, $gc->depth);
        // path = rootB.path/child.uuid/grandchild.uuid
        $this->assertStringStartsWith($rootB->path . '/' . $child->uuid, $gc->path);
    }

    public function test_move_into_itself_throws(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->move($dir->uuid, $org->id, $dir->uuid, $owner->id);
    }

    public function test_move_into_descendant_throws(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $root  = $this->svc->create($org->id, null, 'Root', $owner->id);
        $child = $this->svc->create($org->id, $root->uuid, 'Child', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->move($root->uuid, $org->id, $child->uuid, $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  delete()                                                           //
    // ------------------------------------------------------------------ //

    public function test_delete_soft_deletes_directory(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->svc->delete($dir->uuid, $org->id, $owner->id);

        $this->assertNull(Directory::findByUuid($dir->uuid));
    }

    public function test_delete_also_soft_deletes_descendants(): void
    {
        $owner      = $this->createTestUser();
        $org        = $this->orgSvc->create('Org', $owner->id);
        $root       = $this->svc->create($org->id, null, 'Root', $owner->id);
        $child      = $this->svc->create($org->id, $root->uuid, 'Child', $owner->id);
        $grandchild = $this->svc->create($org->id, $child->uuid, 'Grandchild', $owner->id);

        $this->svc->delete($root->uuid, $org->id, $owner->id);

        $this->assertNull(Directory::findByUuid($root->uuid));
        $this->assertNull(Directory::findByUuid($child->uuid));
        $this->assertNull(Directory::findByUuid($grandchild->uuid));
    }

    public function test_delete_throws_for_non_member(): void
    {
        $owner    = $this->createTestUser();
        $stranger = $this->createTestUser('s@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->delete($dir->uuid, $org->id, $stranger->id);
    }

    public function test_delete_throws_for_not_found(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->delete('non-existent-uuid', $org->id, $owner->id);
    }

    public function test_delete_requires_directory_owner(): void
    {
        $owner = $this->createTestUser();
        $editor = $this->createTestUser('editor@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->delete($dir->uuid, $org->id, $editor->id);
    }

    public function test_delete_allows_directory_owner_even_without_editor_role(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        Database::getInstance()->update('directories', ['owner_user_id' => (int) $reader->id], ['id' => (int) $dir->id]);

        $this->svc->delete($dir->uuid, $org->id, $reader->id);

        $this->assertNull(Directory::findByUuid($dir->uuid));
    }

    public function test_transfer_ownership_updates_directory_owner(): void
    {
        $owner = $this->createTestUser();
        $newOwner = $this->createTestUser('new-owner@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $newOwner->id, 'reader', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $updated = $this->svc->transferOwnership($dir->uuid, $org->id, $newOwner->id, $owner->id);

        $this->assertSame($newOwner->id, $updated->ownerUserId);
    }

    public function test_transfer_ownership_requires_directory_owner(): void
    {
        $owner = $this->createTestUser();
        $editor = $this->createTestUser('editor@example.com');
        $newOwner = $this->createTestUser('new-owner@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);
        $this->orgSvc->addMember($org->id, $newOwner->id, 'reader', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->transferOwnership($dir->uuid, $org->id, $newOwner->id, $editor->id);
    }

    public function test_list_acl_requires_directory_owner(): void
    {
        $owner = $this->createTestUser();
        $editor = $this->createTestUser('editor@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->listAcl($dir->uuid, $org->id, $editor->id);
    }

    public function test_replace_acl_stores_exact_directory_rules(): void
    {
        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $rules = $this->svc->replaceAcl($dir->uuid, $org->id, $owner->id, [[
            'subject_type' => 'user',
            'subject_id' => $reader->id,
            'read' => 'deny',
            'write' => 'allow',
        ]]);

        $this->assertCount(2, $rules);
        $stored = $this->svc->listAcl($dir->uuid, $org->id, $owner->id);
        $this->assertCount(2, $stored);
    }

    public function test_replace_acl_stores_api_key_subject_rules(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);
        $apiKeyService = new ApiKeyService($this->orgSvc);
        ['key' => $apiKey] = $apiKeyService->create('Deploy key', $org->id, $owner->id);

        $rules = $this->svc->replaceAcl($dir->uuid, $org->id, $owner->id, [[
            'subject_type' => 'api_key',
            'subject_id' => $apiKey->id,
            'read' => 'allow',
            'write' => 'deny',
        ]]);

        $this->assertCount(2, $rules);
        $this->assertSame(['api_key'], array_values(array_unique(array_map(
            static fn($rule) => $rule->subjectType,
            $rules,
        ))));
    }

    public function test_replace_acl_rejects_revoked_api_key_subject(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);
        $apiKeyService = new ApiKeyService($this->orgSvc);
        ['key' => $apiKey] = $apiKeyService->create('Deploy key', $org->id, $owner->id);
        $apiKeyService->revoke($apiKey->uuid, $org->id, $owner->id);
        AuthContext::setUser($owner);

        $request = new Request(
            ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/'],
            [],
            ['rules' => [[
                'subject_type' => 'api_key',
                'subject_uuid' => $apiKey->uuid,
                'read' => 'allow',
                'write' => null,
            ]]],
            [],
            [],
            ''
        );
        $request->setRouteParams(['uuid' => $org->uuid, 'dirUuid' => $dir->uuid]);

        $response = (new DirectoryController($this->svc))->replaceAcl($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->svc->listAcl($dir->uuid, $org->id, $owner->id));
    }

    public function test_replace_acl_stores_group_subject_rules(): void
    {
        $owner = $this->createTestUser();
        $member = $this->createTestUser('group-member@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);
        $groupService = new GroupService($this->orgSvc);
        $group = $groupService->create($org->id, 'Writers', null, $owner->id);
        $groupService->addMember($group->uuid, $member->id, $owner->id, $org->id);

        $rules = $this->svc->replaceAcl($dir->uuid, $org->id, $owner->id, [[
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

    public function test_replace_acl_rejects_user_subject_in_solo_mode(): void
    {
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'solo' WHERE key = 'deploy_mode'"
        );

        $owner = $this->createTestUser();
        $reader = $this->createTestUser('reader-solo@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->replaceAcl($dir->uuid, $org->id, $owner->id, [[
            'subject_type' => 'user',
            'subject_id' => $reader->id,
            'read' => 'allow',
            'write' => 'deny',
        ]]);
    }

    public function test_update_access_policy_requires_directory_owner(): void
    {
        $owner = $this->createTestUser();
        $editor = $this->createTestUser('editor-policy@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->updateAccessPolicy($dir->uuid, $org->id, $editor->id, 'deny', 'deny');
    }

    public function test_update_access_policy_persists_values(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->svc->create($org->id, null, 'Dir', $owner->id);

        $policy = $this->svc->updateAccessPolicy($dir->uuid, $org->id, $owner->id, 'deny', 'allow');

        $this->assertSame('deny', $policy['default_read_access']);
        $this->assertSame('allow', $policy['default_write_access']);
        $stored = $this->svc->getAccessPolicy($dir->uuid, $org->id, $owner->id);
        $this->assertSame('deny', $stored['default_read_access']);
        $this->assertSame('allow', $stored['default_write_access']);
    }
}
