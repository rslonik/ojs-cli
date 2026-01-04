<?php

namespace OJS_CLI\Dispatcher;

/**
 * Subcommand
 *
 * A leaf command that actually executes (e.g., 'ojs plugin list')
 */
class Subcommand
{
    /**
     * @var string Subcommand name
     */
    private $name;

    /**
     * @var callable Command implementation
     */
    private $callable;

    /**
     * @var string Parent command name
     */
    private $parent;

    /**
     * @var string Short description
     */
    private $shortdesc;

    /**
     * @var string Long description
     */
    private $longdesc;

    /**
     * @var array PHPDoc parsed data
     */
    private $phpdoc;

    /**
     * Constructor
     *
     * @param string $name Subcommand name
     * @param callable $callable Command implementation
     * @param array $options Command options
     */
    public function __construct($name, $callable, $options = [])
    {
        $this->name = $name;
        $this->callable = $callable;
        $this->parent = $options['parent'] ?? '';
        $this->shortdesc = $options['shortdesc'] ?? '';
        $this->longdesc = $options['longdesc'] ?? '';

        // Parse PHPDoc if callable is a method
        if (is_array($callable) && count($callable) === 2) {
            $this->parse_phpdoc($callable[0], $callable[1]);
        }
    }

    /**
     * Parse PHPDoc from method
     *
     * @param object|string $class Class instance or name
     * @param string $method Method name
     */
    private function parse_phpdoc($class, $method)
    {
        try {
            $reflection = new \ReflectionMethod($class, $method);
            $docblock = $reflection->getDocComment();

            if ($docblock) {
                $this->phpdoc = $this->parse_docblock($docblock);

                // Extract short description if not set
                if (!$this->shortdesc && isset($this->phpdoc['shortdesc'])) {
                    $this->shortdesc = $this->phpdoc['shortdesc'];
                }

                if (!$this->longdesc && isset($this->phpdoc['longdesc'])) {
                    $this->longdesc = $this->phpdoc['longdesc'];
                }
            }
        } catch (\ReflectionException $e) {
            // Method doesn't exist or not accessible
        }
    }

    /**
     * Parse docblock into structured data
     *
     * @param string $docblock Raw docblock
     * @return array Parsed data
     */
    private function parse_docblock($docblock)
    {
        $parsed = [
            'shortdesc' => '',
            'longdesc' => '',
            'synopsis' => [],
            'examples' => [],
        ];

        // Remove comment markers
        $lines = explode("\n", $docblock);
        $clean_lines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/?\*+\/?/', '', $line);
            $line = trim($line);
            if ($line) {
                $clean_lines[] = $line;
            }
        }

        $current_section = 'shortdesc';
        $section_buffer = [];

        foreach ($clean_lines as $line) {
            // Check for section markers
            if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
                // Save previous section
                if (!empty($section_buffer)) {
                    $this->save_section($parsed, $current_section, $section_buffer);
                    $section_buffer = [];
                }

                $section_name = strtoupper(trim($matches[1]));
                $current_section = $section_name;
                continue;
            }

            // Add to current section
            $section_buffer[] = $line;
        }

        // Save final section
        if (!empty($section_buffer)) {
            $this->save_section($parsed, $current_section, $section_buffer);
        }

        return $parsed;
    }

    /**
     * Save parsed section
     *
     * @param array &$parsed Parsed data array
     * @param string $section Section name
     * @param array $lines Section lines
     */
    private function save_section(&$parsed, $section, $lines)
    {
        $content = implode("\n", $lines);

        switch ($section) {
            case 'shortdesc':
                $parsed['shortdesc'] = trim($content);
                break;

            case 'OPTIONS':
                $parsed['synopsis'] = $this->parse_synopsis($lines);
                break;

            case 'EXAMPLES':
                $parsed['examples'] = $lines;
                break;

            default:
                if (!isset($parsed['sections'])) {
                    $parsed['sections'] = [];
                }
                $parsed['sections'][$section] = $content;
        }
    }

    /**
     * Parse synopsis/options from lines
     *
     * @param array $lines Option lines
     * @return array Parsed options
     */
    private function parse_synopsis($lines)
    {
        $options = [];
        $current_option = null;

        foreach ($lines as $line) {
            // Option definition (e.g., <plugin>, [--flag=<value>])
            if (preg_match('/^(<[^>]+>|\[--[^\]]+\])/', $line, $matches)) {
                if ($current_option) {
                    $options[] = $current_option;
                }

                $current_option = [
                    'synopsis' => $matches[1],
                    'description' => '',
                    'options' => []
                ];
                continue;
            }

            // Description line (starts with :)
            if (preg_match('/^:\s*(.+)$/', $line, $matches)) {
                if ($current_option) {
                    $current_option['description'] = $matches[1];
                }
                continue;
            }

            // Additional metadata
            if ($current_option && trim($line)) {
                $current_option['description'] .= ' ' . trim($line);
            }
        }

        if ($current_option) {
            $options[] = $current_option;
        }

        return $options;
    }

    /**
     * Invoke the command
     *
     * @param array $args Positional arguments
     * @param array $assoc_args Associative arguments
     */
    public function invoke($args, $assoc_args)
    {
        // If callable is a string class name, instantiate it
        if (is_string($this->callable) && class_exists($this->callable)) {
            $instance = new $this->callable();
            if (method_exists($instance, '__invoke')) {
                $instance($args, $assoc_args);
                return;
            }
        }

        // Try to call directly
        if (is_callable($this->callable)) {
            call_user_func($this->callable, $args, $assoc_args);
        } else {
            \OJS_CLI::error("Command is not callable: " . $this->name);
        }
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
     * Get parent command name
     *
     * @return string Parent command name
     */
    public function get_parent()
    {
        return $this->parent;
    }

    /**
     * Get short description
     *
     * @return string Short description
     */
    public function get_shortdesc()
    {
        return $this->shortdesc ?: 'No description';
    }

    /**
     * Get long description
     *
     * @return string Long description
     */
    public function get_longdesc()
    {
        return $this->longdesc;
    }

    /**
     * Get full command path (e.g., 'plugin list')
     *
     * @return string Full command path
     */
    public function get_full_name()
    {
        if ($this->parent) {
            return $this->parent . ' ' . $this->name;
        }
        return $this->name;
    }

    /**
     * Show usage/help for this command
     */
    public function show_usage()
    {
        $full_name = $this->get_full_name();

        \OJS_CLI::line('NAME');
        \OJS_CLI::line('');
        \OJS_CLI::line('  ojs ' . $full_name);
        \OJS_CLI::line('');

        if ($this->shortdesc) {
            \OJS_CLI::line('DESCRIPTION');
            \OJS_CLI::line('');
            \OJS_CLI::line('  ' . $this->shortdesc);
            \OJS_CLI::line('');
        }

        if ($this->phpdoc && !empty($this->phpdoc['synopsis'])) {
            \OJS_CLI::line('SYNOPSIS');
            \OJS_CLI::line('');
            foreach ($this->phpdoc['synopsis'] as $option) {
                \OJS_CLI::line('  ' . $option['synopsis']);
                if ($option['description']) {
                    \OJS_CLI::line('    ' . $option['description']);
                }
                \OJS_CLI::line('');
            }
        }

        if ($this->phpdoc && !empty($this->phpdoc['examples'])) {
            \OJS_CLI::line('EXAMPLES');
            \OJS_CLI::line('');
            foreach ($this->phpdoc['examples'] as $example) {
                \OJS_CLI::line('  ' . $example);
            }
            \OJS_CLI::line('');
        }
    }
}
