<div class="topbar">
    <div>
        <div class="brand">Passway</div>
        <div class="muted" style="margin-top:.35rem;">Profile & security settings</div>
    </div>
    <div class="topnav">
        <a class="button secondary" href="/">Dashboard</a>
        <a class="button secondary" href="/auth/logout">Logout</a>
    </div>
</div>

<?php if (!empty($queryError)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $queryError) ?></div><?php endif; ?>
<?php if (!empty($querySuccess)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $querySuccess) ?></div><?php endif; ?>

<div class="grid grid-2" style="align-items:start; padding-bottom:2rem;">
    <section class="panel" style="padding:1.5rem; display:grid; gap:1rem;">
        <div>
            <h1 style="margin:0; font-size:1.6rem;"><?= e($user->email) ?></h1>
            <p class="muted" style="margin:.4rem 0 0;">Created <?= e($user->createdAt) ?> · Last login <?= e((string) ($user->lastLoginAt ?? 'never')) ?></p>
        </div>

        <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55);">
            <h3 style="margin:0 0 .75rem;">Two-Factor Authentication</h3>
            <?php if ($user->totpEnabled): ?>
                <p class="muted">TOTP is enabled for this account.</p>
                <form method="POST" action="/profile/totp/disable" class="grid" style="gap:.75rem;">
                    <div>
                        <label for="disable-password">Confirm password</label>
                        <input id="disable-password" type="password" name="password" required>
                    </div>
                    <button type="submit">Disable TOTP</button>
                </form>
            <?php else: ?>
                <p class="muted">TOTP is disabled.</p>
                <?php if ($totpSetup === null): ?>
                    <form method="POST" action="/profile/totp/start">
                        <button type="submit">Start TOTP Setup</button>
                    </form>
                <?php else: ?>
                    <div class="grid" style="gap:.75rem;">
                        <div>
                            <label>Manual entry key</label>
                            <input class="mono" value="<?= e((string) $totpSetup['raw_secret']) ?>" readonly>
                        </div>
                        <div>
                            <label>otpauth URI</label>
                            <textarea class="mono" rows="4" readonly><?= e((string) $totpSetup['qr_code_uri']) ?></textarea>
                        </div>
                        <form method="POST" action="/profile/totp/enable" class="grid" style="gap:.75rem;">
                            <div>
                                <label for="totp-code">Verification code</label>
                                <input id="totp-code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                            </div>
                            <button type="submit">Enable TOTP</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel" style="padding:1.5rem;">
        <h2 style="margin:0 0 1rem;">Passkeys</h2>
        <div class="panel panel-muted" style="padding:1rem; margin-bottom:1rem; display:grid; gap:.75rem;">
            <div>
                <h3 style="margin:0 0 .35rem;">Register New Passkey</h3>
                <div class="muted" style="font-size:.92rem;">Use a platform authenticator or security key to add a new passkey to this account.</div>
            </div>
            <div class="grid field-actions-2" style="gap:.75rem;">
                <div>
                    <label for="passkey-register-name">Passkey name</label>
                    <input id="passkey-register-name" maxlength="255" placeholder="MacBook Touch ID" value="<?= e((string) ($user->email . ' passkey')) ?>">
                </div>
                <button type="button" id="passkey-register-button">Register Passkey</button>
            </div>
            <div id="passkey-register-status" class="muted" style="font-size:.92rem;">Registration uses the existing WebAuthn endpoints.</div>
        </div>
        <div class="grid" style="gap:.75rem;">
            <?php foreach ($passkeys as $passkey): ?>
                <div class="panel" style="padding:1rem; background:rgba(15,23,42,.55); display:grid; gap:.75rem;">
                    <div>
                        <div style="font-weight:700;"><?= e($passkey->name) ?></div>
                        <div class="muted" style="font-size:.92rem;">Created <?= e($passkey->createdAt) ?> · Last used <?= e((string) ($passkey->lastUsedAt ?? 'never')) ?></div>
                    </div>
                    <form method="POST" action="/profile/passkeys/<?= e($passkey->uuid) ?>/delete">
                        <button type="submit" class="danger">Remove Passkey</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if ($passkeys === []): ?><div class="muted">No passkeys registered yet.</div><?php endif; ?>
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
        status.textContent = 'This browser does not support passkey registration.';
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
        setStatus('Preparing registration...', 'muted');

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
                throw new Error(startPayload?.message || 'Failed to initialize passkey registration.');
            }

            const credential = await navigator.credentials.create({
                publicKey: normalizeCreationOptions(startPayload.options),
                signal: AbortSignal.timeout ? AbortSignal.timeout(65000) : undefined,
            });

            if (!credential) {
                throw new Error('Passkey registration was cancelled.');
            }

            setStatus('Finishing registration...', 'muted');

            const finishResponse = await fetch('/auth/passkey/register/finish', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: input.value.trim() || 'Passkey',
                    credential: serializeCredential(credential),
                }),
            });

            const finishPayload = await finishResponse.json();
            if (!finishResponse.ok || finishPayload?.success !== true) {
                throw new Error(finishPayload?.message || 'Passkey registration failed.');
            }

            setStatus('Passkey registered successfully. Reloading...', 'success');
            window.setTimeout(() => window.location.reload(), 500);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Passkey registration failed.', 'error');
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
