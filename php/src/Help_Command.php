<?php

/**
 * Help Command
 *
 * Displays help information for commands
 */
class Help_Command
{
    /**
     * Get help for a command
     *
     * ## OPTIONS
     *
     * [<command>]
     * : Command to get help for
     *
     * [<subcommand>]
     * : Subcommand to get help for
     *
     * ## EXAMPLES
     *
     *   # Show general help
     *   $ ojs help
     *
     *   # Show help for a command
     *   $ ojs help plugin
     *
     *   # Show help for a subcommand
     *   $ ojs help plugin list
     */
    public function __invoke($args, $assoc_args)
    {
        $root = OJS_CLI::get_root_command();

        // No arguments - show general help
        if (empty($args)) {
            $root->show_usage();
            return;
        }

        // Get command name
        $command_name = array_shift($args);

        // Find the command
        $command = $root->get_subcommand($command_name);

        if (!$command) {
            OJS_CLI::error("Unknown command: $command_name\n\nRun 'ojs help' to see available commands.");
        }

        // No subcommand - show command help
        if (empty($args)) {
            if ($command instanceof \OJS_CLI\Dispatcher\CompositeCommand) {
                $command->show_usage();
            } elseif ($command instanceof \OJS_CLI\Dispatcher\Subcommand) {
                $command->show_usage();
            } else {
                OJS_CLI::line("Help for: $command_name");
            }
            return;
        }

        // Get subcommand
        if ($command instanceof \OJS_CLI\Dispatcher\CompositeCommand) {
            $subcommand_name = array_shift($args);
            $subcommands = $command->get_subcommands();

            if (!isset($subcommands[$subcommand_name])) {
                OJS_CLI::error("Unknown subcommand: $command_name $subcommand_name\n\nRun 'ojs help $command_name' to see available subcommands.");
            }

            $subcommand = $subcommands[$subcommand_name];
            $subcommand->show_usage();
        } else {
            OJS_CLI::warning("Command '$command_name' has no subcommands");
        }
    }
}
