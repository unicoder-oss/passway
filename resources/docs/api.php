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
        'title' => __('ui.api_docs.sections.service.title'),
        'description' => __('ui.api_docs.sections.service.description'),
        'endpoints' => [
            $ep('GET', '/api/v1', __('ui.api_docs.access.public'), __('ui.api_docs.endpoints.root.summary'), "curl https://example.test/api/v1", $json(['service' => 'Passway API', 'version' => 'v1', 'status' => 'ok'])),
            $ep('GET', '/api/v1/health', __('ui.api_docs.access.public'), __('ui.api_docs.endpoints.health.summary'), "curl https://example.test/api/v1/health", $json(['status' => 'ok', 'time' => '2026-05-10T12:00:00+00:00'])),
        ],
    ],
    [
        'title' => __('ui.api_docs.sections.organizations.title'),
        'description' => __('ui.api_docs.sections.organizations.description'),
        'endpoints' => [
            $ep('GET', '/api/v1/organizations/:uuid', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.organization_show.summary'), "curl https://example.test/api/v1/organizations/org-uuid \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'org-uuid', 'name' => 'Platform Team', 'slug' => 'platform-team', 'is_active' => true, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
        ],
    ],
    [
        'title' => __('ui.api_docs.sections.directories.title'),
        'description' => __('ui.api_docs.sections.directories.description'),
        'endpoints' => [
            $ep('GET', '/api/v1/organizations/:uuid/directories', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.directories_list.summary'), "curl https://example.test/api/v1/organizations/org-uuid/directories \
  -H 'X-Api-Key: sv_...';", $ok([['uuid' => 'dir-uuid', 'name' => 'Infrastructure', 'parent_uuid' => null, 'depth' => 0, 'path' => '/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/directories', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.directories_create.summary'), "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"name\":\"Infrastructure\",\"parent_uuid\":null}'", $ok(['uuid' => 'dir-uuid', 'name' => 'Infrastructure', 'parent_uuid' => null, 'depth' => 0, 'path' => '/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.directories_show.summary'), "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'dir-uuid', 'name' => 'Infrastructure', 'parent_uuid' => null, 'depth' => 0, 'path' => '/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('PATCH', '/api/v1/organizations/:uuid/directories/:dirUuid', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.directories_update.summary'), "curl -X PATCH https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"name\":\"Platform\",\"parent_uuid\":\"parent-uuid\"}'", $ok(['uuid' => 'dir-uuid', 'name' => 'Platform', 'parent_uuid' => 'parent-uuid', 'depth' => 1, 'path' => '/parent-uuid/dir-uuid', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/directories/:dirUuid', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.directories_delete.summary'), "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid \
  -H 'X-Api-Key: sv_...';", $ok([])),
        ],
    ],
    [
        'title' => __('ui.api_docs.sections.secrets.title'),
        'description' => __('ui.api_docs.sections.secrets.description'),
        'endpoints' => [
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_list.summary'), "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets \
  -H 'X-Api-Key: sv_...';", $ok([['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'static', 'requires_approval' => false, 'version' => 1, 'rotation_schedule' => null, 'last_rotated_at' => null, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00']])) ,
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_create.summary'), "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"name\":\"DB_PASSWORD\",\"type\":\"static\",\"value\":\"secret\"}'", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'static', 'requires_approval' => false, 'version' => 1, 'rotation_schedule' => null, 'last_rotated_at' => null, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:00:00'])),
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/template-preview', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_template_preview.summary'), "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/template-preview \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"template_uuid\":\"tpl-uuid\",\"template_overrides\":{\"length\":32}}'", $ok(['template_uuid' => 'tpl-uuid', 'template_name' => 'Password', 'template_type' => 'password', 'value' => 'secret', 'display_value' => 'secret', 'extra_fields' => [], 'parameter_schema' => [], 'template_overrides' => ['length' => 32]])),
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_show.summary'), "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'dynamic', 'requires_approval' => false, 'version' => 2, 'rotation_schedule' => '0 3 * * *', 'last_rotated_at' => '2026-05-10 12:00:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00', 'value' => 'secret', 'rotation_input' => ['username' => 'db-user'], 'rotation_outputs' => ['dsn' => 'postgres://...'], 'rotation_primary_field' => 'password'])),
            $ep('PATCH', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_update.summary'), "curl -X PATCH https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"rotation_schedule\":\"0 4 * * *\"}'", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'dynamic', 'requires_approval' => false, 'version' => 2, 'rotation_schedule' => '0 4 * * *', 'last_rotated_at' => '2026-05-10 12:00:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:12:00'])),
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/regenerate', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_regenerate.summary'), "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid/regenerate \
  -H 'Content-Type: application/json' \
  -H 'X-Api-Key: sv_...'; \
  -d '{\"template_overrides\":{\"length\":40}}'", $ok(['uuid' => 'sec-uuid', 'name' => 'Generated Password', 'type' => 'template', 'requires_approval' => false, 'version' => 2, 'rotation_schedule' => null, 'last_rotated_at' => null, 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:10:00', 'value' => 'secret', 'display_value' => 'secret', 'extra_fields' => [], 'template_uuid' => 'tpl-uuid', 'template_name' => 'Password', 'template_type' => 'password', 'parameter_schema' => [], 'template_overrides' => ['length' => 40]])),
            $ep('POST', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/rotate', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_rotate.summary'), "curl -X POST https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid/rotate \
  -H 'X-Api-Key: sv_...';", $ok(['uuid' => 'sec-uuid', 'name' => 'DB_PASSWORD', 'type' => 'dynamic', 'requires_approval' => false, 'version' => 3, 'rotation_schedule' => '0 3 * * *', 'last_rotated_at' => '2026-05-10 12:15:00', 'created_at' => '2026-05-10 12:00:00', 'updated_at' => '2026-05-10 12:15:00'])),
            $ep('DELETE', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_delete.summary'), "curl -X DELETE https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid \
  -H 'X-Api-Key: sv_...';", $ok([])),
            $ep('GET', '/api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/versions', __('ui.api_docs.access.api_key'), __('ui.api_docs.endpoints.secrets_versions.summary'), "curl https://example.test/api/v1/organizations/org-uuid/directories/dir-uuid/secrets/sec-uuid/versions \
  -H 'X-Api-Key: sv_...';", $ok([['version' => 1, 'rotation_type' => 'manual', 'status' => 'success', 'created_at' => '2026-05-10 12:00:00']])) ,
        ],
    ],
];
