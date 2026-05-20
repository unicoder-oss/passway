<?php
$topbarLinks = [
    ['href' => '/', 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.rotation_services.subtitle')) ?></h1>
</section>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h2 style="margin:0 0 .5rem;"><?= e(__('ui.rotation_services.register')) ?></h2>
            <div class="muted"><?= e(__('ui.rotation_services.admin_only_hint')) ?></div>
        </div>

        <?php if ($isSetupAdmin): ?>
            <form method="POST" action="/rotation-services" class="grid" style="gap:.75rem;">
                <div>
                    <label for="rotation-name"><?= e(__('ui.rotation_services.service_name')) ?></label>
                    <input id="rotation-name" name="name" placeholder="<?= e(__('ui.rotation_services.service_name_placeholder')) ?>" required>
                </div>
                <div>
                    <label for="rotation-url"><?= e(__('ui.rotation_services.base_url')) ?></label>
                    <input id="rotation-url" name="url" placeholder="<?= e(__('ui.rotation_services.base_url_placeholder')) ?>" required>
                </div>
                <button type="submit"><?= e(__('ui.rotation_services.register_submit')) ?></button>
            </form>
        <?php else: ?>
            <div class="muted"><?= e(__('ui.rotation_services.browse_only_hint')) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.rotation_services.registered')) ?></h2>
        <div class="grid" style="gap:.9rem;">
            <?php foreach ($services as $service): $spec = $service->spec(); ?>
                <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($service->name) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($service->url) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($service->isVerified ? __('ui.app.verified') : __('ui.app.not_verified')) ?> · <?= e($service->isActive ? __('ui.app.active') : __('ui.app.inactive')) ?><?= $service->lastCheckAt ? ' · ' . e(__('ui.rotation_services.checked', ['date' => $service->lastCheckAt])) : '' ?></div>
                        <?php if ($spec !== []): ?><div class="muted" style="font-size:.92rem; margin-top:.35rem;"><?= e(__('ui.rotation_services.spec_keys', ['keys' => implode(', ', array_keys($spec))])) ?></div><?php endif; ?>
                    </div>

                    <?php if ($isSetupAdmin): ?>
                        <form method="POST" action="/rotation-services/<?= e($service->uuid) ?>/update" class="grid" style="gap:.75rem;">
                            <div>
                                <label><?= e(__('ui.integrations.name')) ?></label>
                                <input name="name" value="<?= e($service->name) ?>" required>
                            </div>
                            <div>
                                <label><?= e(__('ui.rotation_services.base_url')) ?></label>
                                <input name="url" value="<?= e($service->url) ?>" required>
                            </div>
                            <label style="display:flex; gap:.5rem; align-items:center;">
                                <input type="checkbox" name="is_active" value="1" <?= $service->isActive ? 'checked' : '' ?>>
                                <span><?= e(__('ui.rotation_services.active')) ?></span>
                            </label>
                            <div class="actions">
                                <button type="submit"><?= e(__('ui.app.save')) ?></button>
                                <button type="submit" formaction="/rotation-services/<?= e($service->uuid) ?>/verify"><?= e(__('ui.rotation_services.verify')) ?></button>
                                <button type="submit" class="danger" formaction="/rotation-services/<?= e($service->uuid) ?>/delete"><?= e(__('ui.app.delete')) ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($services === []): ?><div class="muted"><?= e(__('ui.rotation_services.no_services')) ?></div><?php endif; ?>
        </div>
    </section>
</div>
