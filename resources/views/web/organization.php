<?php
$topbarTitle = $organization->name;
$topbarLinks = [
    ['href' => '/', 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
$sectionTitle = $currentDir ? $currentDir->name : __('ui.organization.root_level');
$secretAction = $currentDir !== null
    ? '/organizations/' . $organization->uuid . '/directories/' . $currentDir->uuid . '/secrets'
    : '/organizations/' . $organization->uuid . '/secrets';
$secretDirUuid = $currentDir?->uuid ?? $rootSecretDirectory?->uuid ?? '';
$secretContextName = $currentDir?->name ?? __('ui.app.root');
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error"><?= e((string) $queryError) ?></div><?php endif; ?>

<section style="width:min(980px, 100%); margin:0 auto; padding-bottom:2rem; display:grid; gap:1rem;">
    <div class="panel" style="padding:1rem 1.25rem; display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap;">
        <div style="display:flex; gap:1rem; align-items:flex-start;">
            <?php if (!empty($organization->avatarPath)): ?>
                <img class="avatar-square avatar-image" src="<?= e($organization->avatarPath) ?>" alt="<?= e($organization->name) ?>" width="64" height="64" style="width:64px; height:64px; flex:0 0 64px;">
            <?php else: ?>
                <div class="avatar-square" style="width:64px; height:64px; flex:0 0 64px; background:<?= e(avatar_fallback_color()) ?>; font-size:1.4rem;"><?= e(avatar_initial($organization->name)) ?></div>
            <?php endif; ?>
            <div>
                <h1 style="margin:0 0 .35rem; font-size:2rem;"><?= e($organization->name) ?></h1>
                <?php if (!empty($organization->description)): ?>
                    <div class="muted"><?= e($organization->description) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <?php if ($canViewAudit): ?><a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/audit"><?= e(__('ui.organization.audit_log')) ?></a><?php endif; ?>
            <?php if ($canManageOrganization): ?><a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage"><?= e(__('ui.organization.manage')) ?></a><?php endif; ?>
        </div>
    </div>

    <section class="panel" style="padding:1.25rem; display:grid; gap:1rem;">
        <style>
            .org-menu { position: relative; }
            .org-menu-panel {
                position: absolute;
                right: 0;
                top: calc(100% + .5rem);
                min-width: 180px;
                padding: .75rem;
                display: none;
                gap: .5rem;
                z-index: 20;
            }
            .org-menu.is-open .org-menu-panel,
            .org-menu:focus-within .org-menu-panel { display: grid; }
            .org-entry {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr);
                gap: .85rem;
                align-items: start;
            }
            .org-entry-icon {
                width: 1.25rem;
                height: 1.25rem;
                color: var(--muted);
                flex: 0 0 1.25rem;
                margin-top: .1rem;
            }
            .org-entry-copy {
                min-width: 0;
                display: grid;
                gap: .15rem;
            }
            .org-entry-title {
                font-weight: 700;
                overflow-wrap: anywhere;
            }
            .org-entry-path {
                font-size: .92rem;
                overflow-wrap: anywhere;
            }
        </style>

        <div style="display:flex; justify-content:space-between; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0;"><?= e($sectionTitle) ?></h2>
                <?php if ($currentDir !== null && $currentDirPath !== null): ?>
                    <div class="org-entry-path" style="margin-top:.25rem;"><?= e($currentDirPath) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($canEditContent): ?>
                <div class="actions">
                    <?php if ($currentDir !== null): ?>
                        <div class="org-menu js-delayed-menu">
                            <button type="button"><?= e(__('ui.organization.manage_directory')) ?></button>
                            <div class="org-menu-panel panel">
                                <button type="button" class="secondary" data-open-modal="rename-directory-modal"><?= e(__('ui.organization.rename_directory')) ?></button>
                                <button type="button" class="secondary danger" data-open-modal="delete-directory-modal"><?= e(__('ui.organization.delete_directory')) ?></button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="org-menu js-delayed-menu">
                        <button type="button" aria-label="<?= e(__('ui.organization.actions')) ?>">+</button>
                        <div class="org-menu-panel panel">
                            <button type="button" class="secondary" data-open-modal="directory-modal"><?= e(__('ui.organization.add_directory_short')) ?></button>
                            <button type="button" class="secondary" data-open-modal="secret-modal"><?= e(__('ui.organization.add_secret_short')) ?></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <form method="GET" class="grid" style="gap:.75rem;">
            <?php if ($currentDir !== null): ?><input type="hidden" name="dir" value="<?= e($currentDir->uuid) ?>"><?php endif; ?>
            <div>
                <label for="organization-search"><?= e(__('ui.organization.search')) ?></label>
                <input id="organization-search" name="q" value="<?= e((string) $search) ?>" placeholder="<?= e(__('ui.organization.search_placeholder')) ?>">
            </div>
        </form>

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

        <div class="grid" style="gap:.75rem;">
            <?php if ($currentDir !== null): ?>
                <a href="<?= e($parentDirectory !== null ? '/organizations/' . $organization->uuid . '?dir=' . $parentDirectory->uuid : '/organizations/' . $organization->uuid) ?>" class="panel panel-muted" style="padding:1rem; display:block;">
                    <div class="org-entry">
                        <svg class="org-entry-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h4l2 2h7A2.5 2.5 0 0 1 21 9.5v7A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="org-entry-copy">
                            <div class="org-entry-title">...</div>
                        </div>
                    </div>
                </a>
            <?php endif; ?>
            <?php foreach ($directories as $directory): ?>
                <a href="/organizations/<?= e($organization->uuid) ?>?dir=<?= e($directory->uuid) ?>" class="panel panel-muted" style="padding:1rem; display:block;">
                    <div class="org-entry">
                        <svg class="org-entry-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h4l2 2h7A2.5 2.5 0 0 1 21 9.5v7A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="org-entry-copy">
                            <div class="org-entry-title"><?= e($directory->name) ?></div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php foreach ($secrets as $secret): ?>
                <a href="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($secretDirUuid) ?>/secrets/<?= e($secret->uuid) ?>" class="panel panel-muted" style="padding:1rem; display:block;">
                    <div class="org-entry">
                        <svg class="org-entry-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M8 10V8a4 4 0 1 1 8 0v2" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 14v2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <div class="org-entry-copy">
                            <div class="org-entry-title"><?= e($secret->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e(__('ui.secret.meta', ['type' => __('ui.home.types.' . $secret->type), 'version' => (string) $secret->version, 'directory' => $secretContextName])) ?></div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if ($directories === [] && $secrets === []): ?><div class="muted"><?= e(__('ui.organization.empty_level')) ?></div><?php endif; ?>
        </div>
    </section>
</section>

<?php if ($canEditContent): ?>
    <dialog id="directory-modal" class="modal">
        <div class="modal-body">
            <div>
                <h3 style="margin:0 0 .35rem;"><?= e(__('ui.organization.add_directory_here')) ?></h3>
                <div class="wizard-meta"><?= e(__('ui.organization.directory_modal_hint')) ?></div>
            </div>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories" class="grid" style="gap:1rem;">
                <input type="hidden" name="parent_uuid" value="<?= e((string) ($currentDir?->uuid ?? '')) ?>">
                <div>
                    <label for="modal-dir-name"><?= e(__('ui.home.directory_name')) ?></label>
                    <input id="modal-dir-name" name="name" placeholder="<?= e(__('ui.home.directory_name_placeholder')) ?>" required>
                </div>
                <div class="actions-end">
                    <button type="button" class="secondary" data-close-modal="directory-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="submit"><?= e(__('ui.organization.create')) ?></button>
                </div>
            </form>
        </div>
    </dialog>

    <?php if ($currentDir !== null): ?>
        <dialog id="rename-directory-modal" class="modal">
            <div class="modal-body">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e(__('ui.organization.rename_directory')) ?></h3>
                </div>
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/rename" class="grid" style="gap:1rem;">
                    <div>
                        <label for="rename-dir-name"><?= e(__('ui.home.directory_name')) ?></label>
                        <input id="rename-dir-name" name="name" value="<?= e($currentDir->name) ?>" required>
                    </div>
                    <div class="actions-end">
                        <button type="button" class="secondary" data-close-modal="rename-directory-modal"><?= e(__('ui.organization.cancel')) ?></button>
                        <button type="submit"><?= e(__('ui.home.rename')) ?></button>
                    </div>
                </form>
            </div>
        </dialog>

        <dialog id="delete-directory-modal" class="modal">
            <div class="modal-body">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e(__('ui.organization.delete_directory')) ?></h3>
                    <div class="wizard-meta"><?= e(__('ui.organization.delete_directory_confirm')) ?></div>
                    <?php if ($currentDirStats !== null): ?>
                        <div class="wizard-meta" style="margin-top:.5rem;"><?= e(__('ui.organization.delete_directory_summary', ['directories' => (string) $currentDirStats['directories'], 'secrets' => (string) $currentDirStats['secrets']])) ?></div>
                    <?php endif; ?>
                </div>
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/delete" class="actions-end">
                    <button type="button" class="secondary" data-close-modal="delete-directory-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="submit" class="danger"><?= e(__('ui.organization.delete_directory')) ?></button>
                </form>
            </div>
        </dialog>
    <?php endif; ?>

    <dialog id="secret-modal" class="modal">
        <div class="modal-body">
            <div>
                <h3 style="margin:0 0 .35rem;"><?= e(__('ui.organization.add_secret_here')) ?></h3>
                <div class="wizard-meta" id="secret-wizard-meta"><?= e(__('ui.organization.wizard_step', ['current' => '1', 'total' => '3'])) ?></div>
            </div>
            <form id="secret-modal-form" method="POST" action="<?= e($secretAction) ?>" class="grid" style="gap:1rem;">
                <section class="wizard-step" data-step="1">
                    <div>
                        <label for="modal-secret-name"><?= e(__('ui.home.secret_name')) ?></label>
                        <input id="modal-secret-name" name="name" placeholder="<?= e(__('ui.home.secret_name_placeholder')) ?>" required>
                    </div>
                    <div>
                        <label for="modal-secret-type"><?= e(__('ui.home.type')) ?></label>
                        <select id="modal-secret-type" name="type">
                            <option value="static"><?= e(__('ui.home.types.static')) ?></option>
                            <option value="template"><?= e(__('ui.home.types.template')) ?></option>
                            <option value="dynamic"><?= e(__('ui.home.types.dynamic')) ?></option>
                        </select>
                    </div>
                </section>

                <section class="wizard-step hidden" data-step="2">
                    <div data-secret-field="template">
                        <label for="modal-template-uuid"><?= e(__('ui.home.template')) ?></label>
                        <select id="modal-template-uuid" name="template_uuid">
                            <option value=""><?= e(__('ui.app.none')) ?></option>
                            <?php foreach ($templates as $template): ?><option value="<?= e($template->uuid) ?>"><?= e($template->name) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div data-secret-field="value">
                        <label for="modal-secret-value"><?= e(__('ui.home.value')) ?></label>
                        <input id="modal-secret-value" class="mono" name="value" placeholder="<?= e(__('ui.home.value_placeholder')) ?>">
                    </div>
                </section>

                <section class="wizard-step hidden" data-step="3">
                    <div>
                        <label for="modal-rotation-integration"><?= e(__('ui.home.rotation_integration')) ?></label>
                        <select id="modal-rotation-integration" name="rotation_integration_uuid">
                            <option value=""><?= e(__('ui.app.none')) ?></option>
                            <?php foreach ($integrations as $integration): ?><option value="<?= e($integration->uuid) ?>"><?= e($integration->name) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="modal-rotation-schedule"><?= e(__('ui.home.rotation_schedule')) ?></label>
                        <input id="modal-rotation-schedule" class="mono" name="rotation_schedule" placeholder="0 3 * * *">
                    </div>
                    <div class="wizard-meta" id="secret-review"></div>
                </section>

                <div class="actions-end">
                    <button type="button" class="secondary" data-close-modal="secret-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="button" class="secondary hidden" id="secret-prev-button"><?= e(__('ui.organization.back')) ?></button>
                    <button type="button" id="secret-next-button"><?= e(__('ui.organization.next')) ?></button>
                    <button type="submit" class="hidden" id="secret-submit-button"><?= e(__('ui.organization.create')) ?></button>
                </div>
            </form>
        </div>
    </dialog>

    <script>
    (() => {
        const menus = document.querySelectorAll('.js-delayed-menu');

        for (const menu of menus) {
            let closeTimer = null;

            const closeMenu = () => {
                menu.classList.remove('is-open');
            };

            const openMenu = () => {
                if (closeTimer !== null) {
                    window.clearTimeout(closeTimer);
                    closeTimer = null;
                }
                for (const other of menus) {
                    if (other !== menu) {
                        other.classList.remove('is-open');
                    }
                }
                menu.classList.add('is-open');
            };

            const scheduleClose = () => {
                if (closeTimer !== null) {
                    window.clearTimeout(closeTimer);
                }
                closeTimer = window.setTimeout(() => {
                    closeMenu();
                    closeTimer = null;
                }, 180);
            };

            menu.addEventListener('mouseenter', openMenu);
            menu.addEventListener('mouseleave', scheduleClose);
            menu.addEventListener('focusin', openMenu);
            menu.addEventListener('focusout', () => {
                window.setTimeout(() => {
                    if (!menu.contains(document.activeElement)) {
                        scheduleClose();
                    }
                }, 0);
            });
        }

        const openButtons = document.querySelectorAll('[data-open-modal]');
        const closeButtons = document.querySelectorAll('[data-close-modal]');

        openButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = document.getElementById(button.getAttribute('data-open-modal'));
                if (dialog && typeof dialog.showModal === 'function') {
                    dialog.showModal();
                }
            });
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const dialog = document.getElementById(button.getAttribute('data-close-modal'));
                if (dialog) {
                    dialog.close();
                }
            });
        });

        const wizardForm = document.getElementById('secret-modal-form');
        if (!wizardForm) {
            return;
        }

        const steps = Array.from(wizardForm.querySelectorAll('[data-step]'));
        const typeField = document.getElementById('modal-secret-type');
        const meta = document.getElementById('secret-wizard-meta');
        const review = document.getElementById('secret-review');
        const nextButton = document.getElementById('secret-next-button');
        const prevButton = document.getElementById('secret-prev-button');
        const submitButton = document.getElementById('secret-submit-button');
        let currentStep = 1;

        const syncTypeFields = () => {
            const type = typeField.value;
            wizardForm.querySelectorAll('[data-secret-field="template"]').forEach((element) => {
                element.classList.toggle('hidden', type !== 'template');
            });
            wizardForm.querySelectorAll('[data-secret-field="value"]').forEach((element) => {
                element.classList.toggle('hidden', type === 'template');
            });
        };

        const updateReview = () => {
            const name = document.getElementById('modal-secret-name').value.trim() || '...';
            const type = typeField.value;
            const schedule = document.getElementById('modal-rotation-schedule').value.trim();
            review.textContent = `${name} / ${type}${schedule !== '' ? ' / ' + schedule : ''}`;
        };

        const renderStep = () => {
            steps.forEach((step, index) => {
                step.classList.toggle('hidden', index + 1 !== currentStep);
            });
            prevButton.classList.toggle('hidden', currentStep === 1);
            nextButton.classList.toggle('hidden', currentStep === steps.length);
            submitButton.classList.toggle('hidden', currentStep !== steps.length);
            meta.textContent = `<?= e(__('ui.organization.wizard_step', ['current' => ':current', 'total' => ':total'])) ?>`
                .replace(':current', String(currentStep))
                .replace(':total', String(steps.length));
            syncTypeFields();
            updateReview();
        };

        nextButton.addEventListener('click', () => {
            if (currentStep < steps.length) {
                currentStep += 1;
                renderStep();
            }
        });
        prevButton.addEventListener('click', () => {
            if (currentStep > 1) {
                currentStep -= 1;
                renderStep();
            }
        });
        typeField.addEventListener('change', renderStep);
        wizardForm.addEventListener('input', updateReview);
        renderStep();
    })();
    </script>
<?php endif; ?>
