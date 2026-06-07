<?php
$isProfileSettingsPartial = !empty($profileSettingsPartial);
if (!$isProfileSettingsPartial) {
    $topbarLinks = [
        ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
    ];
    require base_path('resources/views/partials/auth_topbar.php');
}
$activeProfileSection = (string) ($activeProfileSection ?? 'basic');
$displayName = display_name_for_user($user);
?>

<style>
    .profile-basic-panel,
    .profile-basic-form,
    .profile-basic-meta,
    .profile-email-row,
    .profile-email-field,
    .profile-avatar-preview {
        min-width: 0;
    }

    .profile-basic-meta,
    .profile-email-field input {
        overflow-wrap: anywhere;
    }

    .profile-avatar-preview {
        width: min(256px, 100%);
    }

    .profile-avatar-preview canvas {
        touch-action: none;
        user-select: none;
    }

    @media (max-width: 900px) {
        .profile-basic-panel {
            padding: 1rem !important;
        }

        .profile-avatar-preview {
            width: min(320px, 100%);
        }

        .profile-email-row > button,
        .profile-basic-form > button[type="submit"] {
            width: 100%;
        }
    }
</style>

<div
    class="js-profile-settings-page"
    data-page-title="<?= e((string) ($title ?? 'Passway')) ?>"
    data-current-theme="<?= e(request_theme()) ?>"
    data-current-locale="<?= e(app_locale()) ?>"
    data-profile-display-name="<?= e(display_name_for_user($user)) ?>"
    data-profile-avatar-src="<?= e((string) ($user->avatarPath ?? '')) ?>"
    data-profile-avatar-initial="<?= e(avatar_initial(display_name_for_user($user))) ?>"
    data-profile-avatar-color="<?= e(avatar_color_for_user($user)) ?>"
>
<?php if (!empty($queryError)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0; font-size:2rem;"><?= e(__('ui.profile.subtitle')) ?></h1>
    <div class="muted" style="margin-top:.35rem;"><?= e($user->email) ?></div>
</section>

<div class="grid sidebar-layout" style="align-items:start; padding-bottom:2rem; gap:1rem;">
    <?php require base_path('resources/views/web/partials/profile_settings_sidebar.php'); ?>

    <?php if ($activeProfileSection === 'basic'): ?>
        <section class="panel profile-basic-panel" style="padding:1.5rem; display:grid; gap:1rem;">
            <div>
                <h2 style="margin:0;"><?= e(__('ui.profile.sections.basic')) ?></h2>
                <p class="muted profile-basic-meta" style="margin:.35rem 0 0;"><?= __('ui.profile.created_last_login', ['created_at' => local_datetime($user->createdAt), 'last_login_at' => $user->lastLoginAt !== null ? local_datetime($user->lastLoginAt) : e(__('ui.app.never'))]) ?></p>
            </div>

            <form method="POST" action="/profile" class="grid js-avatar-editor profile-basic-form" style="gap:.75rem;" data-profile-settings-form="true" data-current-avatar-src="<?= e((string) ($user->avatarPath ?? '')) ?>">
                <div>
                    <label for="profile-nickname"><?= e(__('ui.profile.nickname')) ?></label>
                    <input id="profile-nickname" name="nickname" value="<?= e((string) ($user->nickname ?? '')) ?>" maxlength="255" placeholder="<?= e(__('ui.profile.nickname_placeholder')) ?>">
                </div>

                <div class="grid field-actions-2 profile-email-row" style="gap:.75rem;">
                    <div class="profile-email-field">
                        <label for="profile-email-display"><?= e(__('ui.profile.email')) ?></label>
                        <input id="profile-email-display" value="<?= e($user->email) ?>" readonly>
                    </div>
                    <button type="button" class="secondary js-open-email-modal"><?= e(__('ui.profile.change_email')) ?></button>
                </div>

                <div>
                    <label for="profile-avatar-file"><?= e(__('ui.profile.avatar_settings')) ?></label>
                    <input id="profile-avatar-file" class="js-avatar-file" type="file" accept="image/png,image/jpeg,image/webp">
                    <div class="muted" style="margin-top:.5rem; font-size:.92rem;"><?= e(__('ui.profile.avatar_hint')) ?></div>
                </div>
                <div class="preview-wrap profile-avatar-preview"><canvas class="js-avatar-canvas" width="256" height="256"></canvas></div>
                <?php if (!empty($user->avatarPath)): ?>
                    <div class="actions">
                        <button type="button" class="secondary js-avatar-clear"><?= e(__('ui.profile.clear_avatar')) ?></button>
                    </div>
                <?php endif; ?>
                <div>
                    <label for="profile-avatar-zoom"><?= e(__('ui.invite.organization_avatar_choose')) ?></label>
                    <input id="profile-avatar-zoom" class="range js-avatar-zoom" type="range" min="1" max="4" step="0.01" value="1">
                </div>
                <input class="js-avatar-data" type="hidden" name="avatar_data">
                <input class="js-remove-avatar" type="hidden" name="remove_avatar" value="0">
                <button type="submit"><?= e(__('ui.app.save')) ?></button>
            </form>
        </section>

        <dialog id="profile-email-modal" class="modal">
            <div class="modal-body">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e(__('ui.profile.change_email')) ?></h3>
                    <div class="wizard-meta"><?= e(__('ui.profile.change_email_hint')) ?></div>
                </div>
                <form method="POST" action="/profile/email" class="grid" style="gap:1rem;">
                    <div>
                        <label for="profile-email"><?= e(__('ui.profile.new_email')) ?></label>
                        <input id="profile-email" type="email" name="email" value="<?= e($user->email) ?>" required>
                    </div>
                    <div>
                        <label for="profile-email-password"><?= e(__('ui.profile.confirm_password')) ?></label>
                        <input id="profile-email-password" type="password" name="password" required>
                    </div>
                    <div class="actions-end">
                        <button type="button" class="secondary js-close-email-modal"><?= e(__('ui.organization.cancel')) ?></button>
                        <button type="submit"><?= e(__('ui.profile.change_email')) ?></button>
                    </div>
                </form>
            </div>
        </dialog>
    <?php elseif ($activeProfileSection === 'security'): ?>
        <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
            <div>
                <h2 style="margin:0;"><?= e(__('ui.profile.sections.security')) ?></h2>
                <div class="muted" style="margin-top:.35rem;"><?= e(__('ui.profile.security_hint')) ?></div>
            </div>

            <section class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e($user->passwordHash === null ? __('ui.profile.set_password') : __('ui.profile.change_password')) ?></h3>
                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.profile.change_password_hint')) ?></div>
                </div>
                <div>
                    <button type="button" class="secondary js-open-password-modal"><?= e($user->passwordHash === null ? __('ui.profile.set_password') : __('ui.profile.change_password')) ?></button>
                </div>
            </section>

            <section class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                <h3 style="margin:0;"><?= e(__('ui.profile.two_factor')) ?></h3>
                <?php if ($user->totpEnabled): ?>
                    <p class="muted" style="margin:0;"><?= e(__('ui.profile.totp_enabled')) ?></p>
                    <form method="POST" action="/profile/totp/disable" class="grid" style="gap:.75rem;">
                        <div>
                            <label for="disable-password"><?= e(__('ui.profile.confirm_password')) ?></label>
                            <input id="disable-password" type="password" name="password" required>
                        </div>
                        <button type="submit"><?= e(__('ui.profile.disable_totp')) ?></button>
                    </form>
                <?php else: ?>
                    <p class="muted" style="margin:0;"><?= e(__('ui.profile.totp_disabled')) ?></p>
                    <?php if ($totpSetup === null): ?>
                        <form method="POST" action="/profile/totp/start">
                            <button type="submit"><?= e(__('ui.profile.start_totp_setup')) ?></button>
                        </form>
                    <?php else: ?>
                        <div class="grid" style="gap:.75rem;">
                            <?php if (!empty($totpSetup['qr_code_image'])): ?>
                                <div>
                                    <label><?= e(__('ui.profile.qr_code')) ?></label>
                                    <div class="panel panel-muted" style="margin-top:.35rem; padding:1rem; display:flex; justify-content:center;">
                                        <img src="<?= e((string) $totpSetup['qr_code_image']) ?>" alt="<?= e(__('ui.profile.qr_code_alt')) ?>" width="220" height="220" style="display:block; width:100%; max-width:220px; height:auto;">
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div>
                                <label><?= e(__('ui.profile.manual_entry_key')) ?></label>
                                <input class="mono" value="<?= e((string) $totpSetup['raw_secret']) ?>" readonly>
                            </div>
                            <div>
                                <label><?= e(__('ui.profile.otpauth_uri')) ?></label>
                                <a class="mono" href="<?= e((string) $totpSetup['qr_code_uri']) ?>"><?= e(__('ui.profile.authenticator_link')) ?></a>
                            </div>
                            <form method="POST" action="/profile/totp/enable" class="grid" style="gap:.75rem;">
                                <div>
                                    <label for="totp-code"><?= e(__('ui.profile.verification_code')) ?></label>
                                    <input id="totp-code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                                </div>
                                <button type="submit"><?= e(__('ui.profile.enable_totp')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="panel panel-muted" style="padding:1rem; display:grid; gap:.75rem;">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e(__('ui.profile.passkeys')) ?></h3>
                    <div class="muted" style="font-size:.92rem;"><?= e(__('ui.profile.register_passkey_hint')) ?></div>
                </div>
                <div class="grid field-actions-2" style="gap:.75rem;">
                    <div>
                        <label for="passkey-register-name"><?= e(__('ui.profile.passkey_name')) ?></label>
                        <input id="passkey-register-name" maxlength="255" placeholder="<?= e(__('ui.profile.passkey_name_placeholder')) ?>" value="<?= e((string) ($user->email . ' passkey')) ?>">
                    </div>
                    <button type="button" id="passkey-register-button"><?= e(__('ui.profile.register_passkey')) ?></button>
                </div>
                <div id="passkey-register-status" class="muted" style="font-size:.92rem;"></div>
                <div class="grid" style="gap:.75rem;">
                    <?php foreach ($passkeys as $passkey): ?>
                        <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                            <div>
                                <div style="font-weight:700;"><?= e($passkey->name) ?></div>
                                <div class="muted" style="font-size:.92rem;"><?= __('ui.profile.created_last_used', ['created_at' => local_datetime($passkey->createdAt), 'last_used_at' => $passkey->lastUsedAt !== null ? local_datetime($passkey->lastUsedAt) : e(__('ui.app.never'))]) ?></div>
                            </div>
                            <form method="POST" action="/profile/passkeys/<?= e($passkey->uuid) ?>/delete">
                                <button type="submit" class="danger"><?= e(__('ui.profile.remove_passkey')) ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($passkeys === []): ?><div class="muted"><?= e(__('ui.profile.no_passkeys')) ?></div><?php endif; ?>
                </div>
            </section>
        </section>

        <dialog id="profile-password-modal" class="modal">
            <div class="modal-body">
                <div>
                    <h3 style="margin:0 0 .35rem;"><?= e($user->passwordHash === null ? __('ui.profile.set_password') : __('ui.profile.change_password')) ?></h3>
                    <div class="wizard-meta"><?= e(__('ui.profile.change_password_hint')) ?></div>
                </div>
                <form method="POST" action="/profile/password" class="grid" style="gap:1rem;">
                    <?php if ($user->passwordHash !== null): ?>
                        <div>
                            <label for="profile-current-password"><?= e(__('ui.profile.current_password')) ?></label>
                            <input id="profile-current-password" type="password" name="current_password" required>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label for="profile-new-password"><?= e(__('ui.profile.new_password')) ?></label>
                        <input id="profile-new-password" type="password" name="password" required>
                    </div>
                    <div>
                        <label for="profile-password-confirm"><?= e(__('ui.profile.confirm_new_password')) ?></label>
                        <input id="profile-password-confirm" type="password" name="password_confirm" required>
                    </div>
                    <div class="actions-end">
                        <button type="button" class="secondary js-close-password-modal"><?= e(__('ui.organization.cancel')) ?></button>
                        <button type="submit"><?= e(__('ui.profile.save_password')) ?></button>
                    </div>
                </form>
            </div>
        </dialog>
    <?php else: ?>
        <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
            <div>
                <h2 style="margin:0;"><?= e(__('ui.profile.sections.interface')) ?></h2>
                <div class="muted" style="margin-top:.35rem;"><?= e(__('ui.profile.interface_hint')) ?></div>
            </div>
            <form method="POST" action="/profile/interface" class="grid" style="gap:.75rem;" data-profile-settings-form="true">
                <div>
                    <label for="profile-locale"><?= e(__('ui.profile.language')) ?></label>
                    <select id="profile-locale" name="locale_preference">
                        <option value="system"<?= $user->localePreference === 'system' ? ' selected' : '' ?>><?= e(__('ui.profile.system_language')) ?></option>
                        <?php foreach (supported_locales() as $locale): ?>
                            <option value="<?= e($locale) ?>"<?= $user->localePreference === $locale ? ' selected' : '' ?>><?= e(__('ui.profile.language_' . $locale)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="profile-theme"><?= e(__('ui.profile.theme')) ?></label>
                    <select id="profile-theme" name="theme_preference">
                        <?php foreach (supported_theme_preferences() as $theme): ?>
                            <option value="<?= e($theme) ?>"<?= $user->themePreference === $theme ? ' selected' : '' ?>><?= e(__('ui.profile.theme_' . $theme)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit"><?= e(__('ui.app.save')) ?></button>
            </form>
        </section>
    <?php endif; ?>
</div>

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

        if (!fileInput || !zoomInput || !canvas || !hidden || !context) {
            continue;
        }

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

            if (state.mode !== 'new') {
                hidden.value = '';
                return;
            }

            try {
                hidden.value = canvas.toDataURL('image/webp', 0.92);
                if (!hidden.value.startsWith('data:image/webp')) {
                    hidden.value = canvas.toDataURL('image/png');
                }
            } catch (error) {
                hidden.value = canvas.toDataURL('image/png');
            }
        };

        const clampOffsets = () => {
            if (!state.image) {
                return;
            }
            const drawWidth = state.image.width * state.baseScale * state.scale;
            const drawHeight = state.image.height * state.baseScale * state.scale;
            state.offsetX = Math.max(Math.min(0, size - drawWidth), Math.min(0, state.offsetX));
            state.offsetY = Math.max(Math.min(0, size - drawHeight), Math.min(0, state.offsetY));
        };

        const loadImage = (src, mode) => {
            const image = new Image();
            image.onload = () => {
                state.image = image;
                state.mode = mode;
                state.baseScale = Math.max(size / image.width, size / image.height);
                state.scale = 1;
                zoomInput.value = '1';
                state.offsetX = (size - image.width * state.baseScale) / 2;
                state.offsetY = (size - image.height * state.baseScale) / 2;
                setEditingEnabled(mode === 'new');
                clampOffsets();
                render();
            };
            image.src = src;
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
                if (removeAvatar) {
                    removeAvatar.value = '0';
                }
                loadImage(String(reader.result || ''), 'new');
            };
            reader.readAsDataURL(file);
        });

        zoomInput.addEventListener('input', () => {
            if (!state.image || state.mode !== 'new') {
                return;
            }
            const previousScale = state.scale;
            state.scale = Number(zoomInput.value || '1');
            state.offsetX -= (state.image.width * state.baseScale * state.scale - state.image.width * state.baseScale * previousScale) / 2;
            state.offsetY -= (state.image.height * state.baseScale * state.scale - state.image.height * state.baseScale * previousScale) / 2;
            clampOffsets();
            render();
        });

        canvas.addEventListener('pointerdown', (event) => {
            if (!state.image || state.mode !== 'new') {
                return;
            }
            event.preventDefault();
            state.dragging = true;
            state.lastX = event.clientX;
            state.lastY = event.clientY;
            canvas.setPointerCapture(event.pointerId);
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!state.dragging || !state.image || state.mode !== 'new') {
                return;
            }
            event.preventDefault();
            state.offsetX += event.clientX - state.lastX;
            state.offsetY += event.clientY - state.lastY;
            state.lastX = event.clientX;
            state.lastY = event.clientY;
            clampOffsets();
            render();
        });
        const stopDragging = (event) => {
            state.dragging = false;
            if (canvas.hasPointerCapture(event.pointerId)) {
                canvas.releasePointerCapture(event.pointerId);
            }
        };
        canvas.addEventListener('pointerup', stopDragging);
        canvas.addEventListener('pointercancel', stopDragging);

        clearButton?.addEventListener('click', () => {
            state.image = null;
            state.mode = 'empty';
            hidden.value = '';
            fileInput.value = '';
            if (removeAvatar) {
                removeAvatar.value = '1';
            }
            setEditingEnabled(false);
            render();
        });

        const currentAvatarSrc = editor.getAttribute('data-current-avatar-src') || '';
        if (currentAvatarSrc !== '') {
            loadImage(currentAvatarSrc, 'current');
        } else {
            setEditingEnabled(false);
            render();
        }
    }

    const button = document.getElementById('passkey-register-button');
    const input = document.getElementById('passkey-register-name');
    const status = document.getElementById('passkey-register-status');
    if (!button || !input || !status) {
        return;
    }

    if (!window.PublicKeyCredential || !navigator.credentials?.create) {
        status.textContent = <?= json_encode(__('ui.profile.browser_not_supported')) ?>;
        button.disabled = true;
        return;
    }

    const toBase64Url = (buffer) => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (const byte of bytes) {
            binary += String.fromCharCode(byte);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };
    const fromBase64Url = (value) => {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((value.length + 3) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    };
    const normalizeCreationOptions = (options) => ({
        ...options,
        challenge: fromBase64Url(options.challenge),
        user: { ...options.user, id: fromBase64Url(options.user.id) },
        excludeCredentials: (options.excludeCredentials || []).map((credential) => ({ ...credential, id: fromBase64Url(credential.id) })),
    });
    const serializeCredential = (credential) => ({
        id: credential.id,
        type: credential.type,
        rawId: toBase64Url(credential.rawId),
        response: {
            clientDataJSON: toBase64Url(credential.response.clientDataJSON),
            attestationObject: toBase64Url(credential.response.attestationObject),
        },
        clientExtensionResults: credential.getClientExtensionResults(),
    });
    const setStatus = (message, type = 'muted') => {
        status.className = type;
        status.textContent = message;
    };

    button.addEventListener('click', async () => {
        button.disabled = true;
        setStatus(<?= json_encode(__('ui.profile.preparing_registration')) ?>, 'muted');
        try {
            const startResponse = await fetch('/auth/passkey/register/start', { method: 'POST', headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            const startPayload = await startResponse.json();
            if (!startResponse.ok || !startPayload?.options) {
                throw new Error(startPayload?.message || <?= json_encode(__('ui.profile.registration_init_failed')) ?>);
            }
            const credential = await navigator.credentials.create({ publicKey: normalizeCreationOptions(startPayload.options), signal: AbortSignal.timeout ? AbortSignal.timeout(65000) : undefined });
            if (!credential) {
                throw new Error(<?= json_encode(__('ui.profile.registration_cancelled')) ?>);
            }
            setStatus(<?= json_encode(__('ui.profile.finishing_registration')) ?>, 'muted');
            const finishResponse = await fetch('/auth/passkey/register/finish', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ name: input.value.trim() || <?= json_encode(__('ui.profile.default_passkey_name')) ?>, credential: serializeCredential(credential) }),
            });
            const finishPayload = await finishResponse.json();
            if (!finishResponse.ok || finishPayload?.success !== true) {
                throw new Error(finishPayload?.message || <?= json_encode(__('ui.profile.registration_failed')) ?>);
            }
            setStatus(<?= json_encode(__('ui.profile.registration_success')) ?>, 'success');
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : <?= json_encode(__('ui.profile.registration_failed')) ?>, 'error');
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
</div>
