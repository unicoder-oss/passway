<?php require base_path('resources/views/partials/auth_topbar.php'); ?>

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
        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/manage" class="grid js-avatar-editor" style="gap:.75rem;">
            <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <?php if (!empty($organization->avatarPath)): ?>
                    <img class="avatar-square avatar-image" src="<?= e($organization->avatarPath) ?>" alt="<?= e($organization->name) ?>" width="64" height="64" style="width:64px; height:64px; flex:0 0 64px;">
                <?php else: ?>
                    <div class="avatar-square" style="width:64px; height:64px; flex:0 0 64px; background:<?= e(avatar_fallback_color()) ?>; font-size:1.4rem;"><?= e(avatar_initial($organization->name)) ?></div>
                <?php endif; ?>
                <div class="muted" style="font-size:.92rem;"><?= e(__('ui.invite.organization_avatar_hint')) ?></div>
            </div>
            <div>
                <label for="org-description"><?= e(__('ui.organization_manage.description')) ?></label>
                <textarea id="org-description" name="description" rows="4" placeholder="<?= e(__('ui.organization_manage.description_placeholder')) ?>"><?= e((string) ($organization->description ?? '')) ?></textarea>
            </div>
            <div>
                <label for="org-avatar-file"><?= e(__('ui.invite.organization_avatar')) ?></label>
                <input id="org-avatar-file" class="js-avatar-file" type="file" accept="image/png,image/jpeg,image/webp">
            </div>
            <div class="preview-wrap" style="width:256px;"><canvas class="js-avatar-canvas" width="256" height="256"></canvas></div>
            <div>
                <label for="org-avatar-zoom"><?= e(__('ui.invite.organization_avatar_choose')) ?></label>
                <input id="org-avatar-zoom" class="range js-avatar-zoom" type="range" min="1" max="4" step="0.01" value="1">
            </div>
            <input class="js-avatar-data" type="hidden" name="avatar_data">
            <button type="submit"<?= empty($canManageSettings) ? ' disabled' : '' ?>><?= e(__('ui.app.save')) ?></button>
        </form>
    </section>
</div>

<script>
(() => {
    const editors = document.querySelectorAll('.js-avatar-editor');

    for (const editor of editors) {
        const fileInput = editor.querySelector('.js-avatar-file');
        const zoomInput = editor.querySelector('.js-avatar-zoom');
        const canvas = editor.querySelector('.js-avatar-canvas');
        const hidden = editor.querySelector('.js-avatar-data');
        const context = canvas?.getContext('2d');
        const size = 256;
        const state = { image: null, scale: 1, baseScale: 1, offsetX: 0, offsetY: 0, dragging: false, lastX: 0, lastY: 0 };

        if (fileInput && zoomInput && canvas && hidden && context) {
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
                    render();
                    return;
                }

                const reader = new FileReader();
                reader.onload = () => {
                    const image = new Image();
                    image.onload = () => {
                        state.image = image;
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
                if (!state.image) {
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
                if (!state.image) {
                    return;
                }
                state.dragging = true;
                state.lastX = event.clientX;
                state.lastY = event.clientY;
                canvas.setPointerCapture(event.pointerId);
            });

            canvas.addEventListener('pointermove', (event) => {
                if (!state.dragging || !state.image) {
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
            render();
        }
    }
})();
</script>
