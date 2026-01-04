<?php

/**
 * Demo Command (for testing hierarchical commands)
 *
 * This is a demonstration of a CompositeCommand with subcommands.
 * Each public method becomes a subcommand.
 */
class Demo_Command
{
    /**
     * Say hello
     *
     * ## OPTIONS
     *
     * [<name>]
     * : Name to greet
     * ---
     * default: World
     * ---
     *
     * [--loud]
     * : Use uppercase
     *
     * ## EXAMPLES
     *
     *   # Simple greeting
     *   $ ojs demo hello
     *   Hello, World!
     *
     *   # Greet someone
     *   $ ojs demo hello John
     *   Hello, John!
     *
     *   # Loud greeting
     *   $ ojs demo hello John --loud
     *   HELLO, JOHN!
     */
    public function hello($args, $assoc_args)
    {
        $name = $args[0] ?? 'World';
        $loud = isset($assoc_args['loud']);

        $message = "Hello, $name!";

        if ($loud) {
            $message = strtoupper($message);
        }

        OJS_CLI::success($message);
    }

    /**
     * Say goodbye
     *
     * ## OPTIONS
     *
     * [<name>]
     * : Name to say goodbye to
     *
     * ## EXAMPLES
     *
     *   $ ojs demo goodbye
     *   Goodbye!
     *
     *   $ ojs demo goodbye World
     *   Goodbye, World!
     */
    public function goodbye($args, $assoc_args)
    {
        $name = $args[0] ?? '';
        $message = $name ? "Goodbye, $name!" : "Goodbye!";

        OJS_CLI::line($message);
    }

    /**
     * List items
     *
     * Demonstrates listing with different formats
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: list
     * options:
     *   - list
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *   $ ojs demo list
     *   $ ojs demo list --format=json
     */
    public function list_($args, $assoc_args)
    {
        $items = ['Apple', 'Banana', 'Cherry'];
        $format = $assoc_args['format'] ?? 'list';

        if ($format === 'json') {
            echo json_encode($items, JSON_PRETTY_PRINT) . "\n";
        } else {
            foreach ($items as $item) {
                OJS_CLI::line("  - $item");
            }
        }
    }
}
