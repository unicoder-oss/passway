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
            .secret-kind-group {
                display: flex;
                gap: .75rem;
                flex-wrap: wrap;
            }
            .secret-kind-button {
                min-width: 150px;
                background: var(--button-secondary);
                color: var(--button-secondary-fg);
                border-color: var(--border);
            }
            .secret-kind-button.is-active {
                background: var(--button);
                color: var(--button-fg);
                border-color: var(--button);
            }
            .template-extra-field {
                padding: .9rem 1rem;
                border: 1px solid var(--border);
                background: var(--panel-subtle);
                display: grid;
                gap: .5rem;
            }
            .template-params-layout {
                display: grid;
                gap: 1rem;
            }
            .template-range-field {
                display: grid;
                gap: .5rem;
            }
            .template-range-inputs {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 84px;
                gap: .75rem;
                align-items: center;
            }
            .template-range-inputs input[type="range"] {
                width: 100%;
                margin: 0;
            }
            .template-range-inputs input[type="number"] {
                width: 84px;
                min-width: 84px;
                text-align: center;
            }
            .template-params-columns {
                display: grid;
                gap: 1rem;
            }
            .template-param-checks {
                display: grid;
                gap: .75rem;
                align-content: start;
            }
            .template-param-check {
                display: flex;
                align-items: center;
                justify-content: flex-start;
                gap: .65rem;
                margin: 0;
            }
            .template-param-check input {
                width: auto;
                margin: 0;
                flex: 0 0 auto;
            }
            .template-param-check span {
                text-align: left;
            }
            @media (min-width: 720px) {
                .template-params-columns {
                    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                    align-items: start;
                }
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
                <div class="wizard-meta" id="secret-wizard-meta"><?= e(__('ui.organization.wizard_step', ['current' => '1', 'total' => '2'])) ?></div>
            </div>
            <form id="secret-modal-form" method="POST" action="<?= e($secretAction) ?>" class="grid" style="gap:1rem;">
                <input type="hidden" id="modal-secret-type" name="type" value="static">
                <input type="hidden" id="modal-template-overrides" name="template_overrides" value="{}">
                <input type="hidden" id="modal-generated-value" name="generated_value" value="">
                <section class="wizard-step" data-step="1">
                    <div>
                        <label for="modal-secret-name"><?= e(__('ui.home.secret_name')) ?></label>
                        <input id="modal-secret-name" name="name" placeholder="<?= e(__('ui.home.secret_name_placeholder')) ?>" required>
                    </div>
                    <div>
                        <label><?= e(__('ui.home.type')) ?></label>
                        <div class="secret-kind-group">
                            <button type="button" class="secret-kind-button is-active" data-secret-mode="static"><?= e(__('ui.home.types.static')) ?></button>
                            <button type="button" class="secret-kind-button" data-secret-mode="dynamic"><?= e(__('ui.home.types.dynamic')) ?></button>
                        </div>
                    </div>
                </section>

                <section class="wizard-step hidden" data-step="2">
                    <div data-secret-flow="static" class="grid" style="gap:1rem;">
                        <div>
                            <label for="modal-template-uuid"><?= e(__('ui.home.template')) ?></label>
                            <select id="modal-template-uuid" name="template_uuid">
                                <option value=""><?= e(__('ui.home.no_template')) ?></option>
                                <?php foreach ($templates as $template): ?><option value="<?= e($template->uuid) ?>"><?= e($template->name) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div id="modal-static-value-field">
                            <label for="modal-secret-value"><?= e(__('ui.home.value')) ?></label>
                            <textarea id="modal-secret-value" class="mono" name="value" rows="6" placeholder="<?= e(__('ui.home.value_placeholder')) ?>"></textarea>
                        </div>
                        <div id="modal-template-preview-field" class="hidden">
                            <label for="modal-generated-display"><?= e(__('ui.home.generated_value')) ?></label>
                            <div class="grid field-actions-2" style="gap:.75rem; align-items:start;">
                                <textarea id="modal-generated-display" class="mono" rows="8" readonly></textarea>
                                <div class="grid" style="gap:.5rem; align-content:start;">
                                    <button type="button" class="secondary" id="modal-regenerate-button"><?= e(__('ui.home.regenerate')) ?></button>
                                    <div class="wizard-meta" id="modal-template-status"></div>
                                </div>
                            </div>
                        </div>
                        <div id="modal-template-params" class="grid hidden"></div>
                        <div id="modal-template-extra-fields" class="grid hidden"></div>
                    </div>

                    <div data-secret-flow="dynamic" class="grid hidden" style="gap:1rem;">
                        <div>
                            <label for="modal-dynamic-secret-value"><?= e(__('ui.home.value')) ?></label>
                            <textarea id="modal-dynamic-secret-value" class="mono" name="value" rows="6" placeholder="<?= e(__('ui.home.value_placeholder')) ?>"></textarea>
                        </div>
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
        const secretModal = document.getElementById('secret-modal');
        const meta = document.getElementById('secret-wizard-meta');
        const review = document.getElementById('secret-review');
        const nextButton = document.getElementById('secret-next-button');
        const prevButton = document.getElementById('secret-prev-button');
        const submitButton = document.getElementById('secret-submit-button');
        const modeButtons = Array.from(wizardForm.querySelectorAll('[data-secret-mode]'));
        const templateSelect = document.getElementById('modal-template-uuid');
        const staticValueField = document.getElementById('modal-static-value-field');
        const staticValueInput = document.getElementById('modal-secret-value');
        const dynamicValueInput = document.getElementById('modal-dynamic-secret-value');
        const previewField = document.getElementById('modal-template-preview-field');
        const previewDisplay = document.getElementById('modal-generated-display');
        const previewStatus = document.getElementById('modal-template-status');
        const regenerateButton = document.getElementById('modal-regenerate-button');
        const templateParams = document.getElementById('modal-template-params');
        const templateExtraFields = document.getElementById('modal-template-extra-fields');
        const templateOverridesInput = document.getElementById('modal-template-overrides');
        const generatedValueInput = document.getElementById('modal-generated-value');
        const previewUrl = <?= json_encode('/api/v1/organizations/' . $organization->uuid . '/directories/' . $secretDirUuid . '/secrets/template-preview') ?>;
        let currentStep = 1;
        let secretMode = 'static';
        let previewRequestId = 0;
        let previewTimer = null;

        const getStepCount = () => secretMode === 'dynamic' ? 3 : 2;
        const isTemplateSelected = () => secretMode === 'static' && templateSelect.value !== '';
        const getBackendType = () => {
            if (secretMode === 'dynamic') {
                return 'dynamic';
            }

            return templateSelect.value !== '' ? 'template' : 'static';
        };

        const setMode = (mode) => {
            secretMode = mode === 'dynamic' ? 'dynamic' : 'static';
            typeField.value = getBackendType();
            modeButtons.forEach((button) => {
                button.classList.toggle('is-active', button.getAttribute('data-secret-mode') === secretMode);
            });
            currentStep = Math.min(currentStep, getStepCount());
        };

        const renderExtraFields = (fields) => {
            templateExtraFields.innerHTML = '';
            templateExtraFields.classList.toggle('hidden', fields.length === 0);

            fields.forEach((field) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'template-extra-field';
                const label = document.createElement('label');
                label.textContent = field.label;
                const textarea = document.createElement('textarea');
                textarea.className = 'mono';
                textarea.rows = 4;
                textarea.readOnly = true;
                textarea.value = field.value;
                wrapper.appendChild(label);
                wrapper.appendChild(textarea);
                templateExtraFields.appendChild(wrapper);
            });
        };

        const schedulePreviewRefresh = () => {
            if (!isTemplateSelected()) {
                return;
            }

            if (previewTimer !== null) {
                window.clearTimeout(previewTimer);
            }

            previewTimer = window.setTimeout(() => {
                previewTimer = null;
                void refreshTemplatePreview(false);
            }, 220);
        };

        const collectTemplateOverrides = () => {
            const overrides = {};
            templateParams.querySelectorAll('[data-template-param]').forEach((input) => {
                const name = input.getAttribute('data-template-param');
                if (!name) {
                    return;
                }

                if (input.type === 'checkbox') {
                    overrides[name] = input.checked;
                    return;
                }

                overrides[name] = input.value;
            });

            return overrides;
        };

        const renderParameterSchema = (schema) => {
            const currentValues = collectTemplateOverrides();
            templateParams.innerHTML = '';
            templateParams.classList.toggle('hidden', schema.length === 0);

            if (schema.length === 0) {
                return;
            }

            const heading = document.createElement('div');
            heading.className = 'wizard-meta';
            heading.textContent = <?= json_encode((string) __('ui.home.template_parameters')) ?>;
            templateParams.appendChild(heading);

            const layout = document.createElement('div');
            layout.className = 'template-params-layout';
            templateParams.appendChild(layout);

            const generalFields = [];
            const booleanFields = [];
            let specialCharsField = null;

            schema.forEach((field) => {
                if (field.type === 'boolean') {
                    booleanFields.push(field);
                    return;
                }

                if (field.name === 'special_chars') {
                    specialCharsField = field;
                    return;
                }

                generalFields.push(field);
            });

            const appendTextField = (container, field) => {
                const wrapper = document.createElement('div');
                if (field.name === 'length') {
                    wrapper.className = 'template-range-field';
                }
                const inputId = `template-param-${field.name}`;
                const value = Object.prototype.hasOwnProperty.call(currentValues, field.name)
                    ? currentValues[field.name]
                    : field.value;
                const label = document.createElement('label');
                label.htmlFor = inputId;
                label.textContent = field.label;
                wrapper.appendChild(label);

                if (field.name === 'length') {
                    const controls = document.createElement('div');
                    controls.className = 'template-range-inputs';

                    const rangeInput = document.createElement('input');
                    rangeInput.id = inputId;
                    rangeInput.type = 'range';
                    rangeInput.setAttribute('data-template-param', field.name);
                    if (field.min !== undefined) {
                        rangeInput.min = String(field.min);
                    }
                    if (field.max !== undefined) {
                        rangeInput.max = String(field.max);
                    }
                    rangeInput.step = '1';
                    rangeInput.value = String(value ?? '');

                    const numberInput = document.createElement('input');
                    numberInput.type = 'number';
                    numberInput.setAttribute('data-template-param-number', field.name);
                    if (field.min !== undefined) {
                        numberInput.min = String(field.min);
                    }
                    if (field.max !== undefined) {
                        numberInput.max = String(field.max);
                    }
                    numberInput.step = '1';
                    numberInput.value = String(value ?? '');

                    const syncLengthValue = (source, target) => {
                        target.value = source.value;
                        schedulePreviewRefresh();
                    };

                    rangeInput.addEventListener('input', () => syncLengthValue(rangeInput, numberInput));
                    numberInput.addEventListener('input', () => syncLengthValue(numberInput, rangeInput));

                    controls.appendChild(rangeInput);
                    controls.appendChild(numberInput);
                    wrapper.appendChild(controls);
                    container.appendChild(wrapper);
                    return;
                }

                const input = document.createElement('input');
                input.id = inputId;
                input.type = field.type;
                input.value = String(value ?? '');
                input.setAttribute('data-template-param', field.name);
                if (field.min !== undefined) {
                    input.min = String(field.min);
                }
                if (field.max !== undefined) {
                    input.max = String(field.max);
                }
                wrapper.appendChild(input);
                container.appendChild(wrapper);
            };

            generalFields.forEach((field) => appendTextField(layout, field));

            if (booleanFields.length > 0 || specialCharsField !== null) {
                const columns = document.createElement('div');
                columns.className = 'template-params-columns';
                layout.appendChild(columns);

                const checksColumn = document.createElement('div');
                checksColumn.className = 'template-param-checks';
                columns.appendChild(checksColumn);

                booleanFields.forEach((field) => {
                    const wrapper = document.createElement('div');
                    const inputId = `template-param-${field.name}`;
                    const value = Object.prototype.hasOwnProperty.call(currentValues, field.name)
                        ? currentValues[field.name]
                        : field.value;
                    const label = document.createElement('label');
                    label.className = 'template-param-check';
                    const input = document.createElement('input');
                    input.id = inputId;
                    input.type = 'checkbox';
                    input.setAttribute('data-template-param', field.name);
                    input.checked = Boolean(value);
                    const span = document.createElement('span');
                    span.textContent = field.label;
                    label.appendChild(input);
                    label.appendChild(span);
                    wrapper.appendChild(label);
                    checksColumn.appendChild(wrapper);
                });

                if (specialCharsField !== null) {
                    const specialColumn = document.createElement('div');
                    columns.appendChild(specialColumn);
                    appendTextField(specialColumn, specialCharsField);
                }
            }

            templateParams.querySelectorAll('[data-template-param]').forEach((input) => {
                input.addEventListener(input.type === 'checkbox' ? 'change' : 'input', schedulePreviewRefresh);
            });
        };

        const clearTemplatePreview = () => {
            generatedValueInput.value = '';
            templateOverridesInput.value = '{}';
            previewDisplay.value = '';
            previewStatus.textContent = '';
            previewField.classList.add('hidden');
            templateParams.innerHTML = '';
            templateParams.classList.add('hidden');
            templateExtraFields.innerHTML = '';
            templateExtraFields.classList.add('hidden');
        };

        const refreshTemplatePreview = async (isManual) => {
            if (previewTimer !== null) {
                window.clearTimeout(previewTimer);
                previewTimer = null;
            }

            if (!isTemplateSelected()) {
                clearTemplatePreview();
                return;
            }

            previewRequestId += 1;
            const requestId = previewRequestId;
            previewStatus.textContent = <?= json_encode((string) __('ui.home.template_generating')) ?>;
            regenerateButton.disabled = true;

            try {
                const response = await fetch(previewUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        template_uuid: templateSelect.value,
                        template_overrides: collectTemplateOverrides(),
                    }),
                });
                const payload = await response.json();

                if (requestId !== previewRequestId) {
                    return;
                }

                if (!response.ok || !payload.success) {
                    throw new Error(payload.error || <?= json_encode((string) __('ui.home.template_preview_error')) ?>);
                }

                const data = payload.data;
                generatedValueInput.value = data.value;
                templateOverridesInput.value = JSON.stringify(data.template_overrides);
                previewDisplay.value = data.display_value;
                previewField.classList.remove('hidden');
                previewStatus.textContent = isManual ? <?= json_encode((string) __('ui.home.regenerated')) ?> : '';
                renderParameterSchema(data.parameter_schema || []);
                renderExtraFields(data.extra_fields || []);
            } catch (error) {
                if (requestId !== previewRequestId) {
                    return;
                }

                generatedValueInput.value = '';
                previewStatus.textContent = error instanceof Error ? error.message : <?= json_encode((string) __('ui.home.template_preview_error')) ?>;
            } finally {
                if (requestId === previewRequestId) {
                    regenerateButton.disabled = false;
                }
            }
        };

        const syncTypeFields = () => {
            const templateSelected = isTemplateSelected();

            typeField.value = getBackendType();
            wizardForm.querySelectorAll('[data-secret-flow="static"]').forEach((element) => {
                element.classList.toggle('hidden', secretMode !== 'static');
            });
            wizardForm.querySelectorAll('[data-secret-flow="dynamic"]').forEach((element) => {
                element.classList.toggle('hidden', secretMode !== 'dynamic');
            });

            staticValueField.classList.toggle('hidden', templateSelected);
            previewField.classList.toggle('hidden', !templateSelected || generatedValueInput.value === '');
            staticValueInput.disabled = secretMode !== 'static' || templateSelected;
            dynamicValueInput.disabled = secretMode !== 'dynamic';

            if (!templateSelected) {
                clearTemplatePreview();
            }
        };

        const updateReview = () => {
            const name = document.getElementById('modal-secret-name').value.trim() || '...';
            const type = getBackendType();
            const schedule = document.getElementById('modal-rotation-schedule').value.trim();
            review.textContent = `${name} / ${type}${schedule !== '' ? ' / ' + schedule : ''}`;
        };

        const renderStep = () => {
            steps.forEach((step, index) => {
                const stepNumber = index + 1;
                const isVisibleStep = stepNumber <= getStepCount();
                step.classList.toggle('hidden', !isVisibleStep || stepNumber !== currentStep);
            });
            prevButton.classList.toggle('hidden', currentStep === 1);
            nextButton.classList.toggle('hidden', currentStep === getStepCount());
            submitButton.classList.toggle('hidden', currentStep !== getStepCount());
            meta.textContent = `<?= e(__('ui.organization.wizard_step', ['current' => ':current', 'total' => ':total'])) ?>`
                .replace(':current', String(currentStep))
                .replace(':total', String(getStepCount()));
            syncTypeFields();
            updateReview();
        };

        nextButton.addEventListener('click', () => {
            if (currentStep < getStepCount()) {
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

        modeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                setMode(button.getAttribute('data-secret-mode'));
                renderStep();
            });
        });
        templateSelect.addEventListener('change', () => {
            syncTypeFields();
            updateReview();
            if (isTemplateSelected()) {
                void refreshTemplatePreview(false);
            }
        });
        regenerateButton.addEventListener('click', () => {
            void refreshTemplatePreview(true);
        });
        wizardForm.addEventListener('submit', (event) => {
            if (isTemplateSelected() && generatedValueInput.value === '') {
                event.preventDefault();
                void refreshTemplatePreview(true);
            }
        });
        wizardForm.addEventListener('input', updateReview);

        if (secretModal) {
            secretModal.addEventListener('close', () => {
                if (previewTimer !== null) {
                    window.clearTimeout(previewTimer);
                    previewTimer = null;
                }
                wizardForm.reset();
                currentStep = 1;
                setMode('static');
                clearTemplatePreview();
                renderStep();
            });
        }

        setMode('static');
        renderStep();
    })();
    </script>
<?php endif; ?>
