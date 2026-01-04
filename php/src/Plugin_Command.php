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
                $plugin_name = $plugin->getName();

                // Check where this plugin is enabled
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

                $all_plugins[] = [
                    'name' => $plugin_name,
                    'display_name' => $plugin->getDisplayName(),
                    'category' => $cat,
                    'version' => $this->get_plugin_version_string($plugin),
                    'enabled' => $enabled_display,
                    'enabled_in' => $enabled_in
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

        // Find the plugin
        $plugin = null;
        $found_category = null;

        // If category specified, only search that category
        if ($category) {
            $categories = [$category];
        } else {
            // Search all categories
            $categories = \PKP\plugins\PluginRegistry::getCategories();
        }

        foreach ($categories as $cat) {
            $plugins = \PKP\plugins\PluginRegistry::loadCategory($cat, false);
            foreach ($plugins as $p) {
                if ($p->getName() === $plugin_name) {
                    $plugin = $p;
                    $found_category = $cat;
                    break 2;
                }
            }
        }

        if (!$plugin) {
            if ($category) {
                OJS_CLI::error("Plugin not found: {$category}/{$plugin_name}");
            } else {
                OJS_CLI::error("Plugin not found: {$plugin_name}");
            }
        }

        // Get plugin information
        $enabled = $plugin->getEnabled($context_id);
        $version = $this->get_plugin_version_string($plugin);
        $can_disable = $plugin->getCanDisable();

        // Display information
        OJS_CLI::line('');
        OJS_CLI::line('Plugin Information:');
        OJS_CLI::line('  Name:         ' . $plugin_name);
        OJS_CLI::line('  Display Name: ' . $plugin->getDisplayName());
        OJS_CLI::line('  Category:     ' . $found_category);
        OJS_CLI::line('  Version:      ' . $version);
        OJS_CLI::line('  Enabled:      ' . ($enabled ? 'Yes' : 'No'));
        OJS_CLI::line('  Mandatory:    ' . ($can_disable ? 'No' : 'Yes'));
        OJS_CLI::line('  Description:  ' . $plugin->getDescription());
        OJS_CLI::line('');

        // Show settings if available
        $pluginSettingsDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');
        $settings = $pluginSettingsDao->getPluginSettings($context_id, $plugin_name);
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

        // Load plugin object
        $plugin = $this->load_plugin_object($found_category, $plugin_name);
        if (!$plugin) {
            OJS_CLI::error("Failed to load plugin: {$plugin_name}");
        }

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

        // Load plugin object
        $plugin = $this->load_plugin_object($found_category, $plugin_name);
        if (!$plugin) {
            OJS_CLI::error("Failed to load plugin: {$plugin_name}");
        }

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
     * @param string $plugin_name Plugin name
     * @param string|null $category Optional category to search
     * @return array|null Plugin info or null if not found
     */
    private function find_plugin($plugin_name, $category = null)
    {
        $categories = $category ? [$category] : \PKP\plugins\PluginRegistry::getCategories();

        foreach ($categories as $cat) {
            $plugins = \PKP\plugins\PluginRegistry::loadCategory($cat, false);
            foreach ($plugins as $plugin) {
                if ($plugin->getName() === $plugin_name) {
                    return [
                        'name' => $plugin_name,
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
            $status_icon = $result['status'] === 'success' ? '[32m✓[0m' : '[31m✗[0m';
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
            $status_icon = $result['status'] === 'success' ? '[32m✓[0m' : '[31m✗[0m';
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
            OJS_CLI::error("Plugin installation from gallery not yet implemented. Use file path instead.");
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
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *   # Delete a plugin (with confirmation)
     *   $ ojs plugin delete customBlockManager
     *
     *   # Delete without confirmation
     *   $ ojs plugin delete customBlockManager --force
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

        // Confirm with user unless --force
        if (!$force) {
            OJS_CLI::line("[33mWarning:[0m This will permanently delete the plugin '{$plugin_name}'.");
            OJS_CLI::line('Type "yes" to confirm: ');
            $confirmation = trim(fgets(STDIN));

            if (strtolower($confirmation) !== 'yes') {
                OJS_CLI::line('Deletion cancelled.');
                return;
            }
        }

        // Get version info
        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $version = $versionDao->getCurrentVersion("plugins.{$found_category}", $plugin_name);

        // Delete plugin files
        $fileManager = new \PKP\file\FileManager();
        $baseDir = \PKP\core\Core::getBaseDir();

        $deleted_files = false;

        // Try both possible locations
        $locations = [
            "{$baseDir}/plugins/{$found_category}/{$plugin_name}",
            "{$baseDir}/lib/pkp/plugins/{$found_category}/{$plugin_name}"
        ];

        foreach ($locations as $location) {
            if (is_dir($location)) {
                OJS_CLI::log("Deleting files from: {$location}");
                $fileManager->rmtree($location);
                $deleted_files = true;
            }
        }

        if (!$deleted_files) {
            OJS_CLI::warning("No plugin files found to delete (may be already removed)");
        }

        // Disable version in database
        if ($version) {
            $versionDao->disableVersion("plugins.{$found_category}", $plugin_name);
            OJS_CLI::log("Disabled plugin version in database");
        }

        OJS_CLI::success("Plugin deleted: {$plugin_name}");
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
}
