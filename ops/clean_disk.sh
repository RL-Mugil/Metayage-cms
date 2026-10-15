#!/usr/bin/env bash
# Disk cleanup (approved items 1-4): old IDPD releases, the June IDPD source tarball, unused Docker images, journal logs.
#   ssh root@139.59.85.216 'bash -s' < ops/clean_disk.sh
# Keeps: the live IDPD release (the /var/www/idpd symlink target) plus the two newest releases for rollback.
# Skips any release a running process still references.
set -u
cd /var/www
LIVE=$(readlink -f idpd)
[ -d "$LIVE" ] || { echo "ABORT: cannot resolve the live IDPD release"; exit 1; }
echo "live release: $LIVE"
echo "before: $(df -h / | tail -1)"

KEEP=$(ls -1 idpd-releases | sort | tail -2)
echo "keeping (newest two): $KEEP"
for d in $(ls -1 idpd-releases); do
  p=/var/www/idpd-releases/$d
  [ "$p" = "$LIVE" ] && continue
  echo "$KEEP" | grep -qx "$d" && continue
  if ps -eo args | grep -v grep | grep -q "$p"; then echo "SKIP (in use): $d"; continue; fi
  rm -rf "$p" && echo "removed $d"
done

rm -fv /root/idpd-source-backup-20260625T200050Z.tar.gz
docker image prune -a -f | tail -2     # only images no container uses
journalctl --vacuum-size=100M 2>&1 | tail -1

echo "after:  $(df -h / | tail -1)"
echo "releases left: $(ls idpd-releases | tr '\n' ' ')"
echo "idpd -> $(readlink -f idpd)"
curl -s -o /dev/null -w "portal health http %{http_code}\n" https://login.myipstrategy.com/api/health
docker ps --format '{{.Names}} {{.Status}}' | head -20
