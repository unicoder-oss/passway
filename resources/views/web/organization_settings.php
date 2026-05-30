<?php if (empty($organizationSettingsPartial)) { require base_path('resources/views/partials/auth_topbar.php'); } ?>

<div class="js-organization-settings-page" data-page-title="<?= e((string) ($title ?? 'Passway')) ?>">
<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.settings_basic')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($organization->name) ?></div>
</section>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/organization_settings_sidebar.php'); ?>

    <section class="panel" style="padding:1.5rem; display:grid; gap:.75rem;">
        <h2 style="margin:0;"><?= e(__('ui.organization_manage.settings')) ?></h2>
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/manage" class="grid js-avatar-editor" style="gap:.75rem;" data-current-avatar-src="<?= e((string) ($organization->avatarPath ?? '')) ?>" data-organization-settings-form="true" onsubmit="return window.passwayOrganizationSettingsSubmit ? window.passwayOrganizationSettingsSubmit(event, this) : true;">
            <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <?php if (!empty($organization->avatarPath)): ?>
                    <img class="avatar-square avatar-image" src="<?= e($organization->avatarPath) ?>" alt="<?= e($organization->name) ?>" width="64" height="64" decoding="async" style="width:64px; height:64px; flex:0 0 64px;">
                <?php else: ?>
                    <div class="avatar-square" style="width:64px; height:64px; flex:0 0 64px; background:<?= e(avatar_fallback_color()) ?>; font-size:1.4rem;"><?= e(avatar_initial($organization->name)) ?></div>
                <?php endif; ?>
                <div class="muted" style="font-size:.92rem;"><?= e(__('ui.invite.organization_avatar_hint')) ?></div>
            </div>
            <div>
                <label for="org-name"><?= e(__('ui.organization_manage.name')) ?></label>
                <input id="org-name" name="name" value="<?= e($organization->name) ?>" required maxlength="255">
            </div>
            <div>
                <label for="org-description"><?= e(__('ui.organization_manage.description')) ?></label>
                <textarea id="org-description" name="description" rows="4" placeholder="<?= e(__('ui.organization_manage.description_placeholder')) ?>"><?= e((string) ($organization->description ?? '')) ?></textarea>
            </div>
            <div>
                <label for="org-avatar-file"><?= e(__('ui.invite.organization_avatar')) ?></label>
                <input id="org-avatar-file" class="js-avatar-file" type="file" accept="image/png,image/jpeg,image/webp">
            </div>
            <?php if (!empty($organization->avatarPath)): ?>
                <div>
                    <button type="button" class="secondary js-avatar-clear"><?= e(__('ui.organization_manage.clear_avatar')) ?></button>
                </div>
            <?php endif; ?>
            <div class="preview-wrap" style="width:256px;"><canvas class="js-avatar-canvas" width="256" height="256"></canvas></div>
            <div>
                <label for="org-avatar-zoom"><?= e(__('ui.invite.organization_avatar_choose')) ?></label>
                <input id="org-avatar-zoom" class="range js-avatar-zoom" type="range" min="1" max="4" step="0.01" value="1">
            </div>
            <input class="js-avatar-data" type="hidden" name="avatar_data">
            <input class="js-remove-avatar" type="hidden" name="remove_avatar" value="0">
            <button type="submit"<?= empty($canManageSettings) ? ' disabled' : '' ?>><?= e(__('ui.app.save')) ?></button>
        </form>

        <?php if (!empty($canDeleteOrganization)): ?>
            <section class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem; border-color:rgba(220, 53, 69, .45);">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e(__('ui.organization_manage.delete_organization')) ?></h3>
                    <div class="muted"><?= e(__('ui.organization_manage.delete_organization_hint')) ?></div>
                </div>
                <div>
                    <button type="button" class="danger js-open-delete-organization-modal"><?= e(__('ui.organization_manage.delete_organization')) ?></button>
                </div>
            </section>
        <?php endif; ?>
    </section>
</div>

<?php if (!empty($canDeleteOrganization)): ?>
    <?php $deleteStats = $organizationDeletionStats ?? ['directories' => 0, 'secrets' => 0, 'api_keys_total' => 0, 'api_keys_active' => 0]; ?>
    <dialog id="delete-organization-modal" class="modal">
        <div class="modal-body">
            <div>
                <h3 style="margin:0 0 .35rem;"><?= e(__('ui.organization_manage.delete_organization')) ?></h3>
                <div class="wizard-meta"><?= e(__('ui.organization_manage.delete_organization_confirm', ['organization' => $organization->name])) ?></div>
                <div class="wizard-meta"><?= e(__('ui.organization_manage.delete_organization_summary', ['directories' => (string) $deleteStats['directories'], 'secrets' => (string) $deleteStats['secrets'], 'api_keys_total' => (string) $deleteStats['api_keys_total'], 'api_keys_active' => (string) $deleteStats['api_keys_active']])) ?></div>
            </div>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/manage/delete" class="grid" style="gap:1rem;">
                <div class="actions-end">
                    <button type="button" class="secondary js-close-delete-organization-modal"><?= e(__('ui.organization.cancel')) ?></button>
                    <button type="submit" class="danger"><?= e(__('ui.organization_manage.delete_organization')) ?></button>
                </div>
            </form>
        </div>
    </dialog>
<?php endif; ?>

<script>
(() => {
    const editors = document.querySelectorAll('.js-avatar-editor');

    for (const editor of editors) {
        const fileInput = editor.querySelector('.js-avatar-file');
        const zoomInput = editor.querySelector('.js-avatar-zoom');
        const canvas = editor.querySelector('.js-avatar-canvas');
        const hidden = editor.querySelector('.js-avatar-data');
        const removeAvatar = editor.querySelector('.js-remove-avatar');
        const clearButton = editor.querySelector('.js-avatar-clear');
        const context = canvas?.getContext('2d');
        const size = 256;
        const state = { image: null, scale: 1, baseScale: 1, offsetX: 0, offsetY: 0, dragging: false, lastX: 0, lastY: 0, mode: 'empty' };

        if (fileInput && zoomInput && canvas && hidden && context) {
            const setEditingEnabled = (enabled) => {
                zoomInput.disabled = !enabled;
                canvas.style.cursor = enabled ? 'grab' : 'default';
            };

            const render = () => {
                context.clearRect(0, 0, size, size);
                context.fillStyle = '#d8d8d8';
                context.fillRect(0, 0, size, size);

                if (!state.image) {
                    hidden.value = '';
                    return;
                }

                const drawWidth = state.image.width * state.baseScale * state.scale;
                const drawHeight = state.image.height * state.baseScale * state.scale;
                context.drawImage(state.image, state.offsetX, state.offsetY, drawWidth, drawHeight);

                if (state.mode === 'new') {
                    let dataUrl = '';
                    try {
                        dataUrl = canvas.toDataURL('image/webp', 0.92);
                        if (!dataUrl.startsWith('data:image/webp')) {
                            dataUrl = canvas.toDataURL('image/png');
                        }
                    } catch (error) {
                        dataUrl = canvas.toDataURL('image/png');
                    }
                    hidden.value = dataUrl;
                } else {
                    hidden.value = '';
                }
            };

            const clampOffsets = () => {
                if (!state.image) {
                    return;
                }
                const drawWidth = state.image.width * state.baseScale * state.scale;
                const drawHeight = state.image.height * state.baseScale * state.scale;
                const minX = Math.min(0, size - drawWidth);
                const minY = Math.min(0, size - drawHeight);
                state.offsetX = Math.max(minX, Math.min(0, state.offsetX));
                state.offsetY = Math.max(minY, Math.min(0, state.offsetY));
            };

            fileInput.addEventListener('change', () => {
                const file = fileInput.files && fileInput.files[0];
                if (!file) {
                    state.image = null;
                    state.mode = 'empty';
                    setEditingEnabled(false);
                    render();
                    return;
                }

                const reader = new FileReader();
                reader.onload = () => {
                    const image = new Image();
                    image.onload = () => {
                        state.image = image;
                        state.mode = 'new';
                        if (removeAvatar) {
                            removeAvatar.value = '0';
                        }
                        setEditingEnabled(true);
                        state.baseScale = Math.max(size / image.width, size / image.height);
                        state.scale = 1;
                        zoomInput.value = '1';
                        const drawWidth = image.width * state.baseScale;
                        const drawHeight = image.height * state.baseScale;
                        state.offsetX = (size - drawWidth) / 2;
                        state.offsetY = (size - drawHeight) / 2;
                        clampOffsets();
                        render();
                    };
                    image.src = String(reader.result || '');
                };
                reader.readAsDataURL(file);
            });

            zoomInput.addEventListener('input', () => {
                if (!state.image || state.mode !== 'new') {
                    return;
                }
                const previousScale = state.scale;
                state.scale = Number(zoomInput.value || '1');
                const prevWidth = state.image.width * state.baseScale * previousScale;
                const prevHeight = state.image.height * state.baseScale * previousScale;
                const nextWidth = state.image.width * state.baseScale * state.scale;
                const nextHeight = state.image.height * state.baseScale * state.scale;
                state.offsetX -= (nextWidth - prevWidth) / 2;
                state.offsetY -= (nextHeight - prevHeight) / 2;
                clampOffsets();
                render();
            });

            canvas.addEventListener('pointerdown', (event) => {
                if (!state.image || state.mode !== 'new') {
                    return;
                }
                state.dragging = true;
                state.lastX = event.clientX;
                state.lastY = event.clientY;
                canvas.setPointerCapture(event.pointerId);
            });

            canvas.addEventListener('pointermove', (event) => {
                if (!state.dragging || !state.image || state.mode !== 'new') {
                    return;
                }
                state.offsetX += event.clientX - state.lastX;
                state.offsetY += event.clientY - state.lastY;
                state.lastX = event.clientX;
                state.lastY = event.clientY;
                clampOffsets();
                render();
            });

            const stopDragging = (event) => {
                if (!state.dragging) {
                    return;
                }
                state.dragging = false;
                canvas.releasePointerCapture(event.pointerId);
            };

            canvas.addEventListener('pointerup', stopDragging);
            canvas.addEventListener('pointercancel', stopDragging);

            if (clearButton && removeAvatar) {
                clearButton.addEventListener('click', () => {
                    state.image = null;
                    state.mode = 'empty';
                    state.scale = 1;
                    zoomInput.value = '1';
                    hidden.value = '';
                    removeAvatar.value = '1';
                    fileInput.value = '';
                    setEditingEnabled(false);
                    render();
                });
            }

            const currentAvatarSrc = editor.getAttribute('data-current-avatar-src') || '';
            if (currentAvatarSrc !== '') {
                const image = new Image();
                image.onload = () => {
                    state.image = image;
                    state.mode = 'current';
                    state.baseScale = Math.max(size / image.width, size / image.height);
                    state.scale = 1;
                    zoomInput.value = '1';
                    const drawWidth = image.width * state.baseScale;
                    const drawHeight = image.height * state.baseScale;
                    state.offsetX = (size - drawWidth) / 2;
                    state.offsetY = (size - drawHeight) / 2;
                    setEditingEnabled(false);
                    render();
                };
                image.src = currentAvatarSrc;
            } else {
                setEditingEnabled(false);
                render();
            }
        }
    }

    const deleteModal = document.getElementById('delete-organization-modal');
    document.querySelectorAll('.js-open-delete-organization-modal').forEach((button) => {
        button.addEventListener('click', () => {
            if (deleteModal && typeof deleteModal.showModal === 'function') {
                deleteModal.showModal();
            }
        });
    });
    document.querySelectorAll('.js-close-delete-organization-modal').forEach((button) => {
        button.addEventListener('click', () => {
            if (deleteModal) {
                deleteModal.close();
            }
        });
    });
})();
</script>
</div>
