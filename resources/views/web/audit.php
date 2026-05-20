<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Audit log for <?= e($organization->name) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage">Back to Management</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<section class="panel" style="padding:1.5rem; margin-bottom:1rem;">
    <form method="GET" class="grid grid-4" style="gap:1rem;">
        <div>
            <label for="search">Search</label>
            <input id="search" name="search" value="<?= e($filters['search']) ?>" placeholder="action, uuid, ip, details">
        </div>
        <div>
            <label for="action">Action</label>
            <input id="action" name="action" value="<?= e($filters['action']) ?>" placeholder="secret.read">
        </div>
        <div>
            <label for="resource_type">Resource type</label>
            <input id="resource_type" name="resource_type" value="<?= e($filters['resource_type']) ?>" placeholder="secret">
        </div>
        <div>
            <label for="success">Success</label>
            <select id="success" name="success">
                <option value="" <?= $filters['success'] === '' ? 'selected' : '' ?>>any</option>
                <option value="1" <?= $filters['success'] === '1' ? 'selected' : '' ?>>success</option>
                <option value="0" <?= $filters['success'] === '0' ? 'selected' : '' ?>>failed</option>
            </select>
        </div>
        <div class="actions" style="grid-column:1 / -1;">
            <button type="submit">Apply Filters</button>
            <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/audit">Reset</a>
        </div>
    </form>
</section>

<section class="panel" style="padding:1.5rem; display:grid; gap:.75rem;">
    <div class="muted">Total: <?= e((string) $meta['total']) ?> · Offset: <?= e((string) $meta['offset']) ?> · Limit: <?= e((string) $meta['limit']) ?></div>
    <?php foreach ($entries as $entry): ?>
        <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55);">
            <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                <div>
                    <div style="font-weight:700;"><?= e($entry->action) ?></div>
                    <div class="muted" style="font-size:.92rem;"><?= e($entry->createdAt) ?> · <?= $entry->success ? 'success' : 'failed' ?></div>
                </div>
                <div class="muted" style="font-size:.92rem;"><?= e((string) ($entry->resourceType ?? 'system')) ?> <?= e((string) ($entry->resourceUuid ?? '')) ?></div>
            </div>
            <?php if ($entry->ipAddress !== null): ?><div class="muted" style="margin-top:.35rem; font-size:.92rem;">IP: <?= e($entry->ipAddress) ?></div><?php endif; ?>
            <?php if ($entry->details() !== []): ?><pre class="mono" style="margin-top:.75rem; background:#0f172a; border-radius:12px; padding:.85rem; overflow:auto;"><?= e((string) json_encode($entry->details(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><div class="muted">No audit entries match the current filter.</div><?php endif; ?>
    <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <?php if ($meta['offset'] > 0): ?><a class="button secondary" href="?search=<?= urlencode($filters['search']) ?>&action=<?= urlencode($filters['action']) ?>&resource_type=<?= urlencode($filters['resource_type']) ?>&success=<?= urlencode($filters['success']) ?>&limit=<?= urlencode((string) $meta['limit']) ?>&offset=<?= urlencode((string) max(0, $meta['offset'] - $meta['limit'])) ?>">Previous</a><?php endif; ?>
        <?php if ($meta['has_more']): ?><a class="button secondary" href="?search=<?= urlencode($filters['search']) ?>&action=<?= urlencode($filters['action']) ?>&resource_type=<?= urlencode($filters['resource_type']) ?>&success=<?= urlencode($filters['success']) ?>&limit=<?= urlencode((string) $meta['limit']) ?>&offset=<?= urlencode((string) ($meta['offset'] + $meta['limit'])) ?>">Next</a><?php endif; ?>
    </div>
</section>
