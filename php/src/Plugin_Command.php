<?php

/**
 * Plugin Command
 *
 * Manages OJS plugins
 */
class Plugin_Command
{
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
     */
    public function list_($args, $assoc_args)
    {
        $category = $assoc_args['category'] ?? null;
        $context_path = $assoc_args['context'] ?? null;
        $format = $assoc_args['format'] ?? 'table';
        $status_filter = $assoc_args['status'] ?? 'all';

        // Get context ID
        $context_id = $this->resolve_context($context_path);

        // Load plugins
        $plugins = $this->get_plugins($category, $context_id, $status_filter);

        // Format and display output
        $formatter = new \OJS_CLI\Formatter();
        $formatter->display($plugins, $format);
    }

    /**
     * Resolve context from path
     *
     * @param string|null $context_path Context path, 'site-wide' for site-wide, or null for auto-detect
     * @return int|null Context ID (null for site-wide)
     */
    private function resolve_context($context_path)
    {
        // Explicit site-wide
        if ($context_path === 'site-wide') {
            return \PKP\core\PKPApplication::SITE_CONTEXT_ID; // null
        }

        // Explicit journal path
        if ($context_path !== null) {
            $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
            $journal = $journal_dao->getByPath($context_path);

            if (!$journal) {
                OJS_CLI::error("Journal not found: {$context_path}");
            }

            return $journal->getId();
        }

        // Auto-detect: return null (will be resolved per-plugin)
        return null;
    }

    /**
     * Resolve context for a specific plugin
     *
     * @param object $plugin Plugin object
     * @param int|null $requested_context Requested context ID or null for auto
     * @return int|null Context ID
     */
    private function resolve_plugin_context($plugin, $requested_context)
    {
        // If context explicitly requested, use it
        if ($requested_context !== null) {
            return $requested_context;
        }

        // Check if plugin is truly site-wide by reading version.xml
        if ($this->is_plugin_sitewide($plugin)) {
            return \PKP\core\PKPApplication::SITE_CONTEXT_ID;
        }

        // Default to first available journal for journal-specific plugins
        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true);

        $first_journal = $journals->next();
        if (!$first_journal) {
            OJS_CLI::error("No journals found. Use --context=<journal-path> or create a journal first.");
        }

        return $first_journal->getId();
    }

    /**
     * Check if plugin is site-wide by reading version.xml
     *
     * @param object $plugin Plugin object
     * @return bool True if plugin is site-wide
     */
    private function is_plugin_sitewide($plugin)
    {
        try {
            $pluginPath = $plugin->getPluginPath();
            $versionFile = $pluginPath . '/version.xml';

            if (file_exists($versionFile)) {
                $versionInfo = \PKP\site\VersionCheck::parseVersionXML($versionFile);
                return !empty($versionInfo['sitewide']);
            }
        } catch (\Exception $e) {
            // Ignore errors reading version.xml
        }

        return false;
    }

    /**
     * Get plugins with filtering
     *
     * @param string|null $category Category filter
     * @param int|null $context_id Context ID
     * @param string $status_filter Status filter (active, inactive, all)
     * @return array Plugin data
     */
    private function get_plugins($category, $context_id, $status_filter)
    {
        // Get categories to load
        if ($category) {
            $categories = [$category];
        } else {
            $categories = \PKP\plugins\PluginRegistry::getCategories();
        }

        // Get all journal contexts
        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true);
        $journal_contexts = [];
        while ($journal = $journals->next()) {
            $journal_contexts[$journal->getId()] = $journal->getPath();
        }

        $all_plugins = [];

        foreach ($categories as $cat) {
            // Load from disk to get ALL plugins (not just enabled)
            $plugins = \PKP\plugins\PluginRegistry::loadCategory($cat, false);

            foreach ($plugins as $plugin) {
                // Use directory name as the external identifier (user-facing)
                $plugin_name = $plugin->getDirName();

                // Check where this plugin is enabled
                // Note: get_plugin_enabled_contexts uses getName() internally for PluginSettingsDAO
                $enabled_in = $this->get_plugin_enabled_contexts($plugin, $journal_contexts);

                // Determine if plugin matches status filter
                $is_enabled = !empty($enabled_in);
                if ($status_filter === 'active' && !$is_enabled) {
                    continue;
                }
                if ($status_filter === 'inactive' && $is_enabled) {
                    continue;
                }

                // Check if plugin is mandatory (cannot be disabled)
                $can_disable = $plugin->getCanDisable();
                $enabled_display = !$can_disable ? 'default' : ($is_enabled ? 'Yes' : 'No');

                // Check for updates
                $current_version = $this->get_plugin_version_string($plugin);
                $update_info = $this->check_plugin_update($plugin, $current_version);

                $all_plugins[] = [
                    'name' => $plugin_name,  // Directory name (e.g., "shariff")
                    'display_name' => $plugin->getDisplayName(),
                    'category' => $cat,
                    'version' => $current_version,
                    'enabled' => $enabled_display,
                    'enabled_in' => $enabled_in,
                    'update' => $update_info['status'],
                    'update_version' => $update_info['version']
                ];
            }
        }

        return $all_plugins;
    }

    /**
     * Get contexts where a plugin is enabled
     *
     * @param object $plugin Plugin object
     * @param array $journal_contexts Map of journal ID => path
     * @return string "site-wide", comma-separated context IDs, or empty string
     */
    private function get_plugin_enabled_contexts($plugin, $journal_contexts)
    {
        $plugin_name = $plugin->getName();
        $pluginSettingsDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');

        // Check site-wide first
        $site_enabled = (bool)$pluginSettingsDao->getSetting(
            \PKP\core\PKPApplication::SITE_CONTEXT_ID,
            $plugin_name,
            'enabled'
        );

        if ($site_enabled) {
            return 'site-wide';
        }

        // Check each journal context
        $enabled_contexts = [];
        foreach ($journal_contexts as $context_id => $path) {
            $enabled = (bool)$pluginSettingsDao->getSetting($context_id, $plugin_name, 'enabled');
            if ($enabled) {
                $enabled_contexts[] = $path;
            }
        }

        return empty($enabled_contexts) ? '' : implode(', ', $enabled_contexts);
    }

    /**
     * Show detailed information about a plugin
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
     * : Journal context path for context-specific information
     *
     * ## EXAMPLES
     *
     *   # Show info for a generic plugin
     *   $ ojs plugin info customBlockManager
     *
     *   # Show info for plugin in specific category
     *   $ ojs plugin info customBlockManager --category=generic
     *
     *   # Show context-specific info
     *   $ ojs plugin info customBlockManager --context=journal-path
     */
    public function info($args, $assoc_args)
    {
        $plugin_name = $args[0] ?? null;
        if (!$plugin_name) {
            OJS_CLI::error('Plugin name required');
        }

        $category = $assoc_args['category'] ?? null;
        $context_path = $assoc_args['context'] ?? null;
        $context_id = $this->resolve_context($context_path);

        // Use find_plugin helper with 4-strategy search (directory name preferred)
        $plugin_info = $this->find_plugin($plugin_name, $category);

        if (!$plugin_info) {
            if ($category) {
                OJS_CLI::error("Plugin not found: {$category}/{$plugin_name}");
            } else {
                OJS_CLI::error("Plugin not found: {$plugin_name}");
            }
        }

        $plugin = $plugin_info['plugin'];
        $found_category = $plugin_info['category'];
        $dir_name = $plugin->getDirName();  // External identifier (display to user)
        $class_name = $plugin->getName();   // Internal identifier (for PluginSettingsDAO)

        // Get plugin information
        $enabled = $plugin->getEnabled($context_id);
        $version = $this->get_plugin_version_string($plugin);
        $can_disable = $plugin->getCanDisable();

        // Display information
        OJS_CLI::line('');
        OJS_CLI::line('Plugin Information:');
        OJS_CLI::line('  Name:         ' . $dir_name);  // Show directory name
        OJS_CLI::line('  Display Name: ' . $plugin->getDisplayName());
        OJS_CLI::line('  Category:     ' . $found_category);
        OJS_CLI::line('  Version:      ' . $version);
        OJS_CLI::line('  Enabled:      ' . ($enabled ? 'Yes' : 'No'));
        OJS_CLI::line('  Mandatory:    ' . ($can_disable ? 'No' : 'Yes'));
        OJS_CLI::line('  Description:  ' . $plugin->getDescription());
        OJS_CLI::line('');

        // Show settings if available (PluginSettingsDAO requires class name)
        $pluginSettingsDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');
        $settings = $pluginSettingsDao->getPluginSettings($context_id, $class_name);
        if (!empty($settings)) {
            OJS_CLI::line('Settings:');
            foreach ($settings as $key => $value) {
                if ($key === 'enabled') {
                    continue; // Already shown above
                }
                $display_value = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                OJS_CLI::line('  ' . $key . ': ' . $display_value);
            }
            OJS_CLI::line('');
        }
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
     * : Plugin category (if known, for faster lookup)
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
     */
    public function activate($args, $assoc_args)
    {
        $plugin_name = $args[0] ?? null;
        if (!$plugin_name) {
            OJS_CLI::error('Plugin name required');
        }

        $category = $assoc_args['category'] ?? null;
        $context_path = $assoc_args['context'] ?? null;
        $all_contexts = isset($assoc_args['all-contexts']);

        // Handle bulk activation for all contexts
        if ($all_contexts) {
            $this->activate_for_all_contexts($plugin_name, $category);
            return;
        }

        $requested_context = $this->resolve_context($context_path);

        // Find the plugin
        $plugin_info = $this->find_plugin($plugin_name, $category);
        if (!$plugin_info) {
            OJS_CLI::error("Plugin not found: {$plugin_name}");
        }

        $found_category = $plugin_info['category'];
        $plugin = $plugin_info['plugin'];  // Plugin object already loaded by find_plugin

        // Resolve the actual context to use
        $context_id = $this->resolve_plugin_context($plugin, $requested_context);

        // Enable plugin using plugin object
        // Check if plugin supports context parameter (like BlockPlugin)
        $reflection = new \ReflectionMethod($plugin, 'setEnabled');
        $params = $reflection->getParameters();
        if (count($params) > 1) {
            // Plugin supports context parameter
            $plugin->setEnabled(true, $context_id);
        } else {
            // Fall back to updateSetting directly
            $plugin->updateSetting($context_id, 'enabled', true, 'bool');
        }

        // Display message
        if ($context_id === \PKP\core\PKPApplication::SITE_CONTEXT_ID) {
            OJS_CLI::success("Plugin activated: {$plugin_name} (site-wide)");
        } else {
            $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
            $journal = $journal_dao->getById($context_id);
            $journal_path = $journal ? $journal->getPath() : "context {$context_id}";
            OJS_CLI::success("Plugin activated: {$plugin_name} (journal: {$journal_path})");
        }
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
     * : Plugin category (if known, for faster lookup)
     *
     * [--context=<path>]
     * : Journal context path (omit for site-wide)
     *
     * [--all-contexts]
     * : Deactivate for all journals
     *
     * ## EXAMPLES
     *
     *   # Deactivate plugin site-wide
     *   $ ojs plugin deactivate customBlockManager
     *
     *   # Deactivate for specific journal
     *   $ ojs plugin deactivate customBlockManager --context=my-journal
     */
    public function deactivate($args, $assoc_args)
    {
        $plugin_name = $args[0] ?? null;
        if (!$plugin_name) {
            OJS_CLI::error('Plugin name required');
        }

        $category = $assoc_args['category'] ?? null;
        $context_path = $assoc_args['context'] ?? null;
        $all_contexts = isset($assoc_args['all-contexts']);

        // Handle bulk deactivation for all contexts
        if ($all_contexts) {
            $this->deactivate_for_all_contexts($plugin_name, $category);
            return;
        }

        $requested_context = $this->resolve_context($context_path);

        // Find the plugin
        $plugin_info = $this->find_plugin($plugin_name, $category);
        if (!$plugin_info) {
            OJS_CLI::error("Plugin not found: {$plugin_name}");
        }

        $found_category = $plugin_info['category'];
        $plugin = $plugin_info['plugin'];  // Plugin object already loaded by find_plugin

        // Check if plugin can be disabled
        if (!$plugin->getCanDisable()) {
            OJS_CLI::error("Plugin '{$plugin_name}' is mandatory and cannot be deactivated.");
        }

        // Resolve the actual context to use
        $context_id = $this->resolve_plugin_context($plugin, $requested_context);

        // Disable plugin using plugin object
        // Check if plugin supports context parameter (like BlockPlugin)
        $reflection = new \ReflectionMethod($plugin, 'setEnabled');
        $params = $reflection->getParameters();
        if (count($params) > 1) {
            // Plugin supports context parameter
            $plugin->setEnabled(false, $context_id);
        } else {
            // Fall back to updateSetting directly
            $plugin->updateSetting($context_id, 'enabled', false, 'bool');
        }

        // Display message
        if ($context_id === \PKP\core\PKPApplication::SITE_CONTEXT_ID) {
            OJS_CLI::success("Plugin deactivated: {$plugin_name} (site-wide)");
        } else {
            $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
            $journal = $journal_dao->getById($context_id);
            $journal_path = $journal ? $journal->getPath() : "context {$context_id}";
            OJS_CLI::success("Plugin deactivated: {$plugin_name} (journal: {$journal_path})");
        }
    }

    /**
     * Find a plugin by name
     *
     * Handles plugin name variations (e.g., "shariff" vs "shariffplugin")
     *
     * @param string $plugin_name Plugin name
     * @param string|null $category Optional category to search
     * @return array|null Plugin info or null if not found
     */
    /**
     * Find a plugin by directory name
     *
     * @param string $plugin_name Plugin directory name (e.g., "shariff", "hypothesis")
     * @param string|null $category Plugin category to search in (null = all categories)
     * @return array|null Array with 'name', 'category', 'plugin' or null if not found
     *
     * Search order:
     * 1. Exact match on directory name (e.g., "shariff")
     * 2. Case-insensitive match on directory name (e.g., "Shariff" → "shariff")
     */
    private function find_plugin($plugin_name, $category = null)
    {
        $categories = $category ? [$category] : \PKP\plugins\PluginRegistry::getCategories();

        // Strategy 1: Try exact match on directory name
        foreach ($categories as $cat) {
            $plugins = \PKP\plugins\PluginRegistry::loadCategory($cat, false);
            foreach ($plugins as $plugin) {
                $dir_name = $plugin->getDirName();
                if ($dir_name === $plugin_name) {
                    return [
                        'name' => $dir_name,
                        'category' => $cat,
                        'plugin' => $plugin
                    ];
                }
            }
        }

        // Strategy 2: Try case-insensitive match on directory name
        foreach ($categories as $cat) {
            $plugins = \PKP\plugins\PluginRegistry::loadCategory($cat, false);
            foreach ($plugins as $plugin) {
                $dir_name = $plugin->getDirName();
                if (strcasecmp($dir_name, $plugin_name) === 0) {
                    return [
                        'name' => $dir_name,
                        'category' => $cat,
                        'plugin' => $plugin
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Load plugin object
     *
     * @param string $category Plugin category
     * @param string $plugin_name Plugin name
     * @return object|null Plugin object or null
     */
    private function load_plugin_object($category, $plugin_name)
    {
        $plugins = \PKP\plugins\PluginRegistry::loadCategory($category, false);
        foreach ($plugins as $plugin) {
            if ($plugin->getName() === $plugin_name) {
                return $plugin;
            }
        }
        return null;
    }


    /**
     * Activates plugin for all journals
     *
     * @param string $plugin_name Plugin name
     * @param string|null $category Plugin category
     */
    private function activate_for_all_contexts($plugin_name, $category)
    {
        // Find the plugin to get category
        $plugin_info = $this->find_plugin($plugin_name, $category);
        if (!$plugin_info) {
            OJS_CLI::error("Plugin not found: {$plugin_name}");
        }
        $found_category = $plugin_info['category'];

        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true); // enabled journals only

        $results = [];
        while ($journal = $journals->next()) {
            $context_id = $journal->getId();

            try {
                $plugin = $this->load_plugin_object($found_category, $plugin_name);
                if ($plugin) {
                    // Use reflection to check if setEnabled accepts context parameter
                    $reflection = new \ReflectionMethod($plugin, 'setEnabled');
                    $params = $reflection->getParameters();
                    if (count($params) > 1) {
                        $plugin->setEnabled(true, $context_id);
                    } else {
                        $plugin->updateSetting($context_id, 'enabled', true, 'bool');
                    }
                    $results[] = [
                        'journal' => $journal->getPath(),
                        'status' => 'success'
                    ];
                } else {
                    $results[] = [
                        'journal' => $journal->getPath(),
                        'status' => 'error',
                        'message' => 'Failed to load plugin'
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

        // Display summary
        OJS_CLI::line('');
        OJS_CLI::line("Activated '{$plugin_name}' for journals:");
        foreach ($results as $result) {
            $status_icon = $result['status'] === 'success' ? OJS_CLI::colorize('✓', 'green') : OJS_CLI::colorize('✗', 'red');
            $message = $result['status'] === 'success' ? '' : ' - ' . $result['message'];
            OJS_CLI::line("  {$status_icon} {$result['journal']}{$message}");
        }
        OJS_CLI::line('');
    }

    /**
     * Deactivates plugin for all journals
     *
     * @param string $plugin_name Plugin name
     * @param string|null $category Plugin category
     */
    private function deactivate_for_all_contexts($plugin_name, $category)
    {
        // Find the plugin to get category
        $plugin_info = $this->find_plugin($plugin_name, $category);
        if (!$plugin_info) {
            OJS_CLI::error("Plugin not found: {$plugin_name}");
        }
        $found_category = $plugin_info['category'];

        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true); // enabled journals only

        $results = [];
        while ($journal = $journals->next()) {
            $context_id = $journal->getId();

            try {
                $plugin = $this->load_plugin_object($found_category, $plugin_name);
                if ($plugin) {
                    if (!$plugin->getCanDisable()) {
                        $results[] = [
                            'journal' => $journal->getPath(),
                            'status' => 'error',
                            'message' => 'Plugin is mandatory'
                        ];
                    } else {
                        // Use reflection to check if setEnabled accepts context parameter
                        $reflection = new \ReflectionMethod($plugin, 'setEnabled');
                        $params = $reflection->getParameters();
                        if (count($params) > 1) {
                            $plugin->setEnabled(false, $context_id);
                        } else {
                            $plugin->updateSetting($context_id, 'enabled', false, 'bool');
                        }
                        $results[] = [
                            'journal' => $journal->getPath(),
                            'status' => 'success'
                        ];
                    }
                } else {
                    $results[] = [
                        'journal' => $journal->getPath(),
                        'status' => 'error',
                        'message' => 'Failed to load plugin'
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

        // Display summary
        OJS_CLI::line('');
        OJS_CLI::line("Deactivated '{$plugin_name}' for journals:");
        foreach ($results as $result) {
            $status_icon = $result['status'] === 'success' ? OJS_CLI::colorize('✓', 'green') : OJS_CLI::colorize('✗', 'red');
            $message = $result['status'] === 'success' ? '' : ' - ' . $result['message'];
            OJS_CLI::line("  {$status_icon} {$result['journal']}{$message}");
        }
        OJS_CLI::line('');
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
     *   # Install from gallery and activate
     *   $ ojs plugin install customBlockManager --activate
     *
     *   # Install from local file
     *   $ ojs plugin install /path/to/plugin.tar.gz
     *
     *   # Install and activate
     *   $ ojs plugin install /path/to/plugin.tar.gz --activate
     *
     *   # Install and activate for specific context
     *   $ ojs plugin install /path/to/plugin.tar.gz --activate --context=journal-path
     */
    public function install($args, $assoc_args)
    {
        $plugin_source = $args[0] ?? null;
        if (!$plugin_source) {
            OJS_CLI::error('Plugin name or path required');
        }

        $activate = isset($assoc_args['activate']);
        $context_path = $assoc_args['context'] ?? null;
        $context_id = $this->resolve_context($context_path);

        // Check if it's a file
        if (file_exists($plugin_source)) {
            $this->install_from_file($plugin_source, $activate, $context_id);
        } else {
            // Install from gallery
            $this->install_from_gallery($plugin_source, $activate, $context_id);
        }
    }

    /**
     * Install plugin from local file
     *
     * @param string $file_path Path to plugin archive
     * @param bool $activate Activate after installation
     * @param int|null $context_id Context ID for activation
     */
    private function install_from_file($file_path, $activate, $context_id)
    {
        if (!file_exists($file_path)) {
            OJS_CLI::error("File not found: {$file_path}");
        }

        // Validate file is readable
        if (!is_readable($file_path)) {
            OJS_CLI::error("File not readable: {$file_path}");
        }

        OJS_CLI::log("Installing plugin from: {$file_path}");

        // Use PluginHelper to install
        $pluginHelper = new \PKP\plugins\PluginHelper();

        // Start transaction
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            // Install plugin (DB operations first)
            $version = $pluginHelper->installPlugin($file_path, basename($file_path));

            if (!$version) {
                throw new \Exception("Plugin installation failed - no version returned");
            }

            // Commit database changes
            \Illuminate\Support\Facades\DB::commit();

            // Get plugin info
            $product = $version->getProduct();
            $category = $version->getProductType();
            $version_string = $version->getVersionString();

            OJS_CLI::success("Plugin installed: {$product} (version {$version_string})");

            // Activate if requested
            if ($activate) {
                OJS_CLI::log("Activating plugin...");
                $plugin = $this->load_plugin_object($category, $product);
                if ($plugin) {
                    // Resolve context for activation
                    $activation_context = $this->resolve_plugin_context($plugin, $context_id);

                    // Enable plugin
                    $reflection = new \ReflectionMethod($plugin, 'setEnabled');
                    $params = $reflection->getParameters();
                    if (count($params) > 1) {
                        $plugin->setEnabled(true, $activation_context);
                    } else {
                        $plugin->updateSetting($activation_context, 'enabled', true, 'bool');
                    }

                    if ($activation_context === \PKP\core\PKPApplication::SITE_CONTEXT_ID) {
                        OJS_CLI::success("Plugin activated (site-wide)");
                    } else {
                        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
                        $journal = $journal_dao->getById($activation_context);
                        $journal_path = $journal ? $journal->getPath() : "context {$activation_context}";
                        OJS_CLI::success("Plugin activated (journal: {$journal_path})");
                    }
                } else {
                    OJS_CLI::warning("Plugin installed but could not be activated automatically");
                }
            }

        } catch (\Exception $e) {
            // Rollback database changes
            if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                \Illuminate\Support\Facades\DB::rollback();
            }

            OJS_CLI::error("Installation failed: " . $e->getMessage());
        }
    }

    /**
     * Install plugin from gallery
     *
     * @param string $plugin_name Plugin name
     * @param bool $activate Activate after installation
     * @param int|null $context_id Context ID for activation
     */
    private function install_from_gallery($plugin_name, $activate, $context_id)
    {
        OJS_CLI::log("Searching plugin gallery for: {$plugin_name}");

        // Get application instance
        $application = \APP\core\Application::get();

        // Get plugin gallery DAO
        $pluginGalleryDao = \PKP\db\DAORegistry::getDAO('PluginGalleryDAO');

        // Search for compatible plugins using directory name
        // Gallery uses directory names (e.g., "shariff", not "shariffplugin")
        $plugins = $pluginGalleryDao->getNewestCompatible($application, null, $plugin_name);

        // Find the requested plugin
        $galleryPlugin = null;
        foreach ($plugins as $plugin) {
            if ($plugin->getProduct() === $plugin_name) {
                $galleryPlugin = $plugin;
                break;
            }
        }

        if (!$galleryPlugin) {
            OJS_CLI::error(
                "Plugin '{$plugin_name}' not found in gallery or not compatible with this OJS version.\n" .
                "Try 'ojs plugin list' to see installed plugins or check https://pkp.github.io/plugin-compatibility/index.html"
            );
        }

        // Get plugin details
        $category = $galleryPlugin->getCategory();
        $product = $galleryPlugin->getProduct();
        $version = $galleryPlugin->getVersion();
        $package_url = $galleryPlugin->getReleasePackage();
        $expected_md5 = $galleryPlugin->getReleaseMD5();

        OJS_CLI::log("Found plugin: {$product} v{$version} ({$category})");
        OJS_CLI::log("Downloading from: {$package_url}");

        // Download plugin to temp file
        try {
            $temp_file = $this->download_plugin($package_url, $expected_md5);

            // Install from downloaded file
            $this->install_from_file($temp_file, $activate, $context_id);

            // Clean up temp file
            if (file_exists($temp_file)) {
                unlink($temp_file);
                OJS_CLI::log("Cleaned up temporary file");
            }

        } catch (\Exception $e) {
            OJS_CLI::error("Gallery installation failed: " . $e->getMessage());
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
     * : Plugin category (if known, for faster lookup)
     *
     * [--force]
     * : Skip confirmation prompt and allow deletion of enabled plugins
     *
     * ## EXAMPLES
     *
     *   # Delete a plugin (with confirmation)
     *   $ ojs plugin delete customBlockManager
     *
     *   # Delete without confirmation
     *   $ ojs plugin delete customBlockManager --force
     *
     * ## NOTES
     *
     * Plugins must be deactivated before deletion. If the plugin is enabled
     * in any context (site-wide or journal-specific), deletion will be blocked
     * unless --force is used. It's recommended to deactivate first:
     *
     *   $ ojs plugin deactivate customBlockManager
     *   $ ojs plugin delete customBlockManager
     */
    public function delete($args, $assoc_args)
    {
        $plugin_name = $args[0] ?? null;
        if (!$plugin_name) {
            OJS_CLI::error('Plugin name required');
        }

        $category = $assoc_args['category'] ?? null;
        $force = isset($assoc_args['force']);

        // Find the plugin
        $plugin = $this->find_plugin($plugin_name, $category);
        if (!$plugin) {
            OJS_CLI::error("Plugin not found: {$plugin_name}");
        }

        $found_category = $plugin['category'];
        $plugin_obj = $plugin['plugin'];

        // Check if plugin is enabled anywhere
        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true);
        $journal_contexts = [];
        while ($journal = $journals->next()) {
            $journal_contexts[$journal->getId()] = $journal->getPath();
        }

        $enabled_in = $this->get_plugin_enabled_contexts($plugin_obj, $journal_contexts);

        if (!empty($enabled_in) && !$force) {
            OJS_CLI::error(
                "Cannot delete plugin '{$plugin_name}': Plugin is currently enabled.\n" .
                "Enabled in: {$enabled_in}\n" .
                "Please deactivate the plugin first using:\n" .
                "  ojs plugin deactivate {$plugin_name}\n" .
                "Or use --force to delete anyway (not recommended)."
            );
        }

        // Confirm with user unless --force
        if (!$force) {
            fwrite(STDERR, OJS_CLI::colorize('Warning:', 'yellow') . " This will permanently delete the plugin '{$plugin_name}'.\n");
            fwrite(STDERR, 'Type "yes" to confirm: ');
            $confirmation = trim(fgets(STDIN));

            if (strtolower($confirmation) !== 'yes') {
                OJS_CLI::line('Deletion cancelled.');
                return;
            }
        }

        // Get actual path from plugin object
        $plugin_path = $plugin_obj->getPluginPath();

        // Get version info
        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $version = $versionDao->getCurrentVersion("plugins.{$found_category}", basename($plugin_path));


        // Delete version record from database
        if ($version) {
            $versionDao->disableVersion("plugins.{$found_category}", basename($plugin_path));
            OJS_CLI::log("Disabled plugin version in database");
        }

        // Delete plugin settings from database
        \Illuminate\Support\Facades\DB::table('plugin_settings')
            ->where('plugin_name', $plugin_name)
            ->delete();
        OJS_CLI::log("Deleted plugin settings from database");
       
        // Delete plugin files using actual plugin path
        $fileManager = new \PKP\file\FileManager();
        $deleted_files = false;

        if ($plugin_path && is_dir($plugin_path)) {
            OJS_CLI::log("Deleting files from: {$plugin_path}");
            $fileManager->rmtree($plugin_path);
            $deleted_files = true;
        }

        if (!$deleted_files) {
            OJS_CLI::warning("No plugin files found to delete (may be already removed)");
        }

        OJS_CLI::success("Plugin deleted: {$plugin_name}");
    }

    /**
     * Upgrades a plugin
     *
     * ## OPTIONS
     *
     * <plugin>
     * : Plugin name to upgrade OR path to plugin archive file
     *
     * [--category=<category>]
     * : Plugin category (if known, for faster lookup)
     *
     * [--force]
     * : Skip version check and force upgrade
     *
     * ## EXAMPLES
     *
     *   # Upgrade plugin from gallery
     *   $ ojs plugin upgrade shariffplugin
     *
     *   # Upgrade from local file
     *   $ ojs plugin upgrade /path/to/plugin.tar.gz
     *
     *   # Force upgrade even if version appears current
     *   $ ojs plugin upgrade shariffplugin --force
     */
    public function upgrade($args, $assoc_args)
    {
        $plugin_source = $args[0] ?? null;
        if (!$plugin_source) {
            OJS_CLI::error('Plugin name or path required');
        }

        $category = $assoc_args['category'] ?? null;
        $force = isset($assoc_args['force']);

        // Check if it's a file path
        if (file_exists($plugin_source)) {
            $this->upgrade_from_file($plugin_source, $category, $force);
        } else {
            $this->upgrade_from_gallery($plugin_source, $category, $force);
        }
    }

    /**
     * Upgrade plugin from local file
     *
     * @param string $file_path Path to plugin archive
     * @param string|null $category Plugin category
     * @param bool $force Force upgrade
     */
    private function upgrade_from_file($file_path, $category, $force)
    {
        if (!file_exists($file_path)) {
            OJS_CLI::error("File not found: {$file_path}");
        }

        if (!is_readable($file_path)) {
            OJS_CLI::error("File not readable: {$file_path}");
        }

        // Parse the archive to get plugin info
        OJS_CLI::log("Reading plugin archive...");

        try {
            $pluginHelper = new \PKP\plugins\PluginHelper();
            $versionInfo = $this->get_version_from_archive($file_path, basename($file_path));

            $plugin_name = $versionInfo['product'];
            $new_version = $versionInfo['version'];
            $plugin_category = str_replace('plugins.', '', $versionInfo['productType']);

            // Verify category if specified
            if ($category && $category !== $plugin_category) {
                OJS_CLI::error("Category mismatch: archive is '{$plugin_category}' but you specified '{$category}'");
            }

            // Find the plugin
            $plugin_info = $this->find_plugin($plugin_name, $plugin_category);
            if (!$plugin_info) {
                OJS_CLI::error("Plugin not installed: {$plugin_name}. Use 'ojs plugin install' instead.");
            }

            // Get current version
            $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
            $current_version = $versionDao->getCurrentVersion("plugins.{$plugin_category}", $plugin_name);

            if (!$current_version) {
                OJS_CLI::error("Plugin not installed: {$plugin_name}. Use 'ojs plugin install' instead.");
            }

            $current_version_string = $current_version->getVersionString();

            // Check version comparison unless forced
            if (!$force) {
                if (version_compare($new_version, $current_version_string, '<=')) {
                    OJS_CLI::error(
                        "Upgrade cancelled: New version ({$new_version}) is not newer than installed version ({$current_version_string}).\n" .
                        "Use --force to upgrade anyway."
                    );
                }
            }

            OJS_CLI::log("Upgrading {$plugin_name} from {$current_version_string} to {$new_version}...");

            // Perform upgrade using PluginHelper
            // Note: upgradePlugin expects the database product name (without "plugin" suffix)
            \Illuminate\Support\Facades\DB::beginTransaction();

            try {
                $version = $pluginHelper->upgradePlugin($plugin_category, $plugin_name, $file_path, basename($file_path));

                \Illuminate\Support\Facades\DB::commit();

                OJS_CLI::success("Plugin upgraded: {$plugin_name} (version {$version->getVersionString()})");
            } catch (\Exception $e) {
                if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                    \Illuminate\Support\Facades\DB::rollback();
                }
                OJS_CLI::error("Upgrade failed: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            OJS_CLI::error("Failed to read plugin archive: " . $e->getMessage());
        }
    }

    /**
     * Upgrade plugin from gallery
     *
     * @param string $plugin_name Plugin name
     * @param string|null $category Plugin category
     * @param bool $force Force upgrade
     */
    private function upgrade_from_gallery($plugin_name, $category, $force)
    {
        // Find the plugin
        $plugin_info = $this->find_plugin($plugin_name, $category);
        if (!$plugin_info) {
            OJS_CLI::error("Plugin not found: {$plugin_name}");
        }

        $found_category = $plugin_info['category'];
        $plugin_obj = $plugin_info['plugin'];

        // Get directory name (find_plugin now returns this)
        $dir_name = $plugin_info['name'];

        // Get current version using directory name
        // VersionDAO stores directory names (e.g., "shariff")
        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $current_version = $versionDao->getCurrentVersion("plugins.{$found_category}", $dir_name);

        if (!$current_version) {
            OJS_CLI::error("Plugin not installed: {$plugin_name}. Use 'ojs plugin install' instead.");
        }

        $current_version_string = $current_version->getVersionString();

        // Check for available update from gallery
        OJS_CLI::log("Checking plugin gallery for updates...");

        try {
            $pluginGalleryDao = \PKP\db\DAORegistry::getDAO('PluginGalleryDAO');
            $application = \APP\core\Application::get();

            // Search for specific plugin using directory name
            // Gallery uses directory names (e.g., "shariff")
            $plugins = $pluginGalleryDao->getNewestCompatible($application, $found_category, $dir_name);

            $galleryPlugin = null;
            foreach ($plugins as $gp) {
                if ($gp->getProduct() === $dir_name) {
                    $galleryPlugin = $gp;
                    break;
                }
            }

            if (!$galleryPlugin) {
                OJS_CLI::error("Plugin not found in gallery or not compatible with your OJS version.");
            }

            $available_version = $galleryPlugin->getVersion();

            // Check if upgrade is needed unless forced
            if (!$force) {
                if (version_compare($available_version, $current_version_string, '<=')) {
                    OJS_CLI::line("Plugin is already at the latest version ({$current_version_string}).");
                    return;
                }
            }

            OJS_CLI::log("Found update: {$available_version} (current: {$current_version_string})");

            // Download the plugin
            $download_url = $galleryPlugin->getReleasePackage();
            OJS_CLI::log("Downloading from: {$download_url}");

            $temp_file = $this->download_plugin($download_url, $db_plugin_name);

            try {
                // Upgrade using local file method
                $this->upgrade_from_file($temp_file, $found_category, true); // Force=true since we already checked

                // Cleanup temp file
                unlink($temp_file);
            } catch (\Exception $e) {
                // Cleanup temp file on error
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
                throw $e;
            }
        } catch (\Exception $e) {
            OJS_CLI::error("Failed to upgrade from gallery: " . $e->getMessage());
        }
    }

    /**
     * Download plugin from URL with optional MD5 verification
     *
     * @param string $url Download URL
     * @param string $plugin_name_or_md5 Plugin name for temp file naming OR expected MD5
     * @return string Path to downloaded file
     * @throws \Exception on download or verification failure
     */
    private function download_plugin($url, $plugin_name_or_md5 = null)
    {
        $application = \APP\core\Application::get();
        $client = $application->getHttpClient();

        // Determine if second param is MD5 (32 hex chars) or plugin name
        $expected_md5 = null;
        $plugin_name = 'plugin';
        if ($plugin_name_or_md5) {
            if (preg_match('/^[a-f0-9]{32}$/i', $plugin_name_or_md5)) {
                $expected_md5 = strtolower($plugin_name_or_md5);
            } else {
                $plugin_name = $plugin_name_or_md5;
            }
        }

        // Create temp file
        $temp_file = tempnam(sys_get_temp_dir(), "ojs_plugin_{$plugin_name}_") . '.tar.gz';
        if ($temp_file === false) {
            throw new \Exception("Failed to create temporary file");
        }

        OJS_CLI::log("Downloading plugin...");

        try {
            // Download plugin
            $response = $client->request('GET', $url, ['timeout' => 120]);
            $body = $response->getBody();

            // Write to temp file in chunks (same as OJS implementation)
            $file = fopen($temp_file, 'w');
            if ($file === false) {
                throw new \Exception("Failed to open temporary file for writing");
            }

            $bytes_written = 0;
            while (!$body->eof()) {
                $chunk = $body->read(80 << 10); // 80KB chunks
                if (fwrite($file, $chunk) === false) {
                    fclose($file);
                    throw new \Exception("Failed to write to temporary file");
                }
                $bytes_written += strlen($chunk);
            }

            fclose($file);

            OJS_CLI::log("Downloaded " . round($bytes_written / 1024, 2) . " KB");

            // Verify MD5 checksum if provided
            if ($expected_md5) {
                $actual_md5 = md5_file($temp_file);
                if ($actual_md5 !== $expected_md5) {
                    unlink($temp_file);
                    throw new \Exception(
                        "Integrity validation failed!\n" .
                        "Expected MD5: {$expected_md5}\n" .
                        "Received MD5: {$actual_md5}\n" .
                        "The downloaded file may be corrupted or tampered with."
                    );
                }
                OJS_CLI::log("MD5 checksum verified");
            }

            return $temp_file;

        } catch (\Exception $e) {
            // Clean up temp file on error
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            throw $e;
        }
    }

    /**
     * Get version info from plugin archive
     *
     * @param string $filePath Path to archive
     * @param string $originalFileName Original filename
     * @return array Version info
     */
    private function get_version_from_archive($filePath, $originalFileName)
    {
        $fileManager = new \PKP\file\FileManager();
        $extension = $fileManager->parseFileExtension($originalFileName);
        $baseName = basename($originalFileName, ".{$extension}") ?: 'plugin';

        // Extract to temp directory
        $extractPath = rtrim(sys_get_temp_dir(), '\\/') . "/{$baseName}" . substr(md5(random_int(0, PHP_INT_MAX)), 0, 10) . '/';
        $fileManager->mkdir($extractPath);

        try {
            // Extract files
            (new \PharData($filePath))->extractTo($extractPath, null, true);

            // Find version.xml
            foreach (new \DirectoryIterator($extractPath) as $current) {
                if ($current->isDir() && $current->getBasename() !== '..' &&
                    is_file(($path = "{$current->getPathname()}/") . 'version.xml')) {

                    $versionFile = $path . 'version.xml';
                    $versionInfo = \PKP\site\VersionCheck::parseVersionXML($versionFile);

                    // Cleanup
                    $fileManager->rmtree($extractPath);

                    return [
                        'product' => $versionInfo['application'], // parseVersionXML returns 'application'
                        'productType' => $versionInfo['type'],     // parseVersionXML returns 'type'
                        'version' => $versionInfo['release']
                    ];
                }
            }

            throw new \Exception('version.xml not found in archive');
        } finally {
            if (is_dir($extractPath)) {
                $fileManager->rmtree($extractPath);
            }
        }
    }

    /**
     * Get plugin version string
     *
     * @param object $plugin Plugin object
     * @return string Version string
     */
    private function get_plugin_version_string($plugin)
    {
        // First try to get from database (for installed plugins)
        $dbVersion = $plugin->getCurrentVersion();
        if ($dbVersion) {
            return $dbVersion->getVersionString();
        }

        // Try to read from version.xml file
        try {
            $pluginPath = $plugin->getPluginPath();
            $versionFile = $pluginPath . '/version.xml';

            if (file_exists($versionFile)) {
                $versionInfo = \PKP\site\VersionCheck::parseVersionXML($versionFile);
                if (isset($versionInfo['release'])) {
                    return $versionInfo['release'];
                }
            }
        } catch (\Exception $e) {
            // Ignore errors reading version.xml
        }

        return 'unknown';
    }

    /**
     * Check if plugin has an update available
     *
     * @param object $plugin Plugin object
     * @param string $current_version Current installed version
     * @return array ['status' => 'none'|'available', 'version' => '']
     */
    private function check_plugin_update($plugin, $current_version)
    {
        // Skip if version is unknown
        if ($current_version === 'unknown') {
            return ['status' => 'none', 'version' => ''];
        }

        try {
            // Use PluginGalleryDAO to get latest compatible version
            $pluginGalleryDao = \PKP\db\DAORegistry::getDAO('PluginGalleryDAO');
            $application = \APP\core\Application::get();

            // Use directory name for gallery search
            // Gallery uses directory names (e.g., "shariff")
            $dir_name = $plugin->getDirName();

            // Search for specific plugin using directory name
            $plugins = $pluginGalleryDao->getNewestCompatible($application, null, $dir_name);

            // Find matching plugin
            foreach ($plugins as $galleryPlugin) {
                // Match against directory name
                if ($galleryPlugin->getProduct() === $dir_name) {
                    $available_version = $galleryPlugin->getVersion();

                    // Compare versions
                    if (version_compare($available_version, $current_version, '>')) {
                        return ['status' => 'available', 'version' => $available_version];
                    }

                    break;
                }
            }
        } catch (\Exception $e) {
            if (getenv('OJS_CLI_DEBUG')) {
                OJS_CLI::log("DEBUG: Exception in check_plugin_update: " . $e->getMessage());
            }
            // Silently fail if plugin gallery unavailable
        }

        return ['status' => 'none', 'version' => ''];
    }
}
