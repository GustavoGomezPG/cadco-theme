#!/usr/bin/env bash
#
# Run the media integration suite.
#
#   tests/integration/run-media.sh
#
# The suite isolates itself: it runs inside one database transaction that it
# never commits, and deletes the files it adds to uploads. See the header of
# media-integration.php.
#
# WP  the WP-CLI command for the site (default: wp)

set -euo pipefail

WP="${WP:-wp}"
HERE="$(cd "$(dirname "$0")" && pwd)"

$WP eval-file "$HERE/media-integration.php" 2> >(grep -v "gs: command not found" >&2)
