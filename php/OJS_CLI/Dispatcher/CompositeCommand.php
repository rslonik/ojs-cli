<?php

namespace OJS_CLI\Dispatcher;

/**
 * Composite Command
 *
 * A command that contains subcommands (e.g., 'ojs plugin')
 * Methods of the command class become subcommands
 */
class CompositeCommand
{
    /**
     * @var string Command name
     */
    private $name;

    /**
     * @var object|string Command class instance or class name
     */
    private $callable;

    /**
     * @var string|null Class name for deferred instantiation
     */
    private $class_name;

    /**
     * @var array Subcommands
     */
    private $subcommands = [];

    /**
     * @var string Short description
     */
    private $shortdesc;

    /**
     * @var string Long description
     */
    private $longdesc;

    /**
     * @var bool Whether this command requires OJS to be loaded
     */
    private $requires_ojs;

    /**
     * @var bool Whether the class has been initialized
     */
    private $initialized = false;

    /**
     * Constructor
     *
     * @param string $name Command name
     * @param object|string $callable Command class instance or class name
     * @param array $options Command options
     */
    public function __construct($name, $callable, $options = [])
    {
        $this->name = $name;
        $this->shortdesc = $options['shortdesc'] ?? '';
        $this->longdesc = $options['longdesc'] ?? '';
        $this->requires_ojs = ($options['when'] ?? '') === 'after_ojs_load';

        // Defer instantiation if command requires OJS
        if (is_string($callable) && class_exists($callable)) {
            if ($this->requires_ojs && !\OJS_CLI::is_ojs_loaded()) {
                // Defer instantiation - store class name only
                $this->class_name = $callable;
                $this->callable = null;
            } else {
                $this->callable = new $callable();
                $this->scan_class_methods(get_class($this->callable));
                $this->initialized = true;
            }
        } elseif (is_object($callable)) {
            $this->callable = $callable;
            $this->scan_class_methods(get_class($callable));
            $this->initialized = true;
        } else {
            $this->callable = $callable;
        }
    }

    /**
     * Ensure the command class is initialized
     *
     * @return bool True if initialized successfully, false if OJS required but not loaded
     */
    private function ensure_initialized()
    {
        if ($this->initialized) {
            return true;
        }

        // Check if OJS is required but not loaded
        if ($this->requires_ojs && !\OJS_CLI::is_ojs_loaded()) {
            return false;
        }

        // Now we can instantiate the class
        if ($this->class_name && class_exists($this->class_name)) {
            $this->callable = new $this->class_name();
            $this->scan_class_methods(get_class($this->callable));
            $this->initialized = true;
            return true;
        }

        return false;
    }

    /**
     * Scan class methods to create subcommands
     *
     * @param string $class_name Class name
     */
    private function scan_class_methods($class_name)
    {
        $reflection = new \ReflectionClass($class_name);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Skip magic methods and inherited methods
            if (strpos($method->name, '__') === 0) {
                continue;
            }

            if ($method->class !== $class_name) {
                continue;
            }

            // Method name becomes subcommand name
            $subcommand_name = $method->name;

            // Handle methods ending with _ (e.g., list_)
            if (substr($subcommand_name, -1) === '_') {
                $subcommand_name = substr($subcommand_name, 0, -1);
            }

            // Create subcommand
            $subcommand = new Subcommand(
                $subcommand_name,
                [$this->callable, $method->name],
                ['parent' => $this->name]
            );

            $this->subcommands[$subcommand_name] = $subcommand;
        }
    }

    /**
     * Find subcommand to execute
     *
     * @param array $args Remaining arguments
     * @return array ['command' => command, 'args' => remaining_args]
     */
    public function find_subcommand($args)
    {
        // No subcommand specified - show help (which handles OJS requirement gracefully)
        if (empty($args)) {
            return ['command' => $this, 'args' => []];
        }

        // Ensure class is initialized before looking for subcommands
        if (!$this->ensure_initialized()) {
            // OJS required but not loaded - show helpful error
            \OJS_CLI::error(
                "The '{$this->name}' command requires an OJS installation.\n\n" .
                "Please run this command from within an OJS installation directory,\n" .
                "or specify the path with: ojs --path=/path/to/ojs {$this->name} <subcommand>"
            );
        }

        $subcommand_name = $args[0];
        $remaining_args = array_slice($args, 1);

        // Check if subcommand exists
        if (!isset($this->subcommands[$subcommand_name])) {
            return ['command' => null, 'args' => [$subcommand_name] + $remaining_args];
        }

        $subcommand = $this->subcommands[$subcommand_name];

        return ['command' => $subcommand, 'args' => $remaining_args];
    }

    /**
     * Get command name
     *
     * @return string Command name
     */
    public function get_name()
    {
        return $this->name;
    }

    /**
     * Get short description
     *
     * @return string Short description
     */
    public function get_shortdesc()
    {
        return $this->shortdesc ?: 'Manage ' . $this->name;
    }

    /**
     * Get all subcommands
     *
     * @return array Subcommands
     */
    public function get_subcommands()
    {
        $this->ensure_initialized();
        return $this->subcommands;
    }

    /**
     * Check if this command requires OJS
     *
     * @return bool True if OJS is required
     */
    public function requires_ojs()
    {
        return $this->requires_ojs;
    }

    /**
     * Show usage for this composite command
     */
    public function show_usage()
    {
        \OJS_CLI::line('Usage: ojs ' . $this->name . ' <subcommand> [<args>]');
        \OJS_CLI::line('');

        if ($this->shortdesc) {
            \OJS_CLI::line($this->shortdesc);
            \OJS_CLI::line('');
        }

        // Check if OJS is required but not loaded
        if ($this->requires_ojs && !$this->initialized && !\OJS_CLI::is_ojs_loaded()) {
            \OJS_CLI::line(\OJS_CLI::colorize('Note:', 'yellow') . ' This command requires an OJS installation.');
            \OJS_CLI::line('Run from an OJS directory or use: ojs --path=/path/to/ojs ' . $this->name . ' <subcommand>');
            \OJS_CLI::line('');
            return;
        }

        $this->ensure_initialized();

        \OJS_CLI::line('Available subcommands:');

        if (empty($this->subcommands)) {
            \OJS_CLI::line('  (No subcommands registered)');
        } else {
            foreach ($this->subcommands as $name => $subcommand) {
                $desc = $subcommand->get_shortdesc();
                \OJS_CLI::line(sprintf('  %-20s %s', $name, $desc));
            }
        }

        \OJS_CLI::line('');
        \OJS_CLI::line("See 'ojs help " . $this->name . " <subcommand>' for more information.");
    }
}
