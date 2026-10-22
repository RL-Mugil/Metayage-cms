#!/usr/bin/env bash
# Patch PHP on the server (security update), keeping our configuration, then restart everything that runs PHP and verify.
#   ssh root@139.59.85.216 'bash -s' < ops/update_php.sh          (or paste into the DigitalOcean web console)
# Safe to re-run. Takes a copy of the PHP configuration first, never replaces our php.ini/pool files, and reloads
# PHP-FPM gracefully so the portal is only briefly interrupted.
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive
BK=/root/backup-ops-20261015/php-update-$(date +%Y%m%d%H%M%S)
mkdir -p "$BK"

echo "=== before ==="
php -v | head -1
dpkg -l | awk '/^ii +php[0-9.]*-(cli|fpm|common)/{print $2, $3}'
df -h / | tail -1

echo "=== backup of PHP and pool configuration -> $BK ==="
cp -a /etc/php "$BK/etc-php"
cp /etc/nginx/sites-enabled/* "$BK/" 2>/dev/null || true

echo "=== package lists ==="
apt-get update -qq 2>&1 | tail -3
echo "--- PHP packages that will be upgraded:"
apt-get -s upgrade 2>/dev/null | grep -E '^Inst php' | awk '{print $2, $3, $4}'

echo "=== upgrading installed PHP packages only (existing config files are kept) ==="
PKGS=$(dpkg -l | awk '/^ii +php/{print $2}' | tr '\n' ' ')
# shellcheck disable=SC2086
apt-get install -y --only-upgrade -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef $PKGS 2>&1 | tail -15

echo "=== restart everything that runs PHP ==="
for v in $(ls /etc/php); do
  if systemctl list-unit-files "php${v}-fpm.service" >/dev/null 2>&1 && systemctl is-enabled "php${v}-fpm" >/dev/null 2>&1; then
    php-fpm${v} -t 2>&1 | tail -1 && systemctl reload "php${v}-fpm" && echo "reloaded php${v}-fpm" || echo "WARNING: php${v}-fpm did not reload"
  fi
done
# Long-running CLI workers keep the old binary in memory until restarted.
supervisorctl restart mypl-horizon mypl-scheduler mypl-reverb 2>&1 | tail -4
systemctl restart idpd-worker 2>/dev/null || true

echo "=== after ==="
php -v | head -1
dpkg -l | awk '/^ii +php[0-9.]*-(cli|fpm|common)/{print $2, $3}'
systemctl is-active php8.4-fpm
curl -s -o /dev/null -w "portal health HTTP %{http_code}\n" https://login.myipstrategy.com/api/health
curl -s https://login.myipstrategy.com/api/health; echo
curl -s -o /dev/null -w "canpatentit HTTP %{http_code}\n" https://canpatentit.com/
(cd /var/www/mypl-cms/backend && sudo -u www-data env HOME=/tmp php artisan about --only=environment 2>/dev/null | grep -i "php version")
/opt/ipflow_monitor.sh --status | grep -v '^OK' || echo "all monitor checks OK"
echo "Done. Backup of the old configuration: $BK"
