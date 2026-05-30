<?php if (empty($organizationSettingsPartial)) { require base_path('resources/views/partials/auth_topbar.php'); } ?>

<div class="js-organization-settings-page" data-page-title="<?= e((string) ($title ?? 'Passway')) ?>">
<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.groups')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($organization->name) ?></div>
</section>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/organization_settings_sidebar.php'); ?>
    <div class="grid grid-2" style="align-items:start; gap:1rem;">
        <?php if (!empty($canManageGroups)): ?>
            <section class="panel" style="padding:1.5rem;">
                <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.create')) ?></h2>
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/groups" class="grid" style="gap:.75rem;">
                    <div>
                        <label for="group-name"><?= e(__('ui.groups.name')) ?></label>
                        <input id="group-name" name="name" placeholder="<?= e(__('ui.groups.name_placeholder')) ?>" required>
                    </div>
                    <div>
                        <label for="group-description"><?= e(__('ui.groups.description')) ?></label>
                        <textarea id="group-description" name="description" rows="4" placeholder="<?= e(__('ui.groups.description_placeholder')) ?>"></textarea>
                    </div>
                    <button type="submit"><?= e(__('ui.groups.create_submit')) ?></button>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel" style="padding:1.5rem;">
            <h2 style="margin:0 0 1rem;"><?= e(__('ui.groups.existing')) ?></h2>
            <div class="grid" style="gap:.75rem;">
                <?php foreach ($groups as $groupRow): ?>
                    <?php $group = $groupRow['group']; ?>
                    <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                        <div>
                            <div style="font-weight:700;"><?= e($group->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e($group->description ?? __('ui.groups.no_description')) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e(__('ui.groups.member_count', ['count' => (string) $groupRow['member_count']])) ?></div>
                        </div>
                        <div class="actions">
                            <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>"><?= e(__('ui.groups.manage_members')) ?></a>
                            <?php if (!empty($canManageGroups)): ?>
                                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/groups/<?= e($group->uuid) ?>/delete" onsubmit="return confirm('<?= e(__('ui.groups.delete_confirm')) ?>');">
                                    <button type="submit" class="danger"><?= e(__('ui.groups.delete_group')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($groups === []): ?><div class="muted"><?= e(__('ui.groups.no_groups')) ?></div><?php endif; ?>
            </div>
        </section>
    </div>
</div>
</div>
