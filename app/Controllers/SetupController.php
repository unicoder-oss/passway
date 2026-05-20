<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Services\SetupService;

/**
 * Контроллер первоначальной настройки.
 *
 * GET  /setup  — форма ввода данных setup
 * POST /setup  — обработка формы
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
            $errors[] = 'Setup token is required.';
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
            $errors[] = 'Passwords do not match.';
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

        // Успех — перенаправить на страницу входа
        return Response::redirect('/auth/login?success=' . \urlencode('Setup complete. You can now sign in.'));
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

            $errorHtml = '<div class="error"><strong style="display:block; margin-bottom:.45rem;">Please fix the following:</strong><ul style="margin:0; padding-left:1.2rem; display:grid; gap:.35rem;">' . $items . '</ul></div>';
        }

        $setupToken = e((string) ($old['setup_token'] ?? ''));
        $email = e((string) ($old['email'] ?? ''));
        $selectedMode = (string) ($old['deploy_mode'] ?? 'solo');

        $modeOptions = '';
        foreach (SetupService::DEPLOY_MODES as $mode) {
            $label       = \ucfirst($mode);
            $selected = $selectedMode === $mode ? ' selected' : '';
            $modeOptions .= "<option value=\"{$mode}\"{$selected}>{$label}</option>";
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Passway — Initial Setup</title>
            <style>
                *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: system-ui, sans-serif; background: #f4f5f7; display: flex;
                       align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
                .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 16px rgba(0,0,0,.1);
                        padding: 2.5rem; width: 100%; max-width: 440px; }
                h1 { font-size: 1.5rem; margin-bottom: .25rem; color: #111; }
                p.subtitle { color: #666; font-size: .9rem; margin-bottom: 1.75rem; }
                .error { background: #fff0f0; color: #c00; border: 1px solid #f5c6c6;
                         border-radius: 4px; padding: .75rem 1rem; margin-bottom: 1.25rem;
                         font-size: .9rem; }
                label { display: block; font-size: .875rem; font-weight: 500; color: #333;
                        margin-bottom: .3rem; margin-top: 1rem; }
                input, select { width: 100%; padding: .625rem .75rem; border: 1px solid #d0d5dd;
                                border-radius: 6px; font-size: .9375rem; outline: none; }
                input:focus, select:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
                button { margin-top: 1.75rem; width: 100%; padding: .75rem; background: #4f46e5;
                         color: #fff; border: none; border-radius: 6px; font-size: 1rem;
                         font-weight: 600; cursor: pointer; }
                button:hover { background: #4338ca; }
                .hint { font-size: .8rem; color: #888; margin-top: .3rem; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>Passway Setup</h1>
                <p class="subtitle">Configure your secrets management instance.</p>
                {$errorHtml}
                <form method="POST" action="/setup">
                     <label for="setup_token">Setup Token</label>
                     <input type="text" id="setup_token" name="setup_token"
                            value="{$setupToken}"
                            placeholder="Paste the token from storage/setup_token.txt"
                            required autocomplete="off">
                    <p class="hint">Found in <code>storage/setup_token.txt</code> or server stdout.</p>

                     <label for="email">Admin Email</label>
                     <input type="email" id="email" name="email"
                            value="{$email}"
                            placeholder="admin@example.com" required autocomplete="email">

                    <label for="password">Admin Password</label>
                    <input type="password" id="password" name="password"
                           placeholder="Min. 8 characters, letter + digit" required autocomplete="new-password">

                    <label for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           placeholder="Repeat password" required autocomplete="new-password">

                    <label for="deploy_mode">Deploy Mode</label>
                    <select id="deploy_mode" name="deploy_mode">
                        {$modeOptions}
                    </select>
                    <p class="hint">
                        <strong>Solo</strong> — single user. &nbsp;
                        <strong>Team</strong> — multiple users with organisations.
                    </p>

                    <button type="submit">Complete Setup</button>
                </form>
            </div>
        </body>
        </html>
        HTML;
    }
}
