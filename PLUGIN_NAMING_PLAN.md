# Plugin Naming Refactoring Plan

## Problem Statement

Currently, ojs-cli uses `$plugin->getName()` (lowercased class name) as the primary plugin identifier throughout the codebase. This causes several issues:

### Current Issues

1. **User-facing names are confusing**: Plugin list shows `shariffplugin`, `hypothesisplugin`, `customblockmanagedplugin` instead of cleaner directory names (`shariff`, `hypothesis`, `customBlockManager`)

2. **Excessive normalization**: Code has to strip "plugin" suffix in multiple places:
   - `install_from_gallery`: Line 907 - `preg_replace('/(plugin|Plugin)$/i', '', $plugin_name)`
   - `upgrade_from_gallery`: Line 1123 - `preg_replace('/(plugin|Plugin)$/i', '', $plugin_name)`
   - `check_plugin_update`: Line 350 - `preg_replace('/(plugin|Plugin)$/i', '', $plugin_name)`

3. **Inconsistency with OJS internals**:
   - VersionDAO uses directory name (`shariff`)
   - PluginGalleryDAO uses directory name (`shariff`)
   - Filesystem uses directory name (`shariff`)
   - Only PluginSettingsDAO uses class name (`shariffplugin`)

4. **User confusion**: Users have to know whether to use `shariff` or `shariffplugin` when running commands

### Investigation Results

| Component | Identifier Used | Example (shariff plugin) |
|-----------|----------------|-------------------------|
| `$plugin->getName()` | Lowercased class name | `shariffplugin` |
| `$plugin->getDirName()` | Directory name | `shariff` |
| `basename($plugin->getPluginPath())` | Directory name | `shariff` |
| `plugin_settings.plugin_name` | Class name | `shariffplugin` |
| `versions.product` | Directory name | `shariff` |
| Plugin Gallery | Directory name | `shariff` |
| version.xml `<application>` | Directory name | `shariff` |

**Key Insight**: Only `PluginSettingsDAO` uses the class name. Everything else uses the directory name.

---

## Proposed Solution

### Primary Change

**Switch from `$plugin->getName()` to `$plugin->getDirName()` or `basename($plugin->getPluginPath())` for all user-facing operations.**

### Internal Identifier Mapping

Create a clear separation:

1. **External Identifier** (shown to users, used for commands): Directory name
   - Format: `shariff`, `hypothesis`, `customBlockManager`
   - Source: `$plugin->getDirName()` or `basename($plugin->getPluginPath())`
   - Used for: CLI arguments, display, version checking, gallery operations

2. **Internal Identifier** (used for settings operations): Class name
   - Format: `shariffplugin`, `hypothesisplugin`, `customblockmanagedplugin`
   - Source: `$plugin->getName()`
   - Used for: PluginSettingsDAO operations only

### Benefits

1. ✅ Cleaner user-facing plugin names
2. ✅ Eliminates all "plugin" suffix normalization code
3. ✅ Consistency with OJS gallery, versions table, and filesystem
4. ✅ Users can copy plugin names directly from directory listings
5. ✅ Matches what users see in OJS UI plugin gallery

---

## Impact Analysis

### Files Requiring Changes

#### 1. `php/src/Plugin_Command.php`

**Affected Methods** (approximately 1200+ lines):

**a) `list_()` method (lines 56-72)**
- Currently: `$plugin_name = $plugin->getName();` (line 189)
- Change to: `$plugin_name = $plugin->getDirName();`
- Display to user: Directory name

**b) `get_plugins()` method (lines 165-225)**
- Currently: Returns `name => $plugin->getName()`
- Change to: Returns `name => $plugin->getDirName()`, add `internal_name => $plugin->getName()`
- Note: Need `internal_name` for `get_plugin_enabled_contexts()` which uses PluginSettingsDAO

**c) `get_plugin_enabled_contexts()` method (lines 234-260)**
- Currently: Uses `$plugin->getName()` for PluginSettingsDAO
- Keep as-is: This method MUST use `getName()` because PluginSettingsDAO requires it
- Change parameter docs to clarify

**d) `info()` method (lines 290-389)**
- User provides directory name
- Must convert to class name for find_plugin if needed

**e) `activate()` method (lines 393-450)**
- User provides directory name
- `find_plugin()` must find by directory name
- Internal operations use `getName()` for PluginSettingsDAO

**f) `deactivate()` method (lines 477-539)**
- Same as activate

**g) `find_plugin()` method (current implementation unknown, needs checking)**
- Currently: Likely searches by class name
- Change to: Search by directory name (primary)
- Add fuzzy matching for both directory name and class name for backward compatibility

**h) `install_from_gallery()` method (lines 896-955)**
- Remove normalization: Line 907 `preg_replace` can be removed
- Gallery already uses directory names

**i) `delete()` method (lines 919-1028)**
- User provides directory name
- VersionDAO operations already use directory name

**j) `upgrade_from_file()` method (lines 1028-1101)**
- Version checking uses directory name (already correct)

**k) `upgrade_from_gallery()` method (lines 1110-1296)**
- Remove normalization: Line 1123 `preg_replace` can be removed
- Gallery operations already use directory names

**l) `check_plugin_update()` method (lines 343-372)**
- Remove normalization: Line 350 `preg_replace` can be removed

**m) `get_plugin_version_string()` method (lines 1449-1472)**
- Currently uses `$plugin->getCurrentVersion()` which internally uses directory name
- No changes needed (already correct)

#### 2. `php/OJS_CLI/Formatter.php`

**Affected:** Table column headers and data display
- Ensure 'name' field shows directory name
- Add internal documentation about the dual-identifier system

#### 3. Documentation Files

- `IMPLEMENTATION_PLAN.md`: Update with new naming convention
- `README.md`: Update examples to use directory names
- PHPDoc comments: Update all examples

---

## Detailed Change Plan

### Phase 1: Add Helper Methods (Non-Breaking)

Add utility methods to handle identifier conversion:

```php
/**
 * Get the external plugin identifier (directory name)
 * This is what users see and use in commands
 *
 * @param object $plugin Plugin object
 * @return string Directory name (e.g., "shariff")
 */
private function get_plugin_external_name($plugin)
{
    return $plugin->getDirName(); // or basename($plugin->getPluginPath())
}

/**
 * Get the internal plugin identifier (class name)
 * This is what PluginSettingsDAO requires
 *
 * @param object $plugin Plugin object
 * @return string Lowercased class name (e.g., "shariffplugin")
 */
private function get_plugin_internal_name($plugin)
{
    return $plugin->getName();
}
```

### Phase 2: Update find_plugin Method

Currently `find_plugin()` must be updated to:
- Accept directory name as input (primary)
- Search plugins by directory name
- Support fuzzy matching for both directory name and class name (for backward compatibility)

```php
private function find_plugin($plugin_name, $category = null)
{
    // ... existing category loading code ...

    foreach ($plugins as $plugin) {
        $dir_name = $plugin->getDirName();
        $class_name = $plugin->getName();

        // Direct match on directory name (preferred)
        if (strcasecmp($dir_name, $plugin_name) === 0) {
            return ['plugin' => $plugin, 'category' => $cat];
        }

        // Fuzzy match (backward compatibility)
        if (strcasecmp($class_name, $plugin_name) === 0) {
            return ['plugin' => $plugin, 'category' => $cat];
        }

        // Try without "plugin" suffix
        $normalized_input = preg_replace('/(plugin|Plugin)$/i', '', $plugin_name);
        if (strcasecmp($dir_name, $normalized_input) === 0) {
            return ['plugin' => $plugin, 'category' => $cat];
        }
    }
}
```

### Phase 3: Update get_plugins for list command

```php
private function get_plugins($category, $context_id, $status_filter)
{
    // ... existing code ...

    foreach ($plugins as $plugin) {
        $dir_name = $plugin->getDirName();          // External identifier
        $class_name = $plugin->getName();           // Internal identifier

        // get_plugin_enabled_contexts MUST use class name
        $enabled_in = $this->get_plugin_enabled_contexts($plugin, $journal_contexts);

        $all_plugins[] = [
            'name' => $dir_name,  // Changed from $class_name to $dir_name
            'display_name' => $plugin->getDisplayName(),
            'category' => $cat,
            'version' => $this->get_plugin_version_string($plugin),
            'enabled' => $enabled_display,
            'enabled_in' => $enabled_in,
            'update' => $update_info['status'],
            'update_version' => $update_info['version']
        ];
    }
}
```

### Phase 4: Update get_plugin_enabled_contexts

Add clear documentation that this method requires the internal name:

```php
/**
 * Get contexts where a plugin is enabled
 *
 * @param object $plugin Plugin object
 * @param array $journal_contexts Map of journal ID => path
 * @return string "site-wide", comma-separated context IDs, or empty string
 *
 * Note: This method uses $plugin->getName() (class name) because
 * PluginSettingsDAO stores settings using the lowercased class name.
 */
private function get_plugin_enabled_contexts($plugin, $journal_contexts)
{
    $plugin_internal_name = $plugin->getName(); // MUST use class name for PluginSettingsDAO
    $pluginSettingsDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');

    // Check site-wide first
    $site_enabled = (bool)$pluginSettingsDao->getSetting(
        \PKP\core\PKPApplication::SITE_CONTEXT_ID,
        $plugin_internal_name,  // Use class name
        'enabled'
    );

    // ... rest unchanged ...
}
```

### Phase 5: Remove All Normalization Code

**Files to update:**
1. `install_from_gallery()` - Remove line 907 `preg_replace`
2. `upgrade_from_gallery()` - Remove line 1123 `preg_replace`
3. `check_plugin_update()` - Remove line 350 `preg_replace`

These are no longer needed because gallery operations will use directory names directly.

### Phase 6: Update Documentation

Update all command examples in PHPDoc:
```php
* ## EXAMPLES
*
*   # Activate shariff plugin (use directory name)
*   $ ojs plugin activate shariff
*
*   # Install hypothesis (use directory name)
*   $ ojs plugin install hypothesis
```

---

## Testing Plan

### Test Cases

1. **Plugin List**
   - Run `ojs plugin list`
   - Verify shows directory names: `shariff`, `hypothesis`, `customBlockManager`
   - Verify NOT showing: `shariffplugin`, `hypothesisplugin`

2. **Plugin Activate**
   - Test: `ojs plugin activate shariff` (directory name)
   - Test: `ojs plugin activate shariffplugin` (class name - should still work via fuzzy matching)
   - Verify enabled in database and UI

3. **Plugin Install from Gallery**
   - Test: `ojs plugin install hypothesis`
   - Verify no "plugin not found" errors
   - Verify installs correctly

4. **Plugin Delete**
   - Test: `ojs plugin delete shariff`
   - Verify finds plugin correctly

5. **Plugin Upgrade**
   - Test: `ojs plugin upgrade shariff`
   - Verify finds correct plugin in gallery

6. **Backward Compatibility**
   - Test commands with class names (e.g., `shariffplugin`)
   - Should still work via fuzzy matching in find_plugin

---

## Migration Strategy

### Option A: Big Bang (Recommended)

1. Implement all changes in a single commit
2. Test thoroughly with multiple plugins
3. Update all documentation
4. Clear communication in commit message about breaking change

**Pros:**
- Clean, atomic change
- No intermediate inconsistent state
- Easy to review

**Cons:**
- Large changeset
- Higher risk if something breaks

### Option B: Gradual Migration

1. Phase 1: Add helper methods
2. Phase 2: Update list command only
3. Phase 3: Update other commands
4. Phase 4: Remove normalization
5. Phase 5: Update docs

**Pros:**
- Lower risk per change
- Easier to isolate bugs

**Cons:**
- Inconsistent state during migration
- More commits to review
- Takes longer

### Recommendation: Option A (Big Bang)

Given that this is a CLI tool in active development (not production with many users), a single atomic change is cleaner and easier to test comprehensively.

---

## Breaking Changes

### User-Facing Changes

**Before:**
```bash
ojs plugin activate shariffplugin
ojs plugin list  # Shows: shariffplugin, hypothesisplugin
```

**After:**
```bash
ojs plugin activate shariff
ojs plugin list  # Shows: shariff, hypothesis
```

**Backward Compatibility:**
- Commands using class names (e.g., `shariffplugin`) will still work via fuzzy matching
- But output will always show directory names

---

## Risk Assessment

### High Risk Areas

1. **PluginSettingsDAO operations** - Must continue using `getName()`
   - activate/deactivate commands
   - get_plugin_enabled_contexts method

2. **find_plugin method** - Must support both naming schemes during transition

### Medium Risk Areas

1. **Gallery operations** - Should be safer as gallery already uses directory names
2. **Version operations** - Should be safer as versions table already uses directory names

### Low Risk Areas

1. **Display/output** - Pure presentation change
2. **Documentation** - No functional impact

---

## Estimated Effort

- **Code changes**: 2-3 hours
- **Testing**: 1-2 hours
- **Documentation**: 1 hour
- **Total**: 4-6 hours

---

## Questions for User

1. Do you approve this approach (directory name as primary identifier)?
2. Should we maintain backward compatibility for class names (e.g., `shariffplugin`)?
3. Preferred migration strategy: Big Bang or Gradual?
4. Any specific plugins you want to test with?

---

## Next Steps (After Approval)

1. Remove debug code (lines 190-193 in Plugin_Command.php)
2. Implement helper methods
3. Update find_plugin with fuzzy matching
4. Update get_plugins to use directory names
5. Remove all normalization preg_replace calls
6. Update documentation
7. Comprehensive testing
8. Single commit with clear message about the change
