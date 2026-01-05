# OJS-CLI Test Plan

## Overview

This document outlines the manual testing procedures for ojs-cli commands. Tests should be executed against a working OJS 3.5 installation.

## Prerequisites

- OJS 3.5 installation with at least one journal configured
- ojs-cli installed and configured
- Database access for verification
- Test plugins available for install/delete operations

## Test Environment Variables

```bash
# Enable debug mode for verbose error output
export OJS_CLI_DEBUG=1

# Set OJS installation path (if not auto-detected)
export OJS_PATH=/path/to/ojs
```

---

## Test Suite 1: Plugin List Command

### Test 1.1: Basic List
**Command**: `ojs plugin list`

**Expected**:
- ✅ Displays table with columns: name, display_name, category, version, enabled, enabled_in, update, update_version
- ✅ Shows all installed plugins
- ✅ Plugin names use directory names (e.g., `hypothesis`, `customBlockManager`)
- ✅ NOT class names (e.g., `hypothesisplugin`, `customblockmanagedplugin`)

### Test 1.2: JSON Format
**Command**: `ojs plugin list --format=json`

**Expected**:
- ✅ Outputs valid JSON array
- ✅ Each plugin object has all fields
- ✅ Plugin names use directory names

### Test 1.3: CSV Format
**Command**: `ojs plugin list --format=csv`

**Expected**:
- ✅ Outputs valid CSV with headers
- ✅ All plugins listed

### Test 1.4: YAML Format
**Command**: `ojs plugin list --format=yaml`

**Expected**:
- ✅ Outputs valid YAML
- ✅ All plugins listed

### Test 1.5: Filter by Category
**Command**: `ojs plugin list --category=generic`

**Expected**:
- ✅ Shows only generic plugins
- ✅ No plugins from other categories

### Test 1.6: Filter by Status
**Command**: `ojs plugin list --status=active`

**Expected**:
- ✅ Shows only enabled plugins
- ✅ All listed plugins show "Yes" or "default" in enabled column

**Command**: `ojs plugin list --status=inactive`

**Expected**:
- ✅ Shows only disabled plugins
- ✅ All listed plugins show "No" in enabled column

### Test 1.7: Filter by Context
**Command**: `ojs plugin list --context=<journal-path>`

**Expected**:
- ✅ Shows plugins with enabled status specific to that journal

### Test 1.8: Enabled_in Column Accuracy
**Verification**:
- ✅ Site-wide plugins show "site-wide"
- ✅ Journal-specific plugins show journal path(s)
- ✅ Disabled plugins show empty string

### Test 1.9: Update Checking
**Expected**:
- ✅ Shows "available" for plugins with updates
- ✅ Shows version number in update_version column
- ✅ Shows "none" for up-to-date plugins

---

## Test Suite 2: Plugin Info Command

### Test 2.1: Info with Directory Name
**Command**: `ojs plugin info hypothesis`

**Expected**:
- ✅ Displays detailed plugin information
- ✅ Shows directory name in "Name" field (not class name)
- ✅ Shows display name, category, version, enabled status, description
- ✅ Shows settings if any exist

### Test 2.2: Info with Class Name (Should Fail)
**Command**: `ojs plugin info hypothesisplugin`

**Expected**:
- ❌ Error: Plugin not found: hypothesisplugin
- ✅ This confirms breaking change is working

### Test 2.3: Info with Context
**Command**: `ojs plugin info customBlockManager --context=<journal-path>`

**Expected**:
- ✅ Shows enabled status for specific journal
- ✅ Settings specific to that journal

### Test 2.4: Info for Non-existent Plugin
**Command**: `ojs plugin info nonexistent`

**Expected**:
- ❌ Error: Plugin not found: nonexistent

---

## Test Suite 3: Plugin Activate/Deactivate

### Test 3.1: Activate with Directory Name
**Command**: `ojs plugin activate hypothesis`

**Expected**:
- ✅ Success message: Plugin activated: hypothesis
- ✅ Shows context where activated (journal path or site-wide)

**Verification**:
- Query database: `SELECT * FROM plugin_settings WHERE plugin_name='hypothesisplugin' AND setting_name='enabled'`
- ✅ setting_value should be '1' or 'true'
- Check OJS UI: Plugin shows as enabled
- Run `ojs plugin list`: hypothesis shows "Yes" in enabled column

### Test 3.2: Activate with Class Name (Should Fail)
**Command**: `ojs plugin activate hypothesisplugin`

**Expected**:
- ❌ Error: Plugin not found: hypothesisplugin

### Test 3.3: Activate Site-wide
**Command**: `ojs plugin activate customBlockManager --context=site-wide`

**Expected**:
- ✅ Success message
- ✅ Shows "site-wide" context

**Verification**:
- `ojs plugin list` shows "site-wide" in enabled_in column

### Test 3.4: Activate for All Journals
**Command**: `ojs plugin activate customBlockManager --all-contexts`

**Expected**:
- ✅ Success messages for each journal
- ✅ Lists all journal paths where activated

**Verification**:
- `ojs plugin list` shows all journal paths in enabled_in column

### Test 3.5: Activate for Specific Journal
**Command**: `ojs plugin activate customBlockManager --context=<journal-path>`

**Expected**:
- ✅ Success message for that journal only
- ✅ Shows journal path

### Test 3.6: Deactivate with Directory Name
**Command**: `ojs plugin deactivate hypothesis`

**Expected**:
- ✅ Success message: Plugin deactivated: hypothesis

**Verification**:
- Database: setting_value should be '0' or 'false'
- OJS UI: Plugin shows as disabled
- `ojs plugin list`: hypothesis shows "No" in enabled column

### Test 3.7: Deactivate Mandatory Plugin (Should Fail)
**Command**: `ojs plugin deactivate <mandatory-plugin>`

**Expected**:
- ❌ Error: Cannot deactivate mandatory plugin

### Test 3.8: Activate Non-existent Plugin
**Command**: `ojs plugin activate nonexistent`

**Expected**:
- ❌ Error: Plugin not found: nonexistent

---

## Test Suite 4: Plugin Install

### Test 4.1: Install from Local File
**Setup**: Download a plugin tar.gz file

**Command**: `ojs plugin install /path/to/plugin.tar.gz`

**Expected**:
- ✅ Success message with plugin name and version
- ✅ Files copied to plugins/{category}/{plugin-name}/

**Verification**:
- Check filesystem: Plugin directory exists
- Database: Check versions table for plugin record
- `ojs plugin list`: New plugin appears in list

### Test 4.2: Install from Local File with Activation
**Command**: `ojs plugin install /path/to/plugin.tar.gz --activate`

**Expected**:
- ✅ Installation success message
- ✅ Activation success message
- ✅ Plugin shows as enabled

**Verification**:
- `ojs plugin list`: Plugin shows "Yes" in enabled column

### Test 4.3: Install from Gallery
**Command**: `ojs plugin install hypothesis`

**Expected**:
- ✅ Searching message
- ✅ Downloading message with progress (KB downloaded)
- ✅ Verifying MD5 checksum message
- ✅ Installation success message

**Verification**:
- Files in plugins/generic/hypothesis/
- `ojs plugin list`: hypothesis appears

### Test 4.4: Install from Gallery with Activation
**Command**: `ojs plugin install hypothesis --activate`

**Expected**:
- ✅ Installation and activation success messages
- ⚠️ May require manual activation (known limitation)

### Test 4.5: Install Non-existent Plugin from Gallery
**Command**: `ojs plugin install nonexistent`

**Expected**:
- ❌ Error: Plugin 'nonexistent' not found in gallery

### Test 4.6: Install with Corrupted Archive
**Setup**: Create corrupted tar.gz file

**Command**: `ojs plugin install /path/to/corrupted.tar.gz`

**Expected**:
- ❌ Error message about corrupted archive
- ✅ No partial installation (rollback working)

**Verification**:
- No plugin directory created
- No database records created

---

## Test Suite 5: Plugin Delete

### Test 5.1: Delete Enabled Plugin (Should Fail)
**Setup**: Ensure plugin is enabled

**Command**: `ojs plugin delete hypothesis`

**Expected**:
- ❌ Error: Cannot delete plugin 'hypothesis': Plugin is currently enabled
- ✅ Shows where plugin is enabled (site-wide or journal paths)
- ✅ Shows deactivation command: `ojs plugin deactivate hypothesis`
- ✅ Mentions --force option

**Verification**:
- Plugin still exists
- Files still present
- Database records intact

### Test 5.2: Delete Disabled Plugin with Confirmation
**Setup**: Deactivate plugin first

**Command**: `ojs plugin delete hypothesis`

**Expected**:
- ⚠️ Colorized warning: "This will permanently delete..."
- ⚠️ Prompt: Type "yes" to confirm
- (Type "yes")
- ✅ Success message: Plugin deleted

**Verification**:
- Plugin directory removed
- Database: versions table record disabled
- Database: plugin_settings records removed
- `ojs plugin list`: Plugin no longer appears (unless it's a core plugin)

### Test 5.3: Delete with Confirmation Declined
**Command**: `ojs plugin delete hypothesis`

**Expected**:
- ⚠️ Confirmation prompt
- (Type "no" or anything other than "yes")
- ✅ Operation cancelled, no output

**Verification**:
- Plugin still exists

### Test 5.4: Delete with --force Flag
**Command**: `ojs plugin delete hypothesis --force`

**Expected**:
- ✅ No confirmation prompt
- ✅ Immediate deletion
- ✅ Success message

**Verification**:
- Plugin deleted immediately

### Test 5.5: Delete Enabled Plugin with --force
**Setup**: Plugin is enabled

**Command**: `ojs plugin delete hypothesis --force`

**Expected**:
- ✅ Deletion proceeds despite being enabled
- ⚠️ Warning message about deleting enabled plugin

### Test 5.6: Delete Non-existent Plugin
**Command**: `ojs plugin delete nonexistent`

**Expected**:
- ❌ Error: Plugin not found: nonexistent

---

## Test Suite 6: Plugin Upgrade

### Test 6.1: Upgrade from Local File
**Setup**: Have plugin version 1.0 installed, have version 1.1 tar.gz

**Command**: `ojs plugin upgrade /path/to/plugin-1.1.tar.gz`

**Expected**:
- ✅ Reading version from archive
- ✅ Comparing versions (1.1 > 1.0)
- ✅ Upgrade success message

**Verification**:
- `ojs plugin info <plugin>`: Version shows 1.1
- Database: versions table updated
- Files updated in plugin directory

### Test 6.2: Upgrade with Same/Lower Version (Should Fail)
**Setup**: Plugin version 1.1 installed, try to upgrade with version 1.0

**Command**: `ojs plugin upgrade /path/to/plugin-1.0.tar.gz`

**Expected**:
- ❌ Error: Upgrade cancelled: New version (1.0) not newer than current (1.1)
- ✅ Suggests using --force

### Test 6.3: Upgrade with --force Flag
**Command**: `ojs plugin upgrade /path/to/plugin-1.0.tar.gz --force`

**Expected**:
- ✅ Skips version check
- ✅ Proceeds with "upgrade" (downgrade)
- ⚠️ Warning about using --force

### Test 6.4: Upgrade from Gallery
**Command**: `ojs plugin upgrade hypothesis`

**Expected**:
- ✅ Querying gallery for latest version
- ✅ Compatibility checking
- ✅ Downloading if update available
- ✅ Upgrade success message

**Verification**:
- `ojs plugin list`: Shows no update available after upgrade

### Test 6.5: Upgrade Non-existent Plugin
**Command**: `ojs plugin upgrade nonexistent`

**Expected**:
- ❌ Error: Plugin not found: nonexistent

### Test 6.6: Upgrade with Broken Archive
**Setup**: Corrupted tar.gz file

**Command**: `ojs plugin upgrade /path/to/corrupted.tar.gz`

**Expected**:
- ❌ Error during upgrade
- ✅ Transaction rollback (plugin still at old version)

**Verification**:
- Plugin at original version
- No corruption in plugin directory

---

## Test Suite 7: Error Handling & Edge Cases

### Test 7.1: Command Without OJS Installation
**Setup**: Run from directory without OJS

**Command**: `ojs plugin list`

**Expected**:
- ❌ Error: OJS installation not found

### Test 7.2: Command with Invalid Category
**Command**: `ojs plugin list --category=invalid`

**Expected**:
- ✅ Empty list or error message

### Test 7.3: Debug Mode
**Command**: `OJS_CLI_DEBUG=1 ojs plugin info nonexistent`

**Expected**:
- ✅ Verbose error output
- ✅ Stack trace shown
- ✅ Additional debugging information

### Test 7.4: Case-Insensitive Plugin Names
**Command**: `ojs plugin info Hypothesis` (capital H)

**Expected**:
- ✅ Finds plugin despite case difference
- ✅ Shows info for hypothesis plugin

### Test 7.5: Help Commands
**Command**: `ojs help plugin`

**Expected**:
- ✅ Shows plugin command help
- ✅ Lists all subcommands
- ✅ Shows usage examples

**Command**: `ojs plugin activate --help`

**Expected**:
- ✅ Shows activate command help
- ✅ Shows all options and flags
- ✅ Shows examples

---

## Test Suite 8: Integration & Workflow Tests

### Test 8.1: Full Install Workflow
**Steps**:
1. `ojs plugin install hypothesis` (from gallery)
2. `ojs plugin list` (verify appears)
3. `ojs plugin info hypothesis` (check details)
4. `ojs plugin activate hypothesis` (enable it)
5. Verify in OJS UI
6. `ojs plugin deactivate hypothesis` (disable it)
7. `ojs plugin delete hypothesis` (remove it)

**Expected**: All steps succeed without errors

### Test 8.2: Full Upgrade Workflow
**Steps**:
1. Install old version of plugin
2. `ojs plugin list` (check for updates)
3. `ojs plugin upgrade <plugin>` (from gallery)
4. `ojs plugin list` (verify no updates)
5. Verify in OJS UI

**Expected**: Plugin upgraded successfully

### Test 8.3: Multi-Journal Workflow
**Steps**:
1. `ojs plugin activate customBlockManager --context=journal1`
2. `ojs plugin activate customBlockManager --context=journal2`
3. `ojs plugin list` (verify enabled_in shows both)
4. `ojs plugin deactivate customBlockManager --context=journal1`
5. `ojs plugin list` (verify enabled_in shows only journal2)

**Expected**: Context-specific activation/deactivation works

---

## Test Suite 9: Database Verification

### Verify Plugin Settings Table
```sql
SELECT context_id, plugin_name, setting_name, setting_value
FROM plugin_settings
WHERE plugin_name = 'hypothesisplugin'
ORDER BY context_id, setting_name;
```

**Expected**:
- enabled setting matches `ojs plugin list` output
- Context IDs match journal IDs

### Verify Versions Table
```sql
SELECT product_type, product, major, minor, revision, build
FROM versions
WHERE product_type = 'plugins.generic'
ORDER BY product;
```

**Expected**:
- All installed plugins have version records
- Versions match `ojs plugin list` output

---

## Test Results Template

For each test, record:
- [ ] Test ID (e.g., 1.1, 2.3, etc.)
- [ ] Pass/Fail
- [ ] Notes (any issues or observations)
- [ ] Date tested
- [ ] Tester name

---

## Critical Verification Points

After all tests:

1. ✅ All plugin names use directory names (not class names)
2. ✅ Class names are rejected with proper error
3. ✅ Database state matches CLI output
4. ✅ OJS UI state matches CLI output
5. ✅ No orphaned files or database records
6. ✅ All error messages are clear and helpful
7. ✅ Color output works correctly
8. ✅ Transaction rollback prevents partial operations

---

## Known Issues to Verify

1. Auto-activation after gallery install may not work (manual activation may be needed)
2. Configuration file support not fully tested
3. Progress indicators not implemented

---

## Test Environment Information

Record for each test run:
- OJS Version: _______________
- PHP Version: _______________
- ojs-cli Version/Commit: _______________
- Operating System: _______________
- Date: _______________
