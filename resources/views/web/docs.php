<?php
$topbarLinks = [
    ['href' => '/', 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/api', 'label' => __('ui.home.api')],
    ['href' => '/rotation-services', 'label' => __('ui.home.rotation_services')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<style>
    .docs-shell {
        display: grid;
        grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
        gap: 1rem;
        align-items: start;
        padding-bottom: 2rem;
    }
    .docs-sidebar {
        position: sticky;
        top: 1rem;
        display: grid;
        gap: 1rem;
        padding: 1rem;
    }
    .docs-category {
        display: grid;
        gap: .55rem;
    }
    .docs-category-title {
        color: var(--muted);
        font-size: .82rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .docs-nav-link {
        display: block;
        padding: .55rem .65rem;
        border: 1px solid transparent;
        color: var(--fg);
    }
    .docs-nav-link:hover,
    .docs-nav-link[aria-current="page"] {
        border-color: var(--border);
        background: var(--panel-subtle);
    }
    .docs-article {
        padding: 1.5rem;
        min-width: 0;
    }
    .docs-article-body {
        display: grid;
        gap: 1rem;
    }
    .docs-article-body h1,
    .docs-article-body h2,
    .docs-article-body h3,
    .docs-article-body p,
    .docs-article-body ul {
        margin: 0;
    }
    .docs-article-body h1 { font-size: 2rem; }
    .docs-article-body h2 { margin-top: .7rem; font-size: 1.35rem; }
    .docs-article-body h3 { margin-top: .35rem; font-size: 1.1rem; }
    .docs-article-body ul {
        padding-left: 1.25rem;
        display: grid;
        gap: .45rem;
    }
    .docs-article-body a {
        color: var(--accent-link);
        text-decoration: underline;
        text-underline-offset: .14em;
    }
    .docs-article-body img {
        max-width: 100%;
        border: 1px solid var(--border);
        background: var(--panel-subtle);
    }
    .docs-note {
        border: 1px solid var(--border);
        border-left: .35rem solid var(--accent-soft);
        background: var(--panel-subtle);
        padding: 1rem;
        display: grid;
        gap: .45rem;
    }
    .docs-code {
        padding: .85rem;
        overflow: auto;
        border: 1px solid var(--border);
        background: var(--panel-subtle);
    }
    @media (max-width: 900px) {
        .docs-shell { grid-template-columns: 1fr; }
        .docs-sidebar { position: static; }
    }
</style>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0 0 .35rem; font-size:2rem;"><?= e(__('ui.docs.heading')) ?></h1>
    <div class="muted"><?= e(__('ui.docs.subtitle')) ?></div>
</section>

<div class="docs-shell">
    <aside class="docs-sidebar panel" aria-label="<?= e(__('ui.docs.navigation')) ?>">
        <?php foreach ($categories as $category): ?>
            <nav class="docs-category">
                <div class="docs-category-title"><?= e((string) $category['name']) ?></div>
                <?php foreach (($category['articles'] ?? []) as $item): ?>
                    <a class="docs-nav-link" href="/docs/<?= e((string) $item['slug']) ?>"<?= $article !== null && (string) $article['slug'] === (string) $item['slug'] ? ' aria-current="page"' : '' ?>><?= e((string) $item['title']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endforeach; ?>
    </aside>

    <article class="docs-article panel">
        <?php if ($article === null): ?>
            <div class="docs-article-body">
                <h1><?= e(__('ui.docs.not_found_title')) ?></h1>
                <p class="muted"><?= e(__('ui.docs.not_found_message')) ?></p>
                <p><a class="button" href="/docs"><?= e(__('ui.docs.open_start')) ?></a></p>
            </div>
        <?php else: ?>
            <div class="muted" style="margin-bottom:.75rem;"><?= e((string) $article['category']) ?></div>
            <div class="docs-article-body">
                <?= (string) $article['html'] ?>
            </div>
        <?php endif; ?>
    </article>
</div>
