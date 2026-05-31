<?php if (empty($organizationSettingsPartial)) { require base_path('resources/views/partials/auth_topbar.php'); } ?>

<div class="js-organization-settings-page" data-page-title="<?= e((string) ($title ?? 'Passway')) ?>">
<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.members')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($organization->name) ?></div>
</section>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/organization_settings_sidebar.php'); ?>

    <section class="panel" style="padding:1.5rem;">
        <style>
            .org-manage-member-card {
                min-width: 0;
            }
        </style>
        <div class="grid" style="gap:.8rem;">
            <?php foreach ($memberRows as $row): $member = $row['member']; $memberUser = $row['user']; ?>
                <div class="panel panel-muted org-manage-member-card" style="padding:1rem; display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($memberUser !== null ? user_label_with_email($memberUser) : __('ui.organization_manage.unknown_user')) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.organization_manage.joined', ['date' => $member->joinedAt])) ?></div>
                    </div>
                    <?php if ($member->role === 'owner' || empty($canManageSettings)): ?>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.organization_manage.roles.' . $member->role)) ?></div>
                    <?php else: ?>
                        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/role" class="grid field-actions-3" style="gap:.75rem;">
                            <div>
                                <label><?= e(__('ui.organization_manage.role')) ?></label>
                                <select name="role">
                                    <?php foreach (array_values(array_filter(\Passway\Models\OrganizationMember::ROLES, static fn(string $role): bool => $role !== 'owner')) as $role): ?>
                                        <option value="<?= e($role) ?>" <?= $member->role === $role ? 'selected' : '' ?>><?= e(__('ui.organization_manage.roles.' . $role)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit"><?= e(__('ui.app.update')) ?></button>
                            <?php if (($memberUser?->uuid ?? '') !== $user->uuid): ?>
                                <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/remove"><?= e(__('ui.app.remove')) ?></button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
</div>
