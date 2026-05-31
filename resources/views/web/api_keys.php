<?php if (empty($organizationSettingsPartial)) { require base_path('resources/views/partials/auth_topbar.php'); } ?>
<?php
$activeKeys = [];
$revokedKeys = [];
foreach ($keys as $key) {
    if ($key->isActive) {
        $activeKeys[] = $key;
    } else {
        $revokedKeys[] = $key;
    }
}
?>

<div class="js-organization-settings-page" data-page-title="<?= e((string) ($title ?? 'Passway')) ?>">
<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.api_keys')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($organization->name) ?></div>
</section>

<style>
    @media (max-width: 900px) {
        .api-keys-left-column {
            display: contents;
        }

        .api-keys-create-panel {
            order: 1;
        }

        .api-keys-active-panel {
            order: 2;
        }

        .api-keys-revoked-panel {
            order: 3;
        }
    }
</style>

<?php if (!empty($createdRawKey)): ?>
    <div class="success" style="margin-bottom:1rem;">
        <div style="font-weight:700; margin-bottom:.4rem;"><?= e(__('ui.api_keys.copy_now')) ?></div>
        <input class="mono" value="<?= e((string) $createdRawKey) ?>" readonly>
    </div>
<?php endif; ?>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/organization_settings_sidebar.php'); ?>
    <div class="grid grid-2" style="align-items:start; gap:1rem;">
        <div class="grid api-keys-left-column" style="gap:1rem;">
            <section class="panel api-keys-create-panel" style="padding:1.5rem;">
                <h2 style="margin:0 0 1rem;"><?= e(__('ui.api_keys.create')) ?></h2>
                <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys" class="grid js-api-key-create-form" style="gap:.75rem;" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
                    <div>
                        <label for="key-name"><?= e(__('ui.api_keys.name')) ?></label>
                        <input id="key-name" name="name" placeholder="<?= e(__('ui.api_keys.name_placeholder')) ?>" required>
                    </div>
                    <div>
                        <label for="key-role"><?= e(__('ui.api_keys.role')) ?></label>
                        <select id="key-role" name="role">
                            <option value="reader"><?= e(__('ui.api_keys.role_reader')) ?></option>
                            <option value="editor"><?= e(__('ui.api_keys.role_editor')) ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="key-expires"><?= e(__('ui.api_keys.expires_at_optional')) ?></label>
                        <input id="key-expires" name="expires_at_local" type="datetime-local" step="1">
                        <input type="hidden" name="expires_at" class="js-api-key-expires-utc" disabled>
                    </div>
                    <button type="submit"><?= e(__('ui.api_keys.create')) ?></button>
                </form>
            </section>

            <section class="panel api-keys-revoked-panel" style="padding:1.5rem;">
                <h2 style="margin:0 0 1rem;"><?= e(__('ui.api_keys.revoked')) ?></h2>
                <div class="grid" style="gap:.75rem;">
                    <?php foreach ($revokedKeys as $key): ?>
                        <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                            <div>
                                <div style="font-weight:700;"><?= e($key->name) ?></div>
                                <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_keys.prefix', ['prefix' => $key->keyPrefix])) ?> · <?= e(__('ui.api_keys.status_revoked')) ?></div>
                                <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_keys.role_label', ['role' => __('ui.api_keys.role_' . $key->role)])) ?></div>
                                <div class="muted" style="font-size:.92rem;"><?= __('ui.api_keys.created', ['created_at' => local_datetime($key->createdAt)]) ?><?= $key->expiresAt ? __('ui.api_keys.expires_suffix', ['date' => local_datetime($key->expiresAt)]) : '' ?></div>
                                <div class="muted" style="font-size:.92rem;"><?= __('ui.api_keys.last_used', ['date' => $key->lastUsedAt !== null ? local_datetime($key->lastUsedAt) : e(__('ui.app.never'))]) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($revokedKeys === []): ?><div class="muted"><?= e(__('ui.api_keys.no_revoked_keys')) ?></div><?php endif; ?>
                </div>
            </section>
        </div>

        <section class="panel api-keys-active-panel" style="padding:1.5rem;">
            <h2 style="margin:0 0 1rem;"><?= e(__('ui.api_keys.active')) ?></h2>
            <div class="grid" style="gap:.75rem;">
                <?php foreach ($activeKeys as $key): ?>
                    <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                        <div>
                            <div style="font-weight:700;"><?= e($key->name) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_keys.prefix', ['prefix' => $key->keyPrefix])) ?> · <?= e($key->isActive ? __('ui.api_keys.status_active') : __('ui.api_keys.status_revoked')) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= e(__('ui.api_keys.role_label', ['role' => __('ui.api_keys.role_' . $key->role)])) ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= __('ui.api_keys.created', ['created_at' => local_datetime($key->createdAt)]) ?><?= $key->expiresAt ? __('ui.api_keys.expires_suffix', ['date' => local_datetime($key->expiresAt)]) : '' ?></div>
                            <div class="muted" style="font-size:.92rem;"><?= __('ui.api_keys.last_used', ['date' => $key->lastUsedAt !== null ? local_datetime($key->lastUsedAt) : e(__('ui.app.never'))]) ?></div>
                        </div>
                        <div class="actions">
                            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($key->uuid) ?>/role" class="actions" style="gap:.5rem;" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
                                <select name="role">
                                    <option value="reader"<?= $key->role === 'reader' ? ' selected' : '' ?>><?= e(__('ui.api_keys.role_reader')) ?></option>
                                    <option value="editor"<?= $key->role === 'editor' ? ' selected' : '' ?>><?= e(__('ui.api_keys.role_editor')) ?></option>
                                </select>
                                <button type="submit" class="secondary"><?= e(__('ui.api_keys.update_role')) ?></button>
                            </form>
                            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/api-keys/<?= e($key->uuid) ?>/revoke" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
                                <button type="submit" class="danger"><?= e(__('ui.api_keys.revoke_key')) ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($activeKeys === []): ?><div class="muted"><?= e(__('ui.api_keys.no_active_keys')) ?></div><?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
(() => {
    const pad = (value) => String(value).padStart(2, '0');
    const toUtcSqlDateTime = (value) => {
        if (!value || !value.includes('T')) {
            return value;
        }

        const localDate = new Date(value);
        if (Number.isNaN(localDate.getTime())) {
            return value;
        }

        return `${localDate.getUTCFullYear()}-${pad(localDate.getUTCMonth() + 1)}-${pad(localDate.getUTCDate())} ${pad(localDate.getUTCHours())}:${pad(localDate.getUTCMinutes())}:${pad(localDate.getUTCSeconds())}`;
    };

    document.querySelectorAll('.js-api-key-create-form').forEach((form) => {
        form.addEventListener('submit', () => {
            const expiresInput = form.querySelector('input[name="expires_at_local"]');
            const utcInput = form.querySelector('.js-api-key-expires-utc');
            if (expiresInput instanceof HTMLInputElement && utcInput instanceof HTMLInputElement) {
                utcInput.value = toUtcSqlDateTime(expiresInput.value || '');
                utcInput.disabled = false;
            }
        }, { capture: true });
    });
})();
</script>
</div>
