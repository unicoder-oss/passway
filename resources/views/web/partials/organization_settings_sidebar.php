<aside class="panel settings-sidebar" style="padding:1rem;">
    <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>"><?= e(__('ui.app.back_to_organization')) ?></a>
    <nav class="settings-nav" aria-label="<?= e(__('ui.titles.manage_organization')) ?>">
        <?php
        $settingsLinks = [
            ['key' => 'settings', 'href' => '/organizations/' . $organization->uuid . '/manage/settings', 'label' => __('ui.organization_manage.sections.settings')],
            ['key' => 'api-keys', 'href' => '/organizations/' . $organization->uuid . '/api-keys', 'label' => __('ui.organization_manage.sections.api_keys')],
            ['key' => 'integrations', 'href' => '/organizations/' . $organization->uuid . '/integrations', 'label' => __('ui.organization_manage.sections.integrations')],
        ];

        if (!is_solo_mode()) {
            array_splice($settingsLinks, 1, 0, [
                ['key' => 'members', 'href' => '/organizations/' . $organization->uuid . '/manage/members', 'label' => __('ui.organization_manage.sections.members')],
                ['key' => 'invites', 'href' => '/organizations/' . $organization->uuid . '/manage/invites', 'label' => __('ui.organization_manage.sections.invites')],
                ['key' => 'groups', 'href' => '/organizations/' . $organization->uuid . '/groups', 'label' => __('ui.organization_manage.sections.groups')],
            ]);
        }
        ?>
        <?php foreach ($settingsLinks as $link): ?>
            <?php $isActive = ($activeSettingsSection ?? '') === $link['key']; ?>
            <a
                class="button secondary<?= $isActive ? ' is-active' : '' ?>"
                href="<?= e($link['href']) ?>"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            ><?= e($link['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
