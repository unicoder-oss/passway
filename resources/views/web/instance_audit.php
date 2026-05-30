<?php
$topbarLinks = [
    ['href' => '/', 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');

$previousQuery = $filters;
$previousQuery['limit'] = (string) $meta['limit'];
$previousQuery['offset'] = (string) max(0, $meta['offset'] - $meta['limit']);
$nextQuery = $filters;
$nextQuery['limit'] = (string) $meta['limit'];
$nextQuery['offset'] = (string) ($meta['offset'] + $meta['limit']);
?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.audit.instance_title')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e(__('ui.audit.instance_subtitle')) ?></div>
</section>

<section class="panel" style="padding:1.5rem; margin-bottom:1rem;">
    <form method="GET" class="grid grid-4" style="gap:1rem;">
        <div>
            <label for="action"><?= e(__('ui.audit.event')) ?></label>
            <select id="action" name="action">
                <option value=""><?= e(__('ui.audit.all_events')) ?></option>
                <?php foreach ($filterOptions['actions'] as $action): ?>
                    <option value="<?= e((string) $action['value']) ?>" <?= $filters['action'] === $action['value'] ? 'selected' : '' ?>><?= e((string) $action['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="actor_kind"><?= e(__('ui.audit.actor')) ?></label>
            <select id="actor_kind" name="actor_kind">
                <?php foreach ($filterOptions['actorKinds'] as $actorKind): ?>
                    <option value="<?= e((string) $actorKind['value']) ?>" <?= $filters['actor_kind'] === $actorKind['value'] ? 'selected' : '' ?>><?= e((string) $actorKind['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="actor_user_email"><?= e(__('ui.audit.actor_user')) ?></label>
            <input id="actor_user_email" name="actor_user_email" value="<?= e($filters['actor_user_email']) ?>" placeholder="admin@example.com">
        </div>
        <div>
            <label for="success"><?= e(__('ui.audit.success')) ?></label>
            <select id="success" name="success">
                <option value="" <?= $filters['success'] === '' ? 'selected' : '' ?>><?= e(__('ui.audit.any')) ?></option>
                <option value="1" <?= $filters['success'] === '1' ? 'selected' : '' ?>><?= e(__('ui.app.success')) ?></option>
                <option value="0" <?= $filters['success'] === '0' ? 'selected' : '' ?>><?= e(__('ui.app.failed')) ?></option>
            </select>
        </div>
        <div>
            <label for="from_date"><?= e(__('ui.audit.from_date')) ?></label>
            <input id="from_date" name="from_date" type="date" value="<?= e($filters['from_date']) ?>">
        </div>
        <div>
            <label for="to_date"><?= e(__('ui.audit.to_date')) ?></label>
            <input id="to_date" name="to_date" type="date" value="<?= e($filters['to_date']) ?>">
        </div>
        <div>
            <label for="ip_address"><?= e(__('ui.audit.ip_address')) ?></label>
            <input id="ip_address" name="ip_address" value="<?= e($filters['ip_address']) ?>" placeholder="<?= e(__('ui.audit.ip_address_placeholder')) ?>">
        </div>
        <div>
            <label for="search"><?= e(__('ui.audit.search')) ?></label>
            <input id="search" name="search" value="<?= e($filters['search']) ?>" placeholder="<?= e(__('ui.audit.instance_search_placeholder')) ?>">
        </div>
        <div class="actions" style="grid-column:1 / -1;">
            <button type="submit"><?= e(__('ui.audit.apply_filters')) ?></button>
            <a class="button secondary" href="/audit"><?= e(__('ui.audit.reset')) ?></a>
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
                        <?php if (!empty($part['accent'])): ?><span class="audit-title-accent"><?= e((string) $part['text']) ?></span><?php else: ?><?= e((string) $part['text']) ?><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="muted" style="font-size:.92rem;"><?= $entry['timestamp_html'] ?> · <?= e((string) $entry['status']) ?> · <?= e((string) $entry['actor_label']) ?></div>
            </div>
            <?php foreach (($entry['details'] ?? []) as $detail): ?>
                <div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e((string) $detail) ?></div>
            <?php endforeach; ?>
            <?php if (($entry['ip_address'] ?? null) !== null): ?><div class="muted" style="margin-top:.35rem; font-size:.92rem;"><?= e(__('ui.audit.ip', ['ip' => (string) $entry['ip_address']])) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><div class="muted"><?= e(__('ui.audit.no_entries')) ?></div><?php endif; ?>
    <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <?php if ($meta['offset'] > 0): ?><a class="button secondary" href="?<?= e(http_build_query($previousQuery)) ?>"><?= e(__('ui.audit.previous')) ?></a><?php endif; ?>
        <?php if ($meta['has_more']): ?><a class="button secondary" href="?<?= e(http_build_query($nextQuery)) ?>"><?= e(__('ui.audit.next')) ?></a><?php endif; ?>
    </div>
</section>
