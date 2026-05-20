<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Secret details for <?= e($organization->name) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/?org=<?= e($organization->uuid) ?>&dir=<?= e($directory->uuid) ?>">Back to Directory</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>

<div class="grid grid-2-compact" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h1 style="margin:0; font-size:1.6rem;"><?= e($secret->name) ?></h1>
            <p class="muted" style="margin:.45rem 0 0;">Type: <?= e($secret->type) ?> · Version <?= e((string) $secret->version) ?> · Directory <?= e($directory->name) ?></p>
            <div class="actions" style="margin-top:.75rem;">
                <span class="pill"><?= e($secret->type) ?></span>
                <?php if ($secret->rotationSchedule !== null && $secret->rotationSchedule !== ''): ?><span class="pill mono">Schedule <?= e($secret->rotationSchedule) ?></span><?php endif; ?>
                <?php if ($secret->lastRotatedAt !== null): ?><span class="pill">Last rotated <?= e($secret->lastRotatedAt) ?></span><?php endif; ?>
                <?php if ($selectedIntegration !== null): ?><span class="pill">Integration <?= e($selectedIntegration->name) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55);">
            <label>Current value</label>
            <textarea class="mono" rows="8" readonly><?= e($value) ?></textarea>
        </div>
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($directory->uuid) ?>/secrets/<?= e($secret->uuid) ?>/update" class="grid grid-2">
            <div>
                <label for="secret-name">Rename secret</label>
                <input id="secret-name" name="name" value="<?= e($secret->name) ?>">
            </div>
            <div>
                <label for="secret-value">Replace value</label>
                <input id="secret-value" name="value" placeholder="Leave empty to keep current value">
            </div>
            <div>
                <label for="secret-rotation-integration">Rotation integration</label>
                <select id="secret-rotation-integration" name="rotation_integration_uuid">
                    <option value="">None</option>
                    <?php foreach ($integrations as $integration): ?>
                        <option value="<?= e($integration->uuid) ?>" <?= $selectedIntegration?->uuid === $integration->uuid ? 'selected' : '' ?>><?= e($integration->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="secret-rotation-schedule">Rotation schedule</label>
                <input id="secret-rotation-schedule" class="mono" name="rotation_schedule" value="<?= e((string) ($secret->rotationSchedule ?? '')) ?>" placeholder="0 3 * * *">
            </div>
            <div class="muted" style="grid-column:1 / -1;">Leave value empty to keep the current secret. Clear integration or schedule by selecting "None" or leaving the schedule blank.</div>
            <div style="grid-column:1 / -1; display:flex; gap:.75rem; flex-wrap:wrap;">
                <button type="submit">Save Changes</button>
            </div>
        </form>
    </section>

    <section class="grid" style="gap:1rem;">
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;">Manual Actions</h3>
            <div class="grid" style="gap:.75rem;">
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($directory->uuid) ?>/secrets/<?= e($secret->uuid) ?>/rotate">
                    <button type="submit">Rotate Secret</button>
                </form>
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($directory->uuid) ?>/secrets/<?= e($secret->uuid) ?>/delete">
                    <button type="submit" class="danger">Delete Secret</button>
                </form>
            </div>
        </div>
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;">Version History</h3>
            <div class="grid" style="gap:.6rem;">
                <?php foreach ($versions as $version): ?>
                    <div class="panel" style="padding:.85rem; background:rgba(15,23,42,.55);">
                        <div style="font-weight:700;">Version <?= e((string) $version->version) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e($version->rotationType) ?> · <?= e($version->status) ?> · <?= e($version->createdAt) ?></div>
                        <?php if ($version->errorMessage !== null): ?><div class="muted" style="margin-top:.25rem;"><?= e($version->errorMessage) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($versions === []): ?><div class="muted">No version history yet.</div><?php endif; ?>
            </div>
        </div>
    </section>
</div>
