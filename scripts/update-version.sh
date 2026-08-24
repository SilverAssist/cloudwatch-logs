#!/bin/bash

###############################################################################
# Silver Assist CloudWatch Logs - Version Update Script
#
# A robust version updater that handles macOS sed quirks
#
# Usage: ./scripts/update-version.sh <new-version> [--no-confirm]
# Example: ./scripts/update-version.sh 1.2.0
#
# @package RevalidatePosts
# @since 1.1.0
# @author Silver Assist
# @version 1.8.0
###############################################################################

# Note: Removed set -e to allow script to continue on minor errors/warnings
# The script will show warnings but continue processing all files

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Validate input
if [ $# -eq 0 ]; then
    print_error "No version specified"
    echo "Usage: $0 <new-version> [--no-confirm]"
    echo "Example: $0 1.2.0"
    echo "Example: $0 1.2.0 --no-confirm"
    exit 1
fi

# Check for help option
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    echo "Silver Assist CloudWatch Logs - Version Update Script"
    echo ""
    echo "Usage: $0 <new-version> [--no-confirm]"
    echo ""
    echo "Arguments:"
    echo "  <new-version>    New version in semantic versioning format (e.g., 1.2.0)"
    echo "  --no-confirm     Skip confirmation prompts (useful for CI/CD)"
    echo ""
    echo "Examples:"
    echo "  $0 1.2.0"
    echo "  $0 1.2.0 --no-confirm"
    echo ""
    echo "This script updates version numbers across all plugin files including:"
    echo "  • Main plugin file header and constants"
    echo "  • PHP files @version tags"
    echo "  • Documentation files"
    echo "  • Script files"
    echo ""
    exit 0
fi

NEW_VERSION="$1"
NO_CONFIRM=false

# Parse arguments
if [ $# -eq 2 ] && [ "$2" = "--no-confirm" ]; then
    NO_CONFIRM=true
elif [ $# -gt 1 ] && [ "$2" != "--no-confirm" ]; then
    print_error "Invalid argument: $2"
    echo "Usage: $0 <new-version> [--no-confirm]"
    exit 1
fi

# Validate version format
if ! [[ $NEW_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    print_error "Invalid version format. Use semantic versioning (e.g., 1.2.0)"
    exit 1
fi

# Get project root
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

print_status "Updating Silver Assist CloudWatch Logs to version ${NEW_VERSION}"
print_status "Project root: ${PROJECT_ROOT}"

# Check if we're in the right directory
if [ ! -f "${PROJECT_ROOT}/silver-assist-cloudwatch-logs.php" ]; then
    print_error "Main plugin file not found. Make sure you're running this from the project root."
    exit 1
fi

# Get current version
CURRENT_VERSION=$(grep -o "Version: [0-9]\+\.[0-9]\+\.[0-9]\+" "${PROJECT_ROOT}/silver-assist-cloudwatch-logs.php" | cut -d' ' -f2)

if [ -z "$CURRENT_VERSION" ]; then
    print_error "Could not detect current version"
    exit 1
fi

print_status "Current version: ${CURRENT_VERSION}"
print_status "New version: ${NEW_VERSION}"

# Check if versions are the same
if [ "$CURRENT_VERSION" = "$NEW_VERSION" ]; then
    print_warning "Current version and new version are the same (${NEW_VERSION})"
    if [ "$NO_CONFIRM" = false ]; then
        echo ""
        read -p "$(echo -e ${YELLOW}[CONFIRM]${NC} Continue anyway? [y/N]: )" -n 1 -r
        echo ""
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            print_warning "Version update cancelled"
            exit 0
        fi
    else
        print_status "Same version detected in CI mode - exiting successfully (no changes needed)"
        exit 0
    fi
else
    # Confirm with user only if not in no-confirm mode
    if [ "$NO_CONFIRM" = false ]; then
        echo ""
        read -p "$(echo -e ${YELLOW}[CONFIRM]${NC} Update version from ${CURRENT_VERSION} to ${NEW_VERSION}? [y/N]: )" -n 1 -r
        echo ""

        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            print_warning "Version update cancelled"
            exit 0
        fi
    else
        print_status "Running in non-interactive mode (--no-confirm)"
    fi
fi

echo ""
print_status "Starting version update process..."

# Initialize deferred commands file (for self-modification)
rm -f "${PROJECT_ROOT}/.version_update_deferred"

# Function to update file using perl (more reliable than sed on macOS)
update_file() {
    local file="$1"
    local pattern="$2"
    local description="$3"
    
    if [ -f "$file" ]; then
        # Special handling for the script modifying itself
        local current_script="${BASH_SOURCE[0]}"
        local current_script_abs="$(cd "$(dirname "$current_script")" && pwd)/$(basename "$current_script")"
        local file_abs="$(cd "$(dirname "$file")" && pwd)/$(basename "$file")"
        
        # Check if this script is trying to modify itself
        if [ "$current_script_abs" = "$file_abs" ]; then
            print_status "  Deferring self-modification for $description"
            # Store the modification for later execution
            echo "perl -i -pe '$pattern' '$file'" >> "${PROJECT_ROOT}/.version_update_deferred"
            return 0
        fi
        
        # Create backup
        cp "$file" "$file.bak" 2>/dev/null || {
            print_warning "  Could not create backup for $description - skipping"
            return 0
        }
        
        # Apply perl substitution
        if perl -i -pe "$pattern" "$file" 2>/dev/null; then
            # Verify the change was made
            if ! cmp -s "$file" "$file.bak" 2>/dev/null; then
                print_status "  Updated $description"
                rm "$file.bak" 2>/dev/null || true
                return 0
            else
                print_warning "  No changes made to $description (pattern not found or already updated)"
                mv "$file.bak" "$file" 2>/dev/null || true
                return 0
            fi
        else
            print_warning "  Could not process $description (perl substitution failed)"
            mv "$file.bak" "$file" 2>/dev/null || true
            return 0
        fi
    else
        print_warning "  File not found: $file"
        return 0
    fi
}

# 1. Update main plugin file
print_status "Updating main plugin file..."

# Update plugin header
update_file "${PROJECT_ROOT}/silver-assist-cloudwatch-logs.php" \
    "s/Version: [0-9]+\\.[0-9]+\\.[0-9]+/Version: ${NEW_VERSION}/g" \
    "plugin header"

# Update constant - FIXED: Removed excessive escaping of single quotes
update_file "${PROJECT_ROOT}/silver-assist-cloudwatch-logs.php" \
    "s/define\\( 'SILVER_ASSIST_REVALIDATE_VERSION', '[0-9]+\\.[0-9]+\\.[0-9]+' \\)/define( 'SILVER_ASSIST_REVALIDATE_VERSION', '${NEW_VERSION}' )/g" \
    "plugin constant"

# Update @version tag
update_file "${PROJECT_ROOT}/silver-assist-cloudwatch-logs.php" \
    "s/\\@version [0-9]+\\.[0-9]+\\.[0-9]+/\\@version ${NEW_VERSION}/g" \
    "main file @version tag"

print_success "Main plugin file processing completed"

# 2. Update PHP files in Includes/
print_status "Updating PHP files..."

# Get all PHP files with @version tags
php_files=""
if [ -d "${PROJECT_ROOT}/includes" ]; then
    # Use a more robust approach to find PHP files
    for php_file in $(find "${PROJECT_ROOT}/includes" -name "*.php" 2>/dev/null); do
        if [ -f "$php_file" ] && grep -q "@version" "$php_file"; then
            php_files="$php_files $php_file"
        fi
    done
fi

# Update each PHP file
if [ -n "$php_files" ]; then
    php_update_count=0
    for php_file in $php_files; do
        file_name=$(basename "$php_file")
        
        update_file "$php_file" \
            "s/\\@version [0-9]+\\.[0-9]+\\.[0-9]+/\\@version ${NEW_VERSION}/g" \
            "$file_name"
        
        php_update_count=$((php_update_count + 1))
    done
    
    print_success "PHP files processed ($php_update_count files)"
else
    print_warning "No PHP files with @version tags found in Includes/ directory"
fi

# 3. Update build release script
print_status "Updating build release script..."

if [ -f "${PROJECT_ROOT}/scripts/build-release.sh" ]; then
    update_file "${PROJECT_ROOT}/scripts/build-release.sh" \
        "s/\\@version [0-9]+\\.[0-9]+\\.[0-9]+/\\@version ${NEW_VERSION}/g" \
        "build-release.sh"
    print_success "Build release script processed"
else
    print_warning "build-release.sh not found"
fi

# 4. Update version scripts
print_status "Updating version scripts..."

# Get all script files with @version tags
script_files=""
if [ -d "${PROJECT_ROOT}/scripts" ]; then
    for script_file in "${PROJECT_ROOT}/scripts"/*.sh; do
        if [ -f "$script_file" ] && grep -q "@version" "$script_file"; then
            script_files="$script_files $script_file"
        fi
    done
fi

# Update each script file
if [ -n "$script_files" ]; then
    script_update_count=0
    for script_file in $script_files; do
        script_name=$(basename "$script_file")
        
        update_file "$script_file" \
            "s/\\@version [0-9]+\\.[0-9]+\\.[0-9]+/\\@version ${NEW_VERSION}/g" \
            "$script_name"
        
        script_update_count=$((script_update_count + 1))
    done
    
    print_success "Version scripts processed ($script_update_count files)"
else
    print_warning "No script files with @version tags found in scripts/ directory"
fi

# 5. Update README.md if it contains version references
print_status "Checking README.md for version references..."

if [ -f "${PROJECT_ROOT}/README.md" ]; then
    if grep -q "Version: [0-9]\+\.[0-9]\+\.[0-9]\+" "${PROJECT_ROOT}/README.md" 2>/dev/null; then
        update_file "${PROJECT_ROOT}/README.md" \
            "s/Version: [0-9]+\\.[0-9]+\\.[0-9]+/Version: ${NEW_VERSION}/g" \
            "README.md version references"
        print_success "README.md processed"
    else
        print_status "No version references found in README.md"
    fi
else
    print_warning "README.md not found"
fi

echo ""
print_success "✨ Version update completed successfully!"

# Execute any deferred modifications (like self-modification)
if [ -f "${PROJECT_ROOT}/.version_update_deferred" ]; then
    print_status "Executing deferred modifications..."
    
    while IFS= read -r command; do
        if [ -n "$command" ]; then
            print_status "  Executing: $command"
            if eval "$command" 2>/dev/null; then
                print_status "  ✓ Deferred modification completed"
            else
                print_warning "  ⚠ Deferred modification failed (continuing anyway)"
            fi
        fi
    done < "${PROJECT_ROOT}/.version_update_deferred"
    
    # Clean up deferred commands file
    rm -f "${PROJECT_ROOT}/.version_update_deferred"
    
    print_success "Deferred modifications completed"
fi

echo ""
print_status "Summary of changes:"
echo "  • Main plugin file: silver-assist-cloudwatch-logs.php"
echo "  • PHP files: includes/**/*.php"
echo "  • Documentation: README.md (if applicable)"
echo "  • Update scripts: scripts/*.sh"
echo ""
print_status "Next steps:"
echo "  1. Review the changes: git diff"
echo "  2. Test the plugin with new version"
echo "  3. Update CHANGELOG.md with version ${NEW_VERSION} changes (REQUIRED)"
echo "  4. Run validation: vendor/bin/phpcs && vendor/bin/phpstan analyse Includes/"
echo "  5. Run tests: vendor/bin/phpunit"
echo "  6. Generate build: bash scripts/build-release.sh"
echo "  7. Commit changes: git add . && git commit -m 'chore: Update version to ${NEW_VERSION}'"
echo "  8. Create tag: git tag -a v${NEW_VERSION} -m 'Release v${NEW_VERSION}'"
echo "  9. Push changes: git push origin master && git push origin v${NEW_VERSION}"
echo " 10. Create GitHub release with release notes"
echo ""
print_warning "Remember: This script only updates @version tags, not @since tags!"
print_warning "New files should have their @since tag set manually to the version when they were introduced."
print_warning "🚨 IMPORTANT: Don't forget to update CHANGELOG.md with v${NEW_VERSION} changes before committing!"
