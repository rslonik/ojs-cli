<?php

namespace OJS_CLI\Bootstrap;

/**
 * Register Commands Bootstrap Step
 *
 * Registers built-in framework commands
 * (Plugin commands and others will be loaded later)
 */
class RegisterCommands implements BootstrapStep
{
    /**
     * Process this bootstrap step
     *
     * @param BootstrapState $state Bootstrap state
     */
    public function process(BootstrapState $state): void
    {
        // Load command files first (so classes are available)
        $this->load_commands();

        // Register help command
        \OJS_CLI::add_command('help', 'Help_Command', [
            'shortdesc' => 'Get help on OJS-CLI commands'
        ]);

        // Register version command
        \OJS_CLI::add_command('version', function ($args, $assoc_args) {
            \OJS_CLI::line('OJS-CLI version ' . OJS_CLI_VERSION);
        }, [
            'shortdesc' => 'Display OJS-CLI version'
        ]);

        // Register info command (shows OJS installation info)
        \OJS_CLI::add_command('info', function ($args, $assoc_args) use ($state) {
            \OJS_CLI::line('OJS-CLI Information:');
            \OJS_CLI::line('  Version: ' . OJS_CLI_VERSION);
            \OJS_CLI::line('  PHP Version: ' . PHP_VERSION);
            \OJS_CLI::line('  OJS Root: ' . ($state->ojs_root ?? '(not found)'));
            \OJS_CLI::line('  OJS Loaded: ' . ($state->ojs_loaded ? 'Yes' : 'No'));

            if ($state->ojs_loaded && $state->application) {
                try {
                    $version = $state->application->getCurrentVersion();
                    if ($version) {
                        \OJS_CLI::line('  OJS Version: ' . $version->getVersionString());
                    }
                } catch (\Exception $e) {
                    \OJS_CLI::line('  OJS Version: (unable to determine)');
                }
            }
        }, [
            'shortdesc' => 'Display system information'
        ]);

        // Register demo command (for testing hierarchical commands)
        \OJS_CLI::add_command('demo', 'Demo_Command', [
            'shortdesc' => 'Demo command with subcommands'
        ]);
    }

    /**
     * Load command files from php/commands/ and php/src/ directories
     */
    private function load_commands(): void
    {
        // Load from php/src/ (command implementations)
        $src_dir = OJS_CLI_ROOT . '/php/src';
        if (is_dir($src_dir)) {
            $src_files = glob($src_dir . '/*.php');
            foreach ($src_files as $file) {
                require_once $file;
            }
        }

        // Load from php/commands/ (command registrations)
        $commands_dir = OJS_CLI_ROOT . '/php/commands';
        if (is_dir($commands_dir)) {
            $command_files = glob($commands_dir . '/*.php');
            foreach ($command_files as $file) {
                require_once $file;
            }
        }
    }
}
