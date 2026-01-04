# OJS-CLI Implementation Plan

## Executive Summary

Build a standalone CLI tool for OJS (Open Journal Systems) modeled after WordPress's WP-CLI. The tool will be an independent PHP application that can discover and connect to OJS installations, providing command-line management capabilities starting with plugin management.

**Primary Goal**: Create `ojs` command with `ojs plugin` subcommands (list, activate, deactivate, delete, install, upgrade).

**Key Decisions**:
- **Installation**: Global Composer package (`composer global require pkp/ojs-cli`)
- **Target Version**: OJS 3.5 (with modern CLI tools, traits, Laravel components)
- **Default Context**: Site-wide operations (apply to all journals unless `--context` specified)
- **Code Standards**: Follow OJS/PKP coding standards (PSR-2, OJS conventions)

---

## Architecture Overview

### Design Philosophy

Follow WP-CLI's proven architecture:
- **Standalone tool**: Separate from OJS core, connects to installations
- **Modular command system**: Hierarchical command structure (root → composite → subcommand)
- **Lazy loading**: Commands loaded on-demand for performance
- **Bootstrap pipeline**: Sequential initialization steps
- **Hook system**: Allow extensibility through events
- **No OJS modifications**: Tool works with existing OJS installations

### Key Architectural Patterns

1. **Bootstrap Pipeline Pattern**: Sequential initialization steps
2. **Command Registry Pattern**: Hierarchical command tree
3. **Lazy Loading**: Load commands only when needed
4. **Discovery Pattern**: Automatically find OJS installation
5. **Context Awareness**: Handle OJS multi-journal architecture

---

## Directory Structure

```
ojs-cli/
├── bin/
│   └── ojs                          # Shell wrapper entry point
├── php/
│   ├── boot.php                     # Initial bootstrap
│   ├── ojs-cli.php                  # Autoloader and constants
│   ├── bootstrap.php                # Bootstrap orchestrator
│   ├── class-ojs-cli.php           # Main OJS_CLI API class
│   ├── config-spec.php              # Configuration specification
│   ├── utils.php                    # Utility functions
│   ├── commands/                    # Built-in commands
│   │   ├── plugin.php              # Plugin command registration
│   │   └── help.php                # Help command
│   ├── src/                        # Command implementations
│   │   ├── Plugin_Command.php      # Plugin management
│   │   └── Help_Command.php        # Help display
│   └── OJS_CLI/
│       ├── Runner.php              # Command execution engine
│       ├── Configurator.php        # Config file handling
│       ├── Bootstrap/              # Bootstrap steps
│       │   ├── BootstrapStep.php  # Interface
│       │   ├── LoadUtilityFunctions.php
│       │   ├── DeclareMainClass.php
│       │   ├── ConfigureRunner.php
│       │   ├── InitializeLogger.php
│       │   ├── RegisterCommands.php
│       │   └── LoadOJSCore.php    # OJS bootstrap
│       └── Dispatcher/             # Command routing
│           ├── RootCommand.php
│           ├── CompositeCommand.php
│           ├── Subcommand.php
│           └── CommandFactory.php
├── composer.json                    # Dependencies
├── .ojs-cli/                       # User config directory
│   └── config.yml                  # User configuration
└── docs/
    ├── commands.md                 # Command reference
    └── architecture.md             # Architecture documentation
```

---

## OJS Discovery & Bootstrapping

### OJS Discovery Algorithm

```php
// In OJS_CLI/Runner.php
public function find_ojs_root(): ?string {
    // 1. Check --path argument
    if (!empty($this->config['path'])) {
        return $this->validate_ojs_path($this->config['path']);
    }

    // 2. Walk up directory tree from current location
    $dir = getcwd();
    while ($dir !== '/') {
        // Look for OJS markers
        if ($this->is_ojs_installation($dir)) {
            return $dir;
        }
        $dir = dirname($dir);
    }

    return null;
}

private function is_ojs_installation(string $path): bool {
    // Check for OJS-specific files
    $markers = [
        'index.php',
        'lib/pkp/includes/bootstrap.inc.php',
        'config.inc.php'
    ];

    foreach ($markers as $marker) {
        if (!file_exists("$path/$marker")) {
            return false;
        }
    }

    // Validate index.php contains OJS bootstrap
    $index_content = file_get_contents("$path/index.php");
    return strpos($index_content, 'lib/pkp/includes/bootstrap') !== false;
}
```

### OJS Bootstrap Process

**CRITICAL: Follow OJS Standard CLI Pattern** (from `/lib/pkp/classes/cliTool/CommandLineTool.php`)

```php
// In OJS_CLI/Bootstrap/LoadOJSCore.php
public function process(BootstrapState $state): void {
    $ojsRoot = $state->ojs_root;

    // 1. Fire before_ojs_load hook
    OJS_CLI::do_hook('before_ojs_load');

    // 2. Define INDEX_FILE_LOCATION constant (required by OJS)
    define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');

    // 3. Change to OJS directory
    chdir($ojsRoot);

    // 4. Load OJS bootstrap - returns Application instance
    $application = require_once './lib/pkp/includes/bootstrap.php';

    // 5. Fire after_ojs_load hook
    OJS_CLI::do_hook('after_ojs_load');

    // 6. Initialize CLI-specific OJS setup
    $this->initialize_ojs_for_cli($application);

    $state->application = $application;
}

private function initialize_ojs_for_cli($application): void {
    // Disable sessions (not needed in CLI)
    \PKP\core\PKPSessionGuard::disableSession();

    // Set up router (required even in CLI)
    $request = $application->getRequest();
    $router = new \APP\core\PageRouter();
    $router->setApplication($application);
    $request->setRouter($router);

    // Load generic plugins by default (OJS CLI standard)
    \PKP\plugins\PluginRegistry::loadCategory('generic');
}
```

**Note**: This follows the exact pattern used by existing OJS CLI tools. Do not reinvent the bootstrap process.

---

## Command Structure & Registration

### Command Hierarchy Example

```
RootCommand ('ojs')
├── CompositeCommand ('plugin')
│   ├── Subcommand ('list')
│   ├── Subcommand ('activate')
│   ├── Subcommand ('deactivate')
│   ├── Subcommand ('install')
│   ├── Subcommand ('delete')
│   └── Subcommand ('upgrade')
└── CompositeCommand ('help')
    └── Subcommand (dynamic per command)
```

### Command Registration Pattern

```php
// In php/commands/plugin.php
if (!class_exists('OJS_CLI')) {
    return;
}

require_once dirname(__FILE__) . '/../src/Plugin_Command.php';

OJS_CLI::add_command('plugin', 'OJS_Plugin_Command', [
    'shortdesc' => 'Manages plugins',
    'when' => 'after_ojs_load'  // Requires OJS to be loaded
]);
```

### Command Implementation Structure

```php
// In php/src/Plugin_Command.php
class OJS_Plugin_Command {

    /**
     * Lists all available plugins
     *
     * ## OPTIONS
     *
     * [--category=<category>]
     * : Filter by plugin category (generic, blocks, themes, etc.)
     *
     * [--context=<path>]
     * : Journal context path for context-specific plugins
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * [--status=<status>]
     * : Filter by status (active, inactive, all)
     * ---
     * default: all
     * options:
     *   - active
     *   - inactive
     *   - all
     * ---
     *
     * ## EXAMPLES
     *
     *   # List all plugins
     *   $ ojs plugin list
     *
     *   # List only active generic plugins
     *   $ ojs plugin list --category=generic --status=active
     *
     *   # List plugins in JSON format
     *   $ ojs plugin list --format=json
     *
     *   # List plugins for specific journal
     *   $ ojs plugin list --context=journal-path
     *
     * @when after_ojs_load
     */
    public function list_($args, $assoc_args) {
        $category = \OJS_CLI\Utils\get_flag_value($assoc_args, 'category');
        $context_path = \OJS_CLI\Utils\get_flag_value($assoc_args, 'context');
        $format = \OJS_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');
        $status_filter = \OJS_CLI\Utils\get_flag_value($assoc_args, 'status', 'all');

        // Get context ID if specified
        $context_id = $this->get_context_id($context_path);

        // Load plugins
        $plugins = $this->get_plugins($category, $context_id);

        // Format output
        $formatter = new \OJS_CLI\Formatter();
        $formatter->display($plugins, $format);
    }

    /**
     * Activates a plugin
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin name (e.g., customBlockManager)
     *
     * [--category=<category>]
     * : Plugin category
     * ---
     * default: generic
     * ---
     *
     * [--context=<path>]
     * : Journal context path (omit for site-wide)
     *
     * [--all-contexts]
     * : Activate for all journals
     *
     * ## EXAMPLES
     *
     *   # Activate plugin site-wide
     *   $ ojs plugin activate customBlockManager
     *
     *   # Activate for specific journal
     *   $ ojs plugin activate customBlockManager --context=my-journal
     *
     *   # Activate for all journals
     *   $ ojs plugin activate customBlockManager --all-contexts
     *
     * @when after_ojs_load
     */
    public function activate($args, $assoc_args) {
        $plugin_name = $args[0] ?? null;
        if (!$plugin_name) {
            OJS_CLI::error('Plugin name required');
        }

        $category = \OJS_CLI\Utils\get_flag_value($assoc_args, 'category', 'generic');
        $context_path = \OJS_CLI\Utils\get_flag_value($assoc_args, 'context');
        $all_contexts = isset($assoc_args['all-contexts']);

        // Handle bulk activation for all contexts
        if ($all_contexts) {
            $this->activate_for_all_contexts($plugin_name, $category);
            return;
        }

        $context_id = $this->resolve_context($context_path);

        // Load plugin
        $plugin = \PKP\plugins\PluginRegistry::loadPlugin($category, $plugin_name, $context_id);

        if (!$plugin) {
            OJS_CLI::error("Plugin not found: $category/$plugin_name");
        }

        // Check if plugin supports site-wide activation
        if ($context_id === \PKP\core\PKPApplication::SITE_CONTEXT_ID && !$plugin->isSitePlugin()) {
            OJS_CLI::warning(
                "Plugin '{$plugin_name}' is not a site-wide plugin.\n" .
                "Consider using --context=<journal-path> or --all-contexts instead."
            );
        }

        // Enable plugin - uses PluginSettingsDAO directly for reliability
        $this->set_plugin_enabled($plugin, true, $context_id);

        OJS_CLI::success("Plugin activated: $plugin_name");
    }

    /**
     * Enables or disables a plugin
     * Uses PluginSettingsDAO directly for most reliable results
     */
    private function set_plugin_enabled($plugin, $enabled, $context_id) {
        $plugin_name = $plugin->getName();

        // Clear cache first (critical!)
        \Illuminate\Support\Facades\Cache::forget("pluginSettings-{$context_id}-" . strtolower($plugin_name));

        // Use PluginSettingsDAO directly (most reliable method)
        $pluginSettingsDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');
        $pluginSettingsDao->updateSetting(
            $context_id,
            $plugin_name,
            'enabled',
            $enabled,
            'bool'
        );
    }

    /**
     * Deactivates a plugin
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin name
     *
     * [--category=<category>]
     * : Plugin category
     * ---
     * default: generic
     * ---
     *
     * [--context=<path>]
     * : Journal context path
     *
     * ## EXAMPLES
     *
     *   $ ojs plugin deactivate customBlockManager
     *
     * @when after_ojs_load
     */
    public function deactivate($args, $assoc_args) {
        // Similar to activate, but call setEnabled(false)
    }

    /**
     * Installs a plugin from archive or gallery
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin name from gallery OR path to plugin archive
     *
     * [--activate]
     * : Activate plugin after installation
     *
     * [--context=<path>]
     * : Journal context for activation
     *
     * ## EXAMPLES
     *
     *   # Install from gallery
     *   $ ojs plugin install customBlockManager
     *
     *   # Install from local file
     *   $ ojs plugin install /path/to/plugin.tar.gz
     *
     *   # Install and activate
     *   $ ojs plugin install customBlockManager --activate
     *
     * @when after_ojs_load
     */
    public function install($args, $assoc_args) {
        $plugin_source = $args[0] ?? null;
        if (!$plugin_source) {
            OJS_CLI::error('Plugin name or path required');
        }

        // Check if it's a file or gallery plugin
        if (file_exists($plugin_source)) {
            $this->install_from_file($plugin_source, $assoc_args);
        } else {
            $this->install_from_gallery($plugin_source, $assoc_args);
        }
    }

    /**
     * Deletes a plugin
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin name
     *
     * [--category=<category>]
     * : Plugin category
     * ---
     * default: generic
     * ---
     *
     * ## EXAMPLES
     *
     *   $ ojs plugin delete customBlockManager
     *
     * @when after_ojs_load
     */
    public function delete($args, $assoc_args) {
        // Implementation using PluginGridHandler pattern
    }

    /**
     * Upgrades a plugin
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin name to upgrade (or 'all' for all plugins)
     *
     * [--category=<category>]
     * : Plugin category (when upgrading single plugin)
     *
     * [--version=<version>]
     * : Specific version to upgrade to
     *
     * ## EXAMPLES
     *
     *   # Upgrade single plugin
     *   $ ojs plugin upgrade customBlockManager
     *
     *   # Upgrade all plugins
     *   $ ojs plugin upgrade all
     *
     * @when after_ojs_load
     */
    public function upgrade($args, $assoc_args) {
        // Implementation using PluginHelper
    }

    // Helper methods

    /**
     * Resolves context from user input (path or ID)
     * Supports: null (site-wide), numeric (ID), string (path)
     */
    private function resolve_context($context_arg) {
        // Site-wide (default)
        if ($context_arg === null) {
            return \PKP\core\PKPApplication::SITE_CONTEXT_ID; // null
        }

        // Context ID provided directly
        if (is_numeric($context_arg)) {
            return (int)$context_arg;
        }

        // Context path provided - look up journal
        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journal = $journal_dao->getByPath($context_arg);

        if (!$journal) {
            OJS_CLI::error(
                "Journal not found: {$context_arg}\n" .
                "Use 'ojs journal list' to see available journals."
            );
        }

        return $journal->getId();
    }

    /**
     * Activates plugin for all journals
     */
    private function activate_for_all_contexts($plugin_name, $category) {
        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true); // enabled journals only

        $results = [];
        while ($journal = $journals->next()) {
            $context_id = $journal->getId();

            try {
                $plugin = \PKP\plugins\PluginRegistry::loadPlugin($category, $plugin_name, $context_id);
                if ($plugin) {
                    $this->set_plugin_enabled($plugin, true, $context_id);
                    $results[] = [
                        'journal' => $journal->getPath(),
                        'status' => 'success'
                    ];
                } else {
                    $results[] = [
                        'journal' => $journal->getPath(),
                        'status' => 'error',
                        'message' => 'Plugin not found'
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'journal' => $journal->getPath(),
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        // Display summary table
        $this->display_bulk_results($results, "Activated '{$plugin_name}' for journals");
    }

    private function get_plugins($category, $context_id) {
        // Load all categories or specific category
        if ($category) {
            $categories = [$category];
        } else {
            $categories = \PKP\plugins\PluginRegistry::getCategories();
        }

        $all_plugins = [];
        $pluginSettingsDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');

        foreach ($categories as $cat) {
            // Load from disk to get ALL plugins (not just enabled)
            $plugins = \PKP\plugins\PluginRegistry::loadCategory($cat, false);

            foreach ($plugins as $plugin) {
                $plugin_name = $plugin->getName();

                // Check enabled status separately via PluginSettingsDAO
                // This is more reliable than calling $plugin->getEnabled()
                $enabled = $pluginSettingsDao->getSetting($context_id, $plugin_name, 'enabled');

                $all_plugins[] = [
                    'name' => $plugin_name,
                    'display_name' => $plugin->getDisplayName(),
                    'category' => $cat,
                    'version' => $this->get_plugin_version($cat, $plugin_name),
                    'enabled' => (bool)$enabled,
                    'description' => $plugin->getDescription()
                ];
            }
        }

        return $all_plugins;
    }

    /**
     * Gets plugin version from versions table
     */
    private function get_plugin_version($category, $plugin_name) {
        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $version = $versionDao->getCurrentVersion("plugins.{$category}", $plugin_name);
        return $version ? $version->getVersionString() : 'not installed';
    }
}
```

---

## Configuration Handling

### Configuration Specification

```php
// In php/config-spec.php
return [
    'path' => [
        'runtime' => '=<path>',
        'file' => '<path>',
        'desc' => 'Path to OJS installation root',
        'default' => null
    ],
    'context' => [
        'runtime' => '=<context>',
        'file' => '<context>',
        'desc' => 'Default journal context path',
        'default' => null
    ],
    'skip_plugins' => [
        'runtime' => '[=<plugins>]',
        'file' => '<list>',
        'multiple' => true,
        'desc' => 'Skip loading specific plugins during bootstrap',
        'default' => []
    ],
    'color' => [
        'runtime' => '=<when>',
        'file' => '<when>',
        'desc' => 'Colorize output (auto, always, never)',
        'default' => 'auto'
    ],
    'disabled_commands' => [
        'file' => '<list>',
        'desc' => 'List of commands to disable',
        'default' => []
    ]
];
```

### Configuration Files

**Global**: `~/.ojs-cli/config.yml`
```yaml
# Default OJS path
path: /var/www/ojs

# Default context
context: my-journal

# Disable color output
color: never

# Skip certain plugins during load
skip_plugins:
  - problematicPlugin
```

**Project**: `ojs-cli.yml` (in OJS root or current directory)
```yaml
# Project-specific overrides
path: ./
context: test-journal
```

---

## Entry Point Implementation

### Shell Wrapper

```bash
#!/usr/bin/env bash
# In bin/ojs

# Resolve symlinks to find actual ojs-cli location
SELF_PATH="$(cd -P -- "$(dirname -- "$0")" && pwd -P)/$(basename -- "$0")"
while [ -h "$SELF_PATH" ]; do
    DIR="$(dirname -- "$SELF_PATH")"
    SYM="$(readlink "$SELF_PATH")"
    SELF_PATH="$(cd "$DIR" && cd "$(dirname -- "$SYM")" && pwd)/$(basename -- "$SYM")"
done

# Use OJS_CLI_PHP env var or find php on PATH
php="${OJS_CLI_PHP:-$(command -v php)}"

# Check PHP version (require 8.1+)
php_version=$("$php" -r 'echo PHP_VERSION;')
required_version="8.1"

if ! "$php" -r "exit(version_compare(PHP_VERSION, '$required_version', '>=') ? 0 : 1);" 2>/dev/null; then
    echo "Error: PHP $required_version or higher is required. Found: $php_version" >&2
    exit 1
fi

# Execute boot.php
SCRIPT_PATH="$(dirname "$SELF_PATH")/../php/boot.php"
export OJS_CLI_PHP_USED="$php"
exec "$php" $OJS_CLI_PHP_ARGS "$SCRIPT_PATH" "$@"
```

### PHP Entry Point

```php
<?php
// In php/boot.php

// Check we're running in CLI
if (php_sapi_name() !== 'cli') {
    echo "Error: OJS-CLI must be run from command line\n";
    exit(1);
}

// Set error handling for CLI
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load main bootstrap
require_once __DIR__ . '/ojs-cli.php';
```

```php
<?php
// In php/ojs-cli.php

// Define constants
define('OJS_CLI_ROOT', dirname(__DIR__));
define('OJS_CLI_VERSION', '1.0.0');

// Load Composer autoloader
$autoloader = OJS_CLI_ROOT . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    echo "Error: Run 'composer install' first\n";
    exit(1);
}

// Load bootstrap
require_once __DIR__ . '/bootstrap.php';
```

---

## Bootstrap Pipeline

### Bootstrap Orchestrator

```php
<?php
// In php/bootstrap.php

use OJS_CLI\Bootstrap\BootstrapState;

// Define bootstrap steps in order
$bootstrap_steps = [
    'OJS_CLI\Bootstrap\LoadUtilityFunctions',
    'OJS_CLI\Bootstrap\DeclareMainClass',
    'OJS_CLI\Bootstrap\ConfigureRunner',
    'OJS_CLI\Bootstrap\InitializeLogger',
    'OJS_CLI\Bootstrap\RegisterFrameworkCommands',
    'OJS_CLI\Bootstrap\LoadOJSCore',          // OJS-specific
    'OJS_CLI\Bootstrap\RegisterDeferredCommands'
];

// Create bootstrap state
$state = new BootstrapState();
$state->argv = $argv ?? [];

// Execute each step
foreach ($bootstrap_steps as $step_class) {
    $step = new $step_class();
    $step->process($state);
}

// Run command
$state->runner->run($state->argv);
```

### Bootstrap Steps

Each step implements `BootstrapStep` interface:

```php
<?php
// In php/OJS_CLI/Bootstrap/BootstrapStep.php

namespace OJS_CLI\Bootstrap;

interface BootstrapStep {
    public function process(BootstrapState $state): void;
}
```

---

## Implementation Phases

### Phase 1: Core Framework (Week 1-2)

**Deliverables**:
- Directory structure setup
- Composer configuration
- Entry point (`bin/ojs`) working
- Bootstrap pipeline functioning
- Basic command dispatcher
- OJS discovery and bootstrap working

**Key Files**:
- `bin/ojs`
- `php/boot.php`
- `php/ojs-cli.php`
- `php/bootstrap.php`
- `php/class-ojs-cli.php`
- `php/OJS_CLI/Runner.php`
- `php/OJS_CLI/Bootstrap/*.php`

**Validation**: `ojs` command runs and discovers OJS installation

### Phase 2: Command System (Week 3)

**Deliverables**:
- Command registry pattern implemented
- Command factory for creating commands
- Help system working
- PHPDoc parsing for command documentation

**Key Files**:
- `php/OJS_CLI/Dispatcher/*.php`
- `php/src/Help_Command.php`
- `php/commands/help.php`

**Validation**: `ojs help` displays available commands

### Phase 3: Plugin List Command (Week 4)

**Deliverables**:
- `ojs plugin list` working
- Multiple output formats (table, JSON, CSV, YAML)
- Filtering by category, status, context
- Integration with PluginRegistry
- Proper enabled status detection via PluginSettingsDAO

**Key Files**:
- `php/src/Plugin_Command.php` (list_ method)
- `php/commands/plugin.php`
- `php/OJS_CLI/Formatter.php`

**Validation**:
- `ojs plugin list` shows all plugins
- `ojs plugin list --format=json` outputs JSON
- `ojs plugin list --status=active` filters correctly
- Enabled status matches database (verify with direct DB query)

### Phase 3.5: Plugin Info Command (Week 4.5)

**Deliverables**:
- `ojs plugin info <name>` shows detailed plugin information
- Display: name, category, version, enabled status, description, settings, dependencies
- Lower complexity than modify operations
- Validates plugin loading logic

**Key Files**:
- `php/src/Plugin_Command.php` (info method)

**Validation**:
- `ojs plugin info customBlockManager` shows full details
- Works for installed and not-installed plugins
- Shows context-specific information when --context provided

### Phase 4: Plugin Enable/Disable (Week 5)

**Deliverables**:
- `ojs plugin activate <name>` working
- `ojs plugin deactivate <name>` working
- Context-aware activation (site-wide vs journal-specific)
- Cache invalidation after enable/disable
- `--all-contexts` flag support
- Warning for non-site-wide plugins activated globally
- Proper error handling

**Key Files**:
- `php/src/Plugin_Command.php` (activate, deactivate, set_plugin_enabled methods)

**Critical Implementation Details**:
- Use PluginSettingsDAO directly (most reliable)
- Clear cache before updating: `Cache::forget("pluginSettings-{$contextId}-{$pluginName}")`
- Verify changes persist across requests

**Validation**:
- Activate plugin: `ojs plugin activate customBlockManager`
- Verify in database: `plugin_settings` table updated with enabled=1
- Verify cache cleared (check subsequent reads)
- Deactivate plugin: `ojs plugin deactivate customBlockManager`
- Test `--all-contexts` with multiple journals

### Phase 5: Plugin Install from Local File (Week 6)

**Deliverables**:
- `ojs plugin install <path>` from local tar.gz file
- Use PluginHelper::installPlugin() method
- Proper transaction handling (DB first, then filesystem)
- Cleanup on failure
- Optional activation after install

**Key Files**:
- `php/src/Plugin_Command.php` (install method for local files)
- Integration with PluginHelper

**Critical Implementation**:
```php
// Order matters: DB operations first (can rollback)
DB::beginTransaction();
try {
    // Install using PluginHelper
    $pluginHelper = new \PKP\plugins\PluginHelper();
    $version = $pluginHelper->installPlugin($archivePath, basename($archivePath));

    DB::commit();

    // Activate if requested (after commit)
    if ($activate) {
        $this->activate_plugin($version->getProduct(), $category, $context_id);
    }
} catch (Exception $e) {
    DB::rollback();
    // PluginHelper handles file cleanup
    OJS_CLI::error("Installation failed: " . $e->getMessage());
}
```

**Validation**:
- Install from file: `ojs plugin install /tmp/plugin.tar.gz`
- Verify files copied to plugins/ directory
- Verify version record in database
- Test rollback on failure (corrupted archive)

### Phase 6: Plugin Installation from Gallery (Week 7)

**Deliverables**:
- `ojs plugin install <plugin>` from PKP gallery
- Download plugin from gallery API
- Compatibility checking before download
- Version selection (latest compatible by default)
- Builds on Phase 5 (local install)

**Key Files**:
- `php/src/Plugin_Command.php` (install_from_gallery method)
- Integration with `PluginGalleryDAO`

**Implementation Pattern**:
```php
// 1. Query gallery for plugin
$pluginGalleryDao = DAORegistry::getDAO('PluginGalleryDAO');
$plugins = $pluginGalleryDao->getNewestCompatible(
    Application::get(),
    $category,
    $plugin_name
);

// 2. Check compatibility
if (!$plugins || !isset($plugins[$plugin_name])) {
    OJS_CLI::error("Plugin '{$plugin_name}' not found in gallery or not compatible");
}

// 3. Download to temp file
$download_url = $plugins[$plugin_name]->getDownloadUrl();
$temp_file = $this->download_plugin($download_url);

// 4. Install using local file method (from Phase 5)
$this->install_from_file($temp_file, $assoc_args);

// 5. Cleanup temp file
unlink($temp_file);
```

**Validation**:
- Install from gallery: `ojs plugin install customBlockManager`
- Verify compatibility checking works
- Test with non-existent plugin
- Verify files downloaded and installed

### Phase 7: Plugin Delete (Week 8)

**Deliverables**:
- `ojs plugin delete <name>` removes plugin
- Confirmation prompt before deletion
- Delete files from both locations (plugins/ and lib/pkp/plugins/)
- Disable version in database
- Remove plugin settings

**Key Files**:
- `php/src/Plugin_Command.php` (delete method)

**Implementation**:
```php
// 1. Confirm with user
if (!$force) {
    OJS_CLI::confirm("Delete plugin '{$plugin_name}'? This cannot be undone.");
}

// 2. Get version info
$versionDao = DAORegistry::getDAO('VersionDAO');
$version = $versionDao->getCurrentVersion("plugins.{$category}", $plugin_name);

// 3. Delete files
$fileManager = new \PKP\file\FileManager();
$baseDir = \PKP\core\Core::getBaseDir();
$fileManager->rmtree("{$baseDir}/plugins/{$category}/{$plugin_name}");
$fileManager->rmtree("{$baseDir}/lib/pkp/plugins/{$category}/{$plugin_name}");

// 4. Disable in database
$versionDao->disableVersion("plugins.{$category}", $plugin_name);

// 5. Clean settings (optional - may want to preserve)
// $pluginSettingsDao->deleteSettingsByPlugin($contextId, $plugin_name);
```

**Validation**:
- Delete plugin and verify files removed
- Verify version disabled in database
- Test with --force flag to skip confirmation

### Phase 8: Plugin Upgrade (Week 9)

**Deliverables**:
- `ojs plugin upgrade <name>` upgrades to latest compatible version
- `ojs plugin upgrade all` for bulk upgrades
- Use PluginHelper::upgradePlugin() method
- Version comparison to ensure upgrade not downgrade
- Backup settings before upgrade (optional)

**Key Files**:
- `php/src/Plugin_Command.php` (upgrade method)
- Integration with PluginHelper::upgradePlugin()

**Implementation**:
```php
// 1. Get current version
$versionDao = DAORegistry::getDAO('VersionDAO');
$current = $versionDao->getCurrentVersion("plugins.{$category}", $plugin_name);

// 2. Get available version from gallery
$pluginGalleryDao = DAORegistry::getDAO('PluginGalleryDAO');
$available = $pluginGalleryDao->getNewestCompatible(...);

// 3. Compare versions
if (version_compare($available->getVersion(), $current->getVersionString(), '<=')) {
    OJS_CLI::line("Plugin already at latest version");
    return;
}

// 4. Download new version
$temp_file = $this->download_plugin($available->getDownloadUrl());

// 5. Upgrade using PluginHelper
$pluginHelper = new \PKP\plugins\PluginHelper();
$version = $pluginHelper->upgradePlugin($category, $plugin_name, $temp_file, basename($temp_file));

// 6. Cleanup
unlink($temp_file);
```

**Validation**:
- Upgrade single plugin and verify new version
- Test `upgrade all` with multiple plugins
- Verify upgrade.xml runs if present
- Test version comparison (don't downgrade)

### Phase 9: Configuration & Polish (Week 10)

**Deliverables**:
- Configuration file support
- Color output
- Progress indicators
- Improved error messages
- Documentation

**Key Files**:
- `php/OJS_CLI/Configurator.php`
- `php/config-spec.php`
- `docs/*.md`

**Validation**:
- Config file loaded correctly
- Color output works
- All commands documented

### Phase 10: Testing & Packaging (Week 11)

**Deliverables**:
- Unit tests for core components
- Integration tests for plugin commands
- Installation instructions
- Package for distribution

**Key Files**:
- `tests/*.php`
- `README.md`
- `INSTALL.md`

**Validation**:
- All tests pass
- Installation works on fresh system

---

## Critical Technical Requirements

### Cache Invalidation (CRITICAL)

PluginSettingsDAO caches settings for 24 hours. **Must** clear cache when changing plugin status:

```php
use Illuminate\Support\Facades\Cache;

// Before updating enabled status
Cache::forget("pluginSettings-{$contextId}-" . strtolower($pluginName));
```

### Transaction Handling Pattern

**Order matters**: Database operations before filesystem (DB can rollback, files cannot)

```php
DB::beginTransaction();
try {
    // 1. Database operations (can rollback)
    $pluginSettingsDao->updateSetting(...);
    $versionDao->insertVersion(...);
    DB::commit();

    // 2. Filesystem operations (cleanup on failure)
    $fileManager->copyDir(...);
} catch (Exception $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollback();
    }
    // Cleanup any files created
    $fileManager->rmtree($tempPath);
    throw $e;
}
```

### Version Compatibility Checking

Always verify plugin compatibility with OJS version before install/upgrade:

```php
// From PluginGalleryDAO
$compatiblePlugins = $pluginGalleryDao->getNewestCompatible(
    Application::get(),
    $category,
    $searchString
);

// This automatically filters by OJS version
```

### PHP Extensions Required

Check for required extensions at bootstrap:

```php
$required = ['phar', 'curl', 'zip'];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        OJS_CLI::warning("PHP extension '{$ext}' not loaded. Some features may not work.");
    }
}
```

## Key Technical Considerations

### Context Handling

**Challenge**: OJS is multi-journal, CLI must handle context explicitly

**Solution**:
- Default to site-wide operations (`context_id = NULL`)
- Allow `--context=<path>` flag for journal-specific operations
- Provide `--all-contexts` flag to iterate all journals

```php
// Example: Enable plugin for all journals
if ($all_contexts) {
    $context_dao = Application::getContextDAO();
    $contexts = $context_dao->getAll();

    while ($context = $contexts->next()) {
        $plugin->setEnabled(true, $context->getId());
        OJS_CLI::log("Enabled for: " . $context->getPath());
    }
}
```

### Database Transactions

**Challenge**: Plugin operations modify database and filesystem

**Solution**:
- Wrap operations in transactions where possible
- Rollback on failure
- Verify filesystem operations before committing

```php
DB::beginTransaction();
try {
    // Install plugin files
    $version = $plugin_helper->installPlugin($path, $filename);

    // Enable if requested
    if ($activate) {
        $plugin->setEnabled(true);
    }

    DB::commit();
    OJS_CLI::success("Plugin installed");
} catch (Exception $e) {
    DB::rollback();
    // Clean up files
    OJS_CLI::error("Installation failed: " . $e->getMessage());
}
```

### Plugin Dependencies

**Challenge**: Plugins may depend on other plugins

**Solution** (future enhancement):
- Parse plugin manifests for dependencies
- Check dependencies before operations
- Install dependencies automatically

### Version Compatibility

**Challenge**: Plugins may not be compatible with all OJS versions

**Solution**:
- Check compatibility before installation
- Use PluginGalleryDAO to filter compatible versions
- Warn user if installing incompatible version

### Security

**Considerations**:
- Validate plugin archives before extraction
- Prevent path traversal attacks in archives
- Require confirmation for destructive operations (delete)
- Validate file permissions

---

## Output Formatting

### Table Format (Default)

```
+---------------------------+----------+---------+---------+
| Plugin                    | Category | Version | Status  |
+---------------------------+----------+---------+---------+
| customBlockManager        | generic  | 1.2.0.0 | active  |
| usageStats                | generic  | 1.0.0.0 | active  |
| announcementFeed          | blocks   | 1.0.0.0 | inactive|
+---------------------------+----------+---------+---------+
```

### JSON Format

```json
[
  {
    "name": "customBlockManager",
    "display_name": "Custom Block Manager",
    "category": "generic",
    "version": "1.2.0.0",
    "enabled": true,
    "description": "Manage custom sidebar blocks"
  }
]
```

### CSV Format

```csv
name,display_name,category,version,enabled,description
customBlockManager,"Custom Block Manager",generic,1.2.0.0,true,"Manage custom sidebar blocks"
```

### YAML Format

```yaml
- name: customBlockManager
  display_name: Custom Block Manager
  category: generic
  version: 1.2.0.0
  enabled: true
  description: Manage custom sidebar blocks
```

---

## Error Handling

### Error Types

1. **OJS Not Found**: Clear message with discovery tips
2. **Plugin Not Found**: List similar plugin names
3. **Permission Denied**: Suggest using sudo or fixing permissions
4. **Database Error**: Show query/connection details
5. **Archive Error**: Invalid archive format or corrupted file

### Error Messages

```php
// Good error message
OJS_CLI::error(
    "Plugin not found: customBlockManager\n" .
    "Did you mean one of these?\n" .
    "  - customBlocksManager\n" .
    "  - customBlock"
);

// With suggestion
OJS_CLI::error(
    "Could not connect to database.\n" .
    "Check config.inc.php database settings.\n" .
    "Error: " . $db_exception->getMessage()
);
```

---

## Documentation Structure

### User Documentation

1. **Installation Guide**: How to install ojs-cli
2. **Quick Start**: Common tasks walkthrough
3. **Command Reference**: All commands with examples
4. **Configuration Guide**: Config file options
5. **Troubleshooting**: Common issues and solutions

### Developer Documentation

1. **Architecture Overview**: System design
2. **Adding Commands**: How to create new commands
3. **Bootstrap Process**: How initialization works
4. **Testing Guide**: Running and writing tests
5. **Contributing**: Guidelines for contributions

---

## Dependencies (composer.json)

```json
{
    "name": "pkp/ojs-cli",
    "description": "Command-line interface for Open Journal Systems",
    "license": "GPL-3.0",
    "require": {
        "php": "^8.1",
        "symfony/console": "^6.0",
        "symfony/yaml": "^6.0",
        "mustangostang/spyc": "^0.6"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "squizlabs/php_codesniffer": "^3.7"
    },
    "autoload": {
        "psr-4": {
            "OJS_CLI\\": "php/OJS_CLI/"
        },
        "files": [
            "php/utils.php",
            "php/class-ojs-cli.php"
        ]
    },
    "bin": [
        "bin/ojs"
    ]
}
```

---

## Success Criteria

### Functional Requirements

- [ ] `ojs` command discovers OJS installation automatically
- [ ] `ojs` without arguments lists available commands
- [ ] `ojs help` displays help information
- [ ] `ojs plugin` without arguments shows plugin subcommands
- [ ] `ojs plugin --help` shows plugin command help
- [ ] `ojs plugin list` displays all plugins in table format
- [ ] `ojs plugin list --format=json` outputs JSON
- [ ] `ojs plugin activate <name>` enables a plugin
- [ ] `ojs plugin deactivate <name>` disables a plugin
- [ ] `ojs plugin install <name>` installs from gallery
- [ ] `ojs plugin install <path>` installs from file
- [ ] `ojs plugin delete <name>` removes a plugin
- [ ] `ojs plugin upgrade <name>` upgrades a plugin

### Quality Requirements

- [ ] Works with OJS 3.5 (primary target)
- [ ] Installable globally via Composer
- [ ] Handles missing OJS installation gracefully
- [ ] Context-aware operations (default: site-wide, override with --context)
- [ ] Clear error messages with actionable suggestions
- [ ] Consistent output formatting
- [ ] Configuration file support (~/.ojs-cli/config.yml)
- [ ] No modifications to OJS core required
- [ ] Well-documented commands (PHPDoc)
- [ ] Follows OJS/PKP coding standards (PSR-2)
- [ ] Unit test coverage > 70%

### User Experience

- [ ] Fast command execution (< 2 seconds for list)
- [ ] Progress indicators for long operations
- [ ] Colorized output (when appropriate)
- [ ] Helpful error messages
- [ ] Intuitive command structure
- [ ] Tab completion support (future)

---

## Future Enhancements

### Phase 2 Commands

1. **Journal Management**: `ojs journal create`, `list`, `delete`
2. **User Management**: `ojs user create`, `list`, `delete`, `role`
3. **Import/Export**: `ojs export articles`, `ojs import users`
4. **Maintenance**: `ojs cache clear`, `ojs db upgrade`
5. **Configuration**: `ojs config get`, `set`

### Advanced Features

1. **Plugin Packages**: Install multiple plugins at once
2. **Plugin Search**: Search PKP gallery from CLI
3. **Plugin Info**: Detailed plugin information
4. **Backup**: Backup before destructive operations
5. **Rollback**: Revert plugin upgrades
6. **Hooks**: Allow custom scripts at lifecycle events
7. **Scripting**: Non-interactive mode for automation
8. **Remote Management**: Manage OJS over SSH

---

## Critical Files to Create

### Must-Have for MVP

1. `bin/ojs` - Shell wrapper
2. `php/boot.php` - Entry point
3. `php/ojs-cli.php` - Autoloader
4. `php/bootstrap.php` - Bootstrap orchestrator
5. `php/class-ojs-cli.php` - Main API class
6. `php/utils.php` - Utility functions
7. `php/OJS_CLI/Runner.php` - Command runner
8. `php/OJS_CLI/Bootstrap/LoadOJSCore.php` - OJS bootstrap
9. `php/OJS_CLI/Dispatcher/RootCommand.php` - Command tree root
10. `php/OJS_CLI/Dispatcher/CommandFactory.php` - Command factory
11. `php/src/Plugin_Command.php` - Plugin command implementation
12. `php/commands/plugin.php` - Plugin command registration
13. `composer.json` - Dependencies

### Important for Polish

14. `php/config-spec.php` - Configuration spec
15. `php/OJS_CLI/Configurator.php` - Config handler
16. `php/OJS_CLI/Formatter.php` - Output formatter
17. `php/src/Help_Command.php` - Help system
18. `docs/commands.md` - Command reference
19. `README.md` - Project documentation

---

## Risk Mitigation

### Risk: OJS API Changes Between Versions

**Mitigation**:
- Test against OJS 3.3, 3.4, 3.5
- Use version detection and conditional logic
- Abstract OJS interactions behind adapters

### Risk: File Permission Issues

**Mitigation**:
- Check permissions before operations
- Provide clear error messages
- Document required permissions

### Risk: Database Inconsistencies

**Mitigation**:
- Use transactions
- Validate before commit
- Provide rollback capability

### Risk: Plugin Archive Security

**Mitigation**:
- Validate archive structure
- Prevent path traversal
- Check file types
- Scan for malicious code (future)

---

## Comparison with Existing Solutions

### vs. Current OJS CLI Tools

| Feature | OJS lib/pkp/tools | OJS-CLI |
|---------|-------------------|---------|
| Standalone | No | Yes |
| Plugin Install | Partial | Full |
| Plugin Enable/Disable | No | Yes |
| Plugin Delete | No | Yes |
| Output Formats | Text | Table/JSON/CSV/YAML |
| Context Aware | Limited | Full |
| Help System | Basic | Rich |
| Configuration | None | YAML files |

### vs. WP-CLI

| Feature | WP-CLI | OJS-CLI |
|---------|--------|---------|
| Auto-discovery | Yes | Yes |
| Plugin Management | Full | Full |
| Package System | Yes | Future |
| Remote Execution | Yes | Future |
| Extensibility | High | Medium (initially) |

---

## Critical OJS Files Reference

**Must study these files before implementation:**

1. `/lib/pkp/classes/cliTool/CommandLineTool.php` - OJS CLI bootstrap pattern (lines 37-72)
2. `/lib/pkp/classes/plugins/PluginRegistry.php` - Plugin loading (loadCategory, loadFromDisk vs loadFromDatabase)
3. `/lib/pkp/classes/plugins/PluginHelper.php` - Install/upgrade implementation (lines 105-241)
4. `/lib/pkp/classes/plugins/PluginSettingsDAO.php` - Enable/disable mechanism
5. `/lib/pkp/classes/plugins/LazyLoadPlugin.php` - Base plugin class with setEnabled patterns
6. `/lib/pkp/includes/bootstrap.php` - OJS application initialization
7. `/lib/pkp/tools/plugins.php` - Existing plugin CLI tool (reference)

---

## Next Steps After Approval

1. Create directory structure in `ojs-cli/` folder
2. Set up Composer project
3. Implement Phase 1 (Core Framework)
4. Create initial documentation
5. Begin Phase 2 (Command System)

Each phase will be developed iteratively with testing and validation before moving to the next phase.
