<?php
$topbarTitle = __('ui.api_keys.for_org', ['organization' => $organization->name]);
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid . '/manage', 'label' => __('ui.app.back_to_management')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<?php if (!empty($createdRawKey)): ?>
    <div class="success" style="margin-bottom:1rem;">
        <div style="font-weight:700; margin-bottom:.4rem;"><?= e(__('ui.api_keys.copy_now')) ?></div>
        <input class="mono" value="<?= e((string) $createdRawKey) ?>" readonly>
    </div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.api_keys.create')) ?></h2>
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys" class="grid" style="gap:.75rem;">
            <div>
                <label for="key-name"><?= e(__('ui.api_keys.name')) ?></label>
                <input id="key-name" name="name" placeholder="<?= e(__('ui.api_keys.name_placeholder')) ?>" required>
            </div>
            <div>
                <label for="key-environment"><?= e(__('ui.api_keys.environment')) ?></label>
                <select id="key-environment" name="environment">
                    <option value="production"><?= e(__('ui.api_keys.environments.production')) ?></option>
                    <option value="staging"><?= e(__('ui.api_keys.environments.staging')) ?></option>
                    <option value="development"><?= e(__('ui.api_keys.environments.development')) ?></option>
                </select>
            </div>
            <div>
                <label for="key-expires"><?= e(__('ui.api_keys.expires_at_optional')) ?></label>
                <input id="key-expires" name="expires_at" placeholder="2026-12-31 23:59:59">
            </div>
            <button type="submit"><?= e(__('ui.api_keys.create')) ?></button>
        </form>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.api_keys.existing')) ?></h2>
        <div class="grid" style="gap:.75rem;">
            <?php foreach ($keys as $key): ?>
                <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($key->name) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_keys.prefix', ['prefix' => $key->keyPrefix])) ?> · <?= e(__('ui.api_keys.environments.' . $key->environment)) ?> · <?= e($key->isActive ? __('ui.api_key_permissions.status_active') : __('ui.api_key_permissions.status_revoked')) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_keys.created', ['created_at' => $key->createdAt])) ?><?= $key->expiresAt ? e(__('ui.api_keys.expires_suffix', ['date' => $key->expiresAt])) : '' ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_keys.last_used', ['date' => (string) ($key->lastUsedAt ?? __('ui.app.never'))])) ?></div>
                    </div>
                    <div class="actions">
                        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($key->uuid) ?>/permissions"><?= e(__('ui.api_keys.permissions')) ?></a>
                        <?php if ($key->isActive): ?>
                            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($key->uuid) ?>/revoke">
                                <button type="submit" class="danger"><?= e(__('ui.api_keys.revoke_key')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($keys === []): ?><div class="muted"><?= e(__('ui.api_keys.no_keys')) ?></div><?php endif; ?>
        </div>
    </section>
</div>
