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

        // If callable is a string class name, instantiate it
        if (is_string($callable) && class_exists($callable)) {
            $this->callable = new $callable();
            $this->scan_class_methods(get_class($this->callable));
        } elseif (is_object($callable)) {
            $this->callable = $callable;
            $this->scan_class_methods(get_class($callable));
        } else {
            $this->callable = $callable;
        }
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
        // No subcommand specified - show help
        if (empty($args)) {
            return ['command' => $this, 'args' => []];
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
        return $this->subcommands;
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
