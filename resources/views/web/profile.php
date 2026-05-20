<?php
$topbarLinks = [
    ['href' => '/auth/logout', 'label' => __('ui.app.logout')],
];
require base_path('resources/views/partials/auth_topbar.php');
?>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<section style="margin:0 0 1rem;">
    <h1 style="margin:0 0 .35rem; font-size:2rem;"><?= e(__('ui.profile.subtitle')) ?></h1>
</section>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h1 style="margin:0; font-size:1.6rem;"><?= e($user->email) ?></h1>
            <p class="muted" style="margin:.4rem 0 0;"><?= e(__('ui.profile.created_last_login', ['created_at' => $user->createdAt, 'last_login_at' => (string) ($user->lastLoginAt ?? __('ui.app.never'))])) ?></p>
        </div>

        <div class="panel panel-muted" style="padding:1rem;">
            <h3 style="margin:0 0 .75rem;"><?= e(__('ui.profile.two_factor')) ?></h3>
            <?php if ($user->totpEnabled): ?>
                <p class="muted"><?= e(__('ui.profile.totp_enabled')) ?></p>
                <form method="POST" action="/profile/totp/disable" class="grid" style="gap:.75rem;">
                    <div>
                        <label for="disable-password"><?= e(__('ui.profile.confirm_password')) ?></label>
                        <input id="disable-password" type="password" name="password" required>
                    </div>
                    <button type="submit"><?= e(__('ui.profile.disable_totp')) ?></button>
                </form>
            <?php else: ?>
                <p class="muted"><?= e(__('ui.profile.totp_disabled')) ?></p>
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
                                    <img
                                        src="<?= e((string) $totpSetup['qr_code_image']) ?>"
                                        alt="<?= e(__('ui.profile.qr_code_alt')) ?>"
                                        width="220"
                                        height="220"
                                        style="display:block; width:100%; max-width:220px; height:auto;"
                                    >
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
        </div>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;"><?= e(__('ui.profile.passkeys')) ?></h2>
        <div class="panel panel-muted" style="padding:1rem; margin-bottom:1rem; display:grid; gap:.75rem;">
            <div>
                <h3 style="margin:0 0 .35rem;"><?= e(__('ui.profile.register_new_passkey')) ?></h3>
                <div class="muted" style="font-size:.92rem;"><?= e(__('ui.profile.register_passkey_hint')) ?></div>
            </div>
            <div class="grid field-actions-2" style="gap:.75rem;">
                <div>
                    <label for="passkey-register-name"><?= e(__('ui.profile.passkey_name')) ?></label>
                    <input id="passkey-register-name" maxlength="255" placeholder="<?= e(__('ui.profile.passkey_name_placeholder')) ?>" value="<?= e((string) ($user->email . ' passkey')) ?>">
                </div>
                <button type="button" id="passkey-register-button"><?= e(__('ui.profile.register_passkey')) ?></button>
            </div>
            <div id="passkey-register-status" class="muted" style="font-size:.92rem;"><?= e(__('ui.profile.registration_hint')) ?></div>
        </div>
        <div class="grid" style="gap:.75rem;">
            <?php foreach ($passkeys as $passkey): ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($passkey->name) ?></div>
                        <div class="muted" style="font-size:.92rem;"><?= e(__('ui.profile.created_last_used', ['created_at' => $passkey->createdAt, 'last_used_at' => (string) ($passkey->lastUsedAt ?? __('ui.app.never'))])) ?></div>
                    </div>
                    <form method="POST" action="/profile/passkeys/<?= e($passkey->uuid) ?>/delete">
                        <button type="submit" class="danger"><?= e(__('ui.profile.remove_passkey')) ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($passkeys === []): ?><div class="muted"><?= e(__('ui.profile.no_passkeys')) ?></div><?php endif; ?>
        </div>
    </section>
</div>

<script>
(() => {
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
        user: {
            ...options.user,
            id: fromBase64Url(options.user.id),
        },
        excludeCredentials: (options.excludeCredentials || []).map((credential) => ({
            ...credential,
            id: fromBase64Url(credential.id),
        })),
    });

    const serializeCredential = (credential) => {
        const response = credential.response;
        return {
            id: credential.id,
            type: credential.type,
            rawId: toBase64Url(credential.rawId),
            response: {
                clientDataJSON: toBase64Url(response.clientDataJSON),
                attestationObject: toBase64Url(response.attestationObject),
            },
            clientExtensionResults: credential.getClientExtensionResults(),
        };
    };

    const setStatus = (message, type = 'muted') => {
        status.className = type;
        status.textContent = message;
    };

    button.addEventListener('click', async () => {
        button.disabled = true;
        setStatus(<?= json_encode(__('ui.profile.preparing_registration')) ?>, 'muted');

        try {
            const startResponse = await fetch('/auth/passkey/register/start', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });

            const startPayload = await startResponse.json();
            if (!startResponse.ok || !startPayload?.options) {
                throw new Error(startPayload?.message || <?= json_encode(__('ui.profile.registration_init_failed')) ?>);
            }

            const credential = await navigator.credentials.create({
                publicKey: normalizeCreationOptions(startPayload.options),
                signal: AbortSignal.timeout ? AbortSignal.timeout(65000) : undefined,
            });

            if (!credential) {
                throw new Error(<?= json_encode(__('ui.profile.registration_cancelled')) ?>);
            }

            setStatus(<?= json_encode(__('ui.profile.finishing_registration')) ?>, 'muted');

            const finishResponse = await fetch('/auth/passkey/register/finish', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: input.value.trim() || <?= json_encode(__('ui.profile.default_passkey_name')) ?>,
                    credential: serializeCredential(credential),
                }),
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
