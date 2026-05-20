<?php

declare(strict_types=1);

$json = static fn(array $payload): string => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$ok = static fn(array $data): string => $json(['success' => true, 'data' => $data]);
$ep = static fn(string $method, string $path, string $auth, string $summary, string $request, string $response): array => [
    'method' => $method,
    'path' => $path,
    'auth' => $auth,
    'summary' => $summary,
    'request' => $request,
    'response' => $response,
];

return [
    [
        'title' => 'Служебные',
        'description' => 'Базовые endpoint\'ы для проверки доступности API.',
        'endpoints' => [
            $ep('GET', '/api/v1', 'Публичный', 'Краткая информация о версии API.', "curl https://example.test/api/v1", $json(['service' => 'Passway API', 'version' => 'v1', 'status' => 'ok'])),
            $ep('GET', '/api/v1/health', 'Публичный', 'Проверка доступности экземпляра.', "curl https://example.test/api/v1/health", $json(['status' => 'ok', 'time' => '2026-05-10T12:00:00+00:00'])),
        ],
    ],
    [
        'title' => 'Аутентификация',
        'description' => 'Вход, текущий пользователь, TOTP и Passkey/WebAuthn.',
        'endpoints' => [
            $ep('POST', '/api/v1/auth/login', 'Публичный', 'Вход по email и паролю; при включённом TOTP может вернуть `totp_required`.', "curl -X POST https://example.test/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{\"email\":\"admin@example.com\",\"password\":\"Test1234!\"}'", $ok(['status' => 'success', 'user' => ['uuid' => 'user-uuid', 'email' => 'admin@example.com', 'totp_enabled' => false, 'email_verified' => false]])),
            $ep('POST', '/api/v1/auth/logout', 'Session cookie', 'Выход и инвалидция текущей сессии.', "curl -X POST https://example.test/api/v1/auth/logout \
  -H 'Cookie: passway_session=...';", $ok(['message' => 'Logged out successfully'])),
            $ep('GET', '/api/v1/auth/me', 'Session cookie', 'Информация о текущем пользователе.', "curl https://example.test/api/v1/auth/me \
  -H 'Cookie: passway_session=...';", $ok(['user' => ['uuid' => 'user-uuid', 'email' => 'admin@example.com', 'totp_enabled' => true, 'email_verified' => false, 'last_login_at' => '2026-05-10 12:00:00', 'last_login_ip' => '127.0.0.1', 'created_at' => '2026-05-01 09:00:00']])),
            $ep('POST', '/api/v1/auth/totp/verify', 'Публичный после login', 'Завершение входа с TOTP-кодом из pending-session.', "curl -X POST https://example.test/api/v1/auth/totp/verify \
  -H 'Content-Type: application/json' \
  -d '{\"code\":\"123456\"}'", $ok(['status' => 'success', 'user' => ['uuid' => 'user-uuid', 'email' => 'admin@example.com']])),
            $ep('GET', '/api/v1/auth/totp/setup', 'Session cookie', 'Начать настройку TOTP и получить QR URI.', "curl https://example.test/api/v1/auth/totp/setup \
  -H 'Cookie: passway_session=...';", $ok(['qr_code_uri' => 'otpauth://totp/Passway:admin@example.com?...', 'manual_entry_key' => 'BASE32SECRET', 'message' => 'Scan the QR code with your authenticator app, then call POST /auth/totp/enable with a code to confirm'])),
            $ep('POST', '/api/v1/auth/totp/enable', 'Session cookie', 'Подтвердить и включить TOTP для текущего пользователя.', "curl -X POST https://example.test/api/v1/auth/totp/enable \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"code\":\"123456\"}'", $ok(['message' => 'Two-factor authentication has been enabled.'])),
            $ep('POST', '/api/v1/auth/totp/disable', 'Session cookie', 'Отключить TOTP по текущему паролю.', "curl -X POST https://example.test/api/v1/auth/totp/disable \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"password\":\"Test1234!\"}'", $ok(['message' => 'Two-factor authentication has been disabled.'])),
            $ep('POST', '/api/v1/auth/passkey/register/start', 'Session cookie', 'Начать регистрацию passkey и получить WebAuthn options.', "curl -X POST https://example.test/api/v1/auth/passkey/register/start \
  -H 'Cookie: passway_session=...';", $json(['success' => true, 'options' => ['challenge' => 'base64url...', 'rp' => ['name' => 'Passway']]])),
            $ep('POST', '/api/v1/auth/passkey/register/finish', 'Session cookie', 'Завершить регистрацию passkey.', "curl -X POST https://example.test/api/v1/auth/passkey/register/finish \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"MacBook\",\"credential\":{}}'", $json(['success' => true, 'passkey' => ['uuid' => 'pk-uuid', 'name' => 'MacBook', 'created_at' => '2026-05-10 12:00:00']])),
            $ep('POST', '/api/v1/auth/passkey/authenticate/start', 'Публичный', 'Получить WebAuthn request options для входа по passkey.', "curl -X POST https://example.test/api/v1/auth/passkey/authenticate/start \
  -H 'Content-Type: application/json' \
  -d '{\"email\":\"admin@example.com\"}'", $json(['success' => true, 'options' => ['challenge' => 'base64url...']])),
            $ep('POST', '/api/v1/auth/passkey/authenticate/finish', 'Публичный', 'Завершить вход по passkey.', "curl -X POST https://example.test/api/v1/auth/passkey/authenticate/finish \
  -H 'Content-Type: application/json' \
  -d '{\"credential\":{}}'", $ok(['status' => 'success', 'user' => ['uuid' => 'user-uuid', 'email' => 'admin@example.com']])),
            $ep('GET', '/api/v1/auth/passkeys', 'Session cookie', 'Список passkey текущего пользователя.', "curl https://example.test/api/v1/auth/passkeys \
  -H 'Cookie: passway_session=...';", $ok(['passkeys' => [['uuid' => 'pk-uuid', 'name' => 'MacBook', 'aaguid' => 'aaguid', 'created_at' => '2026-05-10 12:00:00', 'last_used_at' => '2026-05-10 12:10:00']]])),
            $ep('DELETE', '/api/v1/auth/passkeys/:uuid', 'Session cookie', 'Удалить passkey текущего пользователя.', "curl -X DELETE https://example.test/api/v1/auth/passkeys/pk-uuid \
  -H 'Cookie: passway_session=...';", $ok(['message' => 'Passkey removed.'])),
        ],
    ],
    [
        'title' => 'Организации и участники',
        'description' => 'Создание организаций и управление составом участников.',
        'endpoints' => [
            $ep('POST', '/api/v1/organizations', 'Session cookie', 'Создать организацию.', "curl -X POST https://example.test/api/v1/organizations \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"Platform Team\"}'", $ok(['uuid' => 'org-uuid', 'name' => 'Platform Team', 'slug' => 'platform-team', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('GET', '/api/v1/organizations', 'Session cookie', 'Список организаций текущего пользователя.', "curl https://example.test/api/v1/organizations \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'org-uuid', 'name' => 'Platform Team', 'slug' => 'platform-team', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('GET', '/api/v1/organizations/:uuid', 'Session cookie или API key', 'Получить организацию. Для API-ключа нужен `organization.read`.', "curl https://example.test/api/v1/organizations/org-uuid \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'org-uuid', 'name' => 'Platform Team', 'slug' => 'platform-team', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('GET', '/api/v1/organizations/:uuid/members', 'Session cookie', 'Список участников организации.', "curl https://example.test/api/v1/organizations/org-uuid/members \
  -H 'Cookie: passway_session=...';", $ok([['user_uuid' => 'user-uuid', 'email' => 'admin@example.com', 'role' => 'owner', 'joined_at' => '2026-05-10 12:00:00']])) ,
            $ep('PATCH', '/api/v1/organizations/:uuid/members/:userUuid', 'Session cookie', 'Изменить роль участника.', "curl -X PATCH https://example.test/api/v1/organizations/org-uuid/members/user-uuid \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"role\":\"moderator\"}'", $ok([])),
            $ep('DELETE', '/api/v1/organizations/:uuid/members/:userUuid', 'Session cookie', 'Удалить участника.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/members/user-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
            $ep('POST', '/api/v1/organizations/:uuid/transfer', 'Session cookie', 'Передать владение организацией.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/transfer \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"user_uuid\":\"new-owner-uuid\"}'", $ok([])),
        ],
    ],
    [
        'title' => 'Инвайты, аудит и интеграции',
        'description' => 'Административные endpoint\'ы организации. Для API-ключей недоступны.',
        'endpoints' => [
            $ep('POST', '/api/v1/organizations/:uuid/invites', 'Session cookie', 'Создать приглашение в организацию.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/invites \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"role\":\"user\",\"ttl\":3600}'", $ok(['uuid' => 'invite-uuid', 'token' => 'raw-token', 'role' => 'user', 'expires_at' => '2026-05-10 13:00:00'])),
            $ep('GET', '/api/v1/organizations/:uuid/invites', 'Session cookie', 'Список активных приглашений.', "curl https://example.test/api/v1/organizations/org-uuid/invites \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'invite-uuid', 'role' => 'user', 'expires_at' => '2026-05-10 13:00:00']])) ,
            $ep('DELETE', '/api/v1/organizations/:uuid/invites/:invUuid', 'Session cookie', 'Отозвать приглашение.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/invites/invite-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
            $ep('GET', '/api/v1/organizations/:uuid/audit', 'Session cookie', 'Постраничный журнал аудита.', "curl 'https://example.test/api/v1/organizations/org-uuid/audit?limit=20&offset=0' \
  -H 'Cookie: passway_session=...';", $ok(['items' => [['id' => '1', 'action' => 'secret.read', 'resource_type' => 'secret', 'resource_uuid' => 'sec-uuid', 'success' => true, 'created_at' => '2026-05-10 12:00:00']], 'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0, 'has_more' => false]])),
            $ep('GET', '/api/v1/organizations/:uuid/integrations', 'Session cookie', 'Список интеграций ротации организации.', "curl https://example.test/api/v1/organizations/org-uuid/integrations \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'int-uuid', 'name' => 'Primary Vault', 'rotation_service_uuid' => 'svc-uuid', 'rotation_service_name' => 'Vault Rotator', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/integrations', 'Session cookie', 'Создать интеграцию ротации.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/integrations \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"Primary Vault\",\"rotation_service_uuid\":\"svc-uuid\",\"credentials\":{\"token\":\"secret\"}}'", $ok(['uuid' => 'int-uuid', 'name' => 'Primary Vault', 'rotation_service_uuid' => 'svc-uuid', 'rotation_service_name' => 'Vault Rotator', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('GET', '/api/v1/organizations/:uuid/integrations/:intUuid', 'Session cookie', 'Показать одну интеграцию.', "curl https://example.test/api/v1/organizations/org-uuid/integrations/int-uuid \
  -H 'Cookie: passway_session=...';", $ok(['uuid' => 'int-uuid', 'name' => 'Primary Vault', 'rotation_service_uuid' => 'svc-uuid', 'rotation_service_name' => 'Vault Rotator', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('PATCH', '/api/v1/organizations/:uuid/integrations/:intUuid', 'Session cookie', 'Обновить имя, credentials или `is_active` интеграции.', "curl -X PATCH https://example.test/api/v1/organizations/org-uuid/integrations/int-uuid \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"Primary Vault 2\",\"is_active\":true}'", $ok(['uuid' => 'int-uuid', 'name' => 'Primary Vault 2', 'rotation_service_uuid' => 'svc-uuid', 'rotation_service_name' => 'Vault Rotator', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/integrations/:intUuid', 'Session cookie', 'Удалить интеграцию.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/integrations/int-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
        ],
    ],
    [
        'title' => 'Каталоги',
        'description' => 'Ресурсные endpoint\'ы каталогов. Доступны по session cookie и по API-ключу с подходящими правами.',
        'endpoints' => [
            $ep('GET', '/api/v1/organizations/:uuid/directories', 'Session cookie или API key', 'Список каталогов организации.', "curl https://example.test/api/v1/organizations/org-uuid/directories \
  -H 'X-Api-Key: sv_...';", $ok([['uuid' => 'dir-uuid', 'name' => 'Infrastructure', 'parent_uuid' => null, 'depth' => 0, 'path' => '/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/directories', 'Session cookie или API key', 'Создать каталог.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"name\":\"Infrastructure\",\"parent_uuid\":null}'", $ok(['uuid' => 'dir-uuid', 'name' => 'Infrastructure', 'parent_uuid' => null, 'depth' => 0, 'path' => '/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid', 'Session cookie или API key', 'Показать каталог.', "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'dir-uuid', 'name' => 'Infrastructure', 'parent_uuid' => null, 'depth' => 0, 'path' => '/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('PATCH', '/api/v1/organizations/:uuid/directories/:dirUuid', 'Session cookie или API key', 'Переименовать и/или переместить каталог.', "curl -X PATCH https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"name\":\"Platform\",\"parent_uuid\":\"parent-uuid\"}'", $ok(['uuid' => 'dir-uuid', 'name' => 'Platform', 'parent_uuid' => 'parent-uuid', 'depth' => 1, 'path' => '/parent-uuid/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/directories/:dirUuid', 'Session cookie или API key', 'Удалить каталог.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid \
  -H 'X-Api-Key: sv_...';", $ok([])),
        ],
    ],
    [
        'title' => 'Группы и user permissions',
        'description' => 'Административное управление группами и fine-grained разрешениями для пользователей и групп.',
        'endpoints' => [
            $ep('GET', '/api/v1/organizations/:uuid/groups', 'Session cookie', 'Список групп.', "curl https://example.test/api/v1/organizations/org-uuid/groups \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'grp-uuid', 'name' => 'SRE', 'description' => 'Ops team', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/groups', 'Session cookie', 'Создать группу.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/groups \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"SRE\",\"description\":\"Ops team\"}'", $ok(['uuid' => 'grp-uuid', 'name' => 'SRE', 'description' => 'Ops team', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('GET', '/api/v1/organizations/:uuid/groups/:grpUuid', 'Session cookie', 'Показать группу.', "curl https://example.test/api/v1/organizations/org-uuid/groups/grp-uuid \
  -H 'Cookie: passway_session=...';", $ok(['uuid' => 'grp-uuid', 'name' => 'SRE', 'description' => 'Ops team', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/groups/:grpUuid', 'Session cookie', 'Удалить группу.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/groups/grp-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
            $ep('GET', '/api/v1/organizations/:uuid/groups/:grpUuid/members', 'Session cookie', 'Список участников группы.', "curl https://example.test/api/v1/organizations/org-uuid/groups/grp-uuid/members \
  -H 'Cookie: passway_session=...';", $ok([['group_id' => '1', 'user_id' => '2', 'added_by' => '1', 'added_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/groups/:grpUuid/members', 'Session cookie', 'Добавить пользователя в группу.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/groups/grp-uuid/members \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"user_uuid\":\"user-uuid\"}'", $ok(['group_id' => '1', 'user_id' => '2', 'added_by' => '1', 'added_at' => '2026-05-10 12:00:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/groups/:grpUuid/members/:userUuid', 'Session cookie', 'Удалить пользователя из группы.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/groups/grp-uuid/members/user-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid/permissions', 'Session cookie', 'Список fine-grained прав на каталог.', "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/permissions \
  -H 'Cookie: passway_session=...';", $ok([['id' => '1', 'subject_type' => 'user', 'subject_id' => '2', 'resource_type' => 'directory', 'resource_id' => '3', 'permission' => 'read', 'is_deny' => false, 'expires_at' => null, 'granted_by' => '1', 'created_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/permissions', 'Session cookie', 'Выдать право пользователю или группе на каталог.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/permissions \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"subject_type\":\"user\",\"subject_uuid\":\"user-uuid\",\"permission\":\"read\",\"is_deny\":false}'", $ok(['id' => '1', 'subject_type' => 'user', 'subject_id' => '2', 'resource_type' => 'directory', 'resource_id' => '3', 'permission' => 'read', 'is_deny' => false, 'expires_at' => null, 'granted_by' => '1', 'created_at' => '2026-05-10 12:00:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/directories/:dirUuid/permissions/:permId', 'Session cookie', 'Отозвать право.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/permissions/1 \
  -H 'Cookie: passway_session=...';", $ok([])),
        ],
    ],
    [
        'title' => 'Одобрения',
        'description' => 'Workflow запросов на одобрение для чувствительных секретов.',
        'endpoints' => [
            $ep('POST', '/api/v1/organizations/:uuid/secrets/:secUuid/approvals', 'Session cookie', 'Создать запрос на одобрение доступа к секрету.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/secrets/sec-uuid/approvals \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"request_type\":\"read\",\"reason\":\"Deploy\"}'", $ok(['uuid' => 'apr-uuid', 'secret_id' => '7', 'requested_by' => '1', 'request_type' => 'read', 'reason' => 'Deploy', 'status' => 'pending', 'approved_by' => null, 'rejection_reason' => null, 'expires_at' => null, 'created_at' => '2026-05-10 12:00:00', 'resolved_at' => null])),
            $ep('GET', '/api/v1/organizations/:uuid/approvals', 'Session cookie', 'Список моих запросов на одобрение.', "curl https://example.test/api/v1/organizations/org-uuid/approvals \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'apr-uuid', 'status' => 'pending', 'request_type' => 'read', 'created_at' => '2026-05-10 12:00:00']])) ,
            $ep('GET', '/api/v1/organizations/:uuid/approvals/pending', 'Session cookie', 'Список pending-запросов для модерации.', "curl https://example.test/api/v1/organizations/org-uuid/approvals/pending \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'apr-uuid', 'status' => 'pending', 'request_type' => 'read', 'created_at' => '2026-05-10 12:00:00']])) ,
            $ep('GET', '/api/v1/organizations/:uuid/approvals/:aprUuid', 'Session cookie', 'Показать один approval request.', "curl https://example.test/api/v1/organizations/org-uuid/approvals/apr-uuid \
  -H 'Cookie: passway_session=...';", $ok(['uuid' => 'apr-uuid', 'status' => 'pending', 'request_type' => 'read', 'created_at' => '2026-05-10 12:00:00'])),
            $ep('POST', '/api/v1/organizations/:uuid/approvals/:aprUuid/approve', 'Session cookie', 'Одобрить запрос и вернуть одноразовый access token.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/approvals/apr-uuid/approve \
  -H 'Cookie: passway_session=...';", $ok(['uuid' => 'apr-uuid', 'status' => 'approved', 'access_token' => 'approval-token'])),
            $ep('POST', '/api/v1/organizations/:uuid/approvals/:aprUuid/reject', 'Session cookie', 'Отклонить запрос с причиной.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/approvals/apr-uuid/reject \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"reason\":\"Not justified\"}'", $ok(['uuid' => 'apr-uuid', 'status' => 'rejected', 'rejection_reason' => 'Not justified'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/approvals/:aprUuid', 'Session cookie', 'Отозвать запрос.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/approvals/apr-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
            $ep('POST', '/api/v1/organizations/:uuid/approvals/:aprUuid/use', 'Session cookie', 'Использовать одноразовый approval token и получить значение секрета.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/approvals/apr-uuid/use \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"token\":\"approval-token\"}'", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'static', 'value' => 'secret-value'])),
        ],
    ],
    [
        'title' => 'API-ключи',
        'description' => 'Управление ключами и их permission scope. Сами эти маршруты доступны только по session cookie.',
        'endpoints' => [
            $ep('GET', '/api/v1/organizations/:uuid/api-keys', 'Session cookie', 'Список API-ключей организации.', "curl https://example.test/api/v1/organizations/org-uuid/api-keys \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'key-uuid', 'name' => 'CI', 'key_prefix' => 'sv_123456789', 'is_active' => true, 'last_used_at' => null, 'expires_at' => null, 'created_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/api-keys', 'Session cookie', 'Создать API-ключ. Сырой ключ возвращается только один раз.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/api-keys \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"CI\",\"expires_at\":null}'", $ok(['uuid' => 'key-uuid', 'name' => 'CI', 'key_prefix' => 'sv_123456789', 'is_active' => true, 'last_used_at' => null, 'expires_at' => null, 'created_at' => '2026-05-10 12:00:00', 'key' => 'sv_0123456789abcdef...'])),
            $ep('GET', '/api/v1/organizations/:uuid/api-keys/:keyUuid', 'Session cookie', 'Показать API-ключ без сырого значения.', "curl https://example.test/api/v1/organizations/org-uuid/api-keys/key-uuid \
  -H 'Cookie: passway_session=...';", $ok(['uuid' => 'key-uuid', 'name' => 'CI', 'key_prefix' => 'sv_123456789', 'is_active' => true, 'last_used_at' => null, 'expires_at' => null, 'created_at' => '2026-05-10 12:00:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/api-keys/:keyUuid', 'Session cookie', 'Отозвать API-ключ.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/api-keys/key-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
            $ep('GET', '/api/v1/organizations/:uuid/api-keys/:keyUuid/permissions', 'Session cookie', 'Список прав API-ключа.', "curl https://example.test/api/v1/organizations/org-uuid/api-keys/key-uuid/permissions \
  -H 'Cookie: passway_session=...';", $ok([['id' => '1', 'resource_type' => 'directory', 'resource_id' => '5', 'permission' => 'read', 'created_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/api-keys/:keyUuid/permissions', 'Session cookie', 'Добавить право API-ключу.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/api-keys/key-uuid/permissions \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"resource_type\":\"directory\",\"resource_id\":\"5\",\"permission\":\"read\"}'", $ok(['id' => '1', 'resource_type' => 'directory', 'resource_id' => '5', 'permission' => 'read', 'created_at' => '2026-05-10 12:00:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/api-keys/:keyUuid/permissions/:permId', 'Session cookie', 'Удалить право API-ключа.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/api-keys/key-uuid/permissions/1 \
  -H 'Cookie: passway_session=...';", $ok([])),
        ],
    ],
    [
        'title' => 'Секреты',
        'description' => 'Ресурсные endpoint\'ы секретов. Доступны по session cookie и по API-ключу с подходящим scope.',
        'endpoints' => [
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets', 'Session cookie или API key', 'Список секретов каталога без значений.', "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets \
  -H 'X-Api-Key: sv_...';", $ok([['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'static', 'requires_approval' => false, 'version' => 1, 'rotation_schedule' => null, 'last_rotated_at' => null, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets', 'Session cookie или API key', 'Создать static, template или dynamic secret.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"name\":\"DB_PASSWORD\",\"type\":\"static\",\"value\":\"secret\"}'", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'static', 'requires_approval' => false, 'version' => 1, 'rotation_schedule' => null, 'last_rotated_at' => null, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/template-preview', 'Session cookie или API key', 'Предпросмотр шаблонного секрета до создания или регенерации.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/template-preview \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"template_uuid\":\"tpl-uuid\",\"template_overrides\":{\"length\":32}}'", $ok(['template_uuid' => 'tpl-uuid', 'template_name' => 'Password', 'template_type' => 'password', 'value' => 'secret', 'display_value' => 'secret', 'extra_fields' => [], 'parameter_schema' => [], 'template_overrides' => ['length' => 32]])),
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid', 'Session cookie или API key', 'Показать секрет со значением и metadata ротации.', "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'dynamic', 'requires_approval' => false, 'version' => 2, 'rotation_schedule' => '0 3 * * *', 'last_rotated_at' => '2026-05-10 12:00:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00', 'value' => 'secret', 'rotation_input' => ['username' => 'db-user'], 'rotation_outputs' => ['dsn' => 'postgres://...'], 'rotation_primary_field' => 'password'])),
            $ep('PATCH', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid', 'Session cookie или API key', 'Обновить имя, значение или `rotation_schedule`. `rotation_integration_uuid` менять нельзя.', "curl -X PATCH https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"rotation_schedule\":\"0 4 * * *\"}'", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'dynamic', 'requires_approval' => false, 'version' => 2, 'rotation_schedule' => '0 4 * * *', 'last_rotated_at' => '2026-05-10 12:00:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:12:00'])),
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/regenerate', 'Session cookie или API key', 'Перегенерировать template secret.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid/regenerate \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"template_overrides\":{\"length\":40}}'", $ok(['uuid' => 'sec-uuid', 'name' => 'Generated Password', 'type' => 'template', 'requires_approval' => false, 'version' => 2, 'rotation_schedule' => null, 'last_rotated_at' => null, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00', 'value' => 'secret', 'display_value' => 'secret', 'extra_fields' => [], 'template_uuid' => 'tpl-uuid', 'template_name' => 'Password', 'template_type' => 'password', 'parameter_schema' => [], 'template_overrides' => ['length' => 40]])),
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/rotate', 'Session cookie или API key', 'Запустить немедленную ротацию dynamic secret.', "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid/rotate \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'dynamic', 'requires_approval' => false, 'version' => 3, 'rotation_schedule' => '0 3 * * *', 'last_rotated_at' => '2026-05-10 12:15:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:15:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid', 'Session cookie или API key', 'Удалить секрет.', "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid \
  -H 'X-Api-Key: sv_...';", $ok([])),
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/versions', 'Session cookie или API key', 'История версий/ротаций секрета.', "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid/versions \
  -H 'X-Api-Key: sv_...';", $ok([['version' => 1, 'rotation_type' => 'manual', 'status' => 'success', 'created_at' => '2026-05-10 12:00:00']])) ,
        ],
    ],
    [
        'title' => 'Реестр сервисов ротации',
        'description' => 'Глобальные rotation services. Только session cookie; API-ключам недоступны.',
        'endpoints' => [
            $ep('GET', '/api/v1/rotation-services', 'Session cookie', 'Список сервисов ротации.', "curl https://example.test/api/v1/rotation-services \
  -H 'Cookie: passway_session=...';", $ok([['uuid' => 'svc-uuid', 'name' => 'Vault Rotator', 'url' => 'https://rotator.internal', 'health_url' => 'https://rotator.internal/health', 'spec' => [], 'is_active' => true, 'is_verified' => true, 'last_check_at' => '2026-05-10 12:00:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/rotation-services', 'Session cookie', 'Зарегистрировать сервис ротации.', "curl -X POST https://example.test/api/v1/rotation-services \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"Vault Rotator\",\"url\":\"https://rotator.internal\"}'", $ok(['uuid' => 'svc-uuid', 'name' => 'Vault Rotator', 'url' => 'https://rotator.internal', 'health_url' => 'https://rotator.internal/health', 'spec' => [], 'is_active' => true, 'is_verified' => false, 'last_check_at' => null, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('GET', '/api/v1/rotation-services/:svcUuid', 'Session cookie', 'Показать один сервис ротации.', "curl https://example.test/api/v1/rotation-services/svc-uuid \
  -H 'Cookie: passway_session=...';", $ok(['uuid' => 'svc-uuid', 'name' => 'Vault Rotator', 'url' => 'https://rotator.internal', 'health_url' => 'https://rotator.internal/health', 'spec' => [], 'is_active' => true, 'is_verified' => true, 'last_check_at' => '2026-05-10 12:00:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('PATCH', '/api/v1/rotation-services/:svcUuid', 'Session cookie', 'Обновить имя, URL или `is_active` сервиса.', "curl -X PATCH https://example.test/api/v1/rotation-services/svc-uuid \
  -H 'Content-Type: application/json' \
  -H 'Cookie: passway_session=...'; \
  -d '{\"name\":\"Vault Rotator EU\",\"is_active\":true}'", $ok(['uuid' => 'svc-uuid', 'name' => 'Vault Rotator EU', 'url' => 'https://rotator.internal', 'health_url' => 'https://rotator.internal/health', 'spec' => [], 'is_active' => true, 'is_verified' => true, 'last_check_at' => '2026-05-10 12:00:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00'])),
            $ep('POST', '/api/v1/rotation-services/:svcUuid/verify', 'Session cookie', 'Проверить доступность и спецификацию сервиса.', "curl -X POST https://example.test/api/v1/rotation-services/svc-uuid/verify \
  -H 'Cookie: passway_session=...';", $ok(['uuid' => 'svc-uuid', 'name' => 'Vault Rotator', 'url' => 'https://rotator.internal', 'health_url' => 'https://rotator.internal/health', 'spec' => ['integration_schema' => ['fields' => []]], 'is_active' => true, 'is_verified' => true, 'last_check_at' => '2026-05-10 12:12:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:12:00'])),
            $ep('DELETE', '/api/v1/rotation-services/:svcUuid', 'Session cookie', 'Удалить сервис ротации.', "curl -X DELETE https://example.test/api/v1/rotation-services/svc-uuid \
  -H 'Cookie: passway_session=...';", $ok([])),
        ],
    ],
];
