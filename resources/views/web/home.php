<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Signed in as <?= e((string) $user->email) ?></div>
    </div>
    <div class="topnav">
        <?php if ($currentOrg): ?><a class="button secondary" href="/organizations/<?= e($currentOrg->uuid) ?>/manage">Manage Org</a><?php endif; ?>
        <a class="button secondary" href="/rotation-services">Rotation Services</a>
        <a class="button secondary" href="/profile">Profile</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem;">
    <aside class="panel" style="padding:1rem;">
        <h2 style="margin:.25rem 0 1rem; font-size:1.1rem;">Organizations</h2>
        <div class="grid" style="gap:.6rem; margin-bottom:1rem;">
            <?php foreach ($organizations as $org): ?>
                <a class="button <?= $currentOrg && $currentOrg->uuid === $org->uuid ? '' : 'secondary' ?>" href="/?org=<?= e($org->uuid) ?>"><?= e($org->name) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="POST" action="/organizations" class="grid" style="gap:.7rem;">
            <div>
                <label for="org-name">New organization</label>
                <input id="org-name" name="name" placeholder="Platform Team" required>
            </div>
            <button type="submit">Create Organization</button>
        </form>
    </aside>

    <main class="grid" style="gap:1rem;">
        <?php if ($currentOrg === null): ?>
            <section class="panel" style="padding:1.5rem;"><h2 style="margin:0 0 .75rem;">No organizations yet</h2><p class="muted" style="margin:0;">Create your first organization to start storing directories and secrets.</p></section>
        <?php else: ?>
            <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
                <div><h1 style="margin:0; font-size:1.6rem;"><?= e($currentOrg->name) ?></h1><p class="muted" style="margin:.4rem 0 0;">Slug: <?= e($currentOrg->slug) ?></p></div>
                <div class="grid grid-2" style="gap:1rem;">
                    <form method="POST" action="/organizations/<?= e($currentOrg->uuid) ?>/directories" class="panel" style="padding:1rem;">
                        <h3 style="margin:0 0 .75rem;">Create Directory</h3>
                        <div class="grid" style="gap:.7rem;">
                            <div><label for="dir-name">Directory name</label><input id="dir-name" name="name" placeholder="Infrastructure" required></div>
                            <div><label for="parent-uuid">Parent directory</label><select id="parent-uuid" name="parent_uuid"><option value="">Root</option><?php foreach ($directories as $dir): ?><option value="<?= e($dir->uuid) ?>"><?= e(str_repeat('  ', $dir->depth) . $dir->name) ?></option><?php endforeach; ?></select></div>
                            <button type="submit">Add Directory</button>
                        </div>
                    </form>
                    <div class="panel" style="padding:1rem;">
                        <h3 style="margin:0 0 .75rem;">Directories</h3>
                        <div class="grid" style="gap:.55rem;">
                            <?php foreach ($directories as $dir): ?><a class="button <?= $currentDir && $currentDir->uuid === $dir->uuid ? '' : 'secondary' ?>" href="/?org=<?= e($currentOrg->uuid) ?>&dir=<?= e($dir->uuid) ?>" style="justify-content:flex-start;"><?= e(str_repeat('  ', $dir->depth) . $dir->name) ?></a><?php endforeach; ?>
                            <?php if ($directories === []): ?><div class="muted">No directories yet.</div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel" style="padding:1.5rem;">
                <div style="margin-bottom:1rem;"><h2 style="margin:0;"><?= $currentDir ? e($currentDir->name) : 'Select a directory' ?></h2><p class="muted" style="margin:.4rem 0 0;"><?= $currentDir ? e('Path: ' . $currentDir->path) : 'Choose a directory to create and inspect secrets.' ?></p></div>
                <?php if ($currentDir !== null): ?>
                    <div class="panel" style="padding:1rem; margin-bottom:1rem; background:rgba(15,23,42,.55);">
                        <form method="POST" action="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/rename" class="grid field-actions-3" style="gap:.75rem;">
                            <div>
                                <label for="rename-dir">Directory name</label>
                                <input id="rename-dir" name="name" value="<?= e($currentDir->name) ?>">
                            </div>
                            <button type="submit">Rename</button>
                            <button type="submit" class="danger" formaction="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/delete">Delete</button>
                        </form>
                    </div>
                    <form method="POST" action="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/secrets" class="grid grid-4" style="gap:1rem; margin-bottom:1rem;">
                        <div><label for="secret-name">Secret name</label><input id="secret-name" name="name" placeholder="DB_PASSWORD" required></div>
                        <div><label for="secret-type">Type</label><select id="secret-type" name="type"><option value="static">Static</option><option value="template">Template</option><option value="dynamic">Dynamic</option></select></div>
                        <div><label for="template-uuid">Template</label><select id="template-uuid" name="template_uuid"><option value="">None</option><?php foreach ($templates as $template): ?><option value="<?= e($template->uuid) ?>"><?= e($template->name) ?></option><?php endforeach; ?></select></div>
                        <div><label for="secret-value">Value</label><input id="secret-value" class="mono" name="value" placeholder="Only for static/dynamic"></div>
                        <div><label for="rotation-integration">Rotation integration</label><select id="rotation-integration" name="rotation_integration_uuid"><option value="">None</option><?php foreach ($integrations as $integration): ?><option value="<?= e($integration->uuid) ?>"><?= e($integration->name) ?></option><?php endforeach; ?></select></div>
                        <div><label for="rotation-schedule">Rotation schedule</label><input id="rotation-schedule" class="mono" name="rotation_schedule" placeholder="0 3 * * *"></div>
                        <div style="grid-column:1 / -1;" class="muted">Templates can use a schedule for automatic regeneration. Dynamic secrets can additionally bind an organization integration.</div>
                        <div style="grid-column:1 / -1;"><button type="submit">Create Secret</button></div>
                    </form>
                    <div class="grid" style="gap:.75rem;">
                        <?php foreach ($secrets as $secret): ?><a href="/organizations/<?= e($currentOrg->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/secrets/<?= e($secret->uuid) ?>" class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:block;"><div style="font-weight:700;"><?= e($secret->name) ?></div><div class="muted" style="font-size:.92rem;">Type: <?= e($secret->type) ?> · Version <?= e((string) $secret->version) ?><?= $secret->rotationSchedule ? ' · Schedule ' . e($secret->rotationSchedule) : '' ?></div></a><?php endforeach; ?>
                        <?php if ($secrets === []): ?><div class="muted">No secrets in this directory yet.</div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
