<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">API keys for <?= e($organization->name) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage">Back to Management</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<?php if (!empty($createdRawKey)): ?>
    <div class="success" style="margin-bottom:1rem;">
        <div style="font-weight:700; margin-bottom:.4rem;">Copy this API key now</div>
        <input class="mono" value="<?= e((string) $createdRawKey) ?>" readonly>
    </div>
<?php endif; ?>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Create API Key</h2>
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys" class="grid" style="gap:.75rem;">
            <div>
                <label for="key-name">Name</label>
                <input id="key-name" name="name" placeholder="CI deployment" required>
            </div>
            <div>
                <label for="key-environment">Environment</label>
                <select id="key-environment" name="environment">
                    <option value="production">production</option>
                    <option value="staging">staging</option>
                    <option value="development">development</option>
                </select>
            </div>
            <div>
                <label for="key-expires">Expires at (optional)</label>
                <input id="key-expires" name="expires_at" placeholder="2026-12-31 23:59:59">
            </div>
            <button type="submit">Create API Key</button>
        </form>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Existing Keys</h2>
        <div class="grid" style="gap:.75rem;">
            <?php foreach ($keys as $key): ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($key->name) ?></div>
                        <div class="muted" style="font-size:.92rem;">Prefix <?= e($key->keyPrefix) ?> · <?= e($key->environment) ?> · <?= $key->isActive ? 'active' : 'revoked' ?></div>
                        <div class="muted" style="font-size:.92rem;">Created <?= e($key->createdAt) ?><?= $key->expiresAt ? ' · Expires ' . e($key->expiresAt) : '' ?></div>
                        <div class="muted" style="font-size:.92rem;">Last used <?= e((string) ($key->lastUsedAt ?? 'never')) ?></div>
                    </div>
                    <div class="actions">
                        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($key->uuid) ?>/permissions">Permissions</a>
                        <?php if ($key->isActive): ?>
                            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($key->uuid) ?>/revoke">
                                <button type="submit" class="danger">Revoke Key</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($keys === []): ?><div class="muted">No API keys yet.</div><?php endif; ?>
        </div>
    </section>
</div>
