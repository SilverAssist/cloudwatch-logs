#!/usr/bin/env bash
#
# Thin wrapper — delegates to the shared script in silverassist/wp-coding-standards
# so local dev commands and docs referencing `scripts/run-quality-checks.sh` keep
# working without every consumer hand-maintaining its own copy.
#
# @package SilverAssist\CloudWatchLogs
#
set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$SCRIPT_DIR/../vendor/silverassist/wp-coding-standards/scripts/run-quality-checks.sh" "$@"
