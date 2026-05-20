<?php
$topbarTitle = __('ui.secret.details_for_org', ['organization' => $organization->name]);
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid . '?dir=' . $directory->uuid, 'label' => __('ui.secret.back_to_directory')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>

<div class="grid grid-2-compact" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h1 style="margin:0; font-size:1.6rem;"><?= e($secret->name) ?></h1>
            <p class="muted" style="margin:.45rem 0 0;"><?= e(__('ui.secret.meta', ['type' => __('ui.home.types.' . $secret->type), 'version' => (string) $secret->version, 'directory' => $directory->name])) ?></p>
            <div class="actions" style="margin-top:.75rem;">
                <span class="pill"><?= e(__('ui.home.types.' . $secret->type)) ?></span>
                <?php if ($secret->rotationSchedule !== null && $secret->rotationSchedule !== ''): ?><span class="pill mono"><?= e(__('ui.secret.schedule', ['schedule' => $secret->rotationSchedule])) ?></span><?php endif; ?>
                <?php if ($secret->lastRotatedAt !== null): ?><span class="pill"><?= e(__('ui.secret.last_rotated', ['date' => $secret->lastRotatedAt])) ?></span><?php endif; ?>
                <?php if ($selectedIntegration !== null): ?><span class="pill"><?= e(__('ui.secret.integration', ['name' => $selectedIntegration->name])) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="panel panel-muted" style="padding:1rem;">
            <label><?= e(__('ui.secret.current_value')) ?></label>
            <textarea class="mono" rows="8" readonly><?= e($value) ?></textarea>
        </div>
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($directory->uuid) ?>/secrets/<?= e($secret->uuid) ?>/update" class="grid grid-2">
            <div>
                <label for="secret-name"><?= e(__('ui.secret.rename_secret')) ?></label>
                <input id="secret-name" name="name" value="<?= e($secret->name) ?>">
            </div>
            <div>
                <label for="secret-value"><?= e(__('ui.secret.replace_value')) ?></label>
                <input id="secret-value" name="value" placeholder="<?= e(__('ui.secret.replace_value_placeholder')) ?>">
            </div>
            <div>
                <label for="secret-rotation-integration"><?= e(__('ui.secret.rotation_integration')) ?></label>
                <select id="secret-rotation-integration" name="rotation_integration_uuid">
                    <option value=""><?= e(__('ui.app.none')) ?></option>
                    <?php foreach ($integrations as $integration): ?>
                        <option value="<?= e($integration->uuid) ?>" <?= $selectedIntegration?->uuid === $integration->uuid ? 'selected' : '' ?>><?= e($integration->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="secret-rotation-schedule"><?= e(__('ui.secret.rotation_schedule')) ?></label>
                <input id="secret-rotation-schedule" class="mono" name="rotation_schedule" value="<?= e((string) ($secret->rotationSchedule ?? '')) ?>" placeholder="0 3 * * *">
            </div>
            <div class="muted" style="grid-column:1 / -1;"><?= e(__('ui.secret.leave_empty_hint')) ?></div>
            <div style="grid-column:1 / -1; display:flex; gap:.75rem; flex-wrap:wrap;">
                <button type="submit"><?= e(__('ui.secret.save_changes')) ?></button>
            </div>
        </form>
    </section>

    <section class="grid" style="gap:1rem;">
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.secret.manual_actions')) ?></h3>
            <div class="grid" style="gap:.75rem;">
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($directory->uuid) ?>/secrets/<?= e($secret->uuid) ?>/rotate">
                    <button type="submit"><?= e(__('ui.secret.rotate_secret')) ?></button>
                </form>
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($directory->uuid) ?>/secrets/<?= e($secret->uuid) ?>/delete">
                    <button type="submit" class="danger"><?= e(__('ui.secret.delete_secret')) ?></button>
                </form>
            </div>
        </div>
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.secret.version_history')) ?></h3>
            <div class="grid" style="gap:.6rem;">
                <?php foreach ($versions as $version): ?>
                    <div class="panel" style="padding:.85rem; background:rgba(15,23,42,.55);">
                        <div style="font-weight:700;"><?= e(__('ui.secret.version_label', ['version' => (string) $version->version])) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.secret.version_meta', ['rotation_type' => $version->rotationType, 'status' => $version->status, 'created_at' => $version->createdAt])) ?></div>
                        <?php if ($version->errorMessage !== null): ?><div class="muted" style="margin-top:.25rem;"><?= e($version->errorMessage) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($versions === []): ?><div class="muted"><?= e(__('ui.secret.no_versions')) ?></div><?php endif; ?>
            </div>
        </div>
    </section>
</div>
