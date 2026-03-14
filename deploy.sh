#!/bin/bash
#
# Simple deployment script to copy src and public folders to a remote server
# Uses rsync for efficient incremental transfers
# 
# Usage:
#   ./deploy.sh                    # Uses defaults (user@host and path from script)
#   ./deploy.sh user@host          # Uses provided host, default path
#   ./deploy.sh user@host /path   # Uses provided host and path
#
# Defaults (edit these for your environment):
#   REMOTE_USER="deploy"
#   REMOTE_HOST="example.com"
#   REMOTE_PATH="/var/www/phuppi"

set -e

# Default values - edit these to match your server
REMOTE_USER="deploy"
REMOTE_HOST="example.com"
REMOTE_PATH="/var/www/phuppi"

# Parse command line arguments
case "$#" in
    0)
        # Use defaults - no arguments provided
        ;;
    1)
        REMOTE_HOST="$1"
        ;;
    2)
        REMOTE_HOST="$1"
        REMOTE_PATH="$2"
        ;;
    *)
        echo "Usage: $0 [user@host] [remote_path]"
        echo "  If no arguments provided, uses defaults in the script"
        echo "  If only host provided, uses default path"
        exit 1
        ;;
esac

# Parse user@host format if provided
if [[ "$REMOTE_HOST" == *"@"* ]]; then
    REMOTE_USER="${REMOTE_HOST%%@*}"
    REMOTE_HOST="${REMOTE_HOST#*@}"
fi

echo "Deploying to ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"
echo ""

# Rsync options:
# -a: archive mode (preserves permissions, timestamps, etc.)
# -z: compress data during transfer
# -h: human-readable output
# --delete: remove files in destination that don't exist in source
# --progress: show progress during transfer
RSYNC_OPTS="-azh --delete --progress"

# Copy src folder
echo "Syncing src folder..."
rsync $RSYNC_OPTS ./src/ "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/src/"

# Copy public folder
echo "Syncing public folder..."
rsync $RSYNC_OPTS ./public/ "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/public/"

echo ""
echo "Deployment complete!"
