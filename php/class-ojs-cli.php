<?php

/**
 * Main OJS_CLI class
 *
 * Central API class for OJS-CLI. Provides static methods for:
 * - Output (success, error, warning, log, line)
 * - Halting execution
 * - Command registration (future)
 * - Hook system (future)
 */
class OJS_CLI
{
    /**
     * @var \OJS_CLI\Dispatcher\RootCommand Root command
     */
    private static $root_command = null;

    /**
     * @var array Registered commands (legacy)
     */
    private static $commands = [];

    /**
     * @var array Registered hooks
     */
    private static $hooks = [];

    /**
     * @var bool Whether colorization is enabled
     */
    private static $colorize = true;

    /**
     * @var bool Whether OJS has been loaded
     */
    private static $ojs_loaded = false;

    /**
     * Output a success message
     *
     * @param string $message Message to output
     */
    public static function success($message)
    {
        self::line(self::colorize('Success: ', 'green') . $message);
    }

    /**
     * Output an error message and exit
     *
     * @param string $message Error message
     * @param int $exit_code Exit code (default: 1)
     */
    public static function error($message, $exit_code = 1)
    {
        fwrite(STDERR, self::colorize('Error: ', 'red') . $message . "\n");
        exit($exit_code);
    }

    /**
     * Output a warning message
     *
     * @param string $message Warning message
     */
    public static function warning($message)
    {
        fwrite(STDERR, self::colorize('Warning: ', 'yellow') . $message . "\n");
    }

    /**
     * Output a log/info message
     *
     * @param string $message Log message
     */
    public static function log($message)
    {
        self::line($message);
    }

    /**
     * Output a plain line
     *
     * @param string $message Message to output
     */
    public static function line($message = '')
    {
        echo $message . "\n";
    }

    /**
     * Output multiple lines
     *
     * @param array $lines Array of lines to output
     */
    public static function lines($lines)
    {
        foreach ($lines as $line) {
            self::line($line);
        }
    }

    /**
     * Colorize text for terminal output
     *
     * @param string $text Text to colorize
     * @param string $color Color name (red, green, yellow, blue, etc.)
     * @return string Colorized text
     */
    public static function colorize($text, $color)
    {
        if (!self::$colorize) {
            return $text;
        }

        $colors = [
            'red'     => "\033[31m",
            'green'   => "\033[32m",
            'yellow'  => "\033[33m",
            'blue'    => "\033[34m",
            'magenta' => "\033[35m",
            'cyan'    => "\033[36m",
            'white'   => "\033[37m",
            'reset'   => "\033[0m",
        ];

        $color_code = $colors[$color] ?? $colors['reset'];
        return $color_code . $text . $colors['reset'];
    }

    /**
     * Enable or disable colorization
     *
     * @param bool $enabled True to enable, false to disable
     */
    public static function set_colorize($enabled)
    {
        self::$colorize = (bool)$enabled;
    }

    /**
     * Get or create root command
     *
     * @return \OJS_CLI\Dispatcher\RootCommand Root command
     */
    public static function get_root_command()
    {
        if (self::$root_command === null) {
            self::$root_command = new \OJS_CLI\Dispatcher\RootCommand('ojs');
        }
        return self::$root_command;
    }

    /**
     * Register a command
     *
     * @param string $name Command name
     * @param callable|string $callable Command implementation
     * @param array $options Command options
     */
    public static function add_command($name, $callable, $options = [])
    {
        // Store in legacy array for backwards compatibility
        self::$commands[$name] = [
            'callable' => $callable,
            'options' => $options
        ];

        // Create command using factory and add to root
        $command = \OJS_CLI\Dispatcher\CommandFactory::create($name, $callable, $options);
        self::get_root_command()->add_subcommand($name, $command);
    }

    /**
     * Get registered commands
     *
     * @return array Registered commands
     */
    public static function get_commands()
    {
        return self::$commands;
    }

    /**
     * Register a hook callback
     *
     * @param string $hook Hook name
     * @param callable $callback Callback function
     */
    public static function add_hook($hook, $callback)
    {
        if (!isset(self::$hooks[$hook])) {
            self::$hooks[$hook] = [];
        }
        self::$hooks[$hook][] = $callback;
    }

    /**
     * Execute hook callbacks
     *
     * @param string $hook Hook name
     * @param mixed ...$args Arguments to pass to callbacks
     */
    public static function do_hook($hook, ...$args)
    {
        if (isset(self::$hooks[$hook])) {
            foreach (self::$hooks[$hook] as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }

    /**
     * Display usage/help information
     */
    public static function usage()
    {
        self::get_root_command()->show_usage();
    }

    /**
     * Set whether OJS has been loaded
     *
     * @param bool $loaded True if OJS is loaded
     */
    public static function set_ojs_loaded($loaded)
    {
        self::$ojs_loaded = (bool)$loaded;
    }

    /**
     * Check whether OJS has been loaded
     *
     * @return bool True if OJS is loaded
     */
    public static function is_ojs_loaded()
    {
        return self::$ojs_loaded;
    }
}
