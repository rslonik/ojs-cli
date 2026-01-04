<?php

/**
 * OJS-CLI Bootstrap Orchestrator
 *
 * Executes bootstrap steps in sequence
 */

use OJS_CLI\Bootstrap\BootstrapState;

// Create bootstrap state
$state = new BootstrapState();
// Remove script name from argv (first element)
$cli_argv = $argv ?? [];
array_shift($cli_argv); // Remove script name
$state->argv = $cli_argv;

// Define bootstrap steps in order
$bootstrap_steps = [
    'OJS_CLI\Bootstrap\ConfigureRunner',       // Parse config, find OJS, create Runner
    'OJS_CLI\Bootstrap\LoadOJSCore',           // Load OJS if found
    'OJS_CLI\Bootstrap\RegisterCommands',      // Register built-in commands
];

// Execute each step
foreach ($bootstrap_steps as $step_class) {
    if (!class_exists($step_class)) {
        fwrite(STDERR, "Error: Bootstrap step class not found: $step_class\n");
        exit(1);
    }

    try {
        $step = new $step_class();
        $step->process($state);
    } catch (Exception $e) {
        fwrite(STDERR, "Error in bootstrap step $step_class: " . $e->getMessage() . "\n");
        if (OJS_CLI_DEBUG) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
        exit(1);
    }
}

// Run command
if ($state->runner) {
    $state->runner->run($state->argv);
} else {
    fwrite(STDERR, "Error: Runner not initialized\n");
    exit(1);
}
