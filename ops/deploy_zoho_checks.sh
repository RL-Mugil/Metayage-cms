#!/usr/bin/env bash
# Deploy the Zoho daily deletion checks + the backup-size check in the monitor.
# Run from the repo root:  ssh root@139.59.85.216 'bash -s' < ops/deploy_zoho_checks.sh   (after copying the files, see below)
# Files are pushed by ops/deploy_zoho_checks_push.sh first (scp to /tmp), then installed here.
set -euo pipefail
B=/var/www/mypl-cms/backend
for f in ZohoMirrorSync.php ZohoSyncController.php ipflow_monitor.sh; do [ -f /tmp/$f ] || { echo "missing /tmp/$f - run the push script first"; exit 1; }; done
php -l /tmp/ZohoMirrorSync.php; php -l /tmp/ZohoSyncController.php; bash -n /tmp/ipflow_monitor.sh
mkdir -p /root/backup-ops-20261015/zoho-reconcile
cp $B/app/Services/ZohoMirrorSync.php /root/backup-ops-20261015/zoho-reconcile/ZohoMirrorSync.php.before-checks
cp $B/app/Http/Controllers/ZohoSyncController.php /root/backup-ops-20261015/zoho-reconcile/ZohoSyncController.php.before-checks
cp /opt/ipflow_monitor.sh /root/backup-ops-20261015/ipflow_monitor.sh.before-sizecheck
install -o www-data -g www-data -m 644 /tmp/ZohoMirrorSync.php $B/app/Services/ZohoMirrorSync.php
install -o www-data -g www-data -m 644 /tmp/ZohoSyncController.php $B/app/Http/Controllers/ZohoSyncController.php
install -m 755 /tmp/ipflow_monitor.sh /opt/ipflow_monitor.sh
rm -f /tmp/ZohoMirrorSync.php /tmp/ZohoSyncController.php /tmp/ipflow_monitor.sh
cd $B
sudo -u www-data env HOME=/tmp php artisan optimize:clear >/dev/null
sudo -u www-data env HOME=/tmp php artisan optimize >/dev/null
supervisorctl restart mypl-horizon | tail -1
echo "health: $(curl -s https://login.myipstrategy.com/api/health)"
/opt/ipflow_monitor.sh --status | grep -v '^OK' || echo "all monitor checks OK"
