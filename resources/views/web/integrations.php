<?php
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid . '/manage', 'label' => __('ui.app.back_to_management')],
    ['href' => '/rotation-services', 'label' => __('ui.home.rotation_services')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.integrations.for_org', ['organization' => $organization->name])) ?></h1>
</section>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.integrations.create')) ?></h2>
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/integrations" class="grid" style="gap:.75rem;">
            <div>
                <label for="integration-name"><?= e(__('ui.integrations.integration_name')) ?></label>
                <input id="integration-name" name="name" placeholder="<?= e(__('ui.integrations.integration_name_placeholder')) ?>" required>
            </div>
            <div>
                <label for="integration-service"><?= e(__('ui.integrations.rotation_service')) ?></label>
                <select id="integration-service" name="rotation_service_uuid">
                    <?php foreach ($services as $service): ?>
                        <?php if ($service->isActive && $service->isVerified): ?>
                            <option value="<?= e($service->uuid) ?>"><?= e($service->name) ?> (<?= e($service->url) ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="integration-credentials"><?= e(__('ui.integrations.credentials_json')) ?></label>
                <textarea id="integration-credentials" name="credentials_json" rows="8" placeholder='{"token":"..."}'>{}</textarea>
            </div>
            <button type="submit"><?= e(__('ui.integrations.create_submit')) ?></button>
        </form>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.integrations.existing')) ?></h2>
        <div class="grid" style="gap:.9rem;">
            <?php foreach ($integrations as $integration): $service = $serviceMap[$integration->rotationServiceId] ?? null; ?>
                <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($integration->name) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($service?->name ?? __('ui.integrations.unknown_service')) ?><?= $service ? ' · ' . e($service->url) : '' ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($integration->isActive ? __('ui.app.active') : __('ui.app.inactive')) ?> · <?= e(__('ui.integrations.updated', ['date' => $integration->updatedAt])) ?></div>
                    </div>

                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/integrations/<?= e($integration->uuid) ?>/update" class="grid" style="gap:.75rem;">
                        <div>
                            <label><?= e(__('ui.integrations.name')) ?></label>
                            <input name="name" value="<?= e($integration->name) ?>" required>
                        </div>
                        <label style="display:flex; gap:.5rem; align-items:center;">
                            <input type="checkbox" name="is_active" value="1" <?= $integration->isActive ? 'checked' : '' ?>>
                            <span><?= e(__('ui.integrations.active')) ?></span>
                        </label>
                        <div>
                            <label><?= e(__('ui.integrations.replace_credentials_optional')) ?></label>
                            <textarea name="credentials_json" rows="6" placeholder='{"token":"new-value"}'></textarea>
                        </div>
                        <div class="actions">
                            <button type="submit"><?= e(__('ui.integrations.save')) ?></button>
                            <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/integrations/<?= e($integration->uuid) ?>/delete"><?= e(__('ui.app.delete')) ?></button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($integrations === []): ?><div class="muted"><?= e(__('ui.integrations.no_integrations')) ?></div><?php endif; ?>
        </div>
    </section>
</div>
