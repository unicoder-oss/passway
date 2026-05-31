<?php

declare(strict_types=1);

/**
 * Web routes (browser interface).
 * The $router variable is injected from Application::registerRoutes().
 *
 * @var \Passway\Core\Router $router
 */

use Passway\Controllers\Auth\LoginController;
use Passway\Controllers\Auth\PasskeyController;
use Passway\Controllers\Auth\TotpController;
use Passway\Controllers\InviteController;
use Passway\Controllers\WebController;
use Passway\Controllers\SetupController;
use Passway\Core\Response;
use Passway\Middleware\AuthMiddleware;

// ------------------------------------------------------------------ //
//  Initial Setup                                                       //
// ------------------------------------------------------------------ //

$router->get('/setup',  [SetupController::class, 'show']);
$router->post('/setup', [SetupController::class, 'process']);

// ------------------------------------------------------------------ //
//  Base Pages                                                          //
// ------------------------------------------------------------------ //

$router->get('/', [WebController::class, 'home'], [AuthMiddleware::class]);
$router->get('/partials/home-organizations', [WebController::class, 'homeOrganizationsPartial'], [AuthMiddleware::class]);
$router->get('/api', [WebController::class, 'showApiDocs'], [AuthMiddleware::class]);
$router->get('/audit', [WebController::class, 'showInstanceAudit'], [AuthMiddleware::class]);

// Health check (Docker, load balancers)
$router->get('/health', fn() => Response::json([
    'status'  => 'ok',
    'service' => 'passway',
    'time'    => date('c'),
]));

// ------------------------------------------------------------------ //
//  Authentication                                                      //
// ------------------------------------------------------------------ //

$router->group('/auth', function (\Passway\Core\Router $router) {

    // --- Email + password ---
    $router->get('/login',   [LoginController::class, 'show']);
    $router->post('/login',  [LoginController::class, 'login']);
    $router->get('/logout',  [LoginController::class, 'logout']);

    // Current user (requires authentication)
    $router->get('/me', [LoginController::class, 'me'], [AuthMiddleware::class]);

    // --- TOTP (2FA) ---
    $router->group('/totp', function (\Passway\Core\Router $router) {
        // Code entry during login (pending session in PHP session)
        $router->get('/verify',  [TotpController::class, 'showVerify']);
        $router->post('/verify', [TotpController::class, 'verify']);

        // TOTP management (requires authentication)
        $router->get('/setup',     [TotpController::class, 'setup'],   [AuthMiddleware::class]);
        $router->post('/enable',   [TotpController::class, 'enable'],  [AuthMiddleware::class]);
        $router->post('/disable',  [TotpController::class, 'disable'], [AuthMiddleware::class]);
    });

    // --- Passkey / WebAuthn ---
    $router->group('/passkey', function (\Passway\Core\Router $router) {
        // Registration (requires authentication)
        $router->post('/register/start',  [PasskeyController::class, 'registerStart'],  [AuthMiddleware::class]);
        $router->post('/register/finish', [PasskeyController::class, 'registerFinish'], [AuthMiddleware::class]);

        // Authentication (public)
        $router->post('/authenticate/start',  [PasskeyController::class, 'authenticateStart']);
        $router->post('/authenticate/finish', [PasskeyController::class, 'authenticateFinish']);

        // List and delete (requires authentication)
        $router->get('/list',         [PasskeyController::class, 'list'],   [AuthMiddleware::class]);
        $router->delete('/:uuid',     [PasskeyController::class, 'delete'], [AuthMiddleware::class]);
    });
});

$router->post('/organizations', [WebController::class, 'createOrganization'], [AuthMiddleware::class]);
$router->post('/organization-invites', [WebController::class, 'createOrganizationInvite'], [AuthMiddleware::class]);
$router->get('/profile', [WebController::class, 'showProfile'], [AuthMiddleware::class]);
$router->post('/profile', [WebController::class, 'updateProfile'], [AuthMiddleware::class]);
$router->get('/profile/security', [WebController::class, 'showProfileSecurity'], [AuthMiddleware::class]);
$router->get('/profile/interface', [WebController::class, 'showProfileInterface'], [AuthMiddleware::class]);
$router->post('/profile/email', [WebController::class, 'updateProfileEmail'], [AuthMiddleware::class]);
$router->post('/profile/password', [WebController::class, 'updateProfilePassword'], [AuthMiddleware::class]);
$router->post('/profile/avatar/delete', [WebController::class, 'deleteProfileAvatar'], [AuthMiddleware::class]);
$router->post('/profile/interface', [WebController::class, 'updateProfileInterface'], [AuthMiddleware::class]);
$router->post('/profile/totp/start', [WebController::class, 'startTotpSetup'], [AuthMiddleware::class]);
$router->post('/profile/totp/enable', [WebController::class, 'enableTotp'], [AuthMiddleware::class]);
$router->post('/profile/totp/disable', [WebController::class, 'disableTotp'], [AuthMiddleware::class]);
$router->post('/profile/passkeys/:uuid/delete', [WebController::class, 'deletePasskey'], [AuthMiddleware::class]);
$router->get('/rotation-services', [WebController::class, 'showRotationServices'], [AuthMiddleware::class]);
$router->post('/rotation-services', [WebController::class, 'createRotationService'], [AuthMiddleware::class]);
$router->post('/rotation-services/:svcUuid/update', [WebController::class, 'updateRotationService'], [AuthMiddleware::class]);
$router->post('/rotation-services/:svcUuid/verify', [WebController::class, 'verifyRotationService'], [AuthMiddleware::class]);
$router->post('/rotation-services/:svcUuid/delete', [WebController::class, 'deleteRotationService'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid', [WebController::class, 'showOrganization'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/search', [WebController::class, 'organizationSearchPartial'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/manage', [WebController::class, 'showOrganizationManage'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/manage/settings', [WebController::class, 'showOrganizationSettings'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/manage/members', [WebController::class, 'showOrganizationMembers'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/manage/invites', [WebController::class, 'showOrganizationInvites'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/manage', [WebController::class, 'updateOrganizationSettings'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/manage/delete', [WebController::class, 'deleteOrganization'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/groups', [WebController::class, 'showOrganizationGroups'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/groups', [WebController::class, 'createGroup'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/groups/:grpUuid', [WebController::class, 'showOrganizationGroup'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/groups/:grpUuid/delete', [WebController::class, 'deleteGroup'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/groups/:grpUuid/members', [WebController::class, 'addGroupMember'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/groups/:grpUuid/members/:userUuid/remove', [WebController::class, 'removeGroupMember'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/api-keys', [WebController::class, 'showApiKeys'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/api-keys', [WebController::class, 'createApiKey'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/api-keys/:keyUuid/role', [WebController::class, 'updateApiKeyRole'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/api-keys/:keyUuid/revoke', [WebController::class, 'revokeApiKey'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/integrations', [WebController::class, 'showOrganizationIntegrations'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/integrations', [WebController::class, 'createOrganizationIntegration'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/integrations/:intUuid/update', [WebController::class, 'updateOrganizationIntegration'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/integrations/:intUuid/delete', [WebController::class, 'deleteOrganizationIntegration'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/members/:userUuid/role', [WebController::class, 'updateMemberRole'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/members/:userUuid/remove', [WebController::class, 'removeMember'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/invites', [WebController::class, 'createInvite'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/invites/:invUuid/revoke', [WebController::class, 'revokeInvite'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/audit', [WebController::class, 'showAudit'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories', [WebController::class, 'createDirectory'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/secrets', [WebController::class, 'createRootSecret'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/rename', [WebController::class, 'renameDirectory'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/delete', [WebController::class, 'deleteDirectory'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/owner', [WebController::class, 'transferDirectoryOwnership'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/secrets', [WebController::class, 'createSecret'], [AuthMiddleware::class]);
$router->get('/organizations/:uuid/directories/:dirUuid/secrets/:secUuid', [WebController::class, 'showSecret'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/update', [WebController::class, 'updateSecret'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/regenerate', [WebController::class, 'regenerateTemplateSecret'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/rotate', [WebController::class, 'rotateSecret'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/delete', [WebController::class, 'deleteSecret'], [AuthMiddleware::class]);
$router->post('/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/owner', [WebController::class, 'transferSecretOwnership'], [AuthMiddleware::class]);

// ------------------------------------------------------------------ //
//  Invites (web — link acceptance)                                    //
// ------------------------------------------------------------------ //

$router->get('/invite/:token',  [InviteController::class, 'showAccept']);
$router->post('/invite/:token', [InviteController::class, 'accept']);
$router->get('/invite/:token/register', [InviteController::class, 'showRegister']);
$router->post('/invite/:token/register', [InviteController::class, 'register']);
$router->get('/invite/:token/create-organization', [InviteController::class, 'showCreateOrganization']);
$router->post('/invite/:token/create-organization', [InviteController::class, 'createOrganizationFromInvite']);
