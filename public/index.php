<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $staticFile = __DIR__ . ($requestPath !== false ? $requestPath : '/');

    if (is_file($staticFile)) {
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
