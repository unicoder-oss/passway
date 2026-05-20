<?php

declare(strict_types=1);

namespace Passway\Middleware;

use Closure;
use Passway\Core\Database;
use Passway\Core\Request;
use Passway\Core\Response;

/**
 * Middleware: редирект на /setup если первоначальная настройка не завершена.
 *
 * Применяется глобально в Application::run() перед диспетчеризацией.
 * Пропускает запросы к /setup и /health без проверки.
 */
final class SetupMiddleware
{
    /** Пути, доступные до завершения setup */
    private const ALLOWED_PATHS = ['/setup', '/health'];

    public function handle(Request $request, Closure $next): Response
    {
        if (\in_array($request->path(), self::ALLOWED_PATHS, true)) {
            return $next($request);
        }

        try {
            $complete = Database::getInstance()->fetchColumn(
                "SELECT value FROM system_config WHERE key = 'setup_complete'"
            );
        } catch (\Throwable) {
            // БД недоступна — перенаправить на /setup
            return $this->setupRequired($request);
        }

        if ($complete !== '1') {
            return $this->setupRequired($request);
        }

        return $next($request);
    }

    private function setupRequired(Request $request): Response
    {
        if ($request->expectsJson() || $request->isApi()) {
            return Response::json([
                'success' => false,
                'error'   => 'Setup not complete. Please visit /setup to configure Passway.',
            ], 503);
        }

        return Response::redirect('/setup');
    }
}
