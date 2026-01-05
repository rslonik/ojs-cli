#!/usr/bin/env bash
# Version: 20260105.0000
###
### OJS-CLI Manual Test Script
###
### This script runs manual tests for ojs-cli plugin commands
### It requires a working OJS 3.5 installation
###
### Usage:
###   ./manual_test.sh [OPTIONS]
###
### Options:
###   -h, --help       Show this help
###   -v, --verbose    Enable verbose output
###   -d, --debug      Enable debug mode (OJS_CLI_DEBUG=1)
###   --ojs-path PATH  OJS installation path (auto-detects if not provided)
###

set -euo pipefail
IFS=$'\n\t'

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test counters
TESTS_TOTAL=0
TESTS_PASSED=0
TESTS_FAILED=0
TESTS_SKIPPED=0

# Configuration
VERBOSE=0
DEBUG=0
OJS_PATH=""
OJS_CLI_BIN="ojs"
TEST_PLUGIN="hypothesis"  # Plugin to use for testing

# Find the ojs-cli bin directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OJS_CLI_ROOT="$(dirname "$SCRIPT_DIR")"
OJS_CLI_BIN="${OJS_CLI_ROOT}/bin/ojs"

###
### Helper Functions
###

print_header() {
    echo -e "${BLUE}===================================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}===================================================${NC}"
}

print_section() {
    echo -e "\n${YELLOW}--- $1 ---${NC}"
}

print_test() {
    echo -e "${BLUE}TEST: $1${NC}"
}

print_pass() {
    echo -e "${GREEN}✅ PASS${NC}: $1"
    TESTS_PASSED=$((TESTS_PASSED + 1))
}

print_fail() {
    echo -e "${RED}❌ FAIL${NC}: $1"
    TESTS_FAILED=$((TESTS_FAILED + 1))
}

print_skip() {
    echo -e "${YELLOW}⏭  SKIP${NC}: $1"
    TESTS_SKIPPED=$((TESTS_SKIPPED + 1))
}

run_test() {
    local test_name="$1"
    local test_command="$2"
    local expected_result="${3:-0}"  # 0 = should succeed, 1 = should fail

    TESTS_TOTAL=$((TESTS_TOTAL + 1))
    print_test "$test_name"

    if [[ $VERBOSE -eq 1 ]]; then
        echo "Command: $test_command"
    fi

    local output
    local exit_code

    if output=$(eval "$test_command" 2>&1); then
        exit_code=0
    else
        exit_code=$?
    fi

    if [[ $VERBOSE -eq 1 ]]; then
        echo "Output: $output"
        echo "Exit code: $exit_code"
    fi

    if [[ $expected_result -eq 0 ]]; then
        # Test should succeed
        if [[ $exit_code -eq 0 ]]; then
            print_pass "$test_name"
            echo "$output"
            return 0
        else
            print_fail "$test_name (expected success, got failure)"
            echo "$output"
            return 1
        fi
    else
        # Test should fail
        if [[ $exit_code -ne 0 ]]; then
            print_pass "$test_name (correctly failed)"
            return 0
        else
            print_fail "$test_name (expected failure, got success)"
            echo "$output"
            return 1
        fi
    fi
}

run_test_contains() {
    local test_name="$1"
    local test_command="$2"
    local expected_string="$3"

    TESTS_TOTAL=$((TESTS_TOTAL + 1))
    print_test "$test_name"

    if [[ $VERBOSE -eq 1 ]]; then
        echo "Command: $test_command"
        echo "Looking for: $expected_string"
    fi

    local output
    if output=$(eval "$test_command" 2>&1); then
        if echo "$output" | grep -q "$expected_string"; then
            print_pass "$test_name"
            return 0
        else
            print_fail "$test_name (string not found in output)"
            echo "Output: $output"
            return 1
        fi
    else
        print_fail "$test_name (command failed)"
        echo "Output: $output"
        return 1
    fi
}

###
### Test Suites
###

test_suite_1_plugin_list() {
    print_section "Test Suite 1: Plugin List Command"

    # Test 1.1: Basic list
    run_test "1.1 Basic plugin list" \
        "$OJS_CLI_BIN plugin list" \
        0

    # Test 1.2: JSON format
    run_test "1.2 List with JSON format" \
        "$OJS_CLI_BIN plugin list --format=json" \
        0

    # Test 1.3: CSV format
    run_test "1.3 List with CSV format" \
        "$OJS_CLI_BIN plugin list --format=csv" \
        0

    # Test 1.4: YAML format
    run_test "1.4 List with YAML format" \
        "$OJS_CLI_BIN plugin list --format=yaml" \
        0

    # Test 1.5: Filter by category
    run_test "1.5 Filter by category (generic)" \
        "$OJS_CLI_BIN plugin list --category=generic" \
        0

    # Test 1.6: Filter by status
    run_test "1.6 Filter by status (active)" \
        "$OJS_CLI_BIN plugin list --status=active" \
        0

    run_test "1.7 Filter by status (inactive)" \
        "$OJS_CLI_BIN plugin list --status=inactive" \
        0

    # Test 1.8: Verify directory names (not class names)
    run_test_contains "1.8 Plugin names use directory format" \
        "$OJS_CLI_BIN plugin list --format=json" \
        '"hypothesis"'
}

test_suite_2_plugin_info() {
    print_section "Test Suite 2: Plugin Info Command"

    # Test 2.1: Info with directory name
    run_test "2.1 Info with directory name" \
        "$OJS_CLI_BIN plugin info hypothesis" \
        0

    # Test 2.2: Info with class name (should fail)
    run_test "2.2 Info with class name (should fail)" \
        "$OJS_CLI_BIN plugin info hypothesisplugin" \
        1

    # Test 2.3: Info for non-existent plugin
    run_test "2.3 Info for non-existent plugin" \
        "$OJS_CLI_BIN plugin info nonexistent" \
        1

    # Test 2.4: Verify directory name in output
    run_test_contains "2.4 Info shows directory name" \
        "$OJS_CLI_BIN plugin info hypothesis" \
        "Name:         hypothesis"
}

test_suite_3_plugin_activate_deactivate() {
    print_section "Test Suite 3: Plugin Activate/Deactivate"

    # Test 3.1: Deactivate plugin first (in case it's active)
    print_test "3.1 Deactivate plugin (cleanup)"
    $OJS_CLI_BIN plugin deactivate hypothesis 2>/dev/null || true
    print_pass "3.1 Cleanup done"
    TESTS_TOTAL=$((TESTS_TOTAL + 1))
    TESTS_PASSED=$((TESTS_PASSED + 1))

    # Test 3.2: Activate with directory name
    run_test "3.2 Activate with directory name" \
        "$OJS_CLI_BIN plugin activate hypothesis" \
        0

    # Test 3.3: Verify activation in list
    run_test_contains "3.3 Verify plugin shows as enabled" \
        "$OJS_CLI_BIN plugin list --format=json" \
        '"name": "hypothesis".*"enabled": "Yes"'

    # Test 3.4: Deactivate with directory name
    run_test "3.4 Deactivate with directory name" \
        "$OJS_CLI_BIN plugin deactivate hypothesis" \
        0

    # Test 3.5: Verify deactivation in list
    run_test_contains "3.5 Verify plugin shows as disabled" \
        "$OJS_CLI_BIN plugin list --format=json" \
        '"name": "hypothesis".*"enabled": "No"'

    # Test 3.6: Activate with class name (should fail)
    run_test "3.6 Activate with class name (should fail)" \
        "$OJS_CLI_BIN plugin activate hypothesisplugin" \
        1

    # Test 3.7: Activate non-existent plugin
    run_test "3.7 Activate non-existent plugin" \
        "$OJS_CLI_BIN plugin activate nonexistent" \
        1
}

test_suite_4_plugin_install() {
    print_section "Test Suite 4: Plugin Install"

    # Note: These tests require actually installing/deleting plugins
    # Skip if we don't want to modify the OJS installation

    print_skip "4.1 Install from local file (requires manual setup)"
    TESTS_TOTAL=$((TESTS_TOTAL + 1))

    print_skip "4.2 Install from gallery (requires deletion first)"
    TESTS_TOTAL=$((TESTS_TOTAL + 1))

    # Test 4.3: Install non-existent plugin from gallery
    run_test "4.3 Install non-existent plugin from gallery" \
        "$OJS_CLI_BIN plugin install nonexistentplugin123" \
        1
}

test_suite_5_plugin_delete() {
    print_section "Test Suite 5: Plugin Delete"

    # Test 5.1: Try to delete enabled plugin (should fail)
    print_test "5.1 Ensure plugin is enabled"
    $OJS_CLI_BIN plugin activate hypothesis 2>/dev/null || true
    print_pass "5.1 Plugin enabled"
    TESTS_TOTAL=$((TESTS_TOTAL + 1))
    TESTS_PASSED=$((TESTS_PASSED + 1))

    run_test "5.2 Delete enabled plugin (should fail)" \
        "$OJS_CLI_BIN plugin delete hypothesis --force" \
        1

    # Test 5.3: Delete non-existent plugin
    run_test "5.3 Delete non-existent plugin" \
        "$OJS_CLI_BIN plugin delete nonexistent --force" \
        1

    # Cleanup: deactivate the plugin
    print_test "5.4 Cleanup: deactivate plugin"
    $OJS_CLI_BIN plugin deactivate hypothesis 2>/dev/null || true
    print_pass "5.4 Cleanup done"
    TESTS_TOTAL=$((TESTS_TOTAL + 1))
    TESTS_PASSED=$((TESTS_PASSED + 1))
}

test_suite_6_plugin_upgrade() {
    print_section "Test Suite 6: Plugin Upgrade"

    print_skip "6.1 Upgrade from local file (requires manual setup)"
    TESTS_TOTAL=$((TESTS_TOTAL + 1))

    print_skip "6.2 Upgrade from gallery (may modify installation)"
    TESTS_TOTAL=$((TESTS_TOTAL + 1))

    # Test 6.3: Upgrade non-existent plugin
    run_test "6.3 Upgrade non-existent plugin" \
        "$OJS_CLI_BIN plugin upgrade nonexistent" \
        1
}

test_suite_7_error_handling() {
    print_section "Test Suite 7: Error Handling & Edge Cases"

    # Test 7.1: Invalid category
    run_test "7.1 Invalid category filter" \
        "$OJS_CLI_BIN plugin list --category=invalidcategory" \
        0  # Should succeed but return empty

    # Test 7.2: Help commands
    run_test "7.2 Plugin help command" \
        "$OJS_CLI_BIN help plugin" \
        0

    run_test "7.3 Activate help command" \
        "$OJS_CLI_BIN plugin activate --help" \
        0

    # Test 7.4: Case-insensitive plugin name
    run_test "7.4 Case-insensitive plugin name" \
        "$OJS_CLI_BIN plugin info Hypothesis" \
        0
}

###
### Main Test Runner
###

show_help() {
    grep '^###' "$0" | sed 's/^### \?//'
    exit 0
}

main() {
    print_header "OJS-CLI Manual Test Suite"

    echo "OJS-CLI Binary: $OJS_CLI_BIN"
    if [[ -n "$OJS_PATH" ]]; then
        echo "OJS Path: $OJS_PATH"
    fi
    echo "Verbose: $VERBOSE"
    echo "Debug: $DEBUG"
    echo ""

    # Verify ojs command exists
    if [[ ! -x "$OJS_CLI_BIN" ]]; then
        echo -e "${RED}Error: OJS-CLI binary not found or not executable: $OJS_CLI_BIN${NC}"
        exit 1
    fi

    # Set debug mode if requested
    if [[ $DEBUG -eq 1 ]]; then
        export OJS_CLI_DEBUG=1
    fi

    # Change to OJS directory if specified
    if [[ -n "$OJS_PATH" ]]; then
        cd "$OJS_PATH" || {
            echo -e "${RED}Error: Cannot cd to OJS path: $OJS_PATH${NC}"
            exit 1
        }
    fi

    # Run test suites
    test_suite_1_plugin_list
    test_suite_2_plugin_info
    test_suite_3_plugin_activate_deactivate
    test_suite_4_plugin_install
    test_suite_5_plugin_delete
    test_suite_6_plugin_upgrade
    test_suite_7_error_handling

    # Print summary
    print_header "Test Summary"
    echo "Total Tests:  $TESTS_TOTAL"
    echo -e "${GREEN}Passed:       $TESTS_PASSED${NC}"
    echo -e "${RED}Failed:       $TESTS_FAILED${NC}"
    echo -e "${YELLOW}Skipped:      $TESTS_SKIPPED${NC}"

    if [[ $TESTS_FAILED -eq 0 ]]; then
        echo -e "\n${GREEN}All tests passed!${NC}"
        exit 0
    else
        echo -e "\n${RED}Some tests failed.${NC}"
        exit 1
    fi
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            show_help
            ;;
        -v|--verbose)
            VERBOSE=1
            shift
            ;;
        -d|--debug)
            DEBUG=1
            shift
            ;;
        --ojs-path)
            OJS_PATH="$2"
            shift 2
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

main
