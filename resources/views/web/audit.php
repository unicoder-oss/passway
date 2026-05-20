<div class="topbar">
    <div>
        <div class="brand"><?= e(__('ui.app.name')) ?></div>
        <div class="muted" style="margin-top:.35rem;"><?= e(__('ui.audit.for_org', ['organization' => $organization->name])) ?></div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage"><?= e(__('ui.app.back_to_management')) ?></a>
        <a class="button secondary" href="/auth/logout"><?= e(__('ui.app.logout')) ?></a>
    </div>
</div>

<section class="panel" style="padding:1.5rem; margin-bottom:1rem;">
    <form method="GET" class="grid grid-4" style="gap:1rem;">
        <div>
            <label for="search"><?= e(__('ui.audit.search')) ?></label>
            <input id="search" name="search" value="<?= e($filters['search']) ?>" placeholder="<?= e(__('ui.audit.search_placeholder')) ?>">
        </div>
        <div>
            <label for="action"><?= e(__('ui.audit.action')) ?></label>
            <input id="action" name="action" value="<?= e($filters['action']) ?>" placeholder="<?= e(__('ui.audit.action_placeholder')) ?>">
        </div>
        <div>
            <label for="resource_type"><?= e(__('ui.audit.resource_type')) ?></label>
            <input id="resource_type" name="resource_type" value="<?= e($filters['resource_type']) ?>" placeholder="<?= e(__('ui.audit.resource_type_placeholder')) ?>">
        </div>
        <div>
            <label for="success"><?= e(__('ui.audit.success')) ?></label>
            <select id="success" name="success">
                <option value="" <?= $filters['success'] === '' ? 'selected' : '' ?>><?= e(__('ui.audit.any')) ?></option>
                <option value="1" <?= $filters['success'] === '1' ? 'selected' : '' ?>><?= e(__('ui.app.success')) ?></option>
                <option value="0" <?= $filters['success'] === '0' ? 'selected' : '' ?>><?= e(__('ui.app.failed')) ?></option>
            </select>
        </div>
        <div class="actions" style="grid-column:1 / -1;">
            <button type="submit"><?= e(__('ui.audit.apply_filters')) ?></button>
            <a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/audit"><?= e(__('ui.audit.reset')) ?></a>
        </div>
    </form>
</section>

<section class="panel" style="padding:1.5rem; display:grid; gap:.75rem;">
    <div class="muted"><?= e(__('ui.audit.summary', ['total' => (string) $meta['total'], 'offset' => (string) $meta['offset'], 'limit' => (string) $meta['limit']])) ?></div>
    <?php foreach ($entries as $entry): ?>
        <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55);">
            <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                <div>
                    <div style="font-weight:700;"><?= e($entry->action) ?></div>
                    <div class="muted" style="font-size:.92rem;"><?= e($entry->createdAt) ?> · <?= e($entry->success ? __('ui.app.success') : __('ui.app.failed')) ?></div>
                </div>
                <div class="muted" style="font-size:.92rem;"><?= e((string) ($entry->resourceType ?? __('ui.audit.system'))) ?> <?= e((string) ($entry->resourceUuid ?? '')) ?></div>
            </div>
            <?php if ($entry->ipAddress !== null): ?><div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e(__('ui.audit.ip', ['ip' => $entry->ipAddress])) ?></div><?php endif; ?>
            <?php if ($entry->details() !== []): ?><pre class="mono" style="margin-top:.75rem; background:#0f172a; border-radius:12px; padding:.85rem; overflow:auto;"><?= e((string) json_encode($entry->details(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><div class="muted"><?= e(__('ui.audit.no_entries')) ?></div><?php endif; ?>
    <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <?php if ($meta['offset'] > 0): ?><a class="button secondary" href="?search=<?= urlencode($filters['search']) ?>&action=<?= urlencode($filters['action']) ?>&resource_type=<?= urlencode($filters['resource_type']) ?>&success=<?= urlencode($filters['success']) ?>&limit=<?= urlencode((string) $meta['limit']) ?>&offset=<?= urlencode((string) max(0, $meta['offset'] - $meta['limit'])) ?>"><?= e(__('ui.audit.previous')) ?></a><?php endif; ?>
        <?php if ($meta['has_more']): ?><a class="button secondary" href="?search=<?= urlencode($filters['search']) ?>&action=<?= urlencode($filters['action']) ?>&resource_type=<?= urlencode($filters['resource_type']) ?>&success=<?= urlencode($filters['success']) ?>&limit=<?= urlencode((string) $meta['limit']) ?>&offset=<?= urlencode((string) ($meta['offset'] + $meta['limit'])) ?>"><?= e(__('ui.audit.next')) ?></a><?php endif; ?>
    </div>
</section>
