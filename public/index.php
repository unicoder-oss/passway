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
 * Единственная точка входа для всех HTTP-запросов.
 * Загружает автозагрузчик Composer, инициализирует приложение
 * и передаёт управление роутеру.
 */

// Строгий режим обработки ошибок
error_reporting(E_ALL);

// В production ошибки НЕ выводятся пользователю (настраивается в App)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Запрет доступа к этому файлу напрямую извне корня (защита от включения)
define('PASSWAY_ROOT', dirname(__DIR__));
define('PASSWAY_START', microtime(true));

// Проверяем наличие Composer
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

// Запускаем приложение
use Passway\Core\Application;

try {
    $app = Application::getInstance();
    $app->run();
} catch (\Throwable $e) {
    // Последний рубеж — если Application упал до инициализации обработчика ошибок
    http_response_code(500);
    header('Content-Type: application/json');

    $body = ['error' => 'Internal Server Error'];

    // В development показываем детали
    if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
        $body['message'] = $e->getMessage();
        $body['file']    = $e->getFile();
        $body['line']    = $e->getLine();
        $body['trace']   = $e->getTraceAsString();
    }

    echo json_encode($body);
    exit(1);
}
