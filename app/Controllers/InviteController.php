<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\InviteLink;
use Passway\Models\Organization;
use Passway\Models\User;
use Passway\Services\HashingService;
use Passway\Services\InviteService;
use Passway\Services\OrganizationService;
use Passway\Services\SessionService;
use Passway\Services\SetupService;

/**
 * Контроллер инвайт-ссылок.
 *
 * POST   /api/v1/organizations/:uuid/invites          — создать инвайт join_org
 * GET    /api/v1/organizations/:uuid/invites          — список активных инвайтов
 * DELETE /api/v1/organizations/:uuid/invites/:invUuid — отозвать инвайт
 * GET    /invite/:token                               — информация об инвайте (web)
 * POST   /invite/:token                               — принять инвайт (web/api)
 */
final class InviteController
{
    public function __construct(
        private readonly InviteService       $inviteService,
        private readonly OrganizationService $organizationService,
        private readonly HashingService      $hashingService,
        private readonly SessionService      $sessionService,
        private readonly SetupService        $setupService,
    ) {}

    // ------------------------------------------------------------------ //
    //  POST /api/v1/organizations/:uuid/invites                           //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        $role       = \trim((string) ($request->input('role') ?? 'reader'));
        $ttlSeconds = (int) ($request->input('ttl') ?? OrganizationService::DEFAULT_INVITE_TTL);

        if ($ttlSeconds < 60 || $ttlSeconds > 7 * 86400) {
            $ttlSeconds = OrganizationService::DEFAULT_INVITE_TTL;
        }

        try {
            $invite = $this->inviteService->createJoinOrgInvite($org->id, $role, $user->id, $ttlSeconds);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['role' => [$e->getMessage()]]);
        }

        return Response::success($this->serializeInvite($invite, withToken: true), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations/:uuid/invites                            //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'admin')) {
            return Response::forbidden(__('ui.backend.invite.requires_admin_list'));
        }

        $invites = $this->inviteService->listActive($org->id);

        return Response::success(\array_map(
            fn(InviteLink $i) => $this->serializeInvite($i, withToken: false),
            $invites
        ));
    }

    // ------------------------------------------------------------------ //
    //  DELETE /api/v1/organizations/:uuid/invites/:invUuid                //
    // ------------------------------------------------------------------ //

    public function revoke(Request $request): Response
    {
        $user      = AuthContext::requireUser();
        $invUuid   = $request->routeParam('invUuid');

        try {
            $this->inviteService->revoke((string) $invUuid, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 404);
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  GET /invite/:token  (web — информация об инвайте)                  //
    // ------------------------------------------------------------------ //

    public function showAccept(Request $request): Response
    {
        $token = (string) $request->routeParam('token');
        $user = $this->resolveInviteUser();

        try {
            $invite = $this->inviteService->findValid($token);
        } catch (AuthException $e) {
            return Response::make(400)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderError($e->getMessage()));
        }

        $org = $invite->organizationId
            ? Organization::findById($invite->organizationId)
            : null;

        if ($invite->type === InviteLink::TYPE_CREATE_ORG && $user !== null) {
            return Response::redirect('/invite/' . $invite->token . '/create-organization');
        }

        if ($invite->type === InviteLink::TYPE_JOIN_ORG && $user !== null) {
            try {
                $acceptedOrg = $this->inviteService->acceptJoinOrg($invite->token, $user->id);
                return Response::redirect('/organizations/' . \urlencode($acceptedOrg->uuid));
            } catch (AuthException | \RuntimeException $e) {
                return Response::make(400)
                    ->withContentType('text/html; charset=utf-8')
                    ->withBody($this->renderError($e->getMessage()));
            }
        }

        if ($invite->type === InviteLink::TYPE_CREATE_ORG) {
            return Response::make(200)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderCreateOrgEntry($invite));
        }

        if ($user === null) {
            return Response::make(200)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderJoinOrgEntry($invite, $org));
        }

        return Response::make(200)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($this->renderAcceptForm($invite, $org));
    }

    // ------------------------------------------------------------------ //
    //  POST /invite/:token  (принять инвайт)                              //
    // ------------------------------------------------------------------ //

    public function accept(Request $request): Response
    {
        $token = $request->routeParam('token');
        $user  = $this->resolveInviteUser();

        if ($user === null) {
            // Не аутентифицирован — редиректим на логин с return_to
            return Response::redirect('/auth/login?return_to=' . \urlencode('/invite/' . $token));
        }

        try {
            $invite = $this->inviteService->findValid((string) $token);

            if ($invite->type === InviteLink::TYPE_JOIN_ORG) {
                $org = $this->inviteService->acceptJoinOrg((string) $token, $user->id);
                if ($request->expectsJson()) {
                    return Response::success(['organization_uuid' => $org->uuid]);
                }
                return Response::redirect('/');
            }

            if ($invite->type === InviteLink::TYPE_CREATE_ORG) {
                return Response::redirect('/invite/' . $invite->token . '/create-organization');
            }

            return Response::error(__('ui.backend.common.unsupported_invite_type'), 400);

        } catch (AuthException $e) {
            if ($request->expectsJson()) {
                return Response::error($e->getMessage(), 400);
            }
            return Response::make(400)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderError($e->getMessage()));
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 409);
        }
    }

    public function showCreateOrganization(Request $request): Response
    {
        $token = (string) $request->routeParam('token');
        $user = $this->requireAuthenticatedInviteUser($token);
        if (!$user instanceof User) {
            return $user;
        }

        $invite = $this->requireWebInvite($token);
        if (!$invite instanceof InviteLink) {
            return $invite;
        }

        return Response::make(200)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($this->renderCreateOrganizationForm($token, (string) $request->query('error')));
    }

    public function showRegister(Request $request): Response
    {
        $token = (string) $request->routeParam('token');

        if ($this->resolveInviteUser() !== null) {
            $invite = $this->requireWebInvite($token);
            if (!$invite instanceof InviteLink) {
                return $invite;
            }

            return Response::redirect($invite->type === InviteLink::TYPE_CREATE_ORG
                ? '/invite/' . $token . '/create-organization'
                : '/invite/' . $token);
        }

        $invite = $this->requireWebInvite($token);
        if (!$invite instanceof InviteLink) {
            return $invite;
        }

        return Response::make(200)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($this->renderRegisterForm(
                $token,
                (string) ($request->query('error') ?? ''),
                (string) ($request->query('email') ?? ''),
            ));
    }

    public function register(Request $request): Response
    {
        $token = (string) $request->routeParam('token');

        if ($this->resolveInviteUser() !== null) {
            $invite = $this->requireWebInvite($token);
            if (!$invite instanceof InviteLink) {
                return $invite;
            }

            return Response::redirect($invite->type === InviteLink::TYPE_CREATE_ORG
                ? '/invite/' . $token . '/create-organization'
                : '/invite/' . $token);
        }

        $invite = $this->requireWebInvite($token);
        if (!$invite instanceof InviteLink) {
            return $invite;
        }

        $email = \strtolower(\trim((string) ($request->input('email') ?? '')));
        $password = (string) ($request->input('password') ?? '');
        $passwordConfirm = (string) ($request->input('password_confirm') ?? '');

        $createdUser = null;

        try {
            $this->setupService->validateEmail($email);
            $this->setupService->validatePassword($password);

            if ($password !== $passwordConfirm) {
                throw new \InvalidArgumentException(__('ui.invite.passwords_do_not_match'));
            }

            if (User::findByEmail($email) !== null) {
                throw new \InvalidArgumentException(__('ui.invite.email_already_exists'));
            }

            $now = now()->format('Y-m-d H:i:s');
            $user = User::create([
                'uuid' => generate_uuid(),
                'email' => $email,
                'password_hash' => $this->hashingService->hashPassword($password),
                'avatar_color' => generate_avatar_color(),
                'totp_enabled' => 0,
                'is_active' => 1,
                'email_verified' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $createdUser = $user;

            $rawToken = $this->sessionService->create($user->id, $request->ip(), $request->header('User-Agent'));
            $user->update([
                'last_login_at' => $now,
                'last_login_ip' => $request->ip(),
            ]);
            $this->sessionService->setCookie($rawToken);
        } catch (\Throwable $e) {
            return Response::redirect('/invite/' . $token . '/register?error=' . \urlencode($e->getMessage()) . '&email=' . \urlencode($email));
        }

        if ($invite->type === InviteLink::TYPE_JOIN_ORG && $createdUser instanceof User) {
            try {
                $org = $this->inviteService->acceptJoinOrg($token, $createdUser->id);
                return Response::redirect('/organizations/' . \urlencode($org->uuid));
            } catch (AuthException | \RuntimeException $e) {
                return Response::make(400)
                    ->withContentType('text/html; charset=utf-8')
                    ->withBody($this->renderError($e->getMessage()));
            }
        }

        return Response::redirect($invite->type === InviteLink::TYPE_CREATE_ORG
            ? '/invite/' . $token . '/create-organization'
            : '/invite/' . $token);
    }

    public function createOrganizationFromInvite(Request $request): Response
    {
        $token = (string) $request->routeParam('token');
        $user = $this->requireAuthenticatedInviteUser($token);
        if (!$user instanceof User) {
            return $user;
        }

        $invite = $this->requireCreateOrgInvite($token);
        if (!$invite instanceof InviteLink) {
            return $invite;
        }

        $name = \trim((string) ($request->input('name') ?? ''));
        $avatarData = \trim((string) ($request->input('avatar_data') ?? ''));
        $avatarPath = null;

        try {
            $avatarPath = $this->storeOrganizationAvatar($avatarData);
            $org = $this->inviteService->acceptCreateOrg($token, $user->id, $name);
            if ($avatarPath !== null) {
                $org->update([
                    'avatar_path' => $avatarPath,
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ]);
            }
        } catch (AuthException $e) {
            return Response::make(400)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderError($e->getMessage()));
        } catch (\Throwable $e) {
            if ($avatarPath !== null) {
                $absolutePath = public_path(ltrim($avatarPath, '/'));
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
            return Response::redirect('/invite/' . $token . '/create-organization?error=' . \urlencode($e->getMessage()));
        }

        return Response::redirect('/organizations/' . \urlencode($org->uuid));
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function findOrgOrFail(Request $request): Organization
    {
        $uuid = $request->routeParam('uuid');
        $org  = Organization::findByUuid((string) $uuid);
        if ($org === null) {
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }
        return $org;
    }

    private function requireCreateOrgInvite(string $token): InviteLink|Response
    {
        try {
            $invite = $this->inviteService->findValid($token);
            if ($invite->type !== InviteLink::TYPE_CREATE_ORG) {
                throw new AuthException(__('ui.backend.invite.wrong_type_create_org'));
            }

            return $invite;
        } catch (AuthException $e) {
            return Response::make(400)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderError($e->getMessage()));
        }
    }

    private function requireAuthenticatedInviteUser(string $token): User|Response
    {
        $user = $this->resolveInviteUser();
        if ($user !== null) {
            return $user;
        }

        return Response::redirect('/auth/login?return_to=' . \urlencode('/invite/' . $token . '/create-organization'));
    }

    private function resolveInviteUser(): ?User
    {
        $user = AuthContext::getUser();
        if ($user !== null) {
            return $user;
        }

        $rawToken = $this->sessionService->getTokenFromCookie();
        if ($rawToken === null) {
            return null;
        }

        $user = $this->sessionService->validate($rawToken);
        if ($user === null || !$user->isActive) {
            return null;
        }

        AuthContext::setUser($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInvite(InviteLink $invite, bool $withToken): array
    {
        $data = [
            'uuid'            => $invite->uuid,
            'type'            => $invite->type,
            'role'            => $invite->role,
            'expires_at'      => $invite->expiresAt,
            'created_at'      => $invite->createdAt,
        ];
        if ($withToken) {
            $data['token'] = $invite->token;
            $data['url']   = app_url('/invite/' . $invite->token);
        }
        return $data;
    }

    private function renderAcceptForm(InviteLink $invite, ?Organization $org): string
    {
        $roleHtml = e($invite->role);
        $locale = e(app_locale());
        $title = e(__('ui.titles.accept_invite'));
        $heading = e(__('ui.invite.heading'));
        $joinAs = e(__('ui.invite.join_as', ['organization' => $org ? $org->name : __('ui.invite.new_organization')]));
        $accept = e(__('ui.invite.accept'));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                :root { color-scheme: light dark; --bg:#f5f5f5; --fg:#161616; --muted:#606060; --panel:#fff; --border:#d0d0d0; --button:#4b4b4b; }
                @media (prefers-color-scheme: dark) { :root { --bg:#111111; --fg:#f3f3f3; --muted:#a4a4a4; --panel:#1a1a1a; --border:#393939; --button:#d6d6d6; } }
                body { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--bg); color: var(--fg); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
                .card { background: var(--panel); border: 1px solid var(--border); padding: 2rem; width: 100%; max-width: 420px; text-align: center; }
                h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
                p { color: var(--muted); margin: 0 0 1.25rem; }
                .badge { display: inline-block; padding: .35rem .75rem; border: 1px solid var(--border); background: var(--panel); font-size: .85rem; font-weight: 600; margin-bottom: 1.25rem; }
                button { width: 100%; border: 1px solid var(--button); background: var(--button); color: var(--bg); padding: .8rem 1rem; font: inherit; cursor: pointer; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>{$heading}</h1>
                <p>{$joinAs}</p>
                <div class="badge">{$roleHtml}</div>
                <form method="POST">
                    <button type="submit">{$accept}</button>
                </form>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderCreateOrganizationForm(string $token, string $error = ''): string
    {
        $locale = e(app_locale());
        $title = e(__('ui.titles.accept_invite'));
        $heading = e(__('ui.invite.create_org_heading'));
        $subtitle = e(__('ui.invite.create_org_subtitle'));
        $nameLabel = e(__('ui.invite.organization_name'));
        $namePlaceholder = e(__('ui.invite.organization_name_placeholder'));
        $avatarLabel = e(__('ui.invite.organization_avatar'));
        $avatarHint = e(__('ui.invite.organization_avatar_hint'));
        $avatarChoose = e(__('ui.invite.organization_avatar_choose'));
        $submit = e(__('ui.invite.create_organization_submit'));
        $errorHtml = $error !== ''
            ? '<div style="border:1px solid #c79494;background:#f5e6e6;color:#5f1e1e;padding:.9rem 1rem;margin-bottom:1rem;">' . e($error) . '</div>'
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                :root { color-scheme: light dark; --bg:#f5f5f5; --fg:#161616; --muted:#606060; --panel:#fff; --border:#d0d0d0; --button:#4b4b4b; }
                @media (prefers-color-scheme: dark) { :root { --bg:#111111; --fg:#f3f3f3; --muted:#a4a4a4; --panel:#1a1a1a; --border:#393939; --button:#d6d6d6; } }
                body { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--bg); color: var(--fg); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
                .card { background: var(--panel); border: 1px solid var(--border); padding: 2rem; width: 100%; max-width: 520px; }
                h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
                p { margin: 0 0 1.25rem; color: var(--muted); }
                label { display: block; margin-bottom: .4rem; color: #606060; }
                input { width: 100%; border: 1px solid #d0d0d0; padding: .8rem .9rem; font: inherit; box-sizing: border-box; }
                button { width: 100%; border: 1px solid #4b4b4b; background: #4b4b4b; color: #fff; padding: .8rem 1rem; font: inherit; cursor: pointer; margin-top: 1rem; }
                .hint { margin-top: .45rem; color: #606060; font-size: .92rem; }
                .crop-shell { margin-top: 1rem; display: grid; gap: .75rem; }
                .preview-wrap { width: 256px; max-width: 100%; border: 1px solid #d0d0d0; background: #ededed; }
                canvas { display: block; width: 100%; height: auto; }
                .range { width: 100%; margin: 0; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>{$heading}</h1>
                <p>{$subtitle}</p>
                {$errorHtml}
                <form method="POST" action="/invite/{$token}/create-organization">
                    <label for="organization-name">{$nameLabel}</label>
                    <input id="organization-name" name="name" placeholder="{$namePlaceholder}" required>
                    <div class="crop-shell">
                        <div>
                            <label for="avatar-file">{$avatarLabel}</label>
                            <input id="avatar-file" type="file" accept="image/png,image/jpeg,image/webp">
                            <div class="hint">{$avatarHint}</div>
                        </div>
                        <div class="preview-wrap"><canvas id="avatar-canvas" width="256" height="256"></canvas></div>
                        <div>
                            <label for="avatar-zoom">{$avatarChoose}</label>
                            <input id="avatar-zoom" class="range" type="range" min="1" max="4" step="0.01" value="1">
                        </div>
                    </div>
                    <input id="avatar-data" type="hidden" name="avatar_data">
                    <button type="submit">{$submit}</button>
                </form>
                <script>
                (() => {
                    const fileInput = document.getElementById('avatar-file');
                    const zoomInput = document.getElementById('avatar-zoom');
                    const canvas = document.getElementById('avatar-canvas');
                    const hidden = document.getElementById('avatar-data');
                    const context = canvas.getContext('2d');
                    const size = 256;
                    const state = { image: null, scale: 1, baseScale: 1, offsetX: 0, offsetY: 0, dragging: false, lastX: 0, lastY: 0 };

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
                })();
                </script>
            </div>
        </body>
        </html>
        HTML;
    }

    private function storeOrganizationAvatar(string $avatarData): ?string
    {
        if ($avatarData === '') {
            return null;
        }

        if (!preg_match('#^data:(image/(png|webp));base64,(.+)$#', $avatarData, $matches)) {
            throw new \InvalidArgumentException(__('ui.invite.organization_avatar_invalid'));
        }

        $mimeType = (string) $matches[1];
        $encoded = (string) $matches[3];
        $binary = base64_decode(str_replace(' ', '+', $encoded), true);

        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException(__('ui.invite.organization_avatar_invalid'));
        }

        $size = @getimagesizefromstring($binary);
        if (!is_array($size) || ($size[0] ?? 0) !== 256 || ($size[1] ?? 0) !== 256) {
            throw new \InvalidArgumentException(__('ui.invite.organization_avatar_size_invalid'));
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: '';
        if (!in_array($detectedMime, ['image/png', 'image/webp'], true) || $detectedMime !== $mimeType) {
            throw new \InvalidArgumentException(__('ui.invite.organization_avatar_invalid'));
        }

        $extension = $mimeType === 'image/webp' ? 'webp' : 'png';
        $directory = public_path('uploads/organizations/avatars');
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('ui.invite.organization_avatar_store_failed'));
        }

        $filename = generate_uuid() . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
            throw new \RuntimeException(__('ui.invite.organization_avatar_store_failed'));
        }

        return '/uploads/organizations/avatars/' . $filename;
    }

    private function renderCreateOrgEntry(InviteLink $invite): string
    {
        $locale = e(app_locale());
        $title = e(__('ui.titles.accept_invite'));
        $heading = e(__('ui.invite.heading'));
        $subtitle = e(__('ui.invite.create_org_invite_subtitle'));
        $loginLabel = e(__('ui.invite.sign_in_to_continue'));
        $registerLabel = e(__('ui.invite.register_to_continue'));
        $loginHref = e('/auth/login?return_to=' . \urlencode('/invite/' . $invite->token . '/create-organization'));
        $registerHref = e('/invite/' . $invite->token . '/register');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                :root { color-scheme: light dark; --bg:#f5f5f5; --fg:#161616; --muted:#606060; --panel:#fff; --border:#d0d0d0; --button:#4b4b4b; }
                @media (prefers-color-scheme: dark) { :root { --bg:#111111; --fg:#f3f3f3; --muted:#a4a4a4; --panel:#1a1a1a; --border:#393939; --button:#d6d6d6; } }
                body { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--bg); color: var(--fg); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
                .card { background: var(--panel); border: 1px solid var(--border); padding: 2rem; width: 100%; max-width: 520px; }
                h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
                p { margin: 0 0 1.25rem; color: var(--muted); }
                .actions { display: grid; gap: .75rem; }
                .button { display: inline-flex; align-items: center; justify-content: center; border: 1px solid #4b4b4b; background: #4b4b4b; color: #fff; padding: .8rem 1rem; text-decoration: none; }
                .button.secondary { background: #ededed; color: #161616; border-color: #d0d0d0; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>{$heading}</h1>
                <p>{$subtitle}</p>
                <div class="actions">
                    <a class="button" href="{$loginHref}">{$loginLabel}</a>
                    <a class="button secondary" href="{$registerHref}">{$registerLabel}</a>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderJoinOrgEntry(InviteLink $invite, ?Organization $org): string
    {
        $locale = e(app_locale());
        $title = e(__('ui.titles.accept_invite'));
        $heading = e(__('ui.invite.heading'));
        $subtitle = e(__('ui.invite.join_org_invite_subtitle', ['organization' => $org?->name ?? __('ui.invite.new_organization')]));
        $loginLabel = e(__('ui.invite.sign_in_to_continue'));
        $registerLabel = e(__('ui.invite.register_to_continue'));
        $loginHref = e('/auth/login?return_to=' . \urlencode('/invite/' . $invite->token));
        $registerHref = e('/invite/' . $invite->token . '/register');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                :root { color-scheme: light dark; --bg:#f5f5f5; --fg:#161616; --muted:#606060; --panel:#fff; --border:#d0d0d0; --button:#4b4b4b; }
                @media (prefers-color-scheme: dark) { :root { --bg:#111111; --fg:#f3f3f3; --muted:#a4a4a4; --panel:#1a1a1a; --border:#393939; --button:#d6d6d6; } }
                body { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--bg); color: var(--fg); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
                .card { background: var(--panel); border: 1px solid var(--border); padding: 2rem; width: 100%; max-width: 520px; }
                h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
                p { margin: 0 0 1.25rem; color: var(--muted); }
                .actions { display: grid; gap: .75rem; }
                .button { display: inline-flex; align-items: center; justify-content: center; border: 1px solid #4b4b4b; background: #4b4b4b; color: #fff; padding: .8rem 1rem; text-decoration: none; }
                .button.secondary { background: #ededed; color: #161616; border-color: #d0d0d0; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>{$heading}</h1>
                <p>{$subtitle}</p>
                <div class="actions">
                    <a class="button" href="{$loginHref}">{$loginLabel}</a>
                    <a class="button secondary" href="{$registerHref}">{$registerLabel}</a>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderRegisterForm(string $token, string $error = '', string $email = ''): string
    {
        $locale = e(app_locale());
        $title = e(__('ui.titles.accept_invite'));
        $heading = e(__('ui.invite.register_heading'));
        $subtitle = e(__('ui.invite.register_subtitle'));
        $emailLabel = e(__('ui.auth.login.email'));
        $passwordLabel = e(__('ui.auth.login.password'));
        $passwordConfirmLabel = e(__('ui.invite.confirm_password'));
        $submit = e(__('ui.invite.register_submit'));
        $errorHtml = $error !== ''
            ? '<div style="border:1px solid #c79494;background:#f5e6e6;color:#5f1e1e;padding:.9rem 1rem;margin-bottom:1rem;">' . e($error) . '</div>'
            : '';
        $emailValue = e($email);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                :root { color-scheme: light dark; --bg:#f5f5f5; --fg:#161616; --muted:#606060; --panel:#fff; --border:#d0d0d0; --button:#4b4b4b; }
                @media (prefers-color-scheme: dark) { :root { --bg:#111111; --fg:#f3f3f3; --muted:#a4a4a4; --panel:#1a1a1a; --border:#393939; --button:#d6d6d6; } }
                body { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--bg); color: var(--fg); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
                .card { background: var(--panel); border: 1px solid var(--border); padding: 2rem; width: 100%; max-width: 520px; }
                h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
                p { margin: 0 0 1.25rem; color: var(--muted); }
                label { display: block; margin-bottom: .4rem; color: var(--muted); }
                input { width: 100%; border: 1px solid var(--border); background: var(--panel); color: var(--fg); padding: .8rem .9rem; font: inherit; box-sizing: border-box; margin-bottom: .9rem; }
                button { width: 100%; border: 1px solid var(--button); background: var(--button); color: var(--bg); padding: .8rem 1rem; font: inherit; cursor: pointer; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>{$heading}</h1>
                <p>{$subtitle}</p>
                {$errorHtml}
                <form method="POST" action="/invite/{$token}/register">
                    <label for="register-email">{$emailLabel}</label>
                    <input id="register-email" type="email" name="email" value="{$emailValue}" required>
                    <label for="register-password">{$passwordLabel}</label>
                    <input id="register-password" type="password" name="password" required>
                    <label for="register-password-confirm">{$passwordConfirmLabel}</label>
                    <input id="register-password-confirm" type="password" name="password_confirm" required>
                    <button type="submit">{$submit}</button>
                </form>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderError(string $message): string
    {
        $msg = e($message);
        $locale = e(app_locale());
        $title = e(__('ui.app.name'));
        $heading = e(__('ui.invite.invalid_heading'));
        $homeLabel = e(__('ui.invite.go_home'));

        return <<<HTML
        <!DOCTYPE html><html lang="{$locale}"><head><meta charset="UTF-8"><title>{$title}</title></head>
        <body style="font-family:system-ui;text-align:center;padding:3rem">
          <h1>{$heading}</h1><p style="color:#c00">{$msg}</p>
          <a href="/">{$homeLabel}</a>
        </body></html>
        HTML;
    }

    private function requireWebInvite(string $token): InviteLink|Response
    {
        try {
            return $this->inviteService->findValid($token);
        } catch (AuthException $e) {
            return Response::make(400)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderError($e->getMessage()));
        }
    }
}
