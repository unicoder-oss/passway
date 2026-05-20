<?php

declare(strict_types=1);

namespace Passway\Services;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerService
{
    private Logger $logger;

    public function __construct()
    {
        $channel = (string) ($_ENV['APP_NAME'] ?? 'passway');
        $this->logger = new Logger($channel);
        $this->logger->pushHandler(new StreamHandler($this->resolvePath(), $this->resolveLevel()));
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    private function resolvePath(): string
    {
        $channel = (string) ($_ENV['LOG_CHANNEL'] ?? 'file');
        if ($channel === 'stderr') {
            return 'php://stderr';
        }

        $path = (string) ($_ENV['LOG_PATH'] ?? storage_path('logs/passway.log'));
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            return 'php://stderr';
        }

        if (!\is_writable($dir)) {
            return 'php://stderr';
        }

        return $path;
    }

    private function resolveLevel(): Level
    {
        return match (\strtolower((string) ($_ENV['LOG_LEVEL'] ?? 'info'))) {
            'debug'   => Level::Debug,
            'warning' => Level::Warning,
            'error'   => Level::Error,
            default   => Level::Info,
        };
    }
}
