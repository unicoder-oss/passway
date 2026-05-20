<?php
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid, 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/organizations/' . $organization->uuid . '/audit', 'label' => __('ui.organization_manage.audit_log')],
    ['href' => '/organizations/' . $organization->uuid . '/api-keys', 'label' => __('ui.organization_manage.api_keys')],
    ['href' => '/organizations/' . $organization->uuid . '/integrations', 'label' => __('ui.organization_manage.integrations')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.manage', ['organization' => $organization->name])) ?></h1>
</section>

<div class="grid grid-2-compact" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.organization_manage.members')) ?></h2>
        <div class="grid" style="gap:.8rem;">
            <?php foreach ($members as $member): $memberUser = \Passway\Models\User::findById($member->userId); ?>
                <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($memberUser?->email ?? __('ui.organization_manage.unknown_user')) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.organization_manage.joined', ['date' => $member->joinedAt])) ?></div>
                    </div>
                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/role" class="grid field-actions-3" style="gap:.75rem;">
                        <div>
                            <label><?= e(__('ui.organization_manage.role')) ?></label>
                            <select name="role">
                                <?php foreach (\Passway\Models\OrganizationMember::ROLES as $role): ?>
                                    <option value="<?= e($role) ?>" <?= $member->role === $role ? 'selected' : '' ?>><?= e(__('ui.organization_manage.roles.' . $role)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"><?= e(__('ui.app.update')) ?></button>
                        <?php if (($memberUser?->uuid ?? '') !== $user->uuid): ?>
                            <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/remove"><?= e(__('ui.app.remove')) ?></button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="grid" style="gap:1rem;">
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.organization_manage.create_invite')) ?></h3>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites" class="grid" style="gap:.75rem;">
                <div>
                    <label for="invite-role"><?= e(__('ui.organization_manage.role')) ?></label>
                    <select id="invite-role" name="role">
                        <option value="user"><?= e(__('ui.organization_manage.roles.user')) ?></option>
                        <option value="observer"><?= e(__('ui.organization_manage.roles.observer')) ?></option>
                        <option value="moderator"><?= e(__('ui.organization_manage.roles.moderator')) ?></option>
                        <option value="admin"><?= e(__('ui.organization_manage.roles.admin')) ?></option>
                    </select>
                </div>
                <div>
                    <label for="invite-ttl"><?= e(__('ui.organization_manage.ttl_hours')) ?></label>
                    <input id="invite-ttl" type="number" name="ttl" value="1" min="1" max="168">
                </div>
                <button type="submit"><?= e(__('ui.organization_manage.create_invite_link')) ?></button>
            </form>
        </div>

        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.organization_manage.active_invites')) ?></h3>
            <div class="grid" style="gap:.75rem;">
                <?php foreach ($invites as $invite): ?>
                    <?php $inviteUrl = app_url('/invite/' . $invite->token); ?>
                    <div class="panel panel-muted" style="padding:1rem;">
                        <div style="font-weight:700;"><?= e(__('ui.organization_manage.role')) ?>: <?= e($invite->role) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.organization_manage.expires', ['date' => $invite->expiresAt])) ?></div>
                        <div style="margin:.5rem 0 .75rem;">
                            <label><?= e(__('ui.organization_manage.link', ['link' => $inviteUrl])) ?></label>
                            <input class="mono js-copy-on-click" value="<?= e($inviteUrl) ?>" readonly>
                        </div>
                        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites/<?= e($invite->uuid) ?>/revoke">
                            <button type="submit" class="danger"><?= e(__('ui.organization_manage.revoke_invite')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if ($invites === []): ?><div class="muted"><?= e(__('ui.organization_manage.no_active_invites')) ?></div><?php endif; ?>
            </div>
        </div>
    </section>
</div>

<script>
(() => {
    const fields = document.querySelectorAll('.js-copy-on-click');

    for (const field of fields) {
        field.addEventListener('click', async () => {
            field.focus();
            field.select();

            try {
                await navigator.clipboard.writeText(field.value);
            } catch (error) {
                document.execCommand('copy');
            }
        });
    }
})();
</script>
