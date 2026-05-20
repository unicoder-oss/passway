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
use Passway\Services\LoggerService;
use Passway\Services\RotationService;

function info(string $msg): void
{
    fwrite(STDOUT, "\033[32m{$msg}\033[0m\n");
}

function warn(string $msg): void
{
    fwrite(STDOUT, "\033[33m{$msg}\033[0m\n");
}

function error(string $msg): void
{
    fwrite(STDERR, "\033[31m{$msg}\033[0m\n");
}

try {
    $app = Application::getInstance();
    /** @var LoggerService $logger */
    $logger = $app->getContainer()->make(LoggerService::class);
    /** @var RotationService $rotationService */
    $rotationService = $app->getContainer()->make(RotationService::class);

    $result = $rotationService->runDue();
    $logger->info('Rotation run finished', $result);

    info(sprintf(
        'Rotation run finished: rotated=%d skipped=%d failed=%d',
        $result['rotated'],
        $result['skipped'],
        $result['failed'],
    ));

    foreach ($result['errors'] as $errorItem) {
        $logger->warning('Rotation item failed', $errorItem);
        warn(sprintf('  %s: %s', $errorItem['secret_uuid'], $errorItem['error']));
    }

    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    error('Rotation run failed: ' . $e->getMessage());
    try {
        Application::getInstance()->getContainer()->make(LoggerService::class)
            ->error('Rotation run failed', ['message' => $e->getMessage()]);
    } catch (Throwable) {}
    if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
        error($e->getTraceAsString());
    }
    exit(1);
}
