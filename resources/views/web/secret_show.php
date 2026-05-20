<?php
$topbarLinks = [
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
$dynamicRotationOutputs = $dynamicRotationView['outputs'] ?? [];
$dynamicRotationInputs = $dynamicRotationView['input'] ?? [];
$dynamicRotationPrimaryField = $dynamicRotationView['primary_field'] ?? null;
$dynamicRotationService = $dynamicRotationView['service'] ?? null;
$dynamicOutputFields = $dynamicRotationService !== null ? $dynamicRotationService->outputFields() : [];
$renderReadonlyRotationField = static function (array $field, array $values): void {
    $fieldName = isset($field['name']) && is_string($field['name']) ? trim($field['name']) : '';
    if ($fieldName === '') {
        return;
    }

    $label = isset($field['label']) && is_string($field['label']) && trim($field['label']) !== '' ? $field['label'] : $fieldName;
    $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'string';
    $fieldId = 'readonly-rotation-field-' . preg_replace('/[^a-z0-9_-]+/i', '-', $fieldName);
    $value = $values[$fieldName] ?? null;
    $displayValue = $value;

    if ($type === 'enum' && isset($field['options']) && is_array($field['options'])) {
        foreach ($field['options'] as $option) {
            $optionValue = is_array($option) ? (string) ($option['value'] ?? '') : (string) $option;
            if ((string) $value === $optionValue) {
                $displayValue = is_array($option) ? (string) ($option['label'] ?? $optionValue) : $optionValue;
                break;
            }
        }
    }
    ?>
    <div>
        <label for="<?= e($fieldId) ?>"><?= e($label) ?></label>
        <?php if ($type === 'boolean'): ?>
            <label class="inline-check">
                <input id="<?= e($fieldId) ?>" type="checkbox" <?= $value ? 'checked' : '' ?> disabled>
                <span><?= e($label) ?></span>
            </label>
        <?php elseif (in_array($type, ['secret_text', 'readonly_text', 'textarea'], true)): ?>
            <textarea id="<?= e($fieldId) ?>" class="mono" rows="5" readonly><?= e(is_scalar($displayValue) ? (string) $displayValue : '') ?></textarea>
        <?php else: ?>
            <input id="<?= e($fieldId) ?>" class="<?= $type === 'integer' ? 'mono' : '' ?>" value="<?= e(is_scalar($displayValue) ? (string) $displayValue : '') ?>" readonly>
        <?php endif; ?>
    </div>
    <?php
};
?>

<?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.secret.details_for_org', ['organization' => $organization->name])) ?></h1>
</section>

<style>
    .template-params-layout {
        display: grid;
        gap: 1rem;
    }
    .secret-page-shell {
        display: grid;
        gap: 1rem;
        align-items: start;
    }
    .secret-back-link {
        width: fit-content;
        min-width: 120px;
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
    .manual-actions-grid > form {
        width: 100%;
    }
    .manual-actions-grid > form > button {
        width: 100%;
    }
    @media (min-width: 720px) {
        .secret-page-shell {
            grid-template-columns: auto minmax(0, 1fr);
        }
        .template-params-columns {
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            align-items: start;
        }
    }
</style>

<div class="secret-page-shell" style="padding-bottom:2rem;">
    <div>
        <a class="button secondary secret-back-link" href="<?= e($directoryBackUrl) ?>"><?= e(__('ui.secret.back')) ?></a>
    </div>

    <div class="grid grid-2-compact" style="align-items:start;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h1 style="margin:0; font-size:1.6rem;"><?= e($secret->name) ?></h1>
            <p class="muted" style="margin:.45rem 0 0;"><?= e(__('ui.home.version', ['version' => (string) $secret->version])) ?></p>
            <div class="actions" style="margin-top:.75rem;">
                <span class="pill"><?= e(__('ui.home.types.' . $secret->type)) ?></span>
                <?php if ($isDynamicSecret && $secret->rotationSchedule !== null && $secret->rotationSchedule !== ''): ?><span class="pill mono"><?= e(__('ui.secret.schedule', ['schedule' => $secret->rotationSchedule])) ?></span><?php endif; ?>
                <?php if ($isDynamicSecret && $secret->lastRotatedAt !== null): ?><span class="pill"><?= __('ui.secret.last_rotated', ['date' => local_datetime($secret->lastRotatedAt)]) ?></span><?php endif; ?>
                <?php if ($isDynamicSecret && $selectedIntegration !== null): ?><span class="pill"><?= e(__('ui.secret.integration', ['name' => $selectedIntegration->name])) ?></span><?php endif; ?>
                <?php if ($isTemplateSecret && $templateDetails !== null): ?><span class="pill"><?= e($templateDetails['name']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
            <label><?= e(__('ui.secret.current_value')) ?></label>
            <button type="button" class="secondary" id="secret-value-mask" style="justify-content:flex-start; min-height:120px; text-align:left;"><?= e(__('ui.secret.click_to_reveal')) ?></button>
            <textarea id="secret-value-display" class="mono hidden" rows="8" readonly><?= e($displayValue ?? $value) ?></textarea>
            <div class="actions hidden" id="secret-value-actions">
                <button type="button" class="secondary" data-copy-target="secret-value-display"><?= e(__('ui.secret.copy_value')) ?></button>
                <button type="button" class="secondary" id="secret-value-hide"><?= e(__('ui.secret.hide_value')) ?></button>
            </div>
        </div>

        <?php foreach ($templateExtraFields as $field): ?>
            <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                <label><?= e($field['label']) ?></label>
                <?php $fieldId = 'template-extra-field-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string) $field['key']); ?>
                <textarea id="<?= e($fieldId) ?>" class="mono" rows="4" readonly><?= e($field['value']) ?></textarea>
                <div class="actions">
                    <button type="button" class="secondary" data-copy-target="<?= e($fieldId) ?>"><?= e(__('ui.secret.copy_value')) ?></button>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($isDynamicSecret): ?>
            <?php foreach ($dynamicOutputFields as $field): ?>
                <?php
                $key = is_string($field['name'] ?? null) ? $field['name'] : null;
                if ($key === null || $key === $dynamicRotationPrimaryField || !array_key_exists($key, $dynamicRotationOutputs)) {
                    continue;
                }
                $fieldId = 'dynamic-output-field-' . preg_replace('/[^a-z0-9_-]+/i', '-', $key);
                $label = is_string($field['label'] ?? null) && trim((string) $field['label']) !== '' ? (string) $field['label'] : $key;
                ?>
                <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                    <label><?= e($label) ?></label>
                    <textarea id="<?= e($fieldId) ?>" class="mono" rows="4" readonly><?= e((string) $dynamicRotationOutputs[$key]) ?></textarea>
                    <div class="actions">
                        <button type="button" class="secondary" data-copy-target="<?= e($fieldId) ?>"><?= e(__('ui.secret.copy_value')) ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="grid" style="gap:1rem;">
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.secret.manual_actions')) ?></h3>
            <div class="grid manual-actions-grid" style="gap:.75rem;">
                <button type="button" class="secondary" data-open-modal="rename-secret-modal"><?= e(__('ui.secret.rename_secret')) ?></button>
                <?php if (!$isDynamicSecret): ?><button type="button" class="secondary" data-open-modal="replace-secret-modal"><?= e(__('ui.secret.replace_value')) ?></button><?php endif; ?>
                <?php if ($isDynamicSecret): ?>
                    <button type="button" class="secondary" data-open-modal="rotation-secret-modal"><?= e(__('ui.secret.rotation_integration')) ?></button>
                    <form method="POST" action="<?= e($rotateAction) ?>">
                        <button type="submit"><?= e(__('ui.secret.rotate_secret')) ?></button>
                    </form>
                <?php endif; ?>
                <button type="button" class="danger" data-open-modal="delete-secret-modal"><?= e(__('ui.secret.delete_secret')) ?></button>
            </div>
        </div>
        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.secret.version_history')) ?></h3>
            <div class="grid" style="gap:.6rem;">
                <?php foreach ($versions as $version): ?>
                    <div class="panel" style="padding:.85rem; background:rgba(15,23,42,.55);">
                        <div style="font-weight:700;"><?= e(__('ui.secret.version_label', ['version' => (string) $version->version])) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= __('ui.secret.version_meta', ['rotation_type' => e($version->rotationType), 'status' => e($version->status), 'created_at' => local_datetime($version->createdAt)]) ?></div>
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
                <div>
                    <label for="template-secret-select"><?= e(__('ui.home.template')) ?></label>
                    <select id="template-secret-select" disabled>
                        <option selected><?= e($templateDetails['name']) ?></option>
                    </select>
                </div>
                <div>
                    <label for="template-secret-display"><?= e(__('ui.home.secret_value')) ?></label>
                    <?php if ($templateDetails['type'] === 'ssh_key'): ?>
                        <div class="actions" style="margin-bottom:.5rem;">
                            <button type="button" class="secondary" id="template-secret-upload-button"><?= e(__('ui.home.upload_private_key')) ?></button>
                            <input type="file" id="template-secret-file" class="hidden" accept=".pem,.key,.txt,.ppk,.openssh,*/*">
                        </div>
                    <?php endif; ?>
                    <div class="grid field-actions-2" style="gap:.75rem; align-items:start;">
                        <textarea id="template-secret-display" class="mono" name="value" rows="8"><?= e($displayValue) ?></textarea>
                        <div class="grid" style="gap:.5rem; align-content:start;">
                            <button type="button" class="secondary" id="template-secret-regenerate-button"><?= e(__('ui.home.regenerate')) ?></button>
                            <div class="wizard-meta" id="template-secret-status"></div>
                        </div>
                    </div>
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
                    <input id="secret-rotation-integration" value="<?= e($selectedIntegration?->name ?? __('ui.app.none')) ?>" readonly>
                    <input type="hidden" name="rotation_integration_uuid" value="<?= e((string) ($selectedIntegration?->uuid ?? '')) ?>">
                </div>
                <div>
                    <label for="secret-rotation-schedule"><?= e(__('ui.secret.rotation_schedule')) ?></label>
                    <input id="secret-rotation-schedule" class="mono" name="rotation_schedule" value="<?= e((string) ($secret->rotationSchedule ?? '')) ?>" placeholder="0 3 * * *">
                </div>
                <?php if ($dynamicRotationService !== null && $dynamicRotationService->secretFields() !== []): ?>
                    <div>
                        <label><?= e(__('ui.secret.rotation_integration')) ?></label>
                        <div class="grid panel panel-muted" style="padding:1rem; gap:1rem;">
                            <?php foreach ($dynamicRotationService->secretFields() as $field): ?>
                                <?php $renderReadonlyRotationField($field, $dynamicRotationInputs); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="actions-end">
                    <button type="button" class="secondary" data-close-modal="rotation-secret-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="submit"><?= e(__('ui.secret.save_changes')) ?></button>
                </div>
            </form>
        </div>
    </dialog>
<?php endif; ?>

<dialog id="delete-secret-modal" class="modal">
    <div class="modal-body">
        <div>
            <h3 style="margin:0 0 .35rem;"><?= e(__('ui.secret.delete_secret')) ?></h3>
            <div class="wizard-meta"><?= e(__('ui.secret.delete_secret_confirm')) ?></div>
        </div>
        <form method="POST" action="<?= e($deleteAction) ?>" class="actions-end">
            <button type="button" class="secondary" data-close-modal="delete-secret-modal"><?= e(__('ui.organization.cancel')) ?></button>
            <button type="submit" class="danger"><?= e(__('ui.secret.delete_secret')) ?></button>
        </form>
    </div>
</dialog>

    </div>
</div>

<script>
(() => {
    const openButtons = document.querySelectorAll('[data-open-modal]');
    const closeButtons = document.querySelectorAll('[data-close-modal]');
    const copyButtons = document.querySelectorAll('[data-copy-target]');
    const valueMask = document.getElementById('secret-value-mask');
    const valueDisplay = document.getElementById('secret-value-display');
    const valueActions = document.getElementById('secret-value-actions');
    const valueHide = document.getElementById('secret-value-hide');

    const copyText = async (text) => {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(text);
            return;
        }

        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'absolute';
        helper.style.left = '-9999px';
        document.body.appendChild(helper);
        helper.select();
        document.execCommand('copy');
        document.body.removeChild(helper);
    };

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

    copyButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const targetId = button.getAttribute('data-copy-target');
            const target = targetId !== null ? document.getElementById(targetId) : null;
            if (target === null) {
                return;
            }

            try {
                await copyText(target.value || target.textContent || '');
                const original = button.textContent;
                button.textContent = <?= json_encode((string) __('ui.secret.copied')) ?>;
                window.setTimeout(() => {
                    button.textContent = original;
                }, 1200);
            } catch (_error) {
                button.textContent = <?= json_encode((string) __('ui.secret.copy_failed')) ?>;
                window.setTimeout(() => {
                    button.textContent = <?= json_encode((string) __('ui.secret.copy_value')) ?>;
                }, 1200);
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
    const templateFileInput = document.getElementById('template-secret-file');
    const templateUploadButton = document.getElementById('template-secret-upload-button');
    const extraFields = document.getElementById('template-secret-extra-fields');
    const params = document.getElementById('template-secret-params');
    const overridesInput = document.getElementById('template-secret-overrides');
    const regenerateButton = document.getElementById('template-secret-regenerate-button');
    const replaceModal = document.getElementById('replace-secret-modal');
    const saveButton = templateForm.querySelector('button[type="submit"]');
    const initialSchema = <?= json_encode($templateParameterSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialExtraFields = <?= json_encode($templateExtraFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialOverrides = <?= json_encode($templateOverrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialValue = <?= json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialDisplayValue = <?= json_encode($displayValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let previewRequestId = 0;
    let previewTimer = null;
    let templateValueMode = 'generated';
    let templateValueValid = true;

    const updateSaveState = () => {
        if (saveButton !== null) {
            saveButton.disabled = !templateValueValid;
        }
    };

    const schedulePreviewRequest = (providedValue = null, replaceDisplay = true, normalizeValue = false) => {
        if (previewTimer !== null) {
            window.clearTimeout(previewTimer);
        }

        previewTimer = window.setTimeout(() => {
            previewTimer = null;
            void requestPreview(false, providedValue, replaceDisplay, normalizeValue);
        }, 220);
    };

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

    const requestPreview = async (isManual, providedValue = null, replaceDisplay = true, normalizeValue = false) => {
        if (previewTimer !== null) {
            window.clearTimeout(previewTimer);
            previewTimer = null;
        }

        previewRequestId += 1;
        const requestId = previewRequestId;
        templateValueValid = false;
        updateSaveState();
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
                    value: providedValue,
                    normalize_value: normalizeValue,
                }),
            });
            const payload = await response.json();

            if (requestId !== previewRequestId) {
                return;
            }

            if (!response.ok || !payload.success) {
                throw new Error(payload.error || <?= json_encode((string) __('ui.home.template_preview_error')) ?>);
            }

            applyPreview(payload.data, isManual, replaceDisplay || providedValue === null);
        } catch (error) {
            if (requestId === previewRequestId) {
                templateValueValid = false;
                extraFields.innerHTML = '';
                extraFields.classList.add('hidden');
                status.textContent = error instanceof Error ? error.message : <?= json_encode((string) __('ui.home.template_preview_error')) ?>;
                updateSaveState();
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

        const layout = document.createElement('div');
        layout.className = 'template-params-layout';
        params.appendChild(layout);

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
            const inputId = `replace-template-${field.name}`;
            const value = Object.prototype.hasOwnProperty.call(values, field.name)
                ? values[field.name]
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
                    schedulePreviewRequest(
                        templateValueMode === 'manual' ? display.value : null,
                        templateValueMode !== 'manual',
                        false,
                    );
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
                const inputId = `replace-template-${field.name}`;
                const value = Object.prototype.hasOwnProperty.call(values, field.name)
                    ? values[field.name]
                    : field.value;
                const label = document.createElement('label');
                label.className = 'template-param-check';
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
                checksColumn.appendChild(wrapper);
            });

            if (specialCharsField !== null) {
                const specialColumn = document.createElement('div');
                columns.appendChild(specialColumn);
                appendTextField(specialColumn, specialCharsField);
            }
        }

        params.querySelectorAll('[data-template-param]').forEach((input) => {
            input.addEventListener(input.type === 'checkbox' ? 'change' : 'input', () => {
                schedulePreviewRequest(
                    templateValueMode === 'manual' ? display.value : null,
                    templateValueMode !== 'manual',
                    false,
                );
            });
        });
    };

    const applyPreview = (data, isManual, replaceDisplay = true) => {
        overridesInput.value = JSON.stringify(data.template_overrides);
        if (replaceDisplay) {
            display.value = data.display_value;
        }
        renderSchema(data.parameter_schema || [], data.template_overrides || {});
        renderExtraFields(data.extra_fields || []);
        templateValueValid = true;
        status.textContent = isManual ? <?= json_encode((string) __('ui.home.regenerated')) ?> : '';
        updateSaveState();
    };

    regenerateButton.addEventListener('click', () => {
        templateValueMode = 'generated';
        void requestPreview(true, null, true, false);
    });

    templateForm.addEventListener('submit', (event) => {
        if (!templateValueValid || display.value.trim() === '') {
            event.preventDefault();
            void requestPreview(true, display.value, false, false);
        }
    });

    display.addEventListener('input', () => {
        templateValueMode = 'manual';
        schedulePreviewRequest(display.value, false, false);
    });

    if (templateUploadButton !== null && templateFileInput !== null) {
        templateUploadButton.addEventListener('click', () => {
            templateFileInput.click();
        });

        templateFileInput.addEventListener('change', async () => {
            const file = templateFileInput.files && templateFileInput.files[0] ? templateFileInput.files[0] : null;
            if (!file) {
                return;
            }

            const text = await file.text();
            display.value = text;
            templateValueMode = 'manual';
            await requestPreview(false, text, true, true);
            templateFileInput.value = '';
        });
    }

    if (replaceModal) {
        replaceModal.addEventListener('close', () => {
            if (previewTimer !== null) {
                window.clearTimeout(previewTimer);
                previewTimer = null;
            }
            templateValueMode = 'generated';
            templateValueValid = true;
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
