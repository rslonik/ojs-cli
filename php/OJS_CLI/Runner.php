<?php

namespace OJS_CLI;

use OJS_CLI\Utils;

/**
 * Command Runner
 *
 * Responsible for:
 * - Finding OJS installation
 * - Parsing command line arguments
 * - Executing commands
 */
class Runner
{
    /**
     * @var array Configuration
     */
    private $config = [];

    /**
     * @var string|null Path to OJS root
     */
    private $ojs_root = null;

    /**
     * @var object|null OJS Application instance
     */
    private $application = null;

    /**
     * Constructor
     *
     * @param array $config Configuration array
     */
    public function __construct($config = [])
    {
        $this->config = $config;
    }

    /**
     * Set OJS root path
     *
     * @param string $path Path to OJS root
     */
    public function set_ojs_root($path)
    {
        $this->ojs_root = $path;
    }

    /**
     * Get OJS root path
     *
     * @return string|null OJS root path or null if not set
     */
    public function get_ojs_root()
    {
        return $this->ojs_root;
    }

    /**
     * Set OJS Application instance
     *
     * @param object $application OJS Application instance
     */
    public function set_application($application)
    {
        $this->application = $application;
    }

    /**
     * Get OJS Application instance
     *
     * @return object|null OJS Application instance or null if not set
     */
    public function get_application()
    {
        return $this->application;
    }

    /**
     * Find OJS installation root
     *
     * Searches for OJS installation by:
     * 1. Checking --path argument
     * 2. Walking up directory tree from current location
     *
     * @return string|null Path to OJS root or null if not found
     */
    public function find_ojs_root()
    {
        // 1. Check --path argument
        if (!empty($this->config['path'])) {
            $path = Utils\resolve_path($this->config['path']);
            if ($this->is_ojs_installation($path)) {
                Utils\debug("Found OJS via --path: $path");
                return $path;
            }
            \OJS_CLI::error("Specified path is not an OJS installation: {$this->config['path']}");
        }

        // 2. Walk up directory tree from current location
        $dir = getcwd();
        $max_depth = 10; // Prevent infinite loop
        $depth = 0;

        while ($dir !== '/' && $depth < $max_depth) {
            Utils\debug("Checking for OJS in: $dir");

            if ($this->is_ojs_installation($dir)) {
                Utils\debug("Found OJS at: $dir");
                return $dir;
            }

            $dir = dirname($dir);
            $depth++;
        }

        return null;
    }

    /**
     * Check if path is an OJS installation
     *
     * @param string $path Path to check
     * @return bool True if path is OJS installation
     */
    private function is_ojs_installation($path)
    {
        if (!is_dir($path)) {
            return false;
        }

        // Check for OJS marker files
        $markers = [
            'index.php',
            'lib/pkp/includes/bootstrap.php',
            'config.inc.php'
        ];

        foreach ($markers as $marker) {
            if (!file_exists($path . '/' . $marker)) {
                Utils\debug("Missing marker: $marker");
                return false;
            }
        }

        // Validate index.php contains OJS bootstrap
        $index_content = @file_get_contents($path . '/index.php');
        if ($index_content === false) {
            return false;
        }

        if (strpos($index_content, 'lib/pkp/includes/bootstrap') === false) {
            Utils\debug("index.php doesn't contain bootstrap reference");
            return false;
        }

        return true;
    }

    /**
     * Run the CLI application
     *
     * @param array $argv Command line arguments
     */
    public function run($argv)
    {
        // Parse arguments
        $parsed = Utils\parse_args($argv);
        $args = $parsed['args'];
        $assoc_args = $parsed['assoc_args'];

        // Handle no command
        if (empty($args)) {
            \OJS_CLI::usage();
            exit(0);
        }

        // Get root command and find the command to execute
        $root = \OJS_CLI::get_root_command();
        $result = $root->find_command($args);

        $command = $result['command'];
        $remaining_args = $result['args'];

        // No command found
        if ($command === null) {
            \OJS_CLI::error("Command not found: " . implode(' ', $args) . "\n\nRun 'ojs help' to see available commands.");
        }

        // If command is root or composite, show usage
        if ($command === $root || $command instanceof \OJS_CLI\Dispatcher\CompositeCommand) {
            $command->show_usage();
            exit(0);
        }

        // Execute subcommand
        if ($command instanceof \OJS_CLI\Dispatcher\Subcommand) {
            $parsed_remaining = Utils\parse_args($remaining_args);
            $command->invoke($parsed_remaining['args'], $assoc_args);
        } else {
            \OJS_CLI::error("Unknown command type");
        }
    }

}
