<?php
$topbarTitle = '';
$topbarLinks = [
    ['href' => '/api', 'label' => __('ui.home.api')],
    ['href' => '/rotation-services', 'label' => __('ui.home.rotation_services')],
];
if (!empty($isSetupAdmin)) {
    array_unshift($topbarLinks, ['href' => '/audit', 'label' => __('ui.audit.instance_nav')]);
}
$createdOrganizationInviteUrl = $createdOrganizationInvite !== null
    ? app_url('/invite/' . $createdOrganizationInvite->token)
    : null;
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" data-toast="true"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="width:min(920px, 100%); margin:0 auto; padding-bottom:2rem;">
    <style>
        .js-copy-on-click {
            cursor: pointer;
        }
    </style>
    <div style="display:grid; gap:1rem; margin-bottom:1rem;">
        <div>
            <h1 style="margin:0 0 .35rem; font-size:2rem;"><?= e(__('ui.home.organizations')) ?></h1>
            <div class="muted"><?= e(__($isSoloMode ? 'ui.home.organizations_subtitle_solo' : 'ui.home.organizations_subtitle')) ?></div>
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
                    <div style="font-weight:700; margin-bottom:.35rem;"><?= e(__($isSoloMode ? 'ui.home.create_organization' : 'ui.home.create_organization_invite')) ?></div>
                    <div class="muted" style="font-size:.92rem;"><?= e(__($isSoloMode ? 'ui.home.organization_create_help' : 'ui.home.organization_invite_help')) ?></div>
                </div>
                <?php if ($isSoloMode): ?>
                    <form method="POST" action="/organizations" class="grid" style="gap:.75rem;">
                        <div>
                            <label for="new-organization-name"><?= e(__('ui.home.new_organization')) ?></label>
                            <input id="new-organization-name" name="name" placeholder="<?= e(__('ui.home.new_organization_placeholder')) ?>" required>
                        </div>
                        <button type="submit"><?= e(__('ui.home.create_organization')) ?></button>
                    </form>
                <?php else: ?>
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
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <div id="home-organization-results" style="display:contents;">
            <?php require base_path('resources/views/web/partials/home_organization_results.php'); ?>
        </div>
    </div>
</section>

<script>
window.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('home-search');
    const results = document.getElementById('home-organization-results');
    const fields = document.querySelectorAll('.js-copy-on-click');
    const linkCopied = <?= json_encode((string) __('ui.home.invite_link_copied')) ?>;
    const linkCopyFailed = <?= json_encode((string) __('ui.home.invite_link_copy_failed')) ?>;
    let searchTimer = null;
    let searchController = null;

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

    if (searchInput && results) {
        const searchLoadFailed = <?= json_encode((string) __('ui.home.search_load_failed')) ?>;

        const fetchResults = async () => {
            if (searchController) {
                searchController.abort();
            }

            searchController = new AbortController();
            const value = searchInput.value.trim();
            const url = new URL('/partials/home-organizations', window.location.origin);
            if (value !== '') {
                url.searchParams.set('q', value);
            }

            try {
                const response = await fetch(url.toString(), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: searchController.signal,
                });
                if (!response.ok) {
                    throw new Error(searchLoadFailed);
                }

                results.innerHTML = await response.text();

                const nextUrl = new URL(window.location.href);
                if (value !== '') {
                    nextUrl.searchParams.set('q', value);
                } else {
                    nextUrl.searchParams.delete('q');
                }
                window.history.replaceState({}, '', `${nextUrl.pathname}${nextUrl.search}`);
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }
                if (window.passwayToast && typeof window.passwayToast.show === 'function') {
                    window.passwayToast.show(searchLoadFailed, 'error');
                }
            }
        };

        searchInput.addEventListener('input', () => {
            if (searchTimer !== null) {
                window.clearTimeout(searchTimer);
            }
            searchTimer = window.setTimeout(fetchResults, 250);
        });

        searchInput.addEventListener('search', fetchResults);
    }
});
</script>
