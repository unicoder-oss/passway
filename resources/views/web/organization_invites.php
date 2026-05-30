<?php if (empty($organizationSettingsPartial)) { require base_path('resources/views/partials/auth_topbar.php'); } ?>

<div class="js-organization-settings-page" data-page-title="<?= e((string) ($title ?? 'Passway')) ?>">
<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.sections.invites')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($organization->name) ?></div>
</section>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/organization_settings_sidebar.php'); ?>

    <div class="grid grid-2" style="align-items:start; gap:1rem;">
        <section class="panel" style="padding:1rem;">
            <h2 style="margin:0 0 .75rem;"><?= e(__('ui.organization_manage.create_invite')) ?></h2>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites" class="grid" style="gap:.75rem;" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
                <div>
                    <label for="invite-role"><?= e(__('ui.organization_manage.role')) ?></label>
                    <select id="invite-role" name="role">
                        <option value="reader"><?= e(__('ui.organization_manage.roles.reader')) ?></option>
                        <option value="editor"><?= e(__('ui.organization_manage.roles.editor')) ?></option>
                        <option value="admin"><?= e(__('ui.organization_manage.roles.admin')) ?></option>
                    </select>
                </div>
                <div>
                    <label for="invite-ttl"><?= e(__('ui.organization_manage.ttl_hours')) ?></label>
                    <input id="invite-ttl" type="number" name="ttl" value="1" min="1" max="168">
                </div>
                <button type="submit"><?= e(__('ui.organization_manage.create_invite_link')) ?></button>
            </form>
        </section>

        <section class="panel" style="padding:1rem;">
            <style>
                .org-manage-invite-card {
                    min-width: 0;
                }
                .org-manage-invite-link {
                    width: 100%;
                    min-width: 0;
                    cursor: pointer;
                }
            </style>
            <h2 style="margin:0 0 .75rem;"><?= e(__('ui.organization_manage.active_invites')) ?></h2>
            <div class="grid" style="gap:.75rem;">
                <?php foreach ($invites as $invite): ?>
                    <?php $inviteUrl = app_url('/invite/' . $invite->token); ?>
                    <div class="panel panel-muted org-manage-invite-card" style="padding:1rem;">
                        <div style="font-weight:700;"><?= e(__('ui.organization_manage.role')) ?>: <?= e(__('ui.organization_manage.roles.' . $invite->role)) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= __('ui.organization_manage.expires', ['date' => local_datetime($invite->expiresAt)]) ?></div>
                        <div style="margin:.5rem 0 .75rem;">
                            <input class="mono js-copy-on-click org-manage-invite-link" value="<?= e($inviteUrl) ?>" readonly>
                        </div>
                        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites/<?= e($invite->uuid) ?>/revoke" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
                            <button type="submit" class="danger"><?= e(__('ui.organization_manage.revoke_invite')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if ($invites === []): ?><div class="muted"><?= e(__('ui.organization_manage.no_active_invites')) ?></div><?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
(() => {
    const fields = document.querySelectorAll('.js-copy-on-click');
    const linkCopied = <?= json_encode((string) __('ui.home.invite_link_copied')) ?>;
    const linkCopyFailed = <?= json_encode((string) __('ui.home.invite_link_copy_failed')) ?>;

    const showToast = (message, type = 'success') => {
        if (window.passwayToast && typeof window.passwayToast.show === 'function') {
            window.passwayToast.show(message, type);
        }
    };

    const selectLink = (field) => {
        field.focus();
        field.select();
    };

    const copyLink = async (field) => {
        selectLink(field);

        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(field.value);
            } else if (!document.execCommand('copy')) {
                throw new Error('Copy failed');
            }
            showToast(linkCopied, 'success');
        } catch (error) {
            showToast(linkCopyFailed, 'error');
        }
    };

    for (const field of fields) {
        field.addEventListener('click', () => copyLink(field));
    }
})();
</script>
</div>
