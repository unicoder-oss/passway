<div style="display:grid; place-items:center; min-height:100vh; padding:2rem 0;">
    <div class="panel" style="width:min(440px, 100%); padding:2rem;">
        <div class="brand" style="margin-bottom:1rem;"><?= e(__('ui.app.name')) ?></div>
        <h1 style="margin:.2rem 0 1rem; font-size:2rem;"><?= e(__('ui.auth.login.heading')) ?></h1>
        <p class="muted" style="margin:0 0 1.25rem;"><?= e(__('ui.auth.login.subtitle')) ?></p>
        <?php if (!empty($success)): ?><div class="success" style="margin-bottom:1rem;"><?= e((string) $success) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>
        <form method="POST" action="/auth/login" class="grid">
            <div>
                <label for="email"><?= e(__('ui.auth.login.email')) ?></label>
                <input id="email" type="email" name="email" value="<?= e((string) ($email ?? '')) ?>" autocomplete="email" required>
            </div>
            <div>
                <label for="password"><?= e(__('ui.auth.login.password')) ?></label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit"><?= e(__('ui.auth.login.submit')) ?></button>
        </form>
        <div class="panel panel-muted" style="margin-top:1rem; padding:1rem; display:grid; gap:.75rem;">
            <div>
                <h2 style="margin:0 0 .35rem; font-size:1.1rem;"><?= e(__('ui.auth.login.passkey_heading')) ?></h2>
                <div class="muted" style="font-size:.92rem;"><?= e(__('ui.auth.login.passkey_hint')) ?></div>
            </div>
            <button type="button" id="passkey-login-button" class="secondary"><?= e(__('ui.auth.login.passkey_submit')) ?></button>
            <div id="passkey-login-status" class="muted" style="font-size:.92rem;"><?= e(__('ui.auth.login.passkey_idle')) ?></div>
        </div>
    </div>
</div>

<script>
(() => {
    const button = document.getElementById('passkey-login-button');
    const status = document.getElementById('passkey-login-status');
    const emailInput = document.getElementById('email');

    if (!button || !status) {
        return;
    }

    if (!window.PublicKeyCredential || !navigator.credentials?.get) {
        status.textContent = <?= json_encode(__('ui.auth.login.passkey_browser_not_supported')) ?>;
        button.disabled = true;
        return;
    }

    const fromBase64Url = (value) => {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((value.length + 3) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    };

    const toBase64Url = (buffer) => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (const byte of bytes) {
            binary += String.fromCharCode(byte);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };

    const normalizeRequestOptions = (options) => ({
        ...options,
        challenge: fromBase64Url(options.challenge),
        allowCredentials: (options.allowCredentials || []).map((credential) => ({
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
                authenticatorData: toBase64Url(response.authenticatorData),
                clientDataJSON: toBase64Url(response.clientDataJSON),
                signature: toBase64Url(response.signature),
                userHandle: response.userHandle ? toBase64Url(response.userHandle) : null,
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
        setStatus(<?= json_encode(__('ui.auth.login.passkey_preparing')) ?>, 'muted');

        try {
            const email = emailInput?.value.trim() || '';
            const startResponse = await fetch('/auth/passkey/authenticate/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify(email === '' ? {} : { email }),
            });

            const startPayload = await startResponse.json();
            if (!startResponse.ok || !startPayload?.options) {
                throw new Error(startPayload?.error || <?= json_encode(__('ui.auth.login.passkey_init_failed')) ?>);
            }

            const credential = await navigator.credentials.get({
                publicKey: normalizeRequestOptions(startPayload.options),
                signal: AbortSignal.timeout ? AbortSignal.timeout(65000) : undefined,
            });

            if (!credential) {
                throw new Error(<?= json_encode(__('ui.auth.login.passkey_cancelled')) ?>);
            }

            setStatus(<?= json_encode(__('ui.auth.login.passkey_finishing')) ?>, 'muted');

            const finishResponse = await fetch('/auth/passkey/authenticate/finish', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ credential: serializeCredential(credential) }),
            });

            const finishPayload = await finishResponse.json();
            if (!finishResponse.ok || finishPayload?.success !== true) {
                throw new Error(finishPayload?.error || <?= json_encode(__('ui.auth.login.passkey_auth_failed')) ?>);
            }

            setStatus(<?= json_encode(__('ui.auth.login.passkey_success')) ?>, 'success');
            window.location.assign('/');
        } catch (error) {
            setStatus(error instanceof Error ? error.message : <?= json_encode(__('ui.auth.login.passkey_auth_failed')) ?>, 'error');
        } finally {
            button.disabled = false;
        }
    });
})();
</script>
