#!/usr/bin/env php
<?php

declare(strict_types=1);

define('PASSWAY_ROOT', dirname(__DIR__));
define('PASSWAY_START', microtime(true));

$autoloader = PASSWAY_ROOT . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}

require_once $autoloader;

use Passway\Core\Application;
use Passway\Services\AuditService;
use Passway\Services\LoggerService;
use Passway\Services\SessionService;

function info(string $msg): void
{
    fwrite(STDOUT, "\033[32m{$msg}\033[0m\n");
}

function error_out(string $msg): void
{
    fwrite(STDERR, "\033[31m{$msg}\033[0m\n");
}

$command = $argv[1] ?? 'cleanup';

try {
    $app = Application::getInstance();
    $container = $app->getContainer();
    /** @var LoggerService $logger */
    $logger = $container->make(LoggerService::class);

    switch ($command) {
        case 'cleanup':
            /** @var AuditService $auditService */
            $auditService = $container->make(AuditService::class);
            /** @var SessionService $sessionService */
            $sessionService = $container->make(SessionService::class);

            $auditResult = $auditService->cleanupExpired();
            $deletedSessions = $sessionService->cleanup();

            info(sprintf(
                'Maintenance cleanup finished: audit_deleted=%d rate_limit_deleted=%d sessions_deleted=%d',
                $auditResult['audit_deleted'],
                $auditResult['rate_limit_deleted'],
                $deletedSessions,
            ));
            $logger->info('Maintenance cleanup finished', [
                'audit_deleted' => $auditResult['audit_deleted'],
                'rate_limit_deleted' => $auditResult['rate_limit_deleted'],
                'sessions_deleted' => $deletedSessions,
            ]);
            exit(0);

        default:
            error_out('Unknown maintenance command: ' . $command);
            exit(1);
    }
} catch (Throwable $e) {
    error_out('Maintenance failed: ' . $e->getMessage());
    try {
        Application::getInstance()->getContainer()->make(LoggerService::class)
            ->error('Maintenance failed', ['message' => $e->getMessage()]);
    } catch (Throwable) {}
    exit(1);
}
