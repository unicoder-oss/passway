<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — загружает автозагрузчик и подготавливает окружение.
 */

define('PASSWAY_ROOT', dirname(__DIR__));
define('PASSWAY_START', microtime(true));

require_once PASSWAY_ROOT . '/vendor/autoload.php';

// Загружаем .env если существует (перекрывается phpunit.xml <php><env>)
if (file_exists(PASSWAY_ROOT . '/.env')) {
    $dotenv = Dotenv\Dotenv::createMutable(PASSWAY_ROOT);
    $dotenv->safeLoad();
}
