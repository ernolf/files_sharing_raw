#!/bin/bash
# Patches Nextcloud's RouteParser.php to add files_sharing_raw to the rootUrlApps list.
# This enables clean /raw/{token} URLs instead of the longer /apps/files_sharing_raw/{token} fallback.
#
# Usage:
#   ./patch-route-parser.sh [path-to-RouteParser.php]
#
# Without an argument, the script auto-detects the Nextcloud root by walking up
# the directory tree from its own location until it finds 'occ'.
#
# Must be re-applied after every Nextcloud core update until
# https://github.com/nextcloud/server/pull/58648 is merged and shipped.

# Auto-detect Nextcloud root by walking up from the script's own location.
find_nextcloud_root() {
    local dir
    dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    while [ "$dir" != "/" ]; do
        if [ -f "$dir/occ" ]; then
            echo "$dir"
            return 0
        fi
        dir="$(dirname "$dir")"
    done
    return 1
}

if [ -n "$1" ]; then
    FILE="$1"
else
    NC_ROOT="$(find_nextcloud_root)"
    if [ -z "$NC_ROOT" ]; then
        echo "Could not find Nextcloud root (no 'occ' found in parent directories)."
        read -rp "Please enter the full path to RouteParser.php: " FILE
        FILE="${FILE//\~/$HOME}"
    else
        FILE="$NC_ROOT/lib/private/AppFramework/Routing/RouteParser.php"
    fi
fi

if [ ! -f "$FILE" ]; then
    echo "Error: file not found: $FILE"
    exit 1
fi

if grep -q "'files_sharing_raw'" "$FILE"; then
    echo "Already patched — 'files_sharing_raw' is present in $FILE"
    exit 0
fi

# Insert 'files_sharing_raw' on a new line directly after 'core'.
sed -i "s/'core',/'core',\n        'files_sharing_raw',/" "$FILE"

if grep -q "'files_sharing_raw'" "$FILE"; then
    echo "Patched successfully: $FILE"
else
    echo "Patch failed — please add 'files_sharing_raw' manually to the rootUrlApps array in $FILE"
    exit 1
fi
