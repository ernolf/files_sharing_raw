#!/bin/bash
# Patches Nextcloud's RouteParser.php to add files_sharing_raw to the rootUrlApps list.
# This enables clean /raw/{token} URLs instead of the longer /apps/files_sharing_raw/{token} fallback.
#
# Usage:
#   ./patch-route-parser.sh [path-to-RouteParser.php]
#
# Default path: /var/www/nextcloud/lib/private/AppFramework/Routing/RouteParser.php
#
# Must be re-applied after every Nextcloud core update until
# https://github.com/nextcloud/server/pull/58648 is merged and shipped.

FILE="${1:-/var/www/nextcloud/lib/private/AppFramework/Routing/RouteParser.php}"

if [ ! -f "$FILE" ]; then
    echo "Error: file not found: $FILE"
    exit 1
fi

if grep -q "'files_sharing_raw'" "$FILE"; then
    echo "Already patched — 'files_sharing_raw' is present in $FILE"
    exit 0
fi

# Insert 'files_sharing_raw', on a new line directly after 'core',
sed -i "s/'core',/'core',\n        'files_sharing_raw',/" "$FILE"

if grep -q "'files_sharing_raw'" "$FILE"; then
    echo "Patched successfully: $FILE"
else
    echo "Patch failed — please add 'files_sharing_raw' manually to the rootUrlApps array in $FILE"
    exit 1
fi
