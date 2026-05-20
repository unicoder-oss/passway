<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap; loads the autoloader and prepares the environment.
 */

define('PASSWAY_ROOT', dirname(__DIR__));
define('PASSWAY_START', microtime(true));

require_once PASSWAY_ROOT . '/vendor/autoload.php';

// Load .env if it exists (overridden by phpunit.xml <php><env>)
if (file_exists(PASSWAY_ROOT . '/.env')) {
    $dotenv = Dotenv\Dotenv::createMutable(PASSWAY_ROOT);
    $dotenv->safeLoad();
}
