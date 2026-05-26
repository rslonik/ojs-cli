# OJS-CLI - Command Line Interface for Open Journal Systems

A standalone CLI tool for OJS (Open Journal Systems) modeled after WordPress's WP-CLI. This tool provides command-line management capabilities for OJS installations.

A few words from the human: this is a work in progress. It supports only OJS 3.5 for now. It was coded with Claude with some human bug hunting/fixing where the IA lost it's mojo. Obviously it's not recommended to use it in production if you do not know how to recover from problems.

To use this just clone and set up an alias to bin/ojs. Run it inside the installation folder or use `--path` global option to specify the installation path.

## Status

✅ **Functional** - Core plugin management commands working

**Completed Features**:
- Plugin list with filtering, update checking, and multiple output formats
- Plugin info (detailed information)
- Plugin activate/deactivate with context awareness
- Plugin install from local file
- Plugin install from gallery (with MD5 verification)
- Plugin delete with confirmation (prevents deletion of enabled plugins)
- Plugin upgrade (from file and gallery)

**Pending**:
- ⏳ Configuration file support (needs testing)
- ⏳ Unit tests

## Quick Overview

**Primary Goal**: Create `ojs` command with `ojs plugin` subcommands (list, activate, deactivate, delete, install, upgrade).

**Key Features**:
- [not yet] Global Composer installation (`composer global require pkp/ojs-cli`)
- Auto-discovery of OJS installations
- Context-aware operations (site-wide or journal-specific)
- Multiple output formats (table, JSON, CSV, YAML)
- No modifications to OJS core required

**Target Version**: OJS 3.5

## Available Commands

### Plugin Management

```bash
# List all plugins (✅ working)
ojs plugin list

# List plugins in JSON format (✅ working)
ojs plugin list --format=json

# List plugins for specific journal (✅ working)
ojs plugin list --context=my-journal

# Filter by status (✅ working)
ojs plugin list --status=active

# Show detailed plugin info (✅ working)
ojs plugin info customBlockManager

# Activate plugin (✅ working)
ojs plugin activate customBlockManager

# Activate for all journals (✅ working)
ojs plugin activate customBlockManager --all-contexts

# Activate for specific journal (✅ working)
ojs plugin activate customBlockManager --context=my-journal

# Deactivate plugin (✅ working)
ojs plugin deactivate customBlockManager

# Install from gallery (✅ working)
ojs plugin install hypothesis

# Install from gallery and activate (✅ working)
ojs plugin install hypothesis --activate

# Install from local file (✅ working)
ojs plugin install /path/to/plugin.tar.gz

# Install and activate (✅ working)
ojs plugin install /path/to/plugin.tar.gz --activate

# Delete plugin (✅ working)
ojs plugin delete customBlockManager

# Delete without confirmation (✅ working)
ojs plugin delete customBlockManager --force

# Upgrade plugin from gallery (✅ working)
ojs plugin upgrade customBlockManager

# Upgrade from local file (✅ working)
ojs plugin upgrade /path/to/plugin.tar.gz

# Force upgrade even if version appears current (✅ working)
ojs plugin upgrade customBlockManager --force
```

### Key Features

**Plugin List Enhancements**:
- Shows `enabled_in` column (site-wide or journal paths where plugin is enabled)
- Shows `update` and `update_version` columns for available updates
- Mandatory plugins show "default" in enabled column
- Auto-detects site-wide plugins via version.xml

**Context Awareness**:
- Auto-detects if plugin is site-wide (reads `<sitewide>` tag from version.xml)
- Defaults to appropriate context for journal-specific plugins
- Override with `--context=<journal-path>` or `--context=site-wide`

**Plugin Name Matching**:
- Fuzzy matching handles variations (e.g., "shariff" vs "shariffplugin")

**Gallery Installation**:
- Downloads from https://pkp.sfu.ca/ojs/xml/plugins.xml
- Compatibility checking (only shows OJS version-compatible plugins)
- MD5 checksum verification for download integrity
- Streaming download in 80KB chunks for large plugins
- Auto-cleanup of temporary files

**Error Handling**:
- Debug mode: Set `OJS_CLI_DEBUG=1` environment variable for verbose errors
- Colorized warnings and error messages
- Transaction rollback on database operation failures
- Prevents deletion of enabled plugins (must deactivate first)

## Architecture

- **Standalone tool**: Separate from OJS core, connects to installations
- **Modular command system**: Hierarchical command structure
- **Bootstrap pipeline**: Sequential initialization steps
- **Context-aware**: Handles OJS multi-journal architecture

## Documentation

- [Implementation Plan](IMPLEMENTATION_PLAN.md) - Complete technical implementation plan
- [Architecture](docs/architecture.md) - System architecture overview
- [Development Roadmap](docs/roadmap.md) - Implementation phases and timeline

## Installation

### Recommended: download the prebuilt binary

```bash
curl -fsSL https://github.com/rslonik/ojs-cli/releases/latest/download/ojs.phar \
  -o /usr/local/bin/ojs
chmod +x /usr/local/bin/ojs
ojs --version
```

Requires PHP 8.2+ with the `phar`, `curl`, and `zip` extensions on the target system.

### From source (development)

```bash
git clone https://github.com/rslonik/ojs-cli.git
cd ojs-cli
composer install
php bin/ojs --version
```

## Requirements

- PHP 8.2 or higher (8.3 recommended)
- Composer
- PHP extensions: phar, curl, zip
- Access to an OJS 3.5 installation

## Development Setup

```bash
# Clone repository
git clone https://github.com/pkp/ojs-cli.git
cd ojs-cli

# Install dependencies
composer install

# Run from source
php bin/ojs --version
```

## Testing

### Manual Testing

A comprehensive manual test suite is available in `tests/`:

```bash
# From your OJS installation directory
cd /path/to/ojs

# Run all tests
/path/to/ojs-cli/tests/manual_test.sh

# Run with verbose output
/path/to/ojs-cli/tests/manual_test.sh --verbose

# Run with debug mode
/path/to/ojs-cli/tests/manual_test.sh --debug
```

The test suite covers:
- Plugin list with all formats and filters
- Plugin info command
- Plugin activate/deactivate
- Plugin install from gallery
- Plugin delete with protection
- Plugin upgrade
- Error handling and edge cases

See `tests/TEST_PLAN.md` for detailed test scenarios and verification steps.

### Running Individual Tests

```bash
# Test plugin list
ojs plugin list
ojs plugin list --format=json

# Test plugin info (uses directory names, not class names)
ojs plugin info hypothesis        # ✅ Works
ojs plugin info hypothesisplugin  # ❌ Error (breaking change)

# Test activate/deactivate
ojs plugin activate hypothesis
ojs plugin deactivate hypothesis

# Test install from gallery
ojs plugin install hypothesis

# Test delete (with protection)
ojs plugin delete hypothesis  # ❌ Error if enabled
ojs plugin deactivate hypothesis
ojs plugin delete hypothesis  # ✅ Prompts for confirmation
```

## Project Structure

```
ojs-cli/
├── bin/ojs                    # Shell wrapper entry point
├── php/                       # PHP source code
│   ├── boot.php              # Bootstrap entry
│   ├── OJS_CLI/              # Core classes
│   ├── src/                  # Command implementations
│   └── commands/             # Command registrations
├── docs/                     # Documentation
├── tests/                    # Test suite
└── composer.json             # Dependencies
```

## Contributing

This project follows OJS/PKP coding standards (PSR-2).

## License

GPL-3.0

## Credits

Inspired by [WP-CLI](https://wp-cli.org/) for WordPress.

Built for the [Public Knowledge Project](https://pkp.sfu.ca/).
