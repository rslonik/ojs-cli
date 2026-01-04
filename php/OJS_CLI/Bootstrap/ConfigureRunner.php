<?php

namespace OJS_CLI\Bootstrap;

use OJS_CLI\Runner;
use OJS_CLI\Utils;

/**
 * Configure Runner Bootstrap Step
 *
 * Creates Runner instance, parses config, finds OJS installation
 */
class ConfigureRunner implements BootstrapStep
{
    /**
     * Process this bootstrap step
     *
     * @param BootstrapState $state Bootstrap state
     */
    public function process(BootstrapState $state): void
    {
        // Parse command line arguments for config
        $config = $this->parse_config($state->argv);

        // Configure colorization based on TTY detection
        $this->configure_colors();

        // Create runner
        $runner = new Runner($config);

        // Try to find OJS installation
        $ojs_root = $runner->find_ojs_root();

        if ($ojs_root) {
            $runner->set_ojs_root($ojs_root);
            $state->ojs_root = $ojs_root;
            Utils\debug("OJS found at: $ojs_root");
        } else {
            // No OJS found - some commands might work without it
            Utils\debug("No OJS installation found");
        }

        $state->runner = $runner;
        $state->config = $config;
    }

    /**
     * Configure color output based on environment
     */
    private function configure_colors(): void
    {
        // Auto-detect TTY for colorization
        // Disable colors if output is being piped or redirected
        $is_tty = function_exists('posix_isatty') && posix_isatty(STDOUT);
        \OJS_CLI::set_colorize($is_tty);
    }

    /**
     * Parse configuration from command line arguments
     *
     * @param array $argv Command line arguments
     * @return array Configuration array
     */
    private function parse_config($argv)
    {
        $config = [];

        foreach ($argv as $arg) {
            // --path=<path>
            if (preg_match('/^--path=(.+)$/', $arg, $matches)) {
                $config['path'] = $matches[1];
            }
            // --debug
            if ($arg === '--debug') {
                $config['debug'] = true;
            }
        }

        return $config;
    }
}
