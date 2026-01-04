<?php

/**
 * OJS-CLI Utility Functions
 *
 * Global utility functions for OJS-CLI
 */

namespace OJS_CLI\Utils;

/**
 * Get value from associative array with default
 *
 * @param array $array Associative array
 * @param string $key Key to retrieve
 * @param mixed $default Default value if key not found
 * @return mixed Value or default
 */
function get_flag_value($array, $key, $default = null)
{
    return $array[$key] ?? $default;
}

/**
 * Check if running in debug mode
 *
 * @return bool True if debug mode enabled
 */
function is_debug()
{
    return defined('OJS_CLI_DEBUG') && OJS_CLI_DEBUG;
}

/**
 * Output debug message if debug mode enabled
 *
 * @param string $message Message to output
 */
function debug($message)
{
    if (is_debug()) {
        fwrite(STDERR, "[DEBUG] " . $message . "\n");
    }
}

/**
 * Normalize path (resolve .., ., remove trailing slash)
 *
 * @param string $path Path to normalize
 * @return string Normalized path
 */
function normalize_path($path)
{
    $path = str_replace('\\', '/', $path);
    $parts = array_filter(explode('/', $path), 'strlen');
    $absolutes = [];

    foreach ($parts as $part) {
        if ('.' == $part) {
            continue;
        }
        if ('..' == $part) {
            array_pop($absolutes);
        } else {
            $absolutes[] = $part;
        }
    }

    $normalized = implode('/', $absolutes);

    // Restore leading slash for absolute paths
    if (substr($path, 0, 1) === '/') {
        $normalized = '/' . $normalized;
    }

    return $normalized;
}

/**
 * Resolve relative path to absolute path
 *
 * @param string $path Path to resolve
 * @return string|false Absolute path or false on failure
 */
function resolve_path($path)
{
    // Already absolute
    if (substr($path, 0, 1) === '/') {
        return normalize_path($path);
    }

    // Resolve relative to current directory
    $absolute = getcwd() . '/' . $path;
    return normalize_path($absolute);
}

/**
 * Check if path is absolute
 *
 * @param string $path Path to check
 * @return bool True if absolute
 */
function is_absolute_path($path)
{
    return substr($path, 0, 1) === '/';
}

/**
 * Format bytes to human-readable string
 *
 * @param int $bytes Number of bytes
 * @param int $precision Decimal precision
 * @return string Formatted string
 */
function format_bytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Parse command line arguments into args and assoc_args
 *
 * @param array $argv Command line arguments
 * @return array Array with ['args' => [...], 'assoc_args' => [...]]
 */
function parse_args($argv)
{
    $args = [];
    $assoc_args = [];

    foreach ($argv as $arg) {
        // --flag or --key=value
        if (preg_match('/^--([^=]+)(?:=(.*))?$/', $arg, $matches)) {
            $key = $matches[1];
            $value = isset($matches[2]) ? $matches[2] : true;
            $assoc_args[$key] = $value;
        }
        // Positional argument
        else {
            $args[] = $arg;
        }
    }

    return ['args' => $args, 'assoc_args' => $assoc_args];
}
