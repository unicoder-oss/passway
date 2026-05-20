<?php if ($search !== ''): ?>
    <div class="grid grid-2" style="gap:1rem; align-items:start;">
        <section class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
            <h3 style="margin:0;"><?= e(__('ui.organization.search_directories')) ?></h3>
            <?php foreach ($searchDirectories as $result): ?>
                <a href="/organizations/<?= e($organization->uuid) ?>?dir=<?= e($result['directory']->uuid) ?>" class="panel" style="padding:1rem; display:block;">
                    <div class="org-entry">
                        <svg class="org-entry-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h4l2 2h7A2.5 2.5 0 0 1 21 9.5v7A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="org-entry-copy">
                            <div class="org-entry-title"><?= e($result['directory']->name) ?></div>
                            <div class="org-entry-path"><?= e($result['path']) ?></div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if ($searchDirectories === []): ?><div class="muted"><?= e(__('ui.organization.search_no_directories')) ?></div><?php endif; ?>
        </section>
        <section class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
            <h3 style="margin:0;"><?= e(__('ui.organization.search_secrets')) ?></h3>
            <?php foreach ($searchSecrets as $result): ?>
                <a href="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($result['directory']->uuid) ?>/secrets/<?= e($result['secret']->uuid) ?>" class="panel" style="padding:1rem; display:block;">
                    <div class="org-entry">
                        <svg class="org-entry-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M8 10V8a4 4 0 1 1 8 0v2" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 14v2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="org-entry-copy">
                            <div class="org-entry-title"><?= e($result['secret']->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e($result['path']) ?></div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if ($searchSecrets === []): ?><div class="muted"><?= e(__('ui.organization.search_no_secrets')) ?></div><?php endif; ?>
        </section>
    </div>
<?php endif; ?>
