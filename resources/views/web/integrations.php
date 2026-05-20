<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Integrations for <?= e($organization->name) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage">Back to Management</a>
        <a class="button secondary" href="/rotation-services">Rotation Services</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Create Integration</h2>
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/integrations" class="grid" style="gap:.75rem;">
            <div>
                <label for="integration-name">Integration name</label>
                <input id="integration-name" name="name" placeholder="Primary PostgreSQL rotator" required>
            </div>
            <div>
                <label for="integration-service">Rotation service</label>
                <select id="integration-service" name="rotation_service_uuid">
                    <?php foreach ($services as $service): ?>
                        <?php if ($service->isActive && $service->isVerified): ?>
                            <option value="<?= e($service->uuid) ?>"><?= e($service->name) ?> (<?= e($service->url) ?>)</option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="integration-credentials">Credentials JSON</label>
                <textarea id="integration-credentials" name="credentials_json" rows="8" placeholder='{"token":"..."}'>{}</textarea>
            </div>
            <button type="submit">Create Integration</button>
        </form>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Existing Integrations</h2>
        <div class="grid" style="gap:.9rem;">
            <?php foreach ($integrations as $integration): $service = $serviceMap[$integration->rotationServiceId] ?? null; ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($integration->name) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($service?->name ?? 'Unknown service') ?><?= $service ? ' · ' . e($service->url) : '' ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= $integration->isActive ? 'active' : 'inactive' ?> · updated <?= e($integration->updatedAt) ?></div>
                    </div>

                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/integrations/<?= e($integration->uuid) ?>/update" class="grid" style="gap:.75rem;">
                        <div>
                            <label>Name</label>
                            <input name="name" value="<?= e($integration->name) ?>" required>
                        </div>
                        <label style="display:flex; gap:.5rem; align-items:center;">
                            <input type="checkbox" name="is_active" value="1" <?= $integration->isActive ? 'checked' : '' ?>>
                            <span>Active</span>
                        </label>
                        <div>
                            <label>Replace credentials JSON (optional)</label>
                            <textarea name="credentials_json" rows="6" placeholder='{"token":"new-value"}'></textarea>
                        </div>
                        <div class="actions">
                            <button type="submit">Save</button>
                            <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/integrations/<?= e($integration->uuid) ?>/delete">Delete</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($integrations === []): ?><div class="muted">No integrations configured yet.</div><?php endif; ?>
        </div>
    </section>
</div>
