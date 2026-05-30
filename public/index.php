<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $staticFile = __DIR__ . ($requestPath !== false ? $requestPath : '/');

    if (is_file($staticFile)) {
        if (is_string($requestPath) && str_starts_with($requestPath, '/uploads/')) {
            $uploadsRoot = realpath(__DIR__ . '/uploads');
            $resolvedStaticFile = realpath($staticFile);

            if ($uploadsRoot === false || $resolvedStaticFile === false || !str_starts_with($resolvedStaticFile, $uploadsRoot . DIRECTORY_SEPARATOR)) {
                return false;
            }

            $lastModified = filemtime($resolvedStaticFile) ?: time();
            $etag = '"' . sha1($resolvedStaticFile . '|' . $lastModified . '|' . filesize($resolvedStaticFile)) . '"';
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($resolvedStaticFile) ?: 'application/octet-stream';

            header('Content-Type: ' . $mimeType);
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            header('ETag: ' . $etag);

            $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
            $ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;

            if ($ifNoneMatch === $etag || $ifModifiedSince >= $lastModified) {
                http_response_code(304);
                return true;
            }

            header('Content-Length: ' . (string) filesize($resolvedStaticFile));

            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                readfile($resolvedStaticFile);
            }

            return true;
        }

        return false;
    }
}

/**
 * Passway — Front Controller
 *
 * Single entry point for all HTTP requests.
 * Loads the Composer autoloader, initializes the application,
 * and passes control to the router.
 */

// Strict error handling mode
error_reporting(E_ALL);

// In production, errors are NOT shown to the user (configured in App)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Prevent direct access to this file outside the root (include protection)
define('PASSWAY_ROOT', dirname(__DIR__));
define('PASSWAY_START', microtime(true));

// Check for Composer
$autoloader = PASSWAY_ROOT . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'Service Unavailable',
        'message' => 'Dependencies not installed. Run: composer install',
    ]);
    exit(1);
}

require_once $autoloader;

// Run the application
use Passway\Core\Application;

try {
    $app = Application::getInstance();
    $app->run();
} catch (\Throwable $e) {
    // Last line of defense if Application failed before error handler initialization
    http_response_code(500);
    header('Content-Type: application/json');

    $body = ['error' => 'Internal Server Error'];

    // Show details in development
    if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
        $body['message'] = $e->getMessage();
        $body['file']    = $e->getFile();
        $body['line']    = $e->getLine();
        $body['trace']   = $e->getTraceAsString();
    }

    echo json_encode($body);
    exit(1);
}
