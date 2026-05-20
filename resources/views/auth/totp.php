<div style="display:grid; place-items:center; min-height:100vh; padding:2rem 0;">
    <div class="panel" style="width:min(440px, 100%); padding:2rem;">
        <div class="brand" style="margin-bottom:1rem;"><?= e(__('ui.app.name')) ?></div>
        <h1 style="margin:.2rem 0 1rem; font-size:2rem;"><?= e(__('ui.auth.totp.heading')) ?></h1>
        <p class="muted" style="margin:0 0 1.25rem;"><?= e(__('ui.auth.totp.subtitle')) ?></p>
        <?php if (!empty($error)): ?><div class="error" data-toast="true" style="margin-bottom:1rem;"><?= e((string) $error) ?></div><?php endif; ?>
        <form method="POST" action="/auth/totp/verify" class="grid">
            <?php if (!empty($returnTo)): ?><input type="hidden" name="return_to" value="<?= e((string) $returnTo) ?>"><?php endif; ?>
            <div>
                <label for="code"><?= e(__('ui.auth.totp.code')) ?></label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button type="submit"><?= e(__('ui.auth.totp.verify')) ?></button>
        </form>
    </div>
</div>
