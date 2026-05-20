<?php
$topbarTitle = '';
$topbarLinks = [
    ['href' => '/api', 'label' => __('ui.home.api')],
    ['href' => '/rotation-services', 'label' => __('ui.home.rotation_services')],
];
$createdOrganizationInviteUrl = $createdOrganizationInvite !== null
    ? app_url('/invite/' . $createdOrganizationInvite->token)
    : null;
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="width:min(920px, 100%); margin:0 auto; padding-bottom:2rem;">
    <div style="display:grid; gap:1rem; margin-bottom:1rem;">
        <div>
            <h1 style="margin:0 0 .35rem; font-size:2rem;"><?= e(__('ui.home.organizations')) ?></h1>
            <div class="muted"><?= e(__('ui.home.organizations_subtitle')) ?></div>
        </div>
        <form method="GET" class="panel" style="padding:1rem;">
            <label for="home-search"><?= e(__('ui.home.search')) ?></label>
            <input id="home-search" name="q" value="<?= e((string) $search) ?>" placeholder="<?= e(__('ui.home.search_placeholder')) ?>">
        </form>
    </div>

    <div class="grid grid-2" style="gap:1rem; align-items:stretch;">
        <?php if ($isSetupAdmin): ?>
            <section class="panel panel-muted" style="padding:1.25rem; display:grid; gap:1rem; align-content:start; min-height:220px;">
                <div style="display:flex; align-items:center; justify-content:center; width:64px; height:64px; border:1px solid var(--border); font-size:2rem; font-weight:700;">+</div>
                <div>
                    <div style="font-weight:700; margin-bottom:.35rem;"><?= e(__('ui.home.create_organization_invite')) ?></div>
                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.home.organization_invite_help')) ?></div>
                </div>
                <form method="POST" action="/organization-invites" class="grid" style="gap:.75rem;">
                    <div>
                        <label for="org-invite-ttl"><?= e(__('ui.home.organization_invite_ttl')) ?></label>
                        <input id="org-invite-ttl" type="number" name="ttl" value="1" min="1" max="168">
                    </div>
                    <button type="submit"><?= e(__('ui.home.create_organization_invite')) ?></button>
                </form>
                <?php if ($createdOrganizationInvite !== null): ?>
                    <div>
                        <label><?= e(__('ui.home.organization_invite_link')) ?></label>
                        <input class="mono js-copy-on-click" value="<?= e((string) $createdOrganizationInviteUrl) ?>" readonly>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php foreach ($organizationCards as $card): ?>
            <?php $organization = $card['organization']; ?>
            <a href="/organizations/<?= e($organization->uuid) ?>" class="panel" style="padding:1.25rem; display:grid; gap:1rem; align-content:start; min-height:220px;">
                <div style="display:flex; gap:1rem; align-items:flex-start;">
                    <?php if (!empty($organization->avatarPath)): ?>
                        <img class="avatar-square avatar-image" src="<?= e($organization->avatarPath) ?>" alt="<?= e($organization->name) ?>" width="64" height="64" style="width:64px; height:64px; flex:0 0 64px;">
                    <?php else: ?>
                        <div class="avatar-square" style="width:64px; height:64px; flex:0 0 64px; background:<?= e(avatar_fallback_color()) ?>; font-size:1.4rem;"><?= e(avatar_initial($organization->name)) ?></div>
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:700; font-size:1.1rem;"><?= e($organization->name) ?></div>
                        <?php if (!empty($organization->description)): ?>
                            <div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e($organization->description) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="grid" style="gap:.5rem; font-size:.92rem;">
                    <div class="muted"><?= e(__('ui.home.directories_total', ['count' => (string) $card['directories']])) ?></div>
                    <div class="muted"><?= e(__('ui.home.secrets_total', ['count' => (string) $card['secrets']])) ?></div>
                    <div class="muted"><?= e(__('ui.home.members_total', ['count' => (string) $card['members']])) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($organizationCards === []): ?>
        <section class="panel" style="padding:1.5rem; margin-top:1rem;">
            <h2 style="margin:0 0 .75rem;"><?= e(__('ui.home.no_organizations_heading')) ?></h2>
            <p class="muted" style="margin:0;"><?= e($search !== '' ? __('ui.home.no_search_results') : __('ui.home.no_organizations_text')) ?></p>
        </section>
    <?php endif; ?>
</section>

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
