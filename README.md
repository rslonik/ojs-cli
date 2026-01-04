# OJS-CLI - Command Line Interface for Open Journal Systems

A standalone CLI tool for OJS (Open Journal Systems) modeled after WordPress's WP-CLI. This tool provides command-line management capabilities for OJS installations.

## Status

🚧 **In Development** - Currently implementing Phase 1 (Core Framework)

## Quick Overview

**Primary Goal**: Create `ojs` command with `ojs plugin` subcommands (list, activate, deactivate, delete, install, upgrade).

**Key Features**:
- Global Composer installation (`composer global require pkp/ojs-cli`)
- Auto-discovery of OJS installations
- Context-aware operations (site-wide or journal-specific)
- Multiple output formats (table, JSON, CSV, YAML)
- No modifications to OJS core required

**Target Version**: OJS 3.5

## Planned Commands

### Plugin Management

```bash
# List all plugins
ojs plugin list

# List plugins in JSON format
ojs plugin list --format=json

# List plugins for specific journal
ojs plugin list --context=my-journal

# Activate plugin
ojs plugin activate customBlockManager

# Activate for all journals
ojs plugin activate customBlockManager --all-contexts

# Deactivate plugin
ojs plugin deactivate customBlockManager

# Install from gallery
ojs plugin install customBlockManager

# Install from local file
ojs plugin install /path/to/plugin.tar.gz

# Install and activate
ojs plugin install customBlockManager --activate

# Delete plugin
ojs plugin delete customBlockManager

# Upgrade plugin
ojs plugin upgrade customBlockManager

# Upgrade all plugins
ojs plugin upgrade all
```

## Architecture

- **Standalone tool**: Separate from OJS core, connects to installations
- **Modular command system**: Hierarchical command structure
- **Bootstrap pipeline**: Sequential initialization steps
- **Context-aware**: Handles OJS multi-journal architecture

## Documentation

- [Implementation Plan](IMPLEMENTATION_PLAN.md) - Complete technical implementation plan
- [Architecture](docs/architecture.md) - System architecture overview
- [Development Roadmap](docs/roadmap.md) - Implementation phases and timeline

## Installation (Future)

```bash
# Global installation via Composer
composer global require pkp/ojs-cli

# Verify installation
ojs --version

# Get help
ojs help
```

## Requirements

- PHP 8.1 or higher
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
