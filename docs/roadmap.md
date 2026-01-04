# OJS-CLI Development Roadmap

## Implementation Phases

### Phase 1: Core Framework (Week 1-2) ✅ CURRENT

**Goal**: Build foundation and get basic `ojs` command working

**Deliverables**:
- Directory structure setup
- Composer configuration
- Entry point (`bin/ojs`) working
- Bootstrap pipeline functioning
- OJS discovery and bootstrap working
- Basic command dispatcher

**Key Files**:
- `bin/ojs`
- `php/boot.php`
- `php/ojs-cli.php`
- `php/bootstrap.php`
- `php/class-ojs-cli.php`
- `php/OJS_CLI/Runner.php`
- `php/OJS_CLI/Bootstrap/*.php`

**Success Criteria**:
- ✅ `ojs` command runs
- ✅ Discovers OJS installation
- ✅ Loads OJS successfully
- ✅ Shows "command not found" error gracefully

---

### Phase 2: Command System (Week 3)

**Goal**: Implement command registry and help system

**Deliverables**:
- Command registry pattern
- Command factory
- Help command working
- PHPDoc parsing for documentation

**Key Files**:
- `php/OJS_CLI/Dispatcher/*.php`
- `php/src/Help_Command.php`
- `php/commands/help.php`

**Success Criteria**:
- ✅ `ojs help` displays available commands
- ✅ `ojs help plugin` shows plugin command help
- ✅ Command discovery works

---

### Phase 3: Plugin List Command (Week 4)

**Goal**: First working plugin command

**Deliverables**:
- `ojs plugin list` working
- Multiple output formats (table, JSON, CSV, YAML)
- Filtering (category, status, context)
- Proper enabled status detection

**Key Files**:
- `php/src/Plugin_Command.php` (list_ method)
- `php/commands/plugin.php`
- `php/OJS_CLI/Formatter.php`

**Success Criteria**:
- ✅ `ojs plugin list` shows all plugins
- ✅ `ojs plugin list --format=json` outputs JSON
- ✅ `ojs plugin list --status=active` filters correctly
- ✅ Enabled status is accurate

---

### Phase 3.5: Plugin Info Command (Week 4.5)

**Goal**: Display detailed plugin information

**Deliverables**:
- `ojs plugin info <name>` working
- Shows comprehensive plugin details
- Validates plugin loading logic

**Key Files**:
- `php/src/Plugin_Command.php` (info method)

**Success Criteria**:
- ✅ Shows plugin metadata
- ✅ Shows version information
- ✅ Shows enabled status per context
- ✅ Works for both installed and not-installed plugins

---

### Phase 4: Plugin Enable/Disable (Week 5)

**Goal**: Modify plugin state

**Deliverables**:
- `ojs plugin activate <name>` working
- `ojs plugin deactivate <name>` working
- Cache invalidation
- `--all-contexts` support
- Site-wide plugin warnings

**Key Files**:
- `php/src/Plugin_Command.php` (activate, deactivate methods)

**Success Criteria**:
- ✅ Activates plugin successfully
- ✅ Changes persist across requests
- ✅ Works for specific contexts
- ✅ Works for all contexts
- ✅ Cache properly invalidated

---

### Phase 5: Plugin Install (Local File) (Week 6)

**Goal**: Install plugins from local archives

**Deliverables**:
- `ojs plugin install <path>` from tar.gz
- Uses PluginHelper
- Transaction handling
- Cleanup on failure
- Optional activation

**Key Files**:
- `php/src/Plugin_Command.php` (install method)

**Success Criteria**:
- ✅ Installs from local tar.gz
- ✅ Files copied correctly
- ✅ Database updated
- ✅ Rollback works on failure
- ✅ Can activate after install

---

### Phase 6: Plugin Install (Gallery) (Week 7)

**Goal**: Install plugins from PKP gallery

**Deliverables**:
- `ojs plugin install <name>` from gallery
- Download from gallery
- Compatibility checking
- Version selection

**Key Files**:
- `php/src/Plugin_Command.php` (install_from_gallery method)

**Success Criteria**:
- ✅ Queries gallery successfully
- ✅ Downloads plugin
- ✅ Checks compatibility
- ✅ Installs correctly
- ✅ Handles gallery errors

---

### Phase 7: Plugin Delete (Week 8)

**Goal**: Remove plugins

**Deliverables**:
- `ojs plugin delete <name>` working
- Confirmation prompts
- File deletion (both locations)
- Database cleanup

**Key Files**:
- `php/src/Plugin_Command.php` (delete method)

**Success Criteria**:
- ✅ Prompts for confirmation
- ✅ Deletes all files
- ✅ Disables in database
- ✅ `--force` flag works
- ✅ Clean error on failure

---

### Phase 8: Plugin Upgrade (Week 9)

**Goal**: Upgrade plugins

**Deliverables**:
- `ojs plugin upgrade <name>` working
- `ojs plugin upgrade all` for bulk
- Version comparison
- Uses PluginHelper

**Key Files**:
- `php/src/Plugin_Command.php` (upgrade method)

**Success Criteria**:
- ✅ Upgrades single plugin
- ✅ Upgrades all plugins
- ✅ Version check prevents downgrade
- ✅ Runs upgrade.xml if present
- ✅ Handles upgrade errors

---

### Phase 9: Configuration & Polish (Week 10)

**Goal**: Configuration and user experience improvements

**Deliverables**:
- Configuration file support
- Color output
- Progress indicators
- Better error messages
- Complete documentation

**Key Files**:
- `php/OJS_CLI/Configurator.php`
- `php/config-spec.php`
- `docs/*.md`

**Success Criteria**:
- ✅ Config files work (global + project)
- ✅ Color output conditional
- ✅ Progress bars for long operations
- ✅ Error messages helpful
- ✅ All commands documented

---

### Phase 10: Testing & Packaging (Week 11)

**Goal**: Testing and distribution

**Deliverables**:
- Unit tests
- Integration tests
- Installation guide
- Package for Packagist

**Key Files**:
- `tests/*.php`
- `README.md`
- `INSTALL.md`

**Success Criteria**:
- ✅ >70% test coverage
- ✅ All critical paths tested
- ✅ Installation works globally
- ✅ Published to Packagist
- ✅ Documentation complete

---

## Future Enhancements (Phase 2)

### Journal Management
- `ojs journal list`
- `ojs journal create <name>`
- `ojs journal delete <path>`
- `ojs journal config`

### User Management
- `ojs user list`
- `ojs user create`
- `ojs user delete`
- `ojs user role add/remove`

### Import/Export
- `ojs export articles`
- `ojs export users`
- `ojs import articles`
- `ojs import users`

### Maintenance
- `ojs cache clear`
- `ojs db upgrade`
- `ojs db migrate`
- `ojs verify`

### Configuration
- `ojs config get <key>`
- `ojs config set <key> <value>`
- `ojs config list`

### Advanced Features
- Plugin package installation
- Plugin search in gallery
- Backup/restore
- Rollback functionality
- Remote management over SSH
- CI/CD integration

---

## Timeline

**Total MVP Development**: ~11 weeks

**Phase 1-3**: Foundation (4-5 weeks)
**Phase 4-8**: Core Features (5 weeks)
**Phase 9-10**: Polish & Release (2 weeks)

## Success Metrics

1. **Adoption**: >100 installations in first 3 months
2. **Reliability**: <1% error rate in production
3. **Performance**: Commands complete in <2 seconds
4. **Coverage**: All critical plugin operations supported
5. **Documentation**: Complete command reference
6. **Community**: At least 3 community contributors

## Release Strategy

1. **Alpha** (End of Phase 5): Internal testing
2. **Beta** (End of Phase 8): Limited public release
3. **RC** (End of Phase 9): Release candidate
4. **v1.0** (End of Phase 10): Public release

## Support Plan

- GitHub Issues for bug reports
- GitHub Discussions for questions
- Wiki for extended documentation
- Monthly releases for updates
