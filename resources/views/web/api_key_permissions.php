<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Permissions for <?= e($apiKey->name) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/api-keys">Back to API Keys</a>
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage">Manage Org</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h2 style="margin:0 0 .6rem;">Key Summary</h2>
            <div class="muted">Owner <?= e($owner?->email ?? 'unknown') ?></div>
            <div class="muted">Prefix <?= e($apiKey->keyPrefix) ?> · <?= e($apiKey->environment) ?> · <?= $apiKey->isActive ? 'active' : 'revoked' ?></div>
            <div class="muted">Created <?= e($apiKey->createdAt) ?><?= $apiKey->expiresAt ? ' · Expires ' . e($apiKey->expiresAt) : '' ?></div>
        </div>

        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($apiKey->uuid) ?>/permissions" class="grid" style="gap:.75rem;">
            <h3 style="margin:0;">Add Permission</h3>
            <div>
                <label for="permission-name">Permission</label>
                <select id="permission-name" name="permission">
                    <?php foreach (\Passway\Models\ApiKeyPermission::VALID_PERMISSIONS as $permission): ?>
                        <option value="<?= e($permission) ?>"><?= e($permission) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="permission-target">Target</label>
                <select id="permission-target" name="target">
                    <?php foreach ($permissionTargets as $target): ?>
                        <option value="<?= e($target['value']) ?>"><?= e($target['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Add Permission</button>
        </form>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Existing Permissions</h2>
        <div class="grid" style="gap:.75rem;">
            <?php foreach ($permissions as $permission): ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($permission->permission) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($permissionLabels[$permission->id] ?? $permission->resourceType) ?></div>
                        <div class="muted" style="font-size:.92rem;">Added <?= e($permission->createdAt) ?></div>
                    </div>
                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($apiKey->uuid) ?>/permissions/<?= e($permission->id) ?>/delete">
                        <button type="submit" class="danger">Remove Permission</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($permissions === []): ?><div class="muted">No permissions configured yet.</div><?php endif; ?>
        </div>
    </section>
</div>
