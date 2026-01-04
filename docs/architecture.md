# OJS-CLI Architecture

## Overview

OJS-CLI is a standalone PHP CLI application that connects to OJS installations to provide command-line management capabilities. The architecture is modeled after WP-CLI's proven design patterns.

## Core Architectural Patterns

### 1. Bootstrap Pipeline Pattern

Sequential initialization steps executed in order:

```
bin/ojs (shell wrapper)
  ↓
php/boot.php (entry validation)
  ↓
php/ojs-cli.php (autoloader)
  ↓
php/bootstrap.php (orchestrator)
  ↓
Bootstrap Steps:
  1. LoadUtilityFunctions
  2. DeclareMainClass
  3. ConfigureRunner
  4. InitializeLogger
  5. RegisterFrameworkCommands
  6. LoadOJSCore (OJS bootstrap)
  7. RegisterDeferredCommands
  ↓
OJS_CLI/Runner.php (command execution)
```

### 2. Command Registry Pattern

Hierarchical command tree with lazy loading:

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
```

### 3. OJS Discovery & Bootstrap

**Discovery Algorithm**:
1. Check `--path` argument
2. Walk up directory tree looking for OJS markers:
   - `index.php`
   - `lib/pkp/includes/bootstrap.php`
   - `config.inc.php`

**Bootstrap Process** (follows OJS standard CLI pattern):
1. Define `INDEX_FILE_LOCATION` constant
2. Change to OJS directory
3. Load OJS bootstrap (`require './lib/pkp/includes/bootstrap.php'`)
4. Disable sessions (`PKPSessionGuard::disableSession()`)
5. Set up router (`PageRouter`)
6. Load generic plugins

### 4. Context Awareness

OJS supports multiple journals (contexts). OJS-CLI handles this with:

- **Default**: Site-wide operations (`context_id = null`)
- **Override**: `--context=<path>` for specific journal
- **Bulk**: `--all-contexts` to iterate all journals

## Component Breakdown

### Entry Points

**bin/ojs** (Shell Wrapper)
- Resolves symlinks
- Checks PHP version (8.1+)
- Executes `php/boot.php`

**php/boot.php**
- CLI validation
- Error reporting setup
- Loads `php/ojs-cli.php`

**php/ojs-cli.php**
- Defines constants (`OJS_CLI_ROOT`, `OJS_CLI_VERSION`)
- Loads Composer autoloader
- Loads bootstrap

### Core Classes

**OJS_CLI/Runner.php**
- Command execution engine
- OJS discovery
- Configuration handling
- Argument parsing

**OJS_CLI/Dispatcher/CommandFactory.php**
- Creates command objects from definitions
- Determines command type (Subcommand vs CompositeCommand)

**OJS_CLI/Dispatcher/RootCommand.php**
- Root of command tree
- Command routing
- Lazy loading of subcommands

### Command Implementation

**php/src/Plugin_Command.php**
- Implements all plugin management methods
- Uses OJS APIs (PluginRegistry, PluginHelper, PluginSettingsDAO)
- Handles context resolution
- Formats output

**php/commands/plugin.php**
- Registers plugin command with OJS_CLI
- Specifies when to load (after OJS bootstrap)

## Key Technical Details

### Cache Invalidation

PluginSettingsDAO caches for 24 hours. Must clear cache when changing settings:

```php
Cache::forget("pluginSettings-{$contextId}-" . strtolower($pluginName));
```

### Transaction Pattern

Database operations before filesystem (DB can rollback, files cannot):

```php
DB::beginTransaction();
try {
    // DB operations
    $dao->updateSetting(...);
    DB::commit();

    // Filesystem operations
    $fileManager->copyDir(...);
} catch (Exception $e) {
    DB::rollback();
    $fileManager->rmtree($tempPath);
    throw $e;
}
```

### Plugin Enable/Disable

Use PluginSettingsDAO directly (most reliable):

```php
$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
$pluginSettingsDao->updateSetting(
    $contextId,
    $pluginName,
    'enabled',
    true,
    'bool'
);
```

### Context Resolution

Support multiple input formats:

```php
private function resolve_context($context_arg) {
    // null → site-wide (SITE_CONTEXT_ID = null)
    if ($context_arg === null) {
        return PKPApplication::SITE_CONTEXT_ID;
    }

    // numeric → context ID
    if (is_numeric($context_arg)) {
        return (int)$context_arg;
    }

    // string → lookup journal by path
    $journal = $journalDao->getByPath($context_arg);
    return $journal->getId();
}
```

## Integration with OJS

### Critical OJS Classes Used

1. **PluginRegistry** - Load/register plugins
2. **PluginHelper** - Install/upgrade plugins
3. **PluginSettingsDAO** - Manage plugin settings
4. **PluginGalleryDAO** - Query PKP gallery
5. **VersionDAO** - Track installed versions
6. **JournalDAO** - Access journals/contexts

### OJS Database Tables

**versions**: Plugin installation records
```sql
- product_type (e.g., 'plugins.generic')
- product (plugin name)
- current (1 = active, 0 = disabled)
```

**plugin_settings**: Plugin configuration per context
```sql
- plugin_name
- context_id (NULL = site-wide)
- setting_name
- setting_value
```

## Design Principles

1. **No OJS Modifications**: Tool works with unmodified OJS installations
2. **Context Safety**: Explicit context handling prevents accidental changes
3. **Reliability**: Direct DAO usage with proper cache management
4. **Error Recovery**: Transactions + filesystem cleanup
5. **Extensibility**: Hook system for future features

## Security Considerations

1. Archive validation before extraction
2. Path traversal prevention
3. Confirmation prompts for destructive operations
4. File permission validation
5. Version compatibility checking

## Performance

- Lazy loading of commands (only load what's needed)
- OJS bootstrap only when required
- Efficient plugin loading (from disk vs database)
- Configuration caching

## Future Architecture Enhancements

1. **Plugin System**: Allow community plugins for ojs-cli
2. **Remote Execution**: Manage OJS over SSH
3. **Package Manager**: Install sets of plugins
4. **Hook API**: Custom scripts at lifecycle events
