<?php
declare(strict_types=1);

use craft\test\TestSetup;
use Dotenv\Dotenv;

ini_set('date.timezone', 'UTC');
error_reporting(E_ALL & ~E_DEPRECATED);

define('CRAFT_ENVIRONMENT', 'test');
define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_BASE_PATH', CRAFT_ROOT_PATH);
define('CRAFT_VENDOR_PATH', CRAFT_ROOT_PATH . '/vendor');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');

Dotenv::createImmutable(__DIR__)->safeLoad();

TestSetup::configureCraft();