<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Services\SetupService;

/**
 * Initial setup controller.
 *
 * GET  /setup  - setup data entry form
 * POST /setup  - form handling
 */
final class SetupController
{
    public function __construct(
        private readonly SetupService $setupService,
    ) {}

    // ------------------------------------------------------------------ //
    //  GET /setup                                                         //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        if ($this->setupService->isSetupComplete()) {
            return Response::redirect('/');
        }

        $this->ensureSessionStarted();

        $errors = $_SESSION['setup_errors'] ?? [];
        $old = $_SESSION['setup_old'] ?? [];
        unset($_SESSION['setup_errors'], $_SESSION['setup_old']);

        return Response::make(200)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($this->renderForm(
                is_array($errors) ? $errors : [],
                is_array($old) ? $old : [],
            ));
    }

    // ------------------------------------------------------------------ //
    //  POST /setup                                                        //
    // ------------------------------------------------------------------ //

    public function process(Request $request): Response
    {
        if ($this->setupService->isSetupComplete()) {
            return Response::redirect('/');
        }

        $this->ensureSessionStarted();

        $token      = \trim((string) ($request->post('setup_token') ?? ''));
        $email      = \trim((string) ($request->post('email') ?? ''));
        $password   = (string) ($request->post('password') ?? '');
        $passConf   = (string) ($request->post('password_confirm') ?? '');
        $deployMode = \trim((string) ($request->post('deploy_mode') ?? 'solo'));

        $errors = [];
        $old = [
            'setup_token' => $token,
            'email' => $email,
            'deploy_mode' => $deployMode,
        ];

        if ($token === '') {
            $errors[] = __('ui.setup.token_required');
        }

        try {
            $this->setupService->validateEmail($email);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->setupService->validatePassword($password);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        if ($password !== $passConf) {
            $errors[] = __('ui.setup.passwords_do_not_match');
        }

        try {
            $this->setupService->validateDeployMode($deployMode);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        if ($errors !== []) {
            return $this->redirectWithErrors($errors, $old);
        }

        try {
            $this->setupService->completeSetup($token, $email, $password, $deployMode);
        } catch (AuthException $e) {
            return $this->redirectWithErrors([$e->getMessage()], $old);
        } catch (\InvalidArgumentException $e) {
            return $this->redirectWithErrors([$e->getMessage()], $old);
        }

        // Success - redirect to the login page
        return Response::redirect('/auth/login?success=' . \urlencode(__('ui.auth.login.success_setup_complete')));
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    /** @param string[] $messages
     *  @param array<string, string> $old
     */
    private function redirectWithErrors(array $messages, array $old = []): Response
    {
        $this->ensureSessionStarted();
        $_SESSION['setup_errors'] = \array_values(\array_unique(\array_filter($messages, static fn($message): bool => \is_string($message) && \trim($message) !== '')));
        $_SESSION['setup_old'] = $old;
        return Response::redirect('/setup');
    }

    private function ensureSessionStarted(): void
    {
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
    }

    /** @param string[] $errors
     *  @param array<string, string> $old
     */
    private function renderForm(array $errors, array $old = []): string
    {
        $errorHtml = '';
        if ($errors !== []) {
            $items = '';
            foreach ($errors as $error) {
                $items .= '<li>' . e($error) . '</li>';
            }

            $errorHtml = '<div class="error"><strong style="display:block; margin-bottom:.45rem;">' . e(__('ui.setup.fix_following')) . '</strong><ul style="margin:0; padding-left:1.2rem; display:grid; gap:.35rem;">' . $items . '</ul></div>';
        }

        $setupToken = e((string) ($old['setup_token'] ?? ''));
        $email = e((string) ($old['email'] ?? ''));
        $selectedMode = (string) ($old['deploy_mode'] ?? 'solo');
        $locale = e(app_locale());
        $title = e(__('ui.titles.setup'));
        $heading = e(__('ui.setup.heading'));
        $subtitle = e(__('ui.setup.subtitle'));
        $tokenLabel = e(__('ui.setup.token'));
        $tokenPlaceholder = e(__('ui.setup.token_placeholder'));
        $tokenHint = e(__('ui.setup.token_hint'));
        $adminEmailLabel = e(__('ui.setup.admin_email'));
        $adminPasswordLabel = e(__('ui.setup.admin_password'));
        $passwordPlaceholder = e(__('ui.setup.password_placeholder'));
        $confirmPasswordLabel = e(__('ui.setup.confirm_password'));
        $confirmPasswordPlaceholder = e(__('ui.setup.confirm_password_placeholder'));
        $deployModeLabel = e(__('ui.setup.deploy_mode'));
        $soloLabel = e(__('ui.setup.mode_solo'));
        $teamLabel = e(__('ui.setup.mode_team'));
        $soloPoints = [
            e(__('ui.setup.mode_solo_point_1')),
            e(__('ui.setup.mode_solo_point_2')),
            e(__('ui.setup.mode_solo_point_3')),
        ];
        $teamPoints = [
            e(__('ui.setup.mode_team_point_1')),
            e(__('ui.setup.mode_team_point_2')),
            e(__('ui.setup.mode_team_point_3')),
        ];
        $submitLabel = e(__('ui.setup.submit'));
        $soloActive = $selectedMode === 'solo' ? ' is-active' : '';
        $teamActive = $selectedMode === 'team' ? ' is-active' : '';
        $soloChecked = $selectedMode === 'solo' ? ' checked' : '';
        $teamChecked = $selectedMode === 'team' ? ' checked' : '';
        $soloItems = '<li>' . \implode('</li><li>', $soloPoints) . '</li>';
        $teamItems = '<li>' . \implode('</li><li>', $teamPoints) . '</li>';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                :root { color-scheme: light dark; --bg:#f5f5f5; --fg:#161616; --muted:#606060; --panel:#fff; --border:#d0d0d0; --error-bg:#f5e6e6; --error-border:#c79494; --error-fg:#5f1e1e; --button:#4b4b4b; }
                @media (prefers-color-scheme: dark) { :root { --bg:#111111; --fg:#f3f3f3; --muted:#a4a4a4; --panel:#1a1a1a; --border:#393939; --error-bg:#351b1b; --error-border:#6a2d2d; --error-fg:#f1cdcd; --button:#d6d6d6; } }
                @font-face { font-family: "Passway Mono"; src: url("/fonts/NotoSansMono-Regular.woff2") format("woff2"); font-weight: 400; font-style: normal; font-display: fallback; }
                @font-face { font-family: "Passway Mono"; src: url("/fonts/NotoSansMono-Bold.woff2") format("woff2"); font-weight: 700; font-style: normal; font-display: fallback; }
                * { box-sizing: border-box; }
                body { margin: 0; font-family: "Passway Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--bg); color: var(--fg); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
                .card { background: var(--panel); border: 1px solid var(--border); padding: 2rem; width: 100%; max-width: 760px; }
                h1 { margin: 0 0 .35rem; font-size: 1.6rem; }
                p.subtitle { margin: 0 0 1.5rem; color: var(--muted); }
                .error { border: 1px solid var(--error-border); background: var(--error-bg); color: var(--error-fg); padding: .9rem 1rem; margin-bottom: 1rem; }
                label { display: block; margin-top: 1rem; margin-bottom: .35rem; color: var(--muted); }
                input, select { width: 100%; border: 1px solid var(--border); background: var(--panel); color: var(--fg); padding: .8rem .9rem; font: inherit; }
                button { margin-top: 1.5rem; width: 100%; border: 1px solid var(--button); background: var(--button); color: var(--bg); padding: .8rem 1rem; font: inherit; cursor: pointer; }
                .hint { font-size: .85rem; color: var(--muted); margin-top: .35rem; }
                .mode-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:1rem; margin-top:.5rem; }
                .mode-input { position:absolute; opacity:0; pointer-events:none; }
                .mode-option { border:1px solid var(--border); padding:1rem; cursor:pointer; display:grid; gap:.75rem; min-height:100%; }
                .mode-option.is-active { border-color: var(--button); box-shadow: inset 0 0 0 1px var(--button); }
                .mode-option strong { font-size:1rem; }
                .mode-option ul { margin:0; padding-left:1.1rem; color:var(--muted); display:grid; gap:.35rem; }
                @media (max-width: 640px) { .mode-grid { grid-template-columns:1fr; } }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>{$heading}</h1>
                <p class="subtitle">{$subtitle}</p>
                {$errorHtml}
                <form method="POST" action="/setup">
                     <label for="setup_token">{$tokenLabel}</label>
                     <input type="text" id="setup_token" name="setup_token"
                            value="{$setupToken}"
                            placeholder="{$tokenPlaceholder}"
                            required autocomplete="off">
                    <p class="hint">{$tokenHint}</p>

                     <label for="email">{$adminEmailLabel}</label>
                     <input type="email" id="email" name="email"
                            value="{$email}"
                            placeholder="admin@example.com" required autocomplete="email">

                    <label for="password">{$adminPasswordLabel}</label>
                    <input type="password" id="password" name="password"
                           placeholder="{$passwordPlaceholder}" required autocomplete="new-password">

                    <label for="password_confirm">{$confirmPasswordLabel}</label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           placeholder="{$confirmPasswordPlaceholder}" required autocomplete="new-password">

                     <label>{$deployModeLabel}</label>
                     <div class="mode-grid" role="radiogroup" aria-label="{$deployModeLabel}">
                         <label class="mode-option{$soloActive}">
                             <input class="mode-input" type="radio" name="deploy_mode" value="solo"{$soloChecked}>
                             <strong>{$soloLabel}</strong>
                             <ul>{$soloItems}</ul>
                         </label>
                         <label class="mode-option{$teamActive}">
                             <input class="mode-input" type="radio" name="deploy_mode" value="team"{$teamChecked}>
                             <strong>{$teamLabel}</strong>
                             <ul>{$teamItems}</ul>
                         </label>
                     </div>

                     <button type="submit">{$submitLabel}</button>
                 </form>
             </div>
            <script>
            (() => {
                const options = Array.from(document.querySelectorAll('.mode-option'));

                const sync = () => {
                    for (const option of options) {
                        const input = option.querySelector('input[name="deploy_mode"]');
                        option.classList.toggle('is-active', Boolean(input && input.checked));
                    }
                };

                for (const option of options) {
                    option.addEventListener('click', () => {
                        const input = option.querySelector('input[name="deploy_mode"]');
                        if (!input) {
                            return;
                        }

                        input.checked = true;
                        sync();
                    });
                }

                sync();
            })();
            </script>
        </body>
        </html>
        HTML;
    }
}
