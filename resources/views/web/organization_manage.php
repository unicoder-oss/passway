<?php
$topbarLinks = [
    ['href' => '/organizations/' . $organization->uuid, 'label' => __('ui.app.back_to_dashboard')],
    ['href' => '/organizations/' . $organization->uuid . '/audit', 'label' => __('ui.organization_manage.audit_log')],
    ['href' => '/organizations/' . $organization->uuid . '/api-keys', 'label' => __('ui.organization_manage.api_keys')],
    ['href' => '/organizations/' . $organization->uuid . '/integrations', 'label' => __('ui.organization_manage.integrations')],
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.organization_manage.manage', ['organization' => $organization->name])) ?></h1>
</section>

<div class="grid grid-2-compact" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.organization_manage.members')) ?></h2>
        <div class="grid" style="gap:.8rem;">
            <?php foreach ($members as $member): $memberUser = \Passway\Models\User::findById($member->userId); ?>
                <div class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($memberUser?->email ?? __('ui.organization_manage.unknown_user')) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.organization_manage.joined', ['date' => $member->joinedAt])) ?></div>
                    </div>
                    <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/role" class="grid field-actions-3" style="gap:.75rem;">
                        <div>
                            <label><?= e(__('ui.organization_manage.role')) ?></label>
                            <select name="role">
                                <?php foreach (\Passway\Models\OrganizationMember::ROLES as $role): ?>
                                    <option value="<?= e($role) ?>" <?= $member->role === $role ? 'selected' : '' ?>><?= e(__('ui.organization_manage.roles.' . $role)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit"><?= e(__('ui.app.update')) ?></button>
                        <?php if (($memberUser?->uuid ?? '') !== $user->uuid): ?>
                            <button type="submit" class="danger" formaction="/organizations/<?= e($organization->uuid) ?>/members/<?= e($memberUser?->uuid ?? '') ?>/remove"><?= e(__('ui.app.remove')) ?></button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="grid" style="gap:1rem;">
        <?php if (!empty($canManageSettings)): ?>
            <div class="panel" style="padding:1rem; display:grid; gap:.75rem;">
                <h3 style="margin:0;"><?= e(__('ui.organization_manage.settings')) ?></h3>
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
                    <button type="submit"><?= e(__('ui.app.save')) ?></button>
                </form>
            </div>
        <?php endif; ?>

        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.organization_manage.create_invite')) ?></h3>
            <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites" class="grid" style="gap:.75rem;">
                <div>
                    <label for="invite-role"><?= e(__('ui.organization_manage.role')) ?></label>
                    <select id="invite-role" name="role">
                        <option value="user"><?= e(__('ui.organization_manage.roles.user')) ?></option>
                        <option value="observer"><?= e(__('ui.organization_manage.roles.observer')) ?></option>
                        <option value="moderator"><?= e(__('ui.organization_manage.roles.moderator')) ?></option>
                        <option value="admin"><?= e(__('ui.organization_manage.roles.admin')) ?></option>
                    </select>
                </div>
                <div>
                    <label for="invite-ttl"><?= e(__('ui.organization_manage.ttl_hours')) ?></label>
                    <input id="invite-ttl" type="number" name="ttl" value="1" min="1" max="168">
                </div>
                <button type="submit"><?= e(__('ui.organization_manage.create_invite_link')) ?></button>
            </form>
        </div>

        <div class="panel" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.organization_manage.active_invites')) ?></h3>
            <div class="grid" style="gap:.75rem;">
                <?php foreach ($invites as $invite): ?>
                    <?php $inviteUrl = app_url('/invite/' . $invite->token); ?>
                    <div class="panel panel-muted" style="padding:1rem;">
                        <div style="font-weight:700;"><?= e(__('ui.organization_manage.role')) ?>: <?= e($invite->role) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= __('ui.organization_manage.expires', ['date' => local_datetime($invite->expiresAt)]) ?></div>
                        <div style="margin:.5rem 0 .75rem;">
                            <label><?= e(__('ui.organization_manage.link', ['link' => $inviteUrl])) ?></label>
                            <input class="mono js-copy-on-click" value="<?= e($inviteUrl) ?>" readonly>
                        </div>
                        <form method="POST" action="/organizations/<?= e($organization->uuid) ?>/invites/<?= e($invite->uuid) ?>/revoke">
                            <button type="submit" class="danger"><?= e(__('ui.organization_manage.revoke_invite')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if ($invites === []): ?><div class="muted"><?= e(__('ui.organization_manage.no_active_invites')) ?></div><?php endif; ?>
            </div>
        </div>
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

            const stopDrag = () => {
                state.dragging = false;
            };

            canvas.addEventListener('pointerup', stopDrag);
            canvas.addEventListener('pointercancel', stopDrag);
            render();
        }
    }

    const fields = document.querySelectorAll('.js-copy-on-click');

    for (const field of fields) {
        field.addEventListener('click', async () => {
            field.focus();
            field.select();

            try {
                await navigator.clipboard.writeText(field.value);
            } catch (error) {
                document.execCommand('copy');
            }
        });
    }
})();
</script>
