<?php

namespace OJS_CLI\Bootstrap;

use OJS_CLI\Utils;

/**
 * Load OJS Core Bootstrap Step
 *
 * Loads OJS if installation was found
 * Follows OJS standard CLI pattern from CommandLineTool
 */
class LoadOJSCore implements BootstrapStep
{
    /**
     * Process this bootstrap step
     *
     * @param BootstrapState $state Bootstrap state
     */
    public function process(BootstrapState $state): void
    {
        // Skip if no OJS installation found
        if (!$state->ojs_root) {
            Utils\debug("Skipping OJS load - no installation found");
            return;
        }

        $ojsRoot = $state->ojs_root;

        Utils\debug("Loading OJS from: $ojsRoot");

        // Fire before_ojs_load hook
        \OJS_CLI::do_hook('before_ojs_load');

        try {
            // Define INDEX_FILE_LOCATION constant (required by OJS)
            if (!defined('INDEX_FILE_LOCATION')) {
                define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
            }

            // Change to OJS directory
            $original_dir = getcwd();
            chdir($ojsRoot);

            // Load OJS bootstrap - returns Application instance
            $application = require_once './lib/pkp/includes/bootstrap.php';

            // Fire after_ojs_load hook
            \OJS_CLI::do_hook('after_ojs_load');

            // Initialize CLI-specific OJS setup
            $this->initialize_ojs_for_cli($application);

            $state->application = $application;
            $state->ojs_loaded = true;

            // Set global OJS loaded flag for command checking
            \OJS_CLI::set_ojs_loaded(true);

            if ($state->runner) {
                $state->runner->set_application($application);
            }

            Utils\debug("OJS loaded successfully");
        } catch (\Exception $e) {
            \OJS_CLI::error("Failed to load OJS: " . $e->getMessage());
        }
    }

    /**
     * Initialize OJS for CLI usage
     *
     * @param object $application OJS Application instance
     */
    private function initialize_ojs_for_cli($application): void
    {
        // Disable sessions (not needed in CLI)
        if (class_exists('\\PKP\\core\\PKPSessionGuard')) {
            \PKP\core\PKPSessionGuard::disableSession();
            Utils\debug("Sessions disabled");
        }

        // Set up router (required even in CLI)
        $request = $application->getRequest();

        if (class_exists('\\APP\\core\\PageRouter')) {
            $router = new \APP\core\PageRouter();
            $router->setApplication($application);
            $request->setRouter($router);
            Utils\debug("Router configured");
        }

        // Load generic plugins by default (OJS CLI standard)
        if (class_exists('\\PKP\\plugins\\PluginRegistry')) {
            \PKP\plugins\PluginRegistry::loadCategory('generic');
            Utils\debug("Generic plugins loaded");
        }
    }
}
