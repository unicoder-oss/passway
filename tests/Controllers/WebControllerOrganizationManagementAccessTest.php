<?php

declare(strict_types=1);

namespace Passway\Tests\Controllers;

use Passway\Controllers\WebController;
use Passway\Core\Application;
use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Core\Request;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 */
final class WebControllerOrganizationManagementAccessTest extends DatabaseTestCase
{
    private WebController $controller;
    private OrganizationService $organizationService;

    protected function setUp(): void
    {
        parent::setUp();
        AuthContext::reset();

        Database::getInstance()->query("UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'");

        $this->organizationService = new OrganizationService();
        $this->controller = Application::getInstance()->getContainer()->make(WebController::class);
    }

    protected function tearDown(): void
    {
        AuthContext::reset();
        parent::tearDown();
    }

    public function test_editor_is_redirected_from_management_pages_to_organization_root(): void
    {
        $this->assertMemberWithoutManagementAccessIsRedirectedToOrganizationRoot('editor');
    }

    public function test_reader_is_redirected_from_management_pages_to_organization_root(): void
    {
        $this->assertMemberWithoutManagementAccessIsRedirectedToOrganizationRoot('reader');
    }

    public function test_admin_can_open_manage_entrypoint(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        $this->organizationService->addMember($org->id, $admin->id, 'admin', null);
        AuthContext::setUser($admin);

        $response = $this->controller->showOrganizationManage($this->requestForOrganization($org->uuid));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/organizations/' . $org->uuid . '/manage/settings', $response->getHeaders()['Location'] ?? null);
    }

    public function test_admin_members_page_only_shows_role_forms_for_readers_and_editors(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $otherAdmin = $this->createTestUser('other-admin@example.com');
        $editor = $this->createTestUser('editor@example.com');
        $reader = $this->createTestUser('reader@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        $this->organizationService->addMember($org->id, $admin->id, 'admin', null);
        $this->organizationService->addMember($org->id, $otherAdmin->id, 'admin', null);
        $this->organizationService->addMember($org->id, $editor->id, 'editor', null);
        $this->organizationService->addMember($org->id, $reader->id, 'reader', null);
        AuthContext::setUser($admin);

        $body = $this->controller->showOrganizationMembers($this->requestForOrganization($org->uuid))->getBody();

        $this->assertStringNotContainsString($this->memberRoleAction($org->uuid, $admin->uuid), $body);
        $this->assertStringNotContainsString($this->memberRoleAction($org->uuid, $otherAdmin->uuid), $body);
        $this->assertStringContainsString($this->memberRoleAction($org->uuid, $editor->uuid), $body);
        $this->assertStringContainsString($this->memberRoleAction($org->uuid, $reader->uuid), $body);
        $this->assertStringNotContainsString('value="admin"', $body);
    }

    public function test_owner_members_page_shows_role_form_for_admins(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        $this->organizationService->addMember($org->id, $admin->id, 'admin', null);
        AuthContext::setUser($owner);

        $body = $this->controller->showOrganizationMembers($this->requestForOrganization($org->uuid))->getBody();

        $this->assertStringNotContainsString($this->memberRoleAction($org->uuid, $owner->uuid), $body);
        $this->assertStringContainsString($this->memberRoleAction($org->uuid, $admin->uuid), $body);
        $this->assertStringContainsString('value="admin"', $body);
    }

    public function test_admin_invites_page_does_not_show_admin_role_option(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        $this->organizationService->addMember($org->id, $admin->id, 'admin', null);
        AuthContext::setUser($admin);

        $body = $this->controller->showOrganizationInvites($this->requestForOrganization($org->uuid))->getBody();

        $this->assertStringContainsString('value="reader"', $body);
        $this->assertStringContainsString('value="editor"', $body);
        $this->assertStringNotContainsString('value="admin"', $body);
    }

    public function test_owner_invites_page_shows_admin_role_option(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        AuthContext::setUser($owner);

        $body = $this->controller->showOrganizationInvites($this->requestForOrganization($org->uuid))->getBody();

        $this->assertStringContainsString('value="reader"', $body);
        $this->assertStringContainsString('value="editor"', $body);
        $this->assertStringContainsString('value="admin"', $body);
    }

    private function assertMemberWithoutManagementAccessIsRedirectedToOrganizationRoot(string $role): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member = $this->createTestUser($role . '@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        $this->organizationService->addMember($org->id, $member->id, $role, null);
        AuthContext::setUser($member);

        $expectedLocation = '/organizations/' . $org->uuid;

        foreach ([
            'showOrganizationManage',
            'showOrganizationSettings',
            'showOrganizationMembers',
            'showOrganizationInvites',
        ] as $method) {
            $response = $this->controller->{$method}($this->requestForOrganization($org->uuid));

            $this->assertSame(302, $response->getStatusCode(), $method);
            $this->assertSame($expectedLocation, $response->getHeaders()['Location'] ?? null, $method);
        }
    }

    private function requestForOrganization(string $orgUuid): Request
    {
        $request = new Request(['REQUEST_METHOD' => 'GET'], [], [], [], [], '');
        $request->setRouteParams(['uuid' => $orgUuid]);

        return $request;
    }

    private function memberRoleAction(string $orgUuid, string $userUuid): string
    {
        return '/organizations/' . $orgUuid . '/members/' . $userUuid . '/role';
    }
}
