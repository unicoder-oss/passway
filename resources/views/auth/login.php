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
    </div>
</div>
