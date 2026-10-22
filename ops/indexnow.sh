#!/usr/bin/env bash
# Tell Bing, Yandex and other IndexNow search engines which canpatentit.com pages changed.
#   bash ops/indexnow.sh            (run from the repo root, after the site is deployed)
# Sends only public page URLs taken from the live sitemaps. The key file is served at /<key>.txt (see build.py).
set -euo pipefail
KEY=$(sed -n 's/^INDEXNOW_KEY = "\([0-9a-f]*\)".*/\1/p' "$(dirname "$0")/../tools/cpi-site/build.py")
[ -n "$KEY" ] || { echo "no IndexNow key found in build.py"; exit 1; }
HOST=canpatentit.com
curl -fsS "https://$HOST/$KEY.txt" | grep -qx "$KEY" || { echo "key file is not live at https://$HOST/$KEY.txt - deploy the site first"; exit 1; }
URLS=$( { curl -fsS "https://$HOST/sitemap-pages.xml"; curl -fsS "https://$HOST/insights/sitemap-insights.xml"; curl -fsS "https://$HOST/patent-showcase/sitemap-patents.xml"; } | sed -n 's#.*<loc>\([^<]*\)</loc>.*#\1#p' )
LIST=$(printf '%s\n' $URLS | sed 's/.*/"&"/' | paste -sd, -)
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "https://api.indexnow.org/indexnow" -H 'Content-Type: application/json; charset=utf-8' \
  -d "{\"host\":\"$HOST\",\"key\":\"$KEY\",\"keyLocation\":\"https://$HOST/$KEY.txt\",\"urlList\":[${LIST}]}")
echo "IndexNow responded HTTP $CODE for $(printf '%s\n' $URLS | wc -l) URLs (200 or 202 = accepted)"
