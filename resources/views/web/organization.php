<?php
$topbarTitle = $organization->name;
$topbarLinks = [
    ['href' => '/', 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error"><?= e((string) $queryError) ?></div><?php endif; ?>

<section style="width:min(980px, 100%); margin:0 auto; padding-bottom:2rem; display:grid; gap:1rem;">
    <div class="panel" style="padding:1rem 1.25rem; display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap;">
        <div>
            <h1 style="margin:0 0 .35rem; font-size:2rem;"><?= e($organization->name) ?></h1>
            <div class="muted"><?= e($organization->description ?? __('ui.home.organization_summary_empty')) ?></div>
        </div>
        <div class="actions">
            <?php if ($canViewAudit): ?><a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/audit"><?= e(__('ui.organization.audit_log')) ?></a><?php endif; ?>
            <?php if ($canManageOrganization): ?><a class="button secondary" href="/organizations/<?= e($organization->uuid) ?>/manage"><?= e(__('ui.organization.manage')) ?></a><?php endif; ?>
        </div>
    </div>

    <section class="panel" style="padding:1.25rem; display:grid; gap:1rem;">
        <div style="display:flex; justify-content:space-between; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 .35rem;"><?= e(__('ui.organization.secrets_and_directories')) ?></h2>
                <div class="muted"><?= $currentDir ? e(__('ui.organization.current_directory', ['name' => $currentDir->name])) : e(__('ui.organization.root_level')) ?></div>
            </div>
            <?php if ($canEditContent): ?>
                <div class="actions">
                    <button type="button" data-open-modal="directory-modal"><?= e(__('ui.organization.add_directory_here')) ?></button>
                    <?php if ($currentDir !== null): ?><button type="button" class="secondary" data-open-modal="secret-modal"><?= e(__('ui.organization.add_secret_here')) ?></button><?php endif; ?>
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
                            <div style="font-weight:700;"><?= e($result['directory']->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e($result['path']) ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($searchDirectories === []): ?><div class="muted"><?= e(__('ui.organization.search_no_directories')) ?></div><?php endif; ?>
                </section>
                <section class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                    <h3 style="margin:0;"><?= e(__('ui.organization.search_secrets')) ?></h3>
                    <?php foreach ($searchSecrets as $result): ?>
                        <a href="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($result['directory']->uuid) ?>/secrets/<?= e($result['secret']->uuid) ?>" class="panel" style="padding:1rem; display:block;">
                            <div style="font-weight:700;"><?= e($result['secret']->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e($result['path']) ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($searchSecrets === []): ?><div class="muted"><?= e(__('ui.organization.search_no_secrets')) ?></div><?php endif; ?>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($currentDir !== null && $canEditContent): ?>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/rename" class="panel panel-muted grid field-actions-3" style="padding:1rem; gap:.75rem;">
                <div>
                    <label for="current-dir-name"><?= e(__('ui.home.directory_name')) ?></label>
                    <input id="current-dir-name" name="name" value="<?= e($currentDir->name) ?>">
                </div>
                <button type="submit"><?= e(__('ui.home.rename')) ?></button>
                <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/delete"><?= e(__('ui.app.delete')) ?></button>
            </form>
        <?php endif; ?>

        <div class="grid" style="gap:.75rem;">
            <?php foreach ($directories as $directory): ?>
                <a href="/organizations/<?= e($organization->uuid) ?>?dir=<?= e($directory->uuid) ?>" class="panel panel-muted" style="padding:1rem; display:block;">
                    <div style="font-weight:700;"><?= e($directory->name) ?></div>
                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.organization.directory_item')) ?></div>
                </a>
            <?php endforeach; ?>
            <?php foreach ($secrets as $secret): ?>
                <a href="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/secrets/<?= e($secret->uuid) ?>" class="panel panel-muted" style="padding:1rem; display:block;">
                    <div style="font-weight:700;"><?= e($secret->name) ?></div>
                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.secret.meta', ['type' => __('ui.home.types.' . $secret->type), 'version' => (string) $secret->version, 'directory' => $currentDir?->name ?? __('ui.app.root')])) ?></div>
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
        <dialog id="secret-modal" class="modal">
            <div class="modal-body">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e(__('ui.organization.add_secret_here')) ?></h3>
                    <div class="wizard-meta" id="secret-wizard-meta"><?= e(__('ui.organization.wizard_step', ['current' => '1', 'total' => '3'])) ?></div>
                </div>
                <form id="secret-modal-form" method="POST" action="/organizations/<?= e($organization->uuid) ?>/directories/<?= e($currentDir->uuid) ?>/secrets" class="grid" style="gap:1rem;">
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
    <?php endif; ?>

    <script>
    (() => {
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
