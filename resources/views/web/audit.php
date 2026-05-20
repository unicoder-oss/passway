<?php
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid, 'label' => __('ui.app.back_to_organization')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.audit.for_org', ['organization' => $organization->name])) ?></h1>
</section>

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
        <div class="panel panel-muted" style="padding:1rem;">
            <div>
                <div style="font-weight:700; line-height:1.45;">
                    <?php foreach ($entry['title_parts'] as $part): ?>
                        <?php if ($part['href'] !== null): ?><a href="<?= e((string) $part['href']) ?>" class="audit-title-link"><?= e((string) $part['text']) ?></a><?php else: ?><?php if (!empty($part['accent'])): ?><span class="audit-title-accent"><?= e((string) $part['text']) ?></span><?php else: ?><?= e((string) $part['text']) ?><?php endif; ?><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="muted" style="font-size:.92rem;"><?= $entry['timestamp_html'] ?> · <?= e((string) $entry['status']) ?> · <?php if ($entry['actor_href'] !== null): ?><a href="<?= e((string) $entry['actor_href']) ?>"><?= e((string) $entry['actor_label']) ?></a><?php else: ?><?= e((string) $entry['actor_label']) ?><?php endif; ?></div>
            </div>
            <?php foreach (($entry['details'] ?? []) as $detail): ?>
                <div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e((string) $detail) ?></div>
            <?php endforeach; ?>
            <?php if (($entry['ip_address'] ?? null) !== null): ?><div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e(__('ui.audit.ip', ['ip' => (string) $entry['ip_address']])) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><div class="muted"><?= e(__('ui.audit.no_entries')) ?></div><?php endif; ?>
    <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <?php if ($meta['offset'] > 0): ?><a class="button secondary" href="?search=<?= urlencode($filters['search']) ?>&action=<?= urlencode($filters['action']) ?>&resource_type=<?= urlencode($filters['resource_type']) ?>&success=<?= urlencode($filters['success']) ?>&limit=<?= urlencode((string) $meta['limit']) ?>&offset=<?= urlencode((string) max(0, $meta['offset'] - $meta['limit'])) ?>"><?= e(__('ui.audit.previous')) ?></a><?php endif; ?>
        <?php if ($meta['has_more']): ?><a class="button secondary" href="?search=<?= urlencode($filters['search']) ?>&action=<?= urlencode($filters['action']) ?>&resource_type=<?= urlencode($filters['resource_type']) ?>&success=<?= urlencode($filters['success']) ?>&limit=<?= urlencode((string) $meta['limit']) ?>&offset=<?= urlencode((string) ($meta['offset'] + $meta['limit'])) ?>"><?= e(__('ui.audit.next')) ?></a><?php endif; ?>
    </div>
</section>
