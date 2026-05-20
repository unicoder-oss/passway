<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Global rotation service registry</div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/">Back to Dashboard</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h2 style="margin:0 0 .5rem;">Register Service</h2>
            <div class="muted">Only the setup administrator can create, update, verify, or delete rotation services.</div>
        </div>

        <?php if ($isSetupAdmin): ?>
            <form method="POST" action="/rotation-services" class="grid" style="gap:.75rem;">
                <div>
                    <label for="rotation-name">Service name</label>
                    <input id="rotation-name" name="name" placeholder="Vault Rotator" required>
                </div>
                <div>
                    <label for="rotation-url">Base URL</label>
                    <input id="rotation-url" name="url" placeholder="https://rotator.internal" required>
                </div>
                <button type="submit">Register Service</button>
            </form>
        <?php else: ?>
            <div class="muted">You can browse the registry, but management actions are restricted.</div>
        <?php endif; ?>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Registered Services</h2>
        <div class="grid" style="gap:.9rem;">
            <?php foreach ($services as $service): $spec = $service->spec(); ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($service->name) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($service->url) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= $service->isVerified ? 'verified' : 'not verified' ?> · <?= $service->isActive ? 'active' : 'inactive' ?><?= $service->lastCheckAt ? ' · checked ' . e($service->lastCheckAt) : '' ?></div>
                        <?php if ($spec !== []): ?><div class="muted" style="font-size:.92rem; margin-top:.35rem;">Spec keys: <?= e(implode(', ', array_keys($spec))) ?></div><?php endif; ?>
                    </div>

                    <?php if ($isSetupAdmin): ?>
                        <form method="POST" action="/rotation-services/<?= e($service->uuid) ?>/update" class="grid" style="gap:.75rem;">
                            <div>
                                <label>Name</label>
                                <input name="name" value="<?= e($service->name) ?>" required>
                            </div>
                            <div>
                                <label>Base URL</label>
                                <input name="url" value="<?= e($service->url) ?>" required>
                            </div>
                            <label style="display:flex; gap:.5rem; align-items:center;">
                                <input type="checkbox" name="is_active" value="1" <?= $service->isActive ? 'checked' : '' ?>>
                                <span>Active</span>
                            </label>
                            <div class="actions">
                                <button type="submit">Save</button>
                                <button type="submit" formaction="/rotation-services/<?= e($service->uuid) ?>/verify">Verify</button>
                                <button type="submit" class="danger" formaction="/rotation-services/<?= e($service->uuid) ?>/delete">Delete</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($services === []): ?><div class="muted">No rotation services registered yet.</div><?php endif; ?>
        </div>
    </section>
</div>
