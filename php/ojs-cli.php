<?php

/**
 * OJS-CLI Main Bootstrap
 *
 * Defines constants, loads Composer autoloader, and initiates bootstrap process.
 */

// Define version
define('OJS_CLI_VERSION', '1.0.0-dev');

// Define root directory
define('OJS_CLI_ROOT', dirname(__DIR__));

// Define debug mode from environment
define('OJS_CLI_DEBUG', getenv('OJS_CLI_DEBUG') === '1' || getenv('OJS_CLI_DEBUG') === 'true');

// Load Composer autoloader
$autoloader_paths = [
    OJS_CLI_ROOT . '/vendor/autoload.php',  // Local installation
    OJS_CLI_ROOT . '/../../autoload.php',   // Global composer install
];

$autoloader_loaded = false;
foreach ($autoloader_paths as $autoloader_path) {
    if (file_exists($autoloader_path)) {
        require_once $autoloader_path;
        $autoloader_loaded = true;
        break;
    }
}

if (!$autoloader_loaded) {
    fwrite(STDERR, "Error: Composer autoloader not found.\n");
    fwrite(STDERR, "Please run 'composer install' in " . OJS_CLI_ROOT . "\n");
    exit(1);
}

// Load bootstrap orchestrator
require_once __DIR__ . '/bootstrap.php';
