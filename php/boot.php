#!/usr/bin/env php
<?php

/**
 * OJS-CLI Bootstrap Entry Point
 *
 * This file is the initial entry point for the OJS-CLI application.
 * It performs basic validation and loads the main bootstrap.
 */

// Check we're running in CLI mode
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    fwrite(STDERR, "Error: OJS-CLI must be run from command line\n");
    exit(1);
}

// Set error handling for CLI
// Only show errors, not warnings/deprecations unless debug mode
if (getenv('OJS_CLI_DEBUG')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ERROR | E_PARSE);
}

// Set up error handler for uncaught exceptions
set_exception_handler(function ($e) {
    fwrite(STDERR, "\nFatal Error: " . $e->getMessage() . "\n");
    if (defined('OJS_CLI_DEBUG') && OJS_CLI_DEBUG) {
        fwrite(STDERR, "\nStack trace:\n" . $e->getTraceAsString() . "\n");
    }
    exit(1);
});

// Load main bootstrap
require_once __DIR__ . '/ojs-cli.php';
