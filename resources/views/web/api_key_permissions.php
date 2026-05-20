<div class="topbar">
    <div>
        <div class="brand"><?= e(__('ui.app.name')) ?></div>
        <div class="muted" style="margin-top:.35rem;"><?= e(__('ui.api_key_permissions.for_key', ['name' => $apiKey->name])) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/api-keys"><?= e(__('ui.api_key_permissions.back_to_api_keys')) ?></a>
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage"><?= e(__('ui.app.manage_org')) ?></a>
        <a class="button secondary" href="/auth/logout"><?= e(__('ui.app.logout')) ?></a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h2 style="margin:0 0 .6rem;"><?= e(__('ui.api_key_permissions.key_summary')) ?></h2>
            <div class="muted"><?= e(__('ui.api_key_permissions.owner', ['owner' => $owner?->email ?? __('ui.api_key_permissions.unknown_owner')])) ?></div>
            <div class="muted"><?= e(__('ui.api_keys.prefix', ['prefix' => $apiKey->keyPrefix])) ?> · <?= e(__('ui.api_keys.environments.' . $apiKey->environment)) ?> · <?= e($apiKey->isActive ? __('ui.api_key_permissions.status_active') : __('ui.api_key_permissions.status_revoked')) ?></div>
            <div class="muted"><?= e(__('ui.api_key_permissions.created', ['created_at' => $apiKey->createdAt])) ?><?= $apiKey->expiresAt ? e(__('ui.api_key_permissions.expires_suffix', ['date' => $apiKey->expiresAt])) : '' ?></div>
        </div>

        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($apiKey->uuid) ?>/permissions" class="grid" style="gap:.75rem;">
            <h3 style="margin:0;"><?= e(__('ui.api_key_permissions.add_permission')) ?></h3>
            <div>
                <label for="permission-name"><?= e(__('ui.api_key_permissions.permission')) ?></label>
                <select id="permission-name" name="permission">
                    <?php foreach (\Passway\Models\ApiKeyPermission::VALID_PERMISSIONS as $permission): ?>
                        <option value="<?= e($permission) ?>"><?= e($permission) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="permission-target"><?= e(__('ui.api_key_permissions.target')) ?></label>
                <select id="permission-target" name="target">
                    <?php foreach ($permissionTargets as $target): ?>
                        <option value="<?= e($target['value']) ?>"><?= e($target['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"><?= e(__('ui.api_key_permissions.add')) ?></button>
        </form>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.api_key_permissions.existing')) ?></h2>
        <div class="grid" style="gap:.75rem;">
            <?php foreach ($permissions as $permission): ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($permission->permission) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($permissionLabels[$permission->id] ?? $permission->resourceType) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_key_permissions.added', ['date' => $permission->createdAt])) ?></div>
                    </div>
                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($apiKey->uuid) ?>/permissions/<?= e($permission->id) ?>/delete">
                        <button type="submit" class="danger"><?= e(__('ui.api_key_permissions.remove_permission')) ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($permissions === []): ?><div class="muted"><?= e(__('ui.api_key_permissions.no_permissions')) ?></div><?php endif; ?>
        </div>
    </section>
</div>
