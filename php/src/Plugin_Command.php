<?php

/**
 * Plugin Command
 *
 * Manages OJS plugins
 */
class Plugin_Command
{
    /**
     * Sentinel for "no --context given" (auto-detect per plugin).
     * Needed because SITE_CONTEXT_ID is null in OJS 3.5, so null cannot
     * distinguish an explicit --context=site-wide from no context at all.
     */
    private const CONTEXT_AUTO = 'auto';

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
     * @return int|string|null Context ID (null for site-wide, CONTEXT_AUTO for auto-detect)
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

        // Auto-detect: resolved per-plugin later
        return self::CONTEXT_AUTO;
    }

    /**
     * Resolve context for a specific plugin
     *
     * @param object $plugin Plugin object
     * @param int|string|null $requested_context Requested context ID or CONTEXT_AUTO
     * @return int|null Context ID
     */
    private function resolve_plugin_context($plugin, $requested_context)
    {
        // If context explicitly requested (including site-wide null), use it
        if ($requested_context !== self::CONTEXT_AUTO) {
            return $requested_context;
        }

        if ($this->is_plugin_sitewide($plugin)) {
            return \PKP\core\PKPApplication::SITE_CONTEXT_ID;
        }

        // Journal-specific plugin: only auto-select when the choice is
        // unambiguous. Guessing writes the setting to a context the user did
        // not intend, and the command has no way to tell them which one.
        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true);

        $journal_paths = [];
        while ($journal = $journals->next()) {
            $journal_paths[$journal->getId()] = $journal->getPath();
        }

        if (empty($journal_paths)) {
            OJS_CLI::error(
                "This plugin is journal-specific and no journals exist.\n" .
                "Create a journal first, or use --context=site-wide."
            );
        }

        if (count($journal_paths) > 1) {
            OJS_CLI::error(
                "This plugin is journal-specific and this installation has several journals.\n" .
                "Choose one with --context=<path>: " . implode(', ', $journal_paths) . "\n" .
                "Or use --context=site-wide to write the setting at the site level."
            );
        }

        return array_key_first($journal_paths);
    }

    /**
     * Describe a context ID for display
     *
     * @param int|null $context_id Context ID (null for site-wide)
     * @return string Human-readable context description
     */
    private function describe_context($context_id)
    {
        if ($context_id === \PKP\core\PKPApplication::SITE_CONTEXT_ID) {
            return 'site-wide';
        }

        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journal = $journal_dao->getById($context_id);

        return $journal ? "journal: {$journal->getPath()}" : "context {$context_id}";
    }

    /**
     * Check whether a plugin stores its 'enabled' setting at the site level
     *
     * OJS decides this with Plugin::isSitePlugin() (see LazyLoadPlugin::getEnabled()),
     * not with the <sitewide> element of version.xml, which is only recorded in the
     * versions table. But some plugins derive isSitePlugin() from the current request
     * (e.g. CustomBlockManagerPlugin returns true whenever there is no context), and
     * under the CLI there never is one. Requiring both signals keeps the plugins that
     * genuinely declare themselves site-wide and rejects the request-dependent ones,
     * which are journal-specific everywhere the site actually reads them.
     *
     * @param object $plugin Plugin object
     * @return bool True if plugin is site-wide
     */
    private function is_plugin_sitewide($plugin)
    {
        try {
            // Request-derived implementations can fail outright without a
            // request context; treat that as "not site-wide"
            if (!$plugin->isSitePlugin()) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        try {
            $versionFile = $plugin->getPluginPath() . '/version.xml';

            if (file_exists($versionFile)) {
                $versionInfo = \PKP\site\VersionCheck::parseVersionXML($versionFile);
                return !empty($versionInfo['sitewide']);
            }
        } catch (\Throwable $e) {
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
        $requested_context = $this->resolve_context($context_path);

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

        // Resolve the context the same way activate/deactivate do, so that the
        // reported state is the one those commands would read and write
        $context_id = $this->resolve_plugin_context($plugin, $requested_context);

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
        OJS_CLI::line('  Context:      ' . $this->describe_context($context_id));
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
     * : Journal context path, or 'site-wide'. When omitted the context is
     * detected from the plugin: site-wide for plugins that declare themselves
     * as such, otherwise the journal - which must be unambiguous.
     *
     * [--all-contexts]
     * : Activate for all journals
     *
     * ## EXAMPLES
     *
     *   # Activate plugin in its own context
     *   $ ojs plugin activate customBlockManager
     *
     *   # Activate for specific journal
     *   $ ojs plugin activate customBlockManager --context=my-journal
     *
     *   # Activate site-wide
     *   $ ojs plugin activate betterPassword --context=site-wide
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

        $plugin = $plugin_info['plugin'];  // Plugin object already loaded by find_plugin

        // Resolve the actual context to use
        $context_id = $this->resolve_plugin_context($plugin, $requested_context);

        $this->set_plugin_enabled($plugin, true, $context_id);

        OJS_CLI::success("Plugin activated: {$plugin_name} ({$this->describe_context($context_id)})");
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
     * : Journal context path, or 'site-wide'. When omitted the context is
     * detected from the plugin: site-wide for plugins that declare themselves
     * as such, otherwise the journal - which must be unambiguous.
     *
     * [--all-contexts]
     * : Deactivate for all journals
     *
     * ## EXAMPLES
     *
     *   # Deactivate plugin in its own context
     *   $ ojs plugin deactivate customBlockManager
     *
     *   # Deactivate for specific journal
     *   $ ojs plugin deactivate customBlockManager --context=my-journal
     *
     *   # Deactivate site-wide
     *   $ ojs plugin deactivate betterPassword --context=site-wide
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

        $plugin = $plugin_info['plugin'];  // Plugin object already loaded by find_plugin

        // Check if plugin can be disabled
        if (!$plugin->getCanDisable()) {
            OJS_CLI::error("Plugin '{$plugin_name}' is mandatory and cannot be deactivated.");
        }

        // Resolve the actual context to use
        $context_id = $this->resolve_plugin_context($plugin, $requested_context);

        $this->set_plugin_enabled($plugin, false, $context_id);

        OJS_CLI::success("Plugin deactivated: {$plugin_name} ({$this->describe_context($context_id)})");
    }

    /**
     * Enable or disable a plugin in a given context
     *
     * Some plugin classes accept a context parameter in setEnabled (e.g.
     * BlockPlugin); others take only the flag or lack setEnabled entirely,
     * in which case the setting is written directly.
     *
     * @param object $plugin Plugin object
     * @param bool $enabled New enabled state
     * @param int|null $context_id Context ID (null for site-wide)
     */
    private function set_plugin_enabled($plugin, $enabled, $context_id)
    {
        if (method_exists($plugin, 'setEnabled')) {
            $reflection = new \ReflectionMethod($plugin, 'setEnabled');
            if (count($reflection->getParameters()) > 1) {
                $plugin->setEnabled($enabled, $context_id);
                return;
            }
        }
        $plugin->updateSetting($context_id, 'enabled', $enabled, 'bool');

        if (!$enabled) {
            $this->clear_registration_agency($plugin, $context_id);
        }
    }

    /**
     * Reproduce the cleanup a single-argument setEnabled() would have done
     *
     * CrossrefPlugin::setEnabled() and DatacitePlugin::setEnabled() take only
     * the flag, so they resolve the context from the request and cannot be
     * called here. They also clear the journal's configured DOI registration
     * agency on disable; without this, deactivating leaves the journal pointing
     * at a disabled agency, a state the web UI never produces.
     *
     * @param object $plugin Plugin object
     * @param int|null $context_id Context ID (null for site-wide)
     */
    private function clear_registration_agency($plugin, $context_id)
    {
        if ($context_id === \PKP\core\PKPApplication::SITE_CONTEXT_ID) {
            return;
        }

        if (!$plugin instanceof \APP\plugins\IDoiRegistrationAgency) {
            return;
        }

        $contextDao = \APP\core\Application::getContextDAO();
        $context = $contextDao->getById($context_id);

        if (!$context) {
            return;
        }

        $agency = \PKP\context\Context::SETTING_CONFIGURED_REGISTRATION_AGENCY;
        if ($context->getData($agency) !== $plugin->getName()) {
            return;
        }

        $context->setData($agency, \PKP\context\Context::SETTING_NO_REGISTRATION_AGENCY);
        $contextDao->updateObject($context);
        OJS_CLI::log('Cleared the journal\'s configured DOI registration agency');
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
     * Look up an installed plugin in the versions table
     *
     * Used instead of find_plugin() wherever PluginHelper is invoked afterwards.
     * find_plugin() calls PluginRegistry::loadCategory(), and every registration
     * hooks that plugin's install migration onto Installer::postInstall, which
     * PluginHelper fires - creating tables for plugins that were never installed.
     * The versions table answers the same question without touching the registry.
     *
     * @param string $plugin_name Plugin directory name
     * @param string|null $category Optional category to restrict the search
     * @return array|null ['name' => ..., 'category' => ...] or null if not installed
     */
    private function find_installed_product($plugin_name, $category = null)
    {
        $query = \Illuminate\Support\Facades\DB::table('versions')
            ->where('current', 1)
            ->whereRaw('LOWER(product) = ?', [strtolower($plugin_name)]);

        if ($category) {
            $query->where('product_type', "plugins.{$category}");
        } else {
            $query->where('product_type', 'like', 'plugins.%');
        }

        $row = $query->first();
        if (!$row) {
            return null;
        }

        return [
            'name' => $row->product,
            'category' => str_replace('plugins.', '', $row->product_type)
        ];
    }

    /**
     * List every installed plugin from the versions table
     *
     * @param string|null $category Optional category filter
     * @return array List of ['name' => ..., 'category' => ...]
     */
    private function get_installed_products($category = null)
    {
        $query = \Illuminate\Support\Facades\DB::table('versions')->where('current', 1);

        if ($category) {
            $query->where('product_type', "plugins.{$category}");
        } else {
            $query->where('product_type', 'like', 'plugins.%');
        }

        $products = [];
        foreach ($query->get() as $row) {
            $products[] = [
                'name' => $row->product,
                'category' => str_replace('plugins.', '', $row->product_type)
            ];
        }

        return $products;
    }

    /**
     * Load plugin object
     *
     * @param string $category Plugin category
     * @param string $plugin_name Plugin directory name or class name
     * @return object|null Plugin object or null
     */
    private function load_plugin_object($category, $plugin_name)
    {
        $plugins = \PKP\plugins\PluginRegistry::loadCategory($category, false);
        foreach ($plugins as $plugin) {
            if ($plugin->getDirName() === $plugin_name
                || strcasecmp($plugin->getName(), $plugin_name) === 0) {
                return $plugin;
            }
        }

        // Not in the registry: loadCategory caches its result, so a plugin
        // installed during this process is missing. Load it from disk directly.
        try {
            return \PKP\plugins\PluginRegistry::loadPlugin($category, $plugin_name);
        } catch (\Exception $e) {
            return null;
        }
    }


    /**
     * Refuse --all-contexts for a site-wide plugin
     *
     * OJS reads such a plugin's 'enabled' setting only at the site level, so
     * writing one row per journal would leave rows nothing ever reads while
     * the plugin's real state stays unchanged.
     *
     * @param object $plugin Plugin object
     * @param string $plugin_name Plugin name as typed by the user
     */
    private function reject_all_contexts_for_sitewide($plugin, $plugin_name)
    {
        if (!$this->is_plugin_sitewide($plugin)) {
            return;
        }

        OJS_CLI::error(
            "Plugin '{$plugin_name}' is site-wide, so --all-contexts does not apply.\n" .
            "Use --context=site-wide instead."
        );
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
        $plugin = $plugin_info['plugin'];

        $this->reject_all_contexts_for_sitewide($plugin, $plugin_name);

        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true); // enabled journals only

        $results = [];
        while ($journal = $journals->next()) {
            $context_id = $journal->getId();

            try {
                $this->set_plugin_enabled($plugin, true, $context_id);
                $results[] = [
                    'journal' => $journal->getPath(),
                    'status' => 'success'
                ];
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
        $plugin = $plugin_info['plugin'];

        if (!$plugin->getCanDisable()) {
            OJS_CLI::error("Plugin '{$plugin_name}' is mandatory and cannot be deactivated.");
        }

        $this->reject_all_contexts_for_sitewide($plugin, $plugin_name);

        $journal_dao = \PKP\db\DAORegistry::getDAO('JournalDAO');
        $journals = $journal_dao->getAll(true); // enabled journals only

        $results = [];
        while ($journal = $journals->next()) {
            $context_id = $journal->getId();

            try {
                $this->set_plugin_enabled($plugin, false, $context_id);
                $results[] = [
                    'journal' => $journal->getPath(),
                    'status' => 'success'
                ];
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

        // Use PluginHelper to install. No transaction is opened around it: the
        // installer runs schema migrations, and DDL implicitly commits on
        // MySQL/MariaDB, so a wrapping transaction cannot roll the install back
        // and would only make the failure report inaccurate. PluginHelper does
        // its own cleanup (it removes the plugin directory on failure).
        $pluginHelper = new \PKP\plugins\PluginHelper();

        try {
            $version = $pluginHelper->installPlugin($file_path, basename($file_path));

            if (!$version) {
                throw new \Exception("Plugin installation failed - no version returned");
            }

            // Get plugin info
            $product = $version->getProduct();
            // getProductType returns e.g. "plugins.generic"; strip the prefix
            $category = str_replace('plugins.', '', $version->getProductType());
            $version_string = $version->getVersionString();

            OJS_CLI::success("Plugin installed: {$product} (version {$version_string})");

            // Activate if requested
            if ($activate) {
                OJS_CLI::log("Activating plugin...");
                $plugin = $this->load_plugin_object($category, $product);
                if ($plugin) {
                    // Resolve context for activation
                    $activation_context = $this->resolve_plugin_context($plugin, $context_id);

                    $this->set_plugin_enabled($plugin, true, $activation_context);

                    OJS_CLI::success("Plugin activated ({$this->describe_context($activation_context)})");
                } else {
                    OJS_CLI::warning("Plugin installed but could not be activated automatically");
                }
            }

        } catch (\Exception $e) {
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

        // Get actual path from plugin object. A plugin may live under the
        // application and/or under lib/pkp; PluginGridHandler removes both.
        $plugin_path = $plugin_obj->getPluginPath();
        $product_name = basename($plugin_path);
        $lib_plugin_path = (defined('PKP_LIB_PATH') ? PKP_LIB_PATH : 'lib/pkp') . '/' . $plugin_path;

        // Read everything needed from the plugin object before its files go away
        // plugin_settings stores the lowercased class name (see PluginSettingsDAO),
        // not the directory name the user typed
        $settings_name = strtolower($plugin_obj->getName());

        // Delete the files first. The database is only touched once the files
        // are actually gone, so a failed removal cannot leave a plugin that is
        // installed on disk but marked uninstalled in the database.
        $fileManager = new \PKP\file\FileManager();
        $deleted_files = false;

        foreach ([$plugin_path, $lib_plugin_path] as $path) {
            if (is_dir($path)) {
                OJS_CLI::log("Deleting files from: {$path}");
                $fileManager->rmtree($path);
                $deleted_files = true;
            }
        }

        if (is_dir($plugin_path) || is_dir($lib_plugin_path)) {
            OJS_CLI::error(
                "Failed to delete the files of plugin '{$plugin_name}'.\n" .
                "The database was left unchanged. Check filesystem permissions and try again."
            );
        }

        if (!$deleted_files) {
            OJS_CLI::warning("No plugin files found to delete (may be already removed)");
        }

        // Files are gone: now retire the version record
        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $version = $versionDao->getCurrentVersion("plugins.{$found_category}", $product_name);

        if ($version) {
            $versionDao->disableVersion("plugins.{$found_category}", $product_name);
            OJS_CLI::log("Disabled plugin version in database");
        }

        $this->delete_plugin_settings($settings_name);

        OJS_CLI::success("Plugin deleted: {$plugin_name}");
    }

    /**
     * Remove every plugin_settings row of a plugin, in all contexts
     *
     * Goes through PluginSettingsDAO rather than deleting the rows directly:
     * the DAO caches settings for 24h in a store shared with the running site,
     * so a raw DELETE would leave the web app reading the deleted values.
     *
     * @param string $plugin_name Lowercased plugin class name, as stored in plugin_settings
     */
    private function delete_plugin_settings($plugin_name)
    {
        $context_ids = \Illuminate\Support\Facades\DB::table('plugin_settings')
            ->where('plugin_name', $plugin_name)
            ->distinct()
            ->pluck('context_id');

        if ($context_ids->isEmpty()) {
            return;
        }

        $pluginSettingsDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');
        foreach ($context_ids as $context_id) {
            $pluginSettingsDao->deleteSettingsByPlugin(
                $context_id === null ? null : (int)$context_id,
                $plugin_name
            );
        }

        OJS_CLI::log("Deleted plugin settings from database");
    }

    /**
     * Upgrades a plugin
     *
     * ## OPTIONS
     *
     * [<plugin>]
     * : Plugin name to upgrade OR path to plugin archive file
     *
     * [--category=<category>]
     * : Plugin category (if known, for faster lookup)
     *
     * [--force]
     * : Skip version check and force upgrade
     *
     * [--all]
     * : Upgrade all plugins with available updates
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
     *
     *   # Upgrade all plugins with available updates
     *   $ ojs plugin upgrade --all
     */
    public function upgrade($args, $assoc_args)
    {
        $category = $assoc_args['category'] ?? null;
        $force = isset($assoc_args['force']);
        $all = isset($assoc_args['all']);

        // Handle bulk upgrade for all plugins with updates
        if ($all) {
            $this->upgrade_all_with_updates($category, $force);
            return;
        }

        $plugin_source = $args[0] ?? null;
        if (!$plugin_source) {
            OJS_CLI::error('Plugin name or path required (or use --all to upgrade all plugins with updates)');
        }

        // Check if it's a file path
        try {
            if (file_exists($plugin_source)) {
                $this->upgrade_from_file($plugin_source, $category, $force);
            } else {
                $this->upgrade_from_gallery($plugin_source, $category, $force);
            }
        } catch (\Exception $e) {
            OJS_CLI::error($e->getMessage());
        }
    }

    /**
     * Upgrade all plugins with available updates
     *
     * @param string|null $category Plugin category filter
     * @param bool $force Force upgrade
     */
    private function upgrade_all_with_updates($category, $force)
    {
        OJS_CLI::line('');
        OJS_CLI::log('Checking for plugin updates...');

        // Read the installed plugins from the versions table instead of
        // get_plugins(), which would register every plugin on disk with the
        // PluginRegistry before the upgrades run - see find_installed_product()
        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $plugins_to_upgrade = [];

        foreach ($this->get_installed_products($category) as $product) {
            $installed = $versionDao->getCurrentVersion("plugins.{$product['category']}", $product['name']);
            if (!$installed) {
                continue;
            }

            $current_version = $installed->getVersionString();
            $update = $this->check_gallery_update($product['name'], $current_version);

            if ($update['status'] === 'available') {
                $plugins_to_upgrade[] = [
                    'name' => $product['name'],
                    'category' => $product['category'],
                    'version' => $current_version,
                    'update_version' => $update['version']
                ];
            }
        }

        if (empty($plugins_to_upgrade)) {
            OJS_CLI::line('No plugin updates available.');
            OJS_CLI::line('');
            return;
        }

        OJS_CLI::line(sprintf('Found %d plugin(s) with available updates:', count($plugins_to_upgrade)));
        foreach ($plugins_to_upgrade as $plugin) {
            OJS_CLI::line(sprintf('  - %s (%s -> %s)', $plugin['name'], $plugin['version'], $plugin['update_version']));
        }
        OJS_CLI::line('');

        // Upgrade each plugin
        $results = [];
        foreach ($plugins_to_upgrade as $plugin) {
            $plugin_name = $plugin['name'];
            $plugin_category = $plugin['category'];
            $current_version = $plugin['version'];
            $new_version = $plugin['update_version'];

            OJS_CLI::log("Upgrading {$plugin_name} ({$current_version} -> {$new_version})...");

            try {
                // Upgrade from gallery (suppress output during bulk operation)
                $this->upgrade_from_gallery($plugin_name, $plugin_category, true);

                $results[] = [
                    'name' => $plugin_name,
                    'status' => 'success',
                    'version' => $new_version
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'name' => $plugin_name,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        // Display summary
        OJS_CLI::line('');
        OJS_CLI::line('Upgrade Summary:');
        $success_count = 0;
        $error_count = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'success') {
                $status_icon = OJS_CLI::colorize('✓', 'green');
                $message = "upgraded to {$result['version']}";
                $success_count++;
            } else {
                $status_icon = OJS_CLI::colorize('✗', 'red');
                $message = "failed: {$result['message']}";
                $error_count++;
            }
            OJS_CLI::line("  {$status_icon} {$result['name']} - {$message}");
        }

        OJS_CLI::line('');
        OJS_CLI::line(sprintf('Total: %d upgraded, %d failed', $success_count, $error_count));
        OJS_CLI::line('');
    }

    /**
     * Upgrade plugin from local file
     *
     * @param string $file_path Path to plugin archive
     * @param string|null $category Plugin category
     * @param bool $force Force upgrade
     * @throws \Exception on failure (callers decide whether to exit or continue)
     */
    private function upgrade_from_file($file_path, $category, $force)
    {
        if (!file_exists($file_path)) {
            throw new \Exception("File not found: {$file_path}");
        }

        if (!is_readable($file_path)) {
            throw new \Exception("File not readable: {$file_path}");
        }

        // Parse the archive to get plugin info
        OJS_CLI::log("Reading plugin archive...");

        $pluginHelper = new \PKP\plugins\PluginHelper();

        try {
            $versionInfo = $this->get_version_from_archive($file_path, basename($file_path));
        } catch (\Exception $e) {
            throw new \Exception("Failed to read plugin archive: " . $e->getMessage());
        }

        $plugin_name = $versionInfo['product'];
        $new_version = $versionInfo['version'];
        $plugin_category = str_replace('plugins.', '', $versionInfo['productType']);

        // Verify category if specified
        if ($category && $category !== $plugin_category) {
            throw new \Exception("Category mismatch: archive is '{$plugin_category}' but you specified '{$category}'");
        }

        // Get current version. Deliberately not going through find_plugin():
        // that registers every plugin of the category with the PluginRegistry,
        // and each registration hooks the plugin's install migration onto
        // Installer::postInstall, which PluginHelper fires below.
        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $current_version = $versionDao->getCurrentVersion("plugins.{$plugin_category}", $plugin_name);

        if (!$current_version) {
            throw new \Exception("Plugin not installed: {$plugin_name}. Use 'ojs plugin install' instead.");
        }

        $current_version_string = $current_version->getVersionString();

        // Check version comparison unless forced
        if (!$force) {
            if (version_compare($new_version, $current_version_string, '<=')) {
                throw new \Exception(
                    "Upgrade cancelled: New version ({$new_version}) is not newer than installed version ({$current_version_string}).\n" .
                    "Use --force to upgrade anyway."
                );
            }
        }

        OJS_CLI::log("Upgrading {$plugin_name} from {$current_version_string} to {$new_version}...");

        // Perform upgrade using PluginHelper. As with install, no transaction is
        // opened: upgrade.xml migrations issue DDL, which implicitly commits on
        // MySQL/MariaDB, so the rollback would be a silent no-op.
        // Note: upgradePlugin expects the database product name (without "plugin" suffix)
        try {
            $version = $pluginHelper->upgradePlugin($plugin_category, $plugin_name, $file_path, basename($file_path));

            OJS_CLI::success("Plugin upgraded: {$plugin_name} (version {$version->getVersionString()})");
        } catch (\Exception $e) {
            throw new \Exception("Upgrade failed: " . $e->getMessage());
        }
    }

    /**
     * Upgrade plugin from gallery
     *
     * @param string $plugin_name Plugin name
     * @param string|null $category Plugin category
     * @param bool $force Force upgrade
     * @throws \Exception on failure (callers decide whether to exit or continue)
     */
    private function upgrade_from_gallery($plugin_name, $category, $force)
    {
        // Resolve the plugin from the versions table rather than find_plugin():
        // see find_installed_product() for why the registry is avoided here
        $installed = $this->find_installed_product($plugin_name, $category);
        if (!$installed) {
            throw new \Exception("Plugin not installed: {$plugin_name}. Use 'ojs plugin install' instead.");
        }

        $found_category = $installed['category'];

        // VersionDAO stores directory names (e.g., "shariff")
        $dir_name = $installed['name'];

        $versionDao = \PKP\db\DAORegistry::getDAO('VersionDAO');
        $current_version = $versionDao->getCurrentVersion("plugins.{$found_category}", $dir_name);

        $current_version_string = $current_version->getVersionString();

        // Check for available update from gallery
        OJS_CLI::log("Checking plugin gallery for updates...");

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
            throw new \Exception("Plugin not found in gallery or not compatible with your OJS version.");
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
        $expected_md5 = $galleryPlugin->getReleaseMD5();
        OJS_CLI::log("Downloading from: {$download_url}");

        $temp_file = $this->download_plugin($download_url, $expected_md5);

        try {
            // Upgrade using local file method
            $this->upgrade_from_file($temp_file, $found_category, true); // Force=true since we already checked
        } finally {
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
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

        // Create temp file (renamed to add the .tar.gz extension PharData needs)
        $base_file = tempnam(sys_get_temp_dir(), "ojs_plugin_{$plugin_name}_");
        if ($base_file === false) {
            throw new \Exception("Failed to create temporary file");
        }
        $temp_file = $base_file . '.tar.gz';
        if (!rename($base_file, $temp_file)) {
            unlink($base_file);
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
        // Gallery uses directory names (e.g., "shariff")
        return $this->check_gallery_update($plugin->getDirName(), $current_version);
    }

    /**
     * Check the gallery for a newer version of a plugin
     *
     * @param string $dir_name Plugin directory name
     * @param string $current_version Current installed version
     * @return array ['status' => 'none'|'available', 'version' => '']
     */
    private function check_gallery_update($dir_name, $current_version)
    {
        // Skip if version is unknown
        if ($current_version === 'unknown') {
            return ['status' => 'none', 'version' => ''];
        }

        try {
            // Use PluginGalleryDAO to get latest compatible version
            $pluginGalleryDao = \PKP\db\DAORegistry::getDAO('PluginGalleryDAO');
            $application = \APP\core\Application::get();

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
