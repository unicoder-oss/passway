<?php
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid . '?dir=' . $directory->uuid, 'label' => __('ui.secret.back_to_directory')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');

$isDynamicSecret = $secret->type === 'dynamic';
$isTemplateSecret = $secret->type === 'template';
$replaceAction = '/organizations/' . $organization->uuid . '/directories/' . $directory->uuid . '/secrets/' . $secret->uuid . '/update';
$regenerateAction = '/organizations/' . $organization->uuid . '/directories/' . $directory->uuid . '/secrets/' . $secret->uuid . '/regenerate';
$rotateAction = '/organizations/' . $organization->uuid . '/directories/' . $directory->uuid . '/secrets/' . $secret->uuid . '/rotate';
$deleteAction = '/organizations/' . $organization->uuid . '/directories/' . $directory->uuid . '/secrets/' . $secret->uuid . '/delete';
$templatePreviewUrl = '/api/v1/organizations/' . $organization->uuid . '/directories/' . $directory->uuid . '/secrets/template-preview';
?>

<?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.secret.details_for_org', ['organization' => $organization->name])) ?></h1>
</section>

<div class="grid grid-2-compact" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h1 style="margin:0; font-size:1.6rem;"><?= e($secret->name) ?></h1>
            <p class="muted" style="margin:.45rem 0 0;"><?= e(__('ui.secret.meta', ['type' => __('ui.home.types.' . $secret->type), 'version' => (string) $secret->version, 'directory' => $directory->name])) ?></p>
            <div class="actions" style="margin-top:.75rem;">
                <span class="pill"><?= e(__('ui.home.types.' . $secret->type)) ?></span>
                <?php if ($isDynamicSecret && $secret->rotationSchedule !== null && $secret->rotationSchedule !== ''): ?><span class="pill mono"><?= e(__('ui.secret.schedule', ['schedule' => $secret->rotationSchedule])) ?></span><?php endif; ?>
                <?php if ($isDynamicSecret && $secret->lastRotatedAt !== null): ?><span class="pill"><?= e(__('ui.secret.last_rotated', ['date' => $secret->lastRotatedAt])) ?></span><?php endif; ?>
                <?php if ($isDynamicSecret && $selectedIntegration !== null): ?><span class="pill"><?= e(__('ui.secret.integration', ['name' => $selectedIntegration->name])) ?></span><?php endif; ?>
                <?php if ($isTemplateSecret && $templateDetails !== null): ?><span class="pill"><?= e($templateDetails['name']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
            <label><?= e(__('ui.secret.current_value')) ?></label>
            <button type="button" class="secondary" id="secret-value-mask" style="justify-content:flex-start; min-height:120px; text-align:left;"><?= e(__('ui.secret.click_to_reveal')) ?></button>
            <textarea id="secret-value-display" class="mono hidden" rows="8" readonly><?= e($displayValue ?? $value) ?></textarea>
            <div class="actions hidden" id="secret-value-actions">
                <button type="button" class="secondary" id="secret-value-hide"><?= e(__('ui.secret.hide_value')) ?></button>
            </div>
        </div>

        <?php foreach ($templateExtraFields as $field): ?>
            <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                <label><?= e($field['label']) ?></label>
                <textarea class="mono" rows="4" readonly><?= e($field['value']) ?></textarea>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="grid" style="gap:1rem;">
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.secret.manual_actions')) ?></h3>
            <div class="grid" style="gap:.75rem;">
                <button type="button" class="secondary" data-open-modal="rename-secret-modal"><?= e(__('ui.secret.rename_secret')) ?></button>
                <button type="button" class="secondary" data-open-modal="replace-secret-modal"><?= e(__('ui.secret.replace_value')) ?></button>
                <?php if ($isDynamicSecret): ?>
                    <button type="button" class="secondary" data-open-modal="rotation-secret-modal"><?= e(__('ui.secret.rotation_integration')) ?></button>
                    <form method="POST" action="<?= e($rotateAction) ?>">
                        <button type="submit"><?= e(__('ui.secret.rotate_secret')) ?></button>
                    </form>
                <?php endif; ?>
                <form method="POST" action="<?= e($deleteAction) ?>">
                    <button type="submit" class="danger"><?= e(__('ui.secret.delete_secret')) ?></button>
                </form>
            </div>
        </div>
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.secret.version_history')) ?></h3>
            <div class="grid" style="gap:.6rem;">
                <?php foreach ($versions as $version): ?>
                    <div class="panel" style="padding:.85rem; background:rgba(15,23,42,.55);">
                        <div style="font-weight:700;"><?= e(__('ui.secret.version_label', ['version' => (string) $version->version])) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.secret.version_meta', ['rotation_type' => $version->rotationType, 'status' => $version->status, 'created_at' => $version->createdAt])) ?></div>
                        <?php if ($version->errorMessage !== null): ?><div class="muted" style="margin-top:.25rem;"><?= e($version->errorMessage) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($versions === []): ?><div class="muted"><?= e(__('ui.secret.no_versions')) ?></div><?php endif; ?>
            </div>
        </div>
    </section>
</div>

<dialog id="rename-secret-modal" class="modal">
    <div class="modal-body">
        <div>
            <h3 style="margin:0 0 .35rem;"><?= e(__('ui.secret.rename_secret')) ?></h3>
        </div>
        <form method="POST" action="<?= e($replaceAction) ?>" class="grid" style="gap:1rem;">
            <div>
                <label for="secret-name"><?= e(__('ui.secret.rename_secret')) ?></label>
                <input id="secret-name" name="name" value="<?= e($secret->name) ?>" required>
            </div>
            <div class="actions-end">
                <button type="button" class="secondary" data-close-modal="rename-secret-modal"><?= e(__('ui.organization.cancel')) ?></button>
                <button type="submit"><?= e(__('ui.secret.save_changes')) ?></button>
            </div>
        </form>
    </div>
</dialog>

<dialog id="replace-secret-modal" class="modal">
    <div class="modal-body">
        <div>
            <h3 style="margin:0 0 .35rem;"><?= e(__('ui.secret.replace_value')) ?></h3>
        </div>

        <?php if ($isTemplateSecret && $templateDetails !== null): ?>
            <form method="POST" action="<?= e($regenerateAction) ?>" id="template-secret-form" class="grid" style="gap:1rem;">
                <input type="hidden" name="template_overrides" id="template-secret-overrides" value='<?= e(json_encode($templateOverrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>'>
                <input type="hidden" name="generated_value" id="template-secret-generated-value" value="<?= e($value) ?>">
                <div>
                    <label for="template-secret-select"><?= e(__('ui.home.template')) ?></label>
                    <select id="template-secret-select" disabled>
                        <option selected><?= e($templateDetails['name']) ?></option>
                    </select>
                </div>
                <div>
                    <label for="template-secret-display"><?= e(__('ui.home.generated_value')) ?></label>
                    <div class="grid field-actions-2" style="gap:.75rem; align-items:start;">
                        <textarea id="template-secret-display" class="mono" rows="8" readonly><?= e($displayValue) ?></textarea>
                        <button type="button" class="secondary" id="template-secret-regenerate-button"><?= e(__('ui.home.regenerate')) ?></button>
                    </div>
                    <div class="wizard-meta" id="template-secret-status"></div>
                </div>
                <div id="template-secret-params" class="grid"></div>
                <div id="template-secret-extra-fields" class="grid"></div>
                <div class="actions-end">
                    <button type="button" class="secondary" data-close-modal="replace-secret-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="submit"><?= e(__('ui.secret.save_changes')) ?></button>
                </div>
            </form>
        <?php else: ?>
            <form method="POST" action="<?= e($replaceAction) ?>" class="grid" style="gap:1rem;">
                <?php if ($secret->type === 'static'): ?>
                    <div>
                        <label for="replace-secret-template"><?= e(__('ui.home.template')) ?></label>
                        <select id="replace-secret-template" disabled>
                            <option selected><?= e(__('ui.home.no_template')) ?></option>
                        </select>
                    </div>
                <?php endif; ?>
                <div>
                    <label for="secret-value"><?= e(__('ui.secret.replace_value')) ?></label>
                    <textarea id="secret-value" class="mono" name="value" rows="8" placeholder="<?= e(__('ui.secret.replace_value_placeholder')) ?>"></textarea>
                </div>
                <div class="actions-end">
                    <button type="button" class="secondary" data-close-modal="replace-secret-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="submit"><?= e(__('ui.secret.save_changes')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</dialog>

<?php if ($isDynamicSecret): ?>
    <dialog id="rotation-secret-modal" class="modal">
        <div class="modal-body">
            <div>
                <h3 style="margin:0 0 .35rem;"><?= e(__('ui.secret.rotation_integration')) ?></h3>
            </div>
            <form method="POST" action="<?= e($replaceAction) ?>" class="grid" style="gap:1rem;">
                <div>
                    <label for="secret-rotation-integration"><?= e(__('ui.secret.rotation_integration')) ?></label>
                    <select id="secret-rotation-integration" name="rotation_integration_uuid">
                        <option value=""><?= e(__('ui.app.none')) ?></option>
                        <?php foreach ($integrations as $integration): ?>
                            <option value="<?= e($integration->uuid) ?>" <?= $selectedIntegration?->uuid === $integration->uuid ? 'selected' : '' ?>><?= e($integration->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="secret-rotation-schedule"><?= e(__('ui.secret.rotation_schedule')) ?></label>
                    <input id="secret-rotation-schedule" class="mono" name="rotation_schedule" value="<?= e((string) ($secret->rotationSchedule ?? '')) ?>" placeholder="0 3 * * *">
                </div>
                <div class="actions-end">
                    <button type="button" class="secondary" data-close-modal="rotation-secret-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="submit"><?= e(__('ui.secret.save_changes')) ?></button>
                </div>
            </form>
        </div>
    </dialog>
<?php endif; ?>

<script>
(() => {
    const openButtons = document.querySelectorAll('[data-open-modal]');
    const closeButtons = document.querySelectorAll('[data-close-modal]');
    const valueMask = document.getElementById('secret-value-mask');
    const valueDisplay = document.getElementById('secret-value-display');
    const valueActions = document.getElementById('secret-value-actions');
    const valueHide = document.getElementById('secret-value-hide');

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

    if (valueMask && valueDisplay && valueActions && valueHide) {
        valueMask.addEventListener('click', () => {
            valueMask.classList.add('hidden');
            valueDisplay.classList.remove('hidden');
            valueActions.classList.remove('hidden');
        });

        valueHide.addEventListener('click', () => {
            valueMask.classList.remove('hidden');
            valueDisplay.classList.add('hidden');
            valueActions.classList.add('hidden');
        });
    }

    const templateForm = document.getElementById('template-secret-form');
    if (!templateForm) {
        return;
    }

    const previewUrl = <?= json_encode($templatePreviewUrl) ?>;
    const templateUuid = <?= json_encode($templateDetails['uuid'] ?? '') ?>;
    const status = document.getElementById('template-secret-status');
    const display = document.getElementById('template-secret-display');
    const extraFields = document.getElementById('template-secret-extra-fields');
    const params = document.getElementById('template-secret-params');
    const overridesInput = document.getElementById('template-secret-overrides');
    const generatedValueInput = document.getElementById('template-secret-generated-value');
    const regenerateButton = document.getElementById('template-secret-regenerate-button');
    const replaceModal = document.getElementById('replace-secret-modal');
    const initialSchema = <?= json_encode($templateParameterSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialExtraFields = <?= json_encode($templateExtraFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialOverrides = <?= json_encode($templateOverrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialValue = <?= json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialDisplayValue = <?= json_encode($displayValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let previewRequestId = 0;

    const renderExtraFields = (fields) => {
        extraFields.innerHTML = '';
        extraFields.classList.toggle('hidden', fields.length === 0);

        fields.forEach((field) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'panel panel-muted';
            wrapper.style.padding = '1rem';
            wrapper.style.display = 'grid';
            wrapper.style.gap = '.75rem';

            const label = document.createElement('label');
            label.textContent = field.label;
            const textarea = document.createElement('textarea');
            textarea.className = 'mono';
            textarea.rows = 4;
            textarea.readOnly = true;
            textarea.value = field.value;

            wrapper.appendChild(label);
            wrapper.appendChild(textarea);
            extraFields.appendChild(wrapper);
        });
    };

    const collectOverrides = () => {
        const overrides = {};
        params.querySelectorAll('[data-template-param]').forEach((input) => {
            const name = input.getAttribute('data-template-param');
            if (!name) {
                return;
            }

            overrides[name] = input.type === 'checkbox' ? input.checked : input.value;
        });

        return overrides;
    };

    const requestPreview = async (isManual) => {
        previewRequestId += 1;
        const requestId = previewRequestId;
        regenerateButton.disabled = true;
        status.textContent = <?= json_encode((string) __('ui.home.template_generating')) ?>;

        try {
            const response = await fetch(previewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    template_uuid: templateUuid,
                    template_overrides: collectOverrides(),
                }),
            });
            const payload = await response.json();

            if (requestId !== previewRequestId) {
                return;
            }

            if (!response.ok || !payload.success) {
                throw new Error(payload.error || <?= json_encode((string) __('ui.home.template_preview_error')) ?>);
            }

            applyPreview(payload.data, isManual);
        } catch (error) {
            if (requestId === previewRequestId) {
                status.textContent = error instanceof Error ? error.message : <?= json_encode((string) __('ui.home.template_preview_error')) ?>;
            }
        } finally {
            if (requestId === previewRequestId) {
                regenerateButton.disabled = false;
            }
        }
    };

    const renderSchema = (schema, values) => {
        params.innerHTML = '';

        const heading = document.createElement('div');
        heading.className = 'wizard-meta';
        heading.textContent = <?= json_encode((string) __('ui.home.template_parameters')) ?>;
        params.appendChild(heading);

        schema.forEach((field) => {
            const wrapper = document.createElement('div');
            const inputId = `replace-template-${field.name}`;
            const value = Object.prototype.hasOwnProperty.call(values, field.name)
                ? values[field.name]
                : field.value;
            const label = document.createElement('label');

            if (field.type === 'boolean') {
                label.style.display = 'flex';
                label.style.gap = '.65rem';
                label.style.alignItems = 'center';
                label.style.margin = '0';

                const input = document.createElement('input');
                input.id = inputId;
                input.type = 'checkbox';
                input.checked = Boolean(value);
                input.setAttribute('data-template-param', field.name);
                const text = document.createElement('span');
                text.textContent = field.label;

                label.appendChild(input);
                label.appendChild(text);
                wrapper.appendChild(label);
            } else {
                label.htmlFor = inputId;
                label.textContent = field.label;

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

                wrapper.appendChild(label);
                wrapper.appendChild(input);
            }

            params.appendChild(wrapper);
        });

        params.querySelectorAll('[data-template-param]').forEach((input) => {
            input.addEventListener(input.type === 'checkbox' ? 'change' : 'input', () => {
                void requestPreview(false);
            });
        });
    };

    const applyPreview = (data, isManual) => {
        overridesInput.value = JSON.stringify(data.template_overrides);
        generatedValueInput.value = data.value;
        display.value = data.display_value;
        renderSchema(data.parameter_schema || [], data.template_overrides || {});
        renderExtraFields(data.extra_fields || []);
        status.textContent = isManual ? <?= json_encode((string) __('ui.home.regenerated')) ?> : '';
    };

    regenerateButton.addEventListener('click', () => {
        void requestPreview(true);
    });

    templateForm.addEventListener('submit', (event) => {
        if (generatedValueInput.value === '') {
            event.preventDefault();
            void requestPreview(true);
        }
    });

    if (replaceModal) {
        replaceModal.addEventListener('close', () => {
            applyPreview({
                value: initialValue,
                display_value: initialDisplayValue,
                extra_fields: initialExtraFields,
                parameter_schema: initialSchema,
                template_overrides: initialOverrides,
            }, false);
            status.textContent = '';
        });
    }

    applyPreview({
        value: initialValue,
        display_value: initialDisplayValue,
        extra_fields: initialExtraFields,
        parameter_schema: initialSchema,
        template_overrides: initialOverrides,
    }, false);
})();
</script>
