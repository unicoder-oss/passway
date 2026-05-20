<div class="topbar">
    <div>
        <div class="brand"><?= e(__('ui.app.name')) ?></div>
        <div class="muted" style="margin-top:.35rem;"><?= e(__('ui.home.signed_in_as', ['email' => (string) $user->email])) ?></div>
    </div>
    <div class="topnav">
        <?php if ($currentOrg): ?><a class="button secondary" href="/organizations/<?= e($currentOrg->uuid) ?>/manage"><?= e(__('ui.app.manage_org')) ?></a><?php endif; ?>
        <a class="button secondary" href="/rotation-services"><?= e(__('ui.home.rotation_services')) ?></a>
        <a class="button secondary" href="/profile"><?= e(__('ui.home.profile')) ?></a>
        <a class="button secondary" href="/auth/logout"><?= e(__('ui.app.logout')) ?></a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem;">
    <aside class="panel" style="padding:1rem;">
        <h2 style="margin:.25rem 0 1rem; font-size:1.1rem;"><?= e(__('ui.home.organizations')) ?></h2>
        <div class="grid" style="gap:.6rem; margin-bottom:1rem;">
            <?php foreach ($organizations as $org): ?>
                <a class="button <?= $currentOrg && $currentOrg->uuid === $org->uuid ? '' : 'secondary' ?>" href="/?org=<?= e($org->uuid) ?>"><?= e($org->name) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="POST" action="/organizations" class="grid" style="gap:.7rem;">
            <div>
                <label for="org-name"><?= e(__('ui.home.new_organization')) ?></label>
                <input id="org-name" name="name" placeholder="<?= e(__('ui.home.new_organization_placeholder')) ?>" required>
            </div>
            <button type="submit"><?= e(__('ui.home.create_organization')) ?></button>
        </form>
    </aside>

    <main class="grid" style="gap:1rem;">
        <?php if ($currentOrg === null): ?>
            <section class="panel" style="padding:1.5rem;"><h2 style="margin:0 0 .75rem;"><?= e(__('ui.home.no_organizations_heading')) ?></h2><p class="muted" style="margin:0;"><?= e(__('ui.home.no_organizations_text')) ?></p></section>
        <?php else: ?>
            <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
                <div><h1 style="margin:0; font-size:1.6rem;"><?= e($currentOrg->name) ?></h1><p class="muted" style="margin:.4rem 0 0;"><?= e(__('ui.home.slug', ['slug' => $currentOrg->slug])) ?></p></div>
                <div class="grid grid-2" style="gap:1rem;">
                    <form method="POST" action="/organizations/<?= e($currentOrg->uuid) ?>/directories" class="panel" style="padding:1rem;">
                        <h3 style="margin:0 0 .75rem;"><?= e(__('ui.home.create_directory')) ?></h3>
                        <div class="grid" style="gap:.7rem;">
                            <div><label for="dir-name"><?= e(__('ui.home.directory_name')) ?></label><input id="dir-name" name="name" placeholder="<?= e(__('ui.home.directory_name_placeholder')) ?>" required></div>
                            <div><label for="parent-uuid"><?= e(__('ui.home.parent_directory')) ?></label><select id="parent-uuid" name="parent_uuid"><option value=""><?= e(__('ui.app.root')) ?></option><?php foreach ($directories as $dir): ?><option value="<?= e($dir->uuid) ?>"><?= e(str_repeat('  ', $dir->depth) . $dir->name) ?></option><?php endforeach; ?></select></div>
                            <button type="submit"><?= e(__('ui.home.add_directory')) ?></button>
                        </div>
                    </form>
                    <div class="panel" style="padding:1rem;">
                        <h3 style="margin:0 0 .75rem;"><?= e(__('ui.home.directories')) ?></h3>
                        <div class="grid" style="gap:.55rem;">
                            <?php foreach ($directories as $dir): ?><a class="button <?= $currentDir && $currentDir->uuid === $dir->uuid ? '' : 'secondary' ?>" href="/?org=<?= e($currentOrg->uuid) ?>&dir=<?= e($dir->uuid) ?>" style="justify-content:flex-start;"><?= e(str_repeat('  ', $dir->depth) . $dir->name) ?></a><?php endforeach; ?>
                            <?php if ($directories === []): ?><div class="muted"><?= e(__('ui.home.no_directories')) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.5rem;">
                <div style="margin-bottom:1rem;"><h2 style="margin:0;"><?= $currentDir ? e($currentDir->name) : e(__('ui.home.select_directory')) ?></h2><p class="muted" style="margin:.4rem 0 0;"><?= $currentDir ? e(__('ui.home.path', ['path' => $currentDir->path])) : e(__('ui.home.choose_directory')) ?></p></div>
                <?php if ($currentDir !== null): ?>
                    <div class="panel" style="padding:1rem; margin-bottom:1rem; background:rgba(15,23,42,.55);">
                        <form method="POST" action="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/rename" class="grid field-actions-3" style="gap:.75rem;">
                            <div>
                                <label for="rename-dir"><?= e(__('ui.home.directory_name')) ?></label>
                                <input id="rename-dir" name="name" value="<?= e($currentDir->name) ?>">
                            </div>
                            <button type="submit"><?= e(__('ui.home.rename')) ?></button>
                            <button type="submit" class="danger" formaction="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/delete"><?= e(__('ui.app.delete')) ?></button>
                        </form>
                    </div>
                    <form method="POST" action="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/secrets" class="grid grid-4" style="gap:1rem; margin-bottom:1rem;">
                        <div><label for="secret-name"><?= e(__('ui.home.secret_name')) ?></label><input id="secret-name" name="name" placeholder="<?= e(__('ui.home.secret_name_placeholder')) ?>" required></div>
                        <div><label for="secret-type"><?= e(__('ui.home.type')) ?></label><select id="secret-type" name="type"><option value="static"><?= e(__('ui.home.types.static')) ?></option><option value="template"><?= e(__('ui.home.types.template')) ?></option><option value="dynamic"><?= e(__('ui.home.types.dynamic')) ?></option></select></div>
                        <div><label for="template-uuid"><?= e(__('ui.home.template')) ?></label><select id="template-uuid" name="template_uuid"><option value=""><?= e(__('ui.app.none')) ?></option><?php foreach ($templates as $template): ?><option value="<?= e($template->uuid) ?>"><?= e($template->name) ?></option><?php endforeach; ?></select></div>
                        <div><label for="secret-value"><?= e(__('ui.home.value')) ?></label><input id="secret-value" class="mono" name="value" placeholder="<?= e(__('ui.home.value_placeholder')) ?>"></div>
                        <div><label for="rotation-integration"><?= e(__('ui.home.rotation_integration')) ?></label><select id="rotation-integration" name="rotation_integration_uuid"><option value=""><?= e(__('ui.app.none')) ?></option><?php foreach ($integrations as $integration): ?><option value="<?= e($integration->uuid) ?>"><?= e($integration->name) ?></option><?php endforeach; ?></select></div>
                        <div><label for="rotation-schedule"><?= e(__('ui.home.rotation_schedule')) ?></label><input id="rotation-schedule" class="mono" name="rotation_schedule" placeholder="0 3 * * *"></div>
                        <div style="grid-column:1 / -1;" class="muted"><?= e(__('ui.home.template_schedule_hint')) ?></div>
                        <div style="grid-column:1 / -1;"><button type="submit"><?= e(__('ui.home.create_secret')) ?></button></div>
                    </form>
                    <div class="grid" style="gap:.75rem;">
                        <?php foreach ($secrets as $secret): ?><a href="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/secrets/<?= e($secret->uuid) ?>" class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:block;"><div style="font-weight:700;"><?= e($secret->name) ?></div><div class="muted" style="font-size:.92rem;"><?= e(__('ui.secret.meta', ['type' => __('ui.home.types.' . $secret->type), 'version' => (string) $secret->version, 'directory' => $currentDir->name])) ?><?= $secret->rotationSchedule ? ' · ' . e(__('ui.home.schedule', ['schedule' => $secret->rotationSchedule])) : '' ?></div></a><?php endforeach; ?>
                        <?php if ($secrets === []): ?><div class="muted"><?= e(__('ui.home.no_secrets')) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
