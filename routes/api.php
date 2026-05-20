<?php

declare(strict_types=1);

/**
 * API-маршруты (/api/v1/...).
 * Все маршруты будут добавляться по мере реализации шагов.
 *
 * @var \Passway\Core\Router $router
 */

use Passway\Controllers\ApiKeyController;
use Passway\Controllers\ApprovalController;
use Passway\Controllers\AuditController;
use Passway\Controllers\Auth\LoginController;
use Passway\Controllers\Auth\PasskeyController;
use Passway\Controllers\Auth\TotpController;
use Passway\Controllers\DirectoryController;
use Passway\Controllers\GroupController;
use Passway\Controllers\InviteController;
use Passway\Controllers\OrganizationIntegrationController;
use Passway\Controllers\OrganizationController;
use Passway\Controllers\PermissionController;
use Passway\Controllers\RotationServiceController;
use Passway\Controllers\SecretController;
use Passway\Core\Response;
use Passway\Middleware\AuthMiddleware;

$router->group('/api/v1', function (\Passway\Core\Router $router) {

    // ------------------------------------------------------------------ //
    //  Служебные                                                          //
    // ------------------------------------------------------------------ //

    $router->get('/', fn() => Response::json([
        'service' => 'Passway API',
        'version' => 'v1',
        'status'  => 'ok',
    ]));

    $router->get('/health', fn() => Response::json([
        'status' => 'ok',
        'time'   => date('c'),
    ]));

    $router->group('/rotation-services', function (\Passway\Core\Router $router) {
        $router->get('/', [RotationServiceController::class, 'list'], [AuthMiddleware::class]);
        $router->post('/', [RotationServiceController::class, 'create'], [AuthMiddleware::class]);
        $router->get('/:svcUuid', [RotationServiceController::class, 'show'], [AuthMiddleware::class]);
        $router->patch('/:svcUuid', [RotationServiceController::class, 'update'], [AuthMiddleware::class]);
        $router->post('/:svcUuid/verify', [RotationServiceController::class, 'verify'], [AuthMiddleware::class]);
        $router->delete('/:svcUuid', [RotationServiceController::class, 'delete'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  Аутентификация                                                     //
    // ------------------------------------------------------------------ //

    $router->group('/auth', function (\Passway\Core\Router $router) {

        // Email + пароль
        $router->post('/login',  [LoginController::class, 'login']);
        $router->post('/logout', [LoginController::class, 'logout']);
        $router->get('/me',      [LoginController::class, 'me'], [AuthMiddleware::class]);

        // TOTP
        $router->post('/totp/verify',  [TotpController::class, 'verify']);
        $router->get('/totp/setup',    [TotpController::class, 'setup'],   [AuthMiddleware::class]);
        $router->post('/totp/enable',  [TotpController::class, 'enable'],  [AuthMiddleware::class]);
        $router->post('/totp/disable', [TotpController::class, 'disable'], [AuthMiddleware::class]);

        // Passkey / WebAuthn
        $router->post('/passkey/register/start',    [PasskeyController::class, 'registerStart'],    [AuthMiddleware::class]);
        $router->post('/passkey/register/finish',   [PasskeyController::class, 'registerFinish'],   [AuthMiddleware::class]);
        $router->post('/passkey/authenticate/start', [PasskeyController::class, 'authenticateStart']);
        $router->post('/passkey/authenticate/finish',[PasskeyController::class, 'authenticateFinish']);
        $router->get('/passkeys',                   [PasskeyController::class, 'list'],   [AuthMiddleware::class]);
        $router->delete('/passkeys/:uuid',          [PasskeyController::class, 'delete'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  Организации (Шаг 5)                                               //
    // ------------------------------------------------------------------ //

    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->post('/',    [OrganizationController::class, 'create'], [AuthMiddleware::class]);
        $router->get('/',     [OrganizationController::class, 'list'],   [AuthMiddleware::class]);
        $router->get('/:uuid', [OrganizationController::class, 'show'],  [AuthMiddleware::class]);

        // Участники
        $router->get('/:uuid/members',
            [OrganizationController::class, 'listMembers'], [AuthMiddleware::class]);
        $router->patch('/:uuid/members/:userUuid',
            [OrganizationController::class, 'updateMember'], [AuthMiddleware::class]);
        $router->delete('/:uuid/members/:userUuid',
            [OrganizationController::class, 'removeMember'], [AuthMiddleware::class]);
        $router->post('/:uuid/transfer',
            [OrganizationController::class, 'transferOwnership'], [AuthMiddleware::class]);

        // Инвайты
        $router->post('/:uuid/invites',
            [InviteController::class, 'create'], [AuthMiddleware::class]);
        $router->get('/:uuid/invites',
            [InviteController::class, 'list'], [AuthMiddleware::class]);
        $router->delete('/:uuid/invites/:invUuid',
            [InviteController::class, 'revoke'], [AuthMiddleware::class]);
        $router->get('/:uuid/audit',
            [AuditController::class, 'list'], [AuthMiddleware::class]);

        $router->get('/:uuid/integrations',
            [OrganizationIntegrationController::class, 'list'], [AuthMiddleware::class]);
        $router->post('/:uuid/integrations',
            [OrganizationIntegrationController::class, 'create'], [AuthMiddleware::class]);
        $router->get('/:uuid/integrations/:intUuid',
            [OrganizationIntegrationController::class, 'show'], [AuthMiddleware::class]);
        $router->patch('/:uuid/integrations/:intUuid',
            [OrganizationIntegrationController::class, 'update'], [AuthMiddleware::class]);
        $router->delete('/:uuid/integrations/:intUuid',
            [OrganizationIntegrationController::class, 'delete'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  Каталоги (Шаг 6)                                                  //
    // ------------------------------------------------------------------ //

    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->get('/:uuid/directories',
            [DirectoryController::class, 'list'], [AuthMiddleware::class]);
        $router->post('/:uuid/directories',
            [DirectoryController::class, 'create'], [AuthMiddleware::class]);
        $router->get('/:uuid/directories/:dirUuid',
            [DirectoryController::class, 'show'], [AuthMiddleware::class]);
        $router->get('/:uuid/directories/:dirUuid/acl',
            [DirectoryController::class, 'acl'], [AuthMiddleware::class]);
        $router->put('/:uuid/directories/:dirUuid/acl',
            [DirectoryController::class, 'replaceAcl'], [AuthMiddleware::class]);
        $router->get('/:uuid/directories/:dirUuid/access-policy',
            [DirectoryController::class, 'accessPolicy'], [AuthMiddleware::class]);
        $router->put('/:uuid/directories/:dirUuid/access-policy',
            [DirectoryController::class, 'updateAccessPolicy'], [AuthMiddleware::class]);
        $router->post('/:uuid/directories/:dirUuid/owner',
            [DirectoryController::class, 'transferOwnership'], [AuthMiddleware::class]);
        $router->patch('/:uuid/directories/:dirUuid',
            [DirectoryController::class, 'update'], [AuthMiddleware::class]);
        $router->delete('/:uuid/directories/:dirUuid',
            [DirectoryController::class, 'delete'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  Группы (Шаг 8)                                                    //
    // ------------------------------------------------------------------ //

    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->get('/:uuid/groups',
            [GroupController::class, 'list'], [AuthMiddleware::class]);
        $router->post('/:uuid/groups',
            [GroupController::class, 'create'], [AuthMiddleware::class]);
        $router->get('/:uuid/groups/:grpUuid',
            [GroupController::class, 'show'], [AuthMiddleware::class]);
        $router->delete('/:uuid/groups/:grpUuid',
            [GroupController::class, 'delete'], [AuthMiddleware::class]);
        $router->get('/:uuid/groups/:grpUuid/members',
            [GroupController::class, 'listMembers'], [AuthMiddleware::class]);
        $router->post('/:uuid/groups/:grpUuid/members',
            [GroupController::class, 'addMember'], [AuthMiddleware::class]);
        $router->delete('/:uuid/groups/:grpUuid/members/:userUuid',
            [GroupController::class, 'removeMember'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  Права на каталоги (Шаг 8)                                         //
    // ------------------------------------------------------------------ //

    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->get('/:uuid/directories/:dirUuid/permissions',
            [PermissionController::class, 'list'], [AuthMiddleware::class]);
        $router->post('/:uuid/directories/:dirUuid/permissions',
            [PermissionController::class, 'grant'], [AuthMiddleware::class]);
        $router->delete('/:uuid/directories/:dirUuid/permissions/:permId',
            [PermissionController::class, 'revoke'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  Система одобрений (Шаг 9)                                        //
    // ------------------------------------------------------------------ //

    // Создать запрос на одобрение для конкретного секрета
    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->get('/:uuid/secrets/:secUuid/acl',
            [SecretController::class, 'acl'], [AuthMiddleware::class]);
        $router->put('/:uuid/secrets/:secUuid/acl',
            [SecretController::class, 'replaceAcl'], [AuthMiddleware::class]);
        $router->get('/:uuid/secrets/:secUuid/access-policy',
            [SecretController::class, 'accessPolicy'], [AuthMiddleware::class]);
        $router->put('/:uuid/secrets/:secUuid/access-policy',
            [SecretController::class, 'updateAccessPolicy'], [AuthMiddleware::class]);
        $router->post('/:uuid/secrets/:secUuid/owner',
            [SecretController::class, 'transferOwnership'], [AuthMiddleware::class]);
        $router->post('/:uuid/secrets/:secUuid/approvals',
            [ApprovalController::class, 'create'], [AuthMiddleware::class]);
    });

    // Управление запросами на уровне организации
    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->get('/:uuid/approvals',
            [ApprovalController::class, 'listMy'], [AuthMiddleware::class]);
        $router->get('/:uuid/approvals/pending',
            [ApprovalController::class, 'listPending'], [AuthMiddleware::class]);
        $router->get('/:uuid/approvals/:aprUuid',
            [ApprovalController::class, 'show'], [AuthMiddleware::class]);
        $router->post('/:uuid/approvals/:aprUuid/approve',
            [ApprovalController::class, 'approve'], [AuthMiddleware::class]);
        $router->post('/:uuid/approvals/:aprUuid/reject',
            [ApprovalController::class, 'reject'], [AuthMiddleware::class]);
        $router->delete('/:uuid/approvals/:aprUuid',
            [ApprovalController::class, 'revoke'], [AuthMiddleware::class]);
        $router->post('/:uuid/approvals/:aprUuid/use',
            [ApprovalController::class, 'use'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  API-ключи (Шаг 10)                                               //
    // ------------------------------------------------------------------ //

    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->get('/:uuid/api-keys',
            [ApiKeyController::class, 'list'], [AuthMiddleware::class]);
        $router->post('/:uuid/api-keys',
            [ApiKeyController::class, 'create'], [AuthMiddleware::class]);
        $router->get('/:uuid/api-keys/:keyUuid',
            [ApiKeyController::class, 'show'], [AuthMiddleware::class]);
        $router->patch('/:uuid/api-keys/:keyUuid',
            [ApiKeyController::class, 'update'], [AuthMiddleware::class]);
        $router->delete('/:uuid/api-keys/:keyUuid',
            [ApiKeyController::class, 'revoke'], [AuthMiddleware::class]);
    });

    // ------------------------------------------------------------------ //
    //  Секреты (Шаг 7)                                                   //
    // ------------------------------------------------------------------ //

    $router->group('/organizations', function (\Passway\Core\Router $router) {
        $router->get('/:uuid/directories/:dirUuid/secrets',
            [SecretController::class, 'list'], [AuthMiddleware::class]);
        $router->post('/:uuid/directories/:dirUuid/secrets',
            [SecretController::class, 'create'], [AuthMiddleware::class]);
        $router->post('/:uuid/directories/:dirUuid/secrets/template-preview',
            [SecretController::class, 'previewTemplate'], [AuthMiddleware::class]);
        $router->get('/:uuid/directories/:dirUuid/secrets/:secUuid',
            [SecretController::class, 'show'], [AuthMiddleware::class]);
        $router->patch('/:uuid/directories/:dirUuid/secrets/:secUuid',
            [SecretController::class, 'update'], [AuthMiddleware::class]);
        $router->post('/:uuid/directories/:dirUuid/secrets/:secUuid/regenerate',
            [SecretController::class, 'regenerate'], [AuthMiddleware::class]);
        $router->post('/:uuid/directories/:dirUuid/secrets/:secUuid/rotate',
            [SecretController::class, 'rotate'], [AuthMiddleware::class]);
        $router->delete('/:uuid/directories/:dirUuid/secrets/:secUuid',
            [SecretController::class, 'delete'], [AuthMiddleware::class]);
        $router->get('/:uuid/directories/:dirUuid/secrets/:secUuid/versions',
            [SecretController::class, 'versions'], [AuthMiddleware::class]);
    });
});
