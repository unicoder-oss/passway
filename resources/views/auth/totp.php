<div style="display:grid; place-items:center; min-height:100vh; padding:2rem 0;">
    <div class="panel" style="width:min(440px, 100%); padding:2rem;">
        <div class="brand" style="margin-bottom:1rem;">Passway</div>
        <h1 style="margin:.2rem 0 1rem; font-size:2rem;">Two-Factor Verification</h1>
        <p class="muted" style="margin:0 0 1.25rem;">Enter the 6-digit code from your authenticator app.</p>
        <?php if (!empty($error)): ?><div class="error" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>
        <form method="POST" action="/auth/totp/verify" class="grid">
            <div>
                <label for="code">TOTP Code</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button type="submit">Verify</button>
        </form>
    </div>
</div>
