<?php
$renderRotationField = static function (array $field, string $namePrefix, bool $required = true): void {
    $fieldName = isset($field['name']) && is_string($field['name']) ? trim($field['name']) : '';
    if ($fieldName === '') {
        return;
    }

    $label = isset($field['label']) && is_string($field['label']) && trim($field['label']) !== '' ? $field['label'] : $fieldName;
    $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'string';
    $placeholder = isset($field['placeholder']) && is_string($field['placeholder']) ? $field['placeholder'] : '';
    $helpText = isset($field['help_text']) && is_string($field['help_text']) ? $field['help_text'] : '';
    $inputName = $namePrefix . '[' . $fieldName . ']';
    $inputId = $namePrefix . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', $fieldName);
    $isRequired = $required && (($field['required'] ?? false) === true);
    ?>
    <div>
        <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
        <?php if ($type === 'enum'): ?>
            <select id="<?= e($inputId) ?>" name="<?= e($inputName) ?>"<?= $isRequired ? ' required' : '' ?>>
                <?php foreach (($field['options'] ?? []) as $option): ?>
                    <?php
                    $optionValue = is_array($option) ? (string) ($option['value'] ?? '') : (string) $option;
                    $optionLabel = is_array($option) ? (string) ($option['label'] ?? $optionValue) : (string) $option;
                    ?>
                    <option value="<?= e($optionValue) ?>"><?= e($optionLabel) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($type === 'boolean'): ?>
            <label class="inline-check">
                <input type="hidden" name="<?= e($inputName) ?>" value="false">
                <input id="<?= e($inputId) ?>" type="checkbox" name="<?= e($inputName) ?>" value="true">
                <span><?= e($label) ?></span>
            </label>
        <?php elseif (in_array($type, ['secret_text', 'readonly_text', 'textarea'], true)): ?>
            <textarea id="<?= e($inputId) ?>" class="mono" name="<?= e($inputName) ?>" rows="5" placeholder="<?= e($placeholder) ?>"<?= $isRequired ? ' required' : '' ?>></textarea>
        <?php else: ?>
            <input id="<?= e($inputId) ?>" class="<?= $type === 'integer' ? 'mono' : '' ?>" name="<?= e($inputName) ?>" type="<?= e($type === 'integer' ? 'number' : 'text') ?>" placeholder="<?= e($placeholder) ?>"<?= $isRequired ? ' required' : '' ?>>
        <?php endif; ?>
        <?php if ($helpText !== ''): ?><div class="muted" style="font-size:.92rem;"><?= e($helpText) ?></div><?php endif; ?>
    </div>
    <?php
};
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.integrations')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($organization->name) ?></div>
</section>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/organization_settings_sidebar.php'); ?>
    <div class="grid grid-2" style="align-items:start; gap:1rem;">
        <section class="panel" style="padding:1.5rem;">
            <h2 style="margin:0 0 1rem;"><?= e(__('ui.integrations.create')) ?></h2>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/integrations" class="grid" style="gap:.75rem;">
                <div>
                    <label for="integration-name"><?= e(__('ui.integrations.integration_name')) ?></label>
                    <input id="integration-name" name="name" placeholder="<?= e(__('ui.integrations.integration_name_placeholder')) ?>" required>
                </div>
                <div>
                    <label for="integration-service"><?= e(__('ui.integrations.rotation_service')) ?></label>
                    <select id="integration-service" name="rotation_service_uuid">
                        <?php foreach ($services as $service): ?>
                            <?php if ($service->isActive && $service->isVerified): ?>
                                <option value="<?= e($service->uuid) ?>"><?= e($service->name) ?> (<?= e($service->url) ?>)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="integration-credential-configs" class="grid" style="gap:1rem;">
                    <?php foreach ($services as $service): ?>
                        <?php if ($service->isActive && $service->isVerified): ?>
                            <div class="hidden" data-credential-config="<?= e($service->uuid) ?>">
                                <?php if ($service->integrationFields() !== []): ?>
                                    <div class="grid" style="gap:1rem;">
                                        <?php foreach ($service->integrationFields() as $field): ?>
                                            <?php $renderRotationField($field, 'credentials'); ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="muted"><?= e(__('ui.integrations.no_schema_fields')) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <button type="submit"><?= e(__('ui.integrations.create_submit')) ?></button>
            </form>
        </section>

        <section class="panel" style="padding:1.5rem;">
            <h2 style="margin:0 0 1rem;"><?= e(__('ui.integrations.existing')) ?></h2>
            <div class="grid" style="gap:.9rem;">
                <?php foreach ($integrations as $integration): $service = $serviceMap[$integration->rotationServiceId] ?? null; ?>
                    <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                        <div>
                            <div style="font-weight:700;"><?= e($integration->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e($service?->name ?? __('ui.integrations.unknown_service')) ?><?= $service ? ' · ' . e($service->url) : '' ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e($integration->isActive ? __('ui.app.active') : __('ui.app.inactive')) ?> · <?= __('ui.integrations.updated', ['date' => local_datetime($integration->updatedAt)]) ?></div>
                        </div>

                        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/integrations/<?= e($integration->uuid) ?>/update" class="grid" style="gap:.75rem;">
                            <div>
                                <label><?= e(__('ui.integrations.name')) ?></label>
                                <input name="name" value="<?= e($integration->name) ?>" required>
                            </div>
                            <label class="inline-check">
                                <input type="checkbox" name="is_active" value="1" <?= $integration->isActive ? 'checked' : '' ?>>
                                <span><?= e(__('ui.integrations.active')) ?></span>
                            </label>
                            <div>
                                <label><?= e(__('ui.integrations.replace_credentials_optional')) ?></label>
                                <?php if ($service !== null && $service->integrationFields() !== []): ?>
                                    <div class="grid" style="gap:1rem;">
                                        <?php foreach ($service->integrationFields() as $field): ?>
                                            <?php $renderRotationField($field, 'credentials', false); ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="muted"><?= e(__('ui.integrations.no_schema_fields')) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="actions">
                                <button type="submit"><?= e(__('ui.integrations.save')) ?></button>
                                <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/integrations/<?= e($integration->uuid) ?>/delete"><?= e(__('ui.app.delete')) ?></button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if ($integrations === []): ?><div class="muted"><?= e(__('ui.integrations.no_integrations')) ?></div><?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
(() => {
    const serviceSelect = document.getElementById('integration-service');
    if (!serviceSelect) {
        return;
    }

    const configs = Array.from(document.querySelectorAll('[data-credential-config]'));
    const syncConfigs = () => {
        const serviceUuid = serviceSelect.value;
        configs.forEach((section) => {
            const isActive = section.getAttribute('data-credential-config') === serviceUuid;
            section.classList.toggle('hidden', !isActive);
            section.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !isActive;
            });
        });
    };

    serviceSelect.addEventListener('change', syncConfigs);
    syncConfigs();
})();
</script>
