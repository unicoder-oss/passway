<?php
$topbarLinks = [
    ['href' => '/', 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/rotation-services', 'label' => __('ui.home.rotation_services')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<style>
    .api-docs-shell {
        display: grid;
        gap: 1rem;
        padding-bottom: 2rem;
    }
    .api-docs-summary {
        display: grid;
        gap: .75rem;
    }
    .api-docs-endpoints {
        display: grid;
        gap: 1rem;
    }
    .api-docs-endpoint {
        padding: 1rem;
        display: grid;
        gap: .85rem;
    }
    .api-docs-method {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 78px;
        padding: .25rem .5rem;
        border: 1px solid var(--border);
        background: var(--panel-subtle);
        font-size: .82rem;
        font-weight: 700;
    }
    .api-docs-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        align-items: center;
    }
    .api-docs-code {
        margin: 0;
        padding: .85rem;
        overflow: auto;
        border: 1px solid var(--border);
        background: var(--panel-subtle);
    }
</style>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0 0 .35rem; font-size:2rem;"><?= e(__('ui.api_docs.heading')) ?></h1>
    <div class="muted"><?= e(__('ui.api_docs.subtitle')) ?></div>
</section>

<div class="api-docs-shell">
    <section class="panel" style="padding:1.25rem;">
        <div class="api-docs-summary">
            <div>
                <h2 style="margin:0 0 .5rem;"><?= e(__('ui.api_docs.auth_heading')) ?></h2>
                <div class="muted"><?= e(__('ui.api_docs.auth_session')) ?></div>
                <div class="muted"><?= e(__('ui.api_docs.auth_api_key')) ?></div>
                <div class="muted"><?= e(__('ui.api_docs.auth_api_key_limits')) ?></div>
            </div>
            <div>
                <h2 style="margin:0 0 .5rem;"><?= e(__('ui.api_docs.notes_heading')) ?></h2>
                <div class="muted"><?= e(__('ui.api_docs.note_rotation')) ?></div>
                <div class="muted"><?= e(__('ui.api_docs.note_response')) ?></div>
                <div class="muted"><?= e(__('ui.api_docs.note_time')) ?></div>
            </div>
        </div>
    </section>

    <?php foreach ($sections as $section): ?>
        <section class="panel" style="padding:1.25rem;">
            <h2 style="margin:0 0 .35rem;"><?= e((string) $section['title']) ?></h2>
            <?php if (!empty($section['description'])): ?><div class="muted" style="margin:0 0 1rem;"><?= e((string) $section['description']) ?></div><?php endif; ?>
            <div class="api-docs-endpoints">
                <?php foreach (($section['endpoints'] ?? []) as $endpoint): ?>
                    <article class="panel panel-muted api-docs-endpoint">
                        <div style="display:grid; gap:.5rem;">
                            <div class="api-docs-meta">
                                <span class="api-docs-method"><?= e((string) $endpoint['method']) ?></span>
                                <code><?= e((string) $endpoint['path']) ?></code>
                                <span class="pill"><?= e(__('ui.api_docs.auth_label')) ?>: <?= e((string) $endpoint['auth']) ?></span>
                            </div>
                            <div><?= e((string) $endpoint['summary']) ?></div>
                        </div>
                        <div class="grid grid-2" style="align-items:start; gap:1rem;">
                            <div>
                                <label><?= e(__('ui.api_docs.request_example')) ?></label>
                                <pre class="api-docs-code mono"><?= e((string) $endpoint['request']) ?></pre>
                            </div>
                            <div>
                                <label><?= e(__('ui.api_docs.response_example')) ?></label>
                                <pre class="api-docs-code mono"><?= e((string) $endpoint['response']) ?></pre>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
