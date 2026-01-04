<?php

/**
 * Register Plugin Command
 */

if (!class_exists('OJS_CLI')) {
    return;
}

// Command will be loaded from src/ directory
OJS_CLI::add_command('plugin', 'Plugin_Command', [
    'shortdesc' => 'Manage OJS plugins',
    'when' => 'after_ojs_load'
]);
