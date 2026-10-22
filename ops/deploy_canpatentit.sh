#!/usr/bin/env bash
# Build, publish and verify canpatentit.com. Run from the repo root:  bash ops/deploy_canpatentit.sh
# Backs up the live site first. Does not touch the portal or nginx.
set -euo pipefail
cd "$(dirname "$0")/.."
python tools/cpi-site/build.py
tar -czf /tmp/canpatentit.tar.gz -C marketing-canpatentit .
tar -czf /tmp/cpi-shells.tar.gz backend/resources/cpi
scp -q /tmp/canpatentit.tar.gz /tmp/cpi-shells.tar.gz root@139.59.85.216:/tmp/
ssh root@139.59.85.216 '
  set -e
  stamp=$(date +%Y%m%d%H%M%S)
  cp -a /var/www/canpatentit /root/backup-ops-20261015/canpatentit-before-$stamp
  tar -xzf /tmp/canpatentit.tar.gz -C /var/www/canpatentit --overwrite
  tar -xzf /tmp/cpi-shells.tar.gz -C /var/www/mypl-cms --overwrite
  chown -R www-data:www-data /var/www/canpatentit /var/www/mypl-cms/backend/resources/cpi
  cd /var/www/mypl-cms/backend && sudo -u www-data env HOME=/tmp php artisan cpi:republish | tail -2
  rm -f /tmp/canpatentit.tar.gz /tmp/cpi-shells.tar.gz'
for u in / /favicon.ico /llms.txt /llms-full.txt /sitemap.xml; do printf '%s ' "$u"; curl -s -o /dev/null -w '%{http_code}\n' "https://canpatentit.com$u"; done
bash ops/indexnow.sh || echo "IndexNow step failed (site is still deployed)"
