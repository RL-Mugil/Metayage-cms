#!/usr/bin/env bash
# Unattended 500-user read-only load test. Waits for the quiet window, creates 100 temporary users (the API
# limits each user to 120 requests/min), runs sampler + k6, ALWAYS removes the users and tokens afterwards,
# and collects the results. Usage: run_fulltest.sh <start-epoch-utc> <k6.exe> <results-dir>
set -u
START="$1"; K6="$2"; OUT="$3"
KEY="$HOME/.ssh/id_ed25519"; SRV=root@139.59.85.216
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(dirname "$OUT")/lt-work"; mkdir -p "$OUT" "$WORK"; chmod 700 "$WORK"
ssh_() { ssh -i "$KEY" -o ServerAliveInterval=30 "$SRV" "$@"; }
log() { echo "$(date -u '+%F %T') $*" | tee -a "$OUT/run.log"; }

cleanup() {
  log "cleanup: removing temporary users and tokens"
  ssh_ 'cd /var/www/mypl-cms/backend && sudo -u www-data env HOME=/tmp php artisan tinker --execute="require \"/tmp/cleanup_users.php\";"' 2>&1 | tail -1 | tee -a "$OUT/run.log"
  rm -f "$WORK/tokens.json"
  ssh_ 'rm -f /var/lib/ipflow-monitor/muted'
}
trap cleanup EXIT

log "waiting until epoch $START ($(date -u -d @"$START" '+%F %T') UTC)"
while [ "$(date +%s)" -lt "$START" ]; do sleep 20; done

# Do not start if the server is already unhealthy.
code=$(curl -s -o /dev/null -m 10 -w '%{http_code}' https://login.myipstrategy.com/api/health)
[ "$code" = "200" ] || { log "ABORT: health check returned $code before start"; exit 1; }

log "creating 100 temporary users"
scp -i "$KEY" "$HERE/setup_users.php" "$HERE/cleanup_users.php" "$HERE/sampler.sh" "$SRV:/tmp/" >/dev/null
ssh_ 'chmod 644 /tmp/setup_users.php /tmp/cleanup_users.php; chmod 755 /tmp/sampler.sh; cd /var/www/mypl-cms/backend && sudo -u www-data env HOME=/tmp LT_USERS=100 php artisan tinker --execute="require \"/tmp/setup_users.php\";"' 2>&1 | tail -1 | tee -a "$OUT/run.log"
scp -i "$KEY" "$SRV:/tmp/lt-tokens.json" "$WORK/tokens.json" >/dev/null && chmod 600 "$WORK/tokens.json"
ssh_ 'shred -u /tmp/lt-tokens.json 2>/dev/null || rm -f /tmp/lt-tokens.json'
cp "$HERE/loadtest.js" "$WORK/"

# Mute the resource alerts (not outage alerts) for the test window so the team is not paged by expected load.
ssh_ 'echo "$(( $(date +%s) + 2400 )) load memory phpfpm queue failed_jobs" > /var/lib/ipflow-monitor/muted'
log "starting server sampler (32 min) and k6 (30 min)"
ssh_ 'nohup /tmp/sampler.sh 1920 /tmp/loadtest-samples.csv >/dev/null 2>&1 &' 
( cd "$WORK" && "$K6" run -e TOKENS_FILE=tokens.json -e PROFILE=full --summary-export "$OUT/summary.json" loadtest.js > "$OUT/k6-output.txt" 2>&1 ); rc=$?
log "k6 finished with exit code $rc (0 = all thresholds passed; 99 = a threshold failed/aborted)"

sleep 40
scp -i "$KEY" "$SRV:/tmp/loadtest-samples.csv" "$OUT/server-samples.csv" >/dev/null 2>&1
ssh_ 'journalctl -u php8.4-fpm --since "-45 min" --no-pager 2>/dev/null | grep -ciE "max_children" ; tail -n 5 /var/log/ipflow-monitor.log' 2>&1 | tee -a "$OUT/run.log"
log "done"
