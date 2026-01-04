<?php

namespace OJS_CLI;

/**
 * Output Formatter
 *
 * Formats data for various output formats
 */
class Formatter
{
    /**
     * Display data in specified format
     *
     * @param array $data Data to display
     * @param string $format Format (table, json, csv, yaml)
     */
    public function display($data, $format)
    {
        switch ($format) {
            case 'json':
                $this->display_json($data);
                break;
            case 'csv':
                $this->display_csv($data);
                break;
            case 'yaml':
                $this->display_yaml($data);
                break;
            case 'table':
            default:
                $this->display_table($data);
                break;
        }
    }

    /**
     * Display as table
     *
     * @param array $data Data rows
     */
    private function display_table($data)
    {
        if (empty($data)) {
            \OJS_CLI::line('No items found.');
            return;
        }

        // Get headers from first row
        $headers = array_keys($data[0]);
        $widths = [];

        // Calculate column widths
        foreach ($headers as $header) {
            $widths[$header] = strlen($header);
        }

        foreach ($data as $row) {
            foreach ($row as $key => $value) {
                $len = strlen((string)$value);
                if ($len > $widths[$key]) {
                    $widths[$key] = $len;
                }
            }
        }

        // Print header
        $this->print_separator($widths);
        $this->print_row($headers, $widths, true);
        $this->print_separator($widths);

        // Print rows
        foreach ($data as $row) {
            $this->print_row($row, $widths);
        }

        $this->print_separator($widths);
    }

    /**
     * Print table separator
     *
     * @param array $widths Column widths
     */
    private function print_separator($widths)
    {
        \OJS_CLI::line('+' . implode('+', array_map(function ($w) {
            return str_repeat('-', $w + 2);
        }, $widths)) . '+');
    }

    /**
     * Print table row
     *
     * @param array $row Row data
     * @param array $widths Column widths
     * @param bool $is_header Is header row
     */
    private function print_row($row, $widths, $is_header = false)
    {
        $cells = [];
        foreach ($widths as $key => $width) {
            $value = $is_header ? $key : ($row[$key] ?? '');
            // Convert boolean to string
            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }
            $cells[] = ' ' . str_pad((string)$value, $width) . ' ';
        }
        \OJS_CLI::line('|' . implode('|', $cells) . '|');
    }

    /**
     * Display as JSON
     *
     * @param array $data Data
     */
    private function display_json($data)
    {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Display as CSV
     *
     * @param array $data Data
     */
    private function display_csv($data)
    {
        if (empty($data)) {
            return;
        }

        // Headers
        $headers = array_keys($data[0]);
        fputcsv(STDOUT, $headers);

        // Rows
        foreach ($data as $row) {
            // Convert booleans to strings
            $row = array_map(function ($value) {
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                return $value;
            }, $row);
            fputcsv(STDOUT, $row);
        }
    }

    /**
     * Display as YAML
     *
     * @param array $data Data
     */
    private function display_yaml($data)
    {
        // Simple YAML output (not using library)
        foreach ($data as $index => $item) {
            \OJS_CLI::line("- ");
            foreach ($item as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                \OJS_CLI::line("  {$key}: " . (is_string($value) ? "\"{$value}\"" : $value));
            }
        }
    }
}

// Alias for easier access
class_alias('OJS_CLI\\Formatter', 'OJS_CLI_Formatter');
