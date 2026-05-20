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

<?php if ($organizationCards === []): ?>
    <section class="panel" style="padding:1.5rem; margin-top:1rem; grid-column:1 / -1;">
        <h2 style="margin:0 0 .75rem;"><?= e(__('ui.home.no_organizations_heading')) ?></h2>
        <p class="muted" style="margin:0;"><?= e($search !== '' ? __('ui.home.no_search_results') : __('ui.home.no_organizations_text')) ?></p>
    </section>
<?php endif; ?>
