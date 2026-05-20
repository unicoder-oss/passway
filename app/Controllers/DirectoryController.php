<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;
use Passway\Models\Organization;
use Passway\Services\DirectoryService;

/**
 * Контроллер каталогов организации.
 *
 * GET    /api/v1/organizations/:uuid/directories            — список всех (плоский)
 * POST   /api/v1/organizations/:uuid/directories            — создать
 * GET    /api/v1/organizations/:uuid/directories/:dirUuid  — детали
 * PATCH  /api/v1/organizations/:uuid/directories/:dirUuid  — переименовать / переместить
 * DELETE /api/v1/organizations/:uuid/directories/:dirUuid  — мягкое удаление
 */
final class DirectoryController
{
    public function __construct(
        private readonly DirectoryService $directoryService,
    ) {}

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations/:uuid/directories                        //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        try {
            $dirs = $this->directoryService->listAll($org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        // Собираем карту id → uuid для быстрого разрешения parent_uuid
        $idToUuid = [];
        foreach ($dirs as $dir) {
            $idToUuid[$dir->id] = $dir->uuid;
        }

        return Response::success(\array_map(
            fn($d) => $this->serializeDir($d, $idToUuid),
            $dirs
        ));
    }

    // ------------------------------------------------------------------ //
    //  POST /api/v1/organizations/:uuid/directories                       //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user       = AuthContext::requireUser();
        $org        = $this->findOrgOrFail($request);
        $name       = \trim((string) ($request->input('name') ?? ''));
        $parentUuid = $request->input('parent_uuid');
        $parentUuid = \is_string($parentUuid) && $parentUuid !== '' ? $parentUuid : null;

        if ($name === '') {
            return Response::validationError(['name' => ['Name is required.']]);
        }

        try {
            $dir = $this->directoryService->create($org->id, $parentUuid, $name, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeDir($dir), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations/:uuid/directories/:dirUuid               //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $dir = $this->directoryService->findInOrg($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeDir($dir));
    }

    // ------------------------------------------------------------------ //
    //  PATCH /api/v1/organizations/:uuid/directories/:dirUuid             //
    // ------------------------------------------------------------------ //

    /**
     * Переименовать и/или переместить каталог.
     * Принимает: name (строка), parent_uuid (строка или пустая строка = корень).
     * Хотя бы одно из полей обязательно.
     */
    public function update(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        $name       = $request->input('name');
        $parentUuid = $request->input('parent_uuid');  // null = не трогать; '' = корень; uuid = новый родитель

        if ($name === null && $parentUuid === null) {
            return Response::validationError([
                'name' => ['At least one of name or parent_uuid must be provided.'],
            ]);
        }

        try {
            // Переименование
            if ($name !== null) {
                $this->directoryService->rename($dirUuid, $org->id, (string) $name, $user->id);
            }

            // Перемещение
            if ($parentUuid !== null) {
                $newParent = \is_string($parentUuid) && $parentUuid !== '' ? $parentUuid : null;
                $this->directoryService->move($dirUuid, $org->id, $newParent, $user->id);
            }

            // Перезагрузить после всех изменений
            $dir = $this->directoryService->findInOrg($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeDir($dir));
    }

    // ------------------------------------------------------------------ //
    //  DELETE /api/v1/organizations/:uuid/directories/:dirUuid            //
    // ------------------------------------------------------------------ //

    public function delete(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $this->directoryService->delete($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function findOrgOrFail(Request $request): Organization
    {
        $uuid = $request->routeParam('uuid');
        $org  = Organization::findByUuid((string) $uuid);
        if ($org === null) {
            throw new \RuntimeException('Organization not found.');
        }
        return $org;
    }

    /**
     * Сериализовать каталог в массив для JSON-ответа.
     *
     * @param array<string, string> $idToUuid Карта id→uuid для быстрого разрешения parent_uuid.
     *                                        Если не передана — выполняется дополнительный запрос.
     * @return array<string, mixed>
     */
    private function serializeDir(Directory $dir, array $idToUuid = []): array
    {
        if ($dir->parentId !== null) {
            $parentUuid = $idToUuid[$dir->parentId]
                ?? Directory::findById($dir->parentId)?->uuid;
        } else {
            $parentUuid = null;
        }

        return [
            'uuid'        => $dir->uuid,
            'name'        => $dir->name,
            'parent_uuid' => $parentUuid,
            'depth'       => $dir->depth,
            'path'        => $dir->path,
            'created_at'  => $dir->createdAt,
            'updated_at'  => $dir->updatedAt,
        ];
    }
}
