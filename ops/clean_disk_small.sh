#!/usr/bin/env bash
# Small, safe cleanup: old deploy tarballs in /tmp, npm/pip-style caches in /root, apt package cache.
#   ssh root@139.59.85.216 'bash -s' < ops/clean_disk_small.sh
# Touches only: /tmp/*.tar.gz and /tmp/*.tgz older than a day (uploads that were already extracted),
# /root/.npm, /root/.cache (re-created on demand), and the apt download cache. Nothing else.
set -u
echo "before: $(df -h / | tail -1)"
find /tmp -maxdepth 1 -type f \( -name '*.tar.gz' -o -name '*.tgz' \) -mtime +1 -print -delete
rm -rf /root/.npm /root/.cache
apt-get clean
echo "after:  $(df -h / | tail -1)"
curl -s -o /dev/null -w "portal health http %{http_code}\n" https://login.myipstrategy.com/api/health
