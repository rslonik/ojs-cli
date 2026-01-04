<?php

namespace OJS_CLI\Dispatcher;

/**
 * Root Command
 *
 * The root of the command tree. All commands are registered under this.
 * Represents the 'ojs' command itself.
 */
class RootCommand
{
    /**
     * @var string Command name
     */
    private $name;

    /**
     * @var array Registered subcommands
     */
    private $subcommands = [];

    /**
     * Constructor
     *
     * @param string $name Root command name (e.g., 'ojs')
     */
    public function __construct($name = 'ojs')
    {
        $this->name = $name;
    }

    /**
     * Add a subcommand
     *
     * @param string $name Subcommand name
     * @param mixed $command Command instance (CompositeCommand or Subcommand)
     */
    public function add_subcommand($name, $command)
    {
        $this->subcommands[$name] = $command;
    }

    /**
     * Get a subcommand by name
     *
     * @param string $name Subcommand name
     * @return mixed Command instance or null if not found
     */
    public function get_subcommand($name)
    {
        return $this->subcommands[$name] ?? null;
    }

    /**
     * Get all subcommands
     *
     * @return array All subcommands
     */
    public function get_subcommands()
    {
        return $this->subcommands;
    }

    /**
     * Check if subcommand exists
     *
     * @param string $name Subcommand name
     * @return bool True if exists
     */
    public function has_subcommand($name)
    {
        return isset($this->subcommands[$name]);
    }

    /**
     * Find command to run based on arguments
     *
     * @param array $args Command line arguments
     * @return array ['command' => command, 'args' => remaining_args]
     */
    public function find_command($args)
    {
        if (empty($args)) {
            return ['command' => $this, 'args' => []];
        }

        $command_name = $args[0];

        // Check if subcommand exists
        if (!$this->has_subcommand($command_name)) {
            return ['command' => null, 'args' => $args];
        }

        $command = $this->get_subcommand($command_name);
        $remaining_args = array_slice($args, 1);

        // If it's a CompositeCommand, let it find its subcommand
        if ($command instanceof CompositeCommand) {
            return $command->find_subcommand($remaining_args);
        }

        // It's a Subcommand, return it
        return ['command' => $command, 'args' => $remaining_args];
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
     * Show usage information
     */
    public function show_usage()
    {
        \OJS_CLI::line('OJS-CLI - Command-line interface for Open Journal Systems');
        \OJS_CLI::line('Version: ' . OJS_CLI_VERSION);
        \OJS_CLI::line('');
        \OJS_CLI::line('Usage: ' . $this->name . ' <command> [<subcommand>] [<args>] [--<flag>=<value>]');
        \OJS_CLI::line('');
        \OJS_CLI::line('Available commands:');

        if (empty($this->subcommands)) {
            \OJS_CLI::line('  (No commands registered yet)');
        } else {
            foreach ($this->subcommands as $name => $command) {
                $desc = $this->get_command_description($command);
                \OJS_CLI::line(sprintf('  %-20s %s', $name, $desc));
            }
        }

        \OJS_CLI::line('');
        \OJS_CLI::line("See '" . $this->name . " help <command>' for more information on a specific command.");
    }

    /**
     * Get command description
     *
     * @param mixed $command Command instance
     * @return string Description
     */
    private function get_command_description($command)
    {
        if (method_exists($command, 'get_shortdesc')) {
            return $command->get_shortdesc();
        }

        if ($command instanceof CompositeCommand) {
            return 'Manage ' . $command->get_name();
        }

        return 'No description';
    }
}
