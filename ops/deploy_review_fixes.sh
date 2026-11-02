#!/usr/bin/env bash
# Install the code-review fixes (booking locking and audit, ops incident recording, poll exit codes, slot range queries).
#   1) (repo root)  bash ops/deploy_review_fixes.sh push     copies the backend files to /tmp/rf on the server
#   2)              bash ops/deploy_review_fixes.sh install  backs up the old files, installs these, reloads PHP, checks health
# Uses the Tailscale alias (~/.ssh/config: Host idpd-do). No migrations are involved.
set -euo pipefail
HOST=idpd-do
# Override with FILES="app/... app/..." to deploy a different set of backend files the same way.
FILES="${FILES:-app/Console/Commands/PollZohoBooks.php app/Services/OpsIncidentService.php app/Services/CpiBookingService.php
app/Http/Controllers/CpiBookingController.php app/Services/ZohoMirrorSync.php}"
case "${1:-}" in
  push)
    ssh $HOST 'rm -rf /tmp/rf && mkdir -p /tmp/rf'
    (cd backend && tar czf - $FILES) | ssh $HOST 'tar xzf - -C /tmp/rf'
    echo "pushed $(ssh $HOST 'find /tmp/rf -type f | wc -l') files" ;;
  install)
    ssh $HOST 'bash -s' <<'REMOTE'
set -euo pipefail
B=/var/www/mypl-cms/backend; D=/tmp/rf; BK=/root/backup-ops-20261015/review-fixes-$(date +%Y%m%d%H%M%S)
[ -d $D/app ] || { echo "run the push step first"; exit 1; }
mkdir -p $BK
for f in $(cd $D && find app -type f); do
  php -l $D/$f >/dev/null
  [ -f $B/$f ] && { mkdir -p $BK/$(dirname $f); cp $B/$f $BK/$f; }
  mkdir -p $B/$(dirname $f); install -o www-data -g www-data -m 644 $D/$f $B/$f
done
rm -rf $D
cd $B
sudo -u www-data env HOME=/tmp php artisan optimize:clear >/dev/null
sudo -u www-data env HOME=/tmp php artisan optimize >/dev/null
supervisorctl restart mypl-horizon mypl-scheduler | tail -2
systemctl reload php8.4-fpm   # opcache keeps old class files until PHP-FPM reloads
echo "backup of the previous files: $BK"
curl -s https://login.myipstrategy.com/api/health; echo
curl -s -o /dev/null -w "range HTTP %{http_code}\n" https://login.myipstrategy.com/api/public/cpi/range
curl -s -o /dev/null -w "canpatentit HTTP %{http_code}\n" https://canpatentit.com/
/opt/ipflow_monitor.sh --status | grep -v '^OK' || echo "monitor: all checks OK"
REMOTE
    ;;
  *) echo "usage: $0 push|install"; exit 1 ;;
esac
