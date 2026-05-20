<?php

declare(strict_types=1);

namespace Passway\Tests\Middleware;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Middleware\AuthMiddleware;
use Passway\Services\ApiKeyService;
use Passway\Services\AuditService;
use Passway\Services\HashingService;
use Passway\Services\LoggerService;
use Passway\Services\OrganizationService;
use Passway\Services\SessionService;
use Passway\Services\TokenService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 */
final class AuthMiddlewareTest extends DatabaseTestCase
{
    private OrganizationService $organizationService;
    private ApiKeyService $apiKeyService;
    private AuthMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        AuthContext::reset();

        $this->organizationService = new OrganizationService();
        $auditService = new AuditService(new LoggerService(), $this->organizationService);
        $this->apiKeyService = new ApiKeyService($this->organizationService, $auditService);
        $this->middleware = new AuthMiddleware(
            new SessionService(new TokenService(), new HashingService()),
            $this->apiKeyService,
            $auditService,
        );

        \Passway\Core\Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    protected function tearDown(): void
    {
        AuthContext::reset();
        parent::tearDown();
    }

    public function test_api_key_can_access_requester_side_approval_route(): void
    {
        $owner = $this->createTestUser('owner-mw@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        ['raw' => $rawKey] = $this->apiKeyService->create('Deploy key', $org->id, $owner->id, 'reader');

        $request = new Request(
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/api/v1/organizations/' . $org->uuid . '/secrets/sec-uuid/approvals',
                'HTTP_X_API_KEY' => $rawKey,
                'HTTP_ACCEPT' => 'application/json',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '{"request_type":"read"}',
        );

        $response = $this->middleware->handle($request, static fn(Request $request): Response => Response::success(['ok' => true]));
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(['ok' => true], $payload['data']);
    }

    public function test_api_key_cannot_access_global_pending_summary_route(): void
    {
        $owner = $this->createTestUser('owner-mw-block@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        ['raw' => $rawKey] = $this->apiKeyService->create('Deploy key', $org->id, $owner->id, 'reader');

        $request = new Request(
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/v1/approvals/pending-summary',
                'HTTP_X_API_KEY' => $rawKey,
                'HTTP_ACCEPT' => 'application/json',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
        );

        $response = $this->middleware->handle($request, static fn(Request $request): Response => Response::success(['ok' => true]));
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
    }
}
