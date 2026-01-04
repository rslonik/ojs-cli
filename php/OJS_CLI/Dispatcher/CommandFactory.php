<?php

namespace OJS_CLI\Dispatcher;

/**
 * Command Factory
 *
 * Creates appropriate command objects from callables
 */
class CommandFactory
{
    /**
     * Create command from callable
     *
     * @param string $name Command name
     * @param callable|string $callable Command implementation
     * @param array $options Command options
     * @return CompositeCommand|Subcommand Command instance
     */
    public static function create($name, $callable, $options = [])
    {
        // If callable is a closure or function, create Subcommand
        if (is_callable($callable) && !is_array($callable) && !is_string($callable)) {
            return new Subcommand($name, $callable, $options);
        }

        // If callable is a class name, determine type
        if (is_string($callable)) {
            // Class doesn't exist - treat as simple callable
            if (!class_exists($callable)) {
                return new Subcommand($name, $callable, $options);
            }

            // Check if class has __invoke method
            if (method_exists($callable, '__invoke')) {
                return new Subcommand($name, $callable, $options);
            }

            // Class with public methods → CompositeCommand
            $reflection = new \ReflectionClass($callable);
            $public_methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            // Filter out magic methods and inherited methods
            $has_public_methods = false;
            foreach ($public_methods as $method) {
                if (strpos($method->name, '__') !== 0 && $method->class === $callable) {
                    $has_public_methods = true;
                    break;
                }
            }

            if ($has_public_methods) {
                return new CompositeCommand($name, $callable, $options);
            }
        }

        // If callable is an object, check for __invoke or create CompositeCommand
        if (is_object($callable)) {
            if (method_exists($callable, '__invoke')) {
                return new Subcommand($name, $callable, $options);
            }

            return new CompositeCommand($name, $callable, $options);
        }

        // If callable is [class, method] array, create Subcommand
        if (is_array($callable) && count($callable) === 2) {
            return new Subcommand($name, $callable, $options);
        }

        // Default: create Subcommand
        return new Subcommand($name, $callable, $options);
    }
}
