<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\InviteLink;
use Passway\Models\Organization;
use Passway\Services\InviteService;
use Passway\Services\OrganizationService;

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
    ) {}

    // ------------------------------------------------------------------ //
    //  POST /api/v1/organizations/:uuid/invites                           //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        $role       = \trim((string) ($request->input('role') ?? 'user'));
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
        $token = $request->routeParam('token');

        try {
            $invite = $this->inviteService->findValid((string) $token);
        } catch (AuthException $e) {
            return Response::make(400)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($this->renderError($e->getMessage()));
        }

        $org = $invite->organizationId
            ? Organization::findById($invite->organizationId)
            : null;

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
        $user  = AuthContext::getUser();

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
            $data['url']   = '/invite/' . $invite->token;
        }
        return $data;
    }

    private function renderAcceptForm(InviteLink $invite, ?Organization $org): string
    {
        $orgName  = $org ? e($org->name) : e(__('ui.invite.new_organization'));
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
                body { font-family: system-ui, sans-serif; background: #f4f5f7;
                       display: flex; align-items: center; justify-content: center;
                       min-height: 100vh; padding: 1rem; }
                .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 16px rgba(0,0,0,.1);
                        padding: 2.5rem; width: 100%; max-width: 400px; text-align: center; }
                h1 { font-size: 1.4rem; margin-bottom: .5rem; }
                p  { color: #555; margin-bottom: 1.5rem; }
                .badge { display: inline-block; padding: .25rem .75rem; border-radius: 99px;
                         background: #e0f2fe; color: #0369a1; font-size: .85rem; font-weight: 600;
                         margin-bottom: 1.5rem; }
                button { width: 100%; padding: .75rem; background: #4f46e5; color: #fff;
                         border: none; border-radius: 6px; font-size: 1rem; font-weight: 600;
                         cursor: pointer; }
                button:hover { background: #4338ca; }
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
}
