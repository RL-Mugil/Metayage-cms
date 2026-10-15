#!/usr/bin/env bash
# Step 1: copy the changed files to the server's /tmp (nothing is installed yet). Run from the repo root.
set -euo pipefail
scp backend/app/Services/ZohoMirrorSync.php backend/app/Http/Controllers/ZohoSyncController.php ops/ipflow_monitor.sh root@139.59.85.216:/tmp/
