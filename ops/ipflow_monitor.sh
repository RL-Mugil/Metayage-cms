#!/usr/bin/env bash
#
# IPFlow / CanPatentIt uptime and health monitor. Runs every minute from cron, outside the Laravel app, so it
# still works when PHP or the database is broken (same idea as /opt/idpd_backup_monitor.sh).
#
# - A check must fail 2 runs in a row before an alert (no flapping), then re-alerts every 30 minutes while it
#   stays down, and sends one RECOVERED message.
# - Alerts go to every active super_admin (system admins) and partner (directors): in-app notification + email
#   (see ipflow_alert.py).
# - Every run pings a healthchecks.io-style URL (HEALTHCHECK_URL in /etc/ipflow-monitor.env). If the whole server
#   stops, that service notices the missing ping and emails you.
#
# Usage:  ipflow_monitor.sh              normal run (cron)
#         ipflow_monitor.sh --status     print every check result, send nothing
#         ipflow_monitor.sh --test-alert send a test alert to the admins and directors
#
set -uo pipefail

APP_DIR="/var/www/mypl-cms/backend"
STATE_DIR="/var/lib/ipflow-monitor"
LOG="/var/log/ipflow-monitor.log"
ALERT="/opt/ipflow_alert.py"
CONF="/etc/ipflow-monitor.env"
FAILS_BEFORE_ALERT=2
REALERT_SECS=1800

# Thresholds
MIN_AVAIL_MB=600        # MemAvailable below this is a real memory emergency (normal is 4 GB+)
MAX_DISK_PCT=90
MAX_LOAD5_PER_CORE=2    # 5-minute load average per core
MAX_QUEUE_BACKLOG=1000
MAX_RECENT_FAILED_JOBS=5

MODE="${1:-run}"
mkdir -p "$STATE_DIR"
exec 9>"$STATE_DIR/.lock"
flock -n 9 || exit 0

# shellcheck disable=SC1090
[ -f "$CONF" ] && source "$CONF"

# Credentials come from the app's own .env; values are never printed.
getenv() { grep -E "^$1=" "$APP_DIR/.env" | head -1 | cut -d= -f2- | tr -d '\r"' ; }
DB_HOST="$(getenv DB_HOST)"; DB_PORT="$(getenv DB_PORT)"; DB_DATABASE="$(getenv DB_DATABASE)"
DB_USERNAME="$(getenv DB_USERNAME)"; DB_PASSWORD="$(getenv DB_PASSWORD)"
export REDISCLI_AUTH="$(getenv REDIS_PASSWORD)"
[ "$REDISCLI_AUTH" = "null" ] && unset REDISCLI_AUTH

log() { echo "$(date '+%F %T') $*" >> "$LOG"; }

# Planned maintenance / load tests: /var/lib/ipflow-monitor/muted holds "<expiry-epoch> <check names...>".
# Listed checks keep running and logging but do not send alerts until the expiry time.
is_muted() {  # $1 check name
  local line; line=$(cat "$STATE_DIR/muted" 2>/dev/null) || return 1
  [ "$(echo "$line" | awk '{print $1}')" -gt "$(date +%s)" ] || return 1
  echo " $line " | grep -q " $1 "
}

send_alert() {  # $1 subject, $2 body
  log "ALERT: $1"
  [ "$MODE" = "--status" ] && return 0
  python3 "$ALERT" "$1" "$2" >> "$LOG" 2>&1 || log "alert delivery failed"
}

declare -A DETAIL
RESULTS=()

# run_check NAME "what it means" command...   (command succeeds = healthy; stdout/stderr first line is kept as detail)
run_check() {
  local name="$1" desc="$2"; shift 2
  local out rc
  out="$("$@" 2>&1)"; rc=$?
  RESULTS+=("$([ $rc -eq 0 ] && echo OK || echo FAIL)  $name  $(echo "$out" | head -1 | cut -c1-120)")
  [ "$MODE" = "--status" ] && return 0

  local f="$STATE_DIR/$name.fails" a="$STATE_DIR/$name.alerted" n now
  if is_muted "$name"; then log "muted check $name rc=$rc: $(echo "$out" | head -1 | cut -c1-100)"; return 0; fi
  n=$(cat "$f" 2>/dev/null || echo 0); now=$(date +%s)
  if [ $rc -eq 0 ]; then
    if [ "$n" -ge "$FAILS_BEFORE_ALERT" ]; then
      send_alert "RECOVERED: $desc" "$desc is healthy again as of $(date '+%F %T %Z') on $(hostname)."
    fi
    rm -f "$f" "$a"
  else
    n=$((n+1)); echo "$n" > "$f"
    local last; last=$(cat "$a" 2>/dev/null || echo 0)
    if { [ "$n" -eq "$FAILS_BEFORE_ALERT" ]; } || { [ "$n" -gt "$FAILS_BEFORE_ALERT" ] && [ $((now-last)) -ge $REALERT_SECS ]; }; then
      echo "$now" > "$a"
      send_alert "DOWN: $desc" "$desc has failed $n check(s) in a row (since about $(date -d "-$((n-1)) min" '+%H:%M %Z')).
Detail: $(echo "$out" | head -3)
Server: $(hostname). Check /var/log/ipflow-monitor.log."
    fi
  fi
}

http_ok() {  # $1 url, optional $2 acceptable codes regex (default 200)
  local code; code=$(curl -s -o /dev/null -m 12 -w '%{http_code}' "$1") || true
  [[ "$code" =~ ^(${2:-200})$ ]] || { echo "HTTP $code from $1"; return 1; }
}

api_health() {
  local body code
  body=$(curl -s -m 12 -w '\n%{http_code}' https://login.myipstrategy.com/api/health) || true
  code=$(echo "$body" | tail -1)
  [ "$code" = "200" ] || { echo "health HTTP $code: $(echo "$body" | head -1 | cut -c1-100)"; return 1; }
}

pg_ok() { pg_isready -q -h "$DB_HOST" -p "$DB_PORT" -d "$DB_DATABASE" || { echo "postgres not accepting connections"; return 1; }; }
redis_ok() { [ "$(redis-cli -h 127.0.0.1 ping 2>&1)" = "PONG" ] || { echo "redis not answering"; return 1; }; }

workers_ok() {
  local bad; bad=$(supervisorctl status 2>&1 | grep -E '^mypl-' | grep -v RUNNING)
  [ -z "$bad" ] || { echo "$bad"; return 1; }
}

mem_ok() {
  local mb; mb=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)
  [ "$mb" -ge "$MIN_AVAIL_MB" ] || { echo "only ${mb} MB memory available"; return 1; }
  echo "${mb} MB available"
}

disk_ok() {
  local p; p=$(df --output=pcent / | tail -1 | tr -dc '0-9')
  [ "$p" -lt "$MAX_DISK_PCT" ] || { echo "disk ${p}% full"; return 1; }
  echo "disk ${p}%"
}

load_ok() {
  local l cores; l=$(awk '{print $2}' /proc/loadavg); cores=$(nproc)
  awk -v l="$l" -v c="$cores" -v m="$MAX_LOAD5_PER_CORE" 'BEGIN{exit !(l < c*m)}' || { echo "5-min load $l on $cores cores"; return 1; }
  echo "load5 $l / $cores cores"
}

fpm_ok() {
  local hits=0 i m c
  for i in 0 1 2 3 4; do
    m=$(date -d "-$i min" '+%d-%b-%Y %H:%M')
    c=$(grep -c "^\[$m.*reached pm.max_children" /var/log/php8.4-fpm.log 2>/dev/null || true)
    hits=$((hits + ${c:-0}))
  done
  [ "$hits" -eq 0 ] || { echo "php-fpm hit max_children $hits time(s) in 5 min (requests are queuing)"; return 1; }
}

cert_ok() {
  echo | openssl s_client -servername "$1" -connect "$1:443" 2>/dev/null | openssl x509 -noout -checkend $((14*86400)) >/dev/null \
    || { echo "TLS certificate for $1 expires within 14 days or is unreadable"; return 1; }
}

failed_jobs_ok() {
  local n; n=$(PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -Atc "select count(*) from failed_jobs where failed_at > now() - interval '1 hour'" 2>&1)
  [[ "$n" =~ ^[0-9]+$ ]] || { echo "cannot read failed_jobs: $n"; return 1; }
  [ "$n" -lt "$MAX_RECENT_FAILED_JOBS" ] || { echo "$n failed queue jobs in the last hour"; return 1; }
}

queue_ok() {
  local total=0 k t n
  while read -r k; do
    t=$(redis-cli -h 127.0.0.1 type "$k" 2>/dev/null)
    [ "$t" = "list" ] || continue
    n=$(redis-cli -h 127.0.0.1 llen "$k" 2>/dev/null); total=$((total + ${n:-0}))
  done < <(redis-cli -h 127.0.0.1 --scan --pattern '*queues:*' 2>/dev/null)
  [ "$total" -lt "$MAX_QUEUE_BACKLOG" ] || { echo "queue backlog $total jobs"; return 1; }
  echo "backlog $total"
}

backup_ok() {
  find /var/backups/mypl-cms-drive -name 'mypl_cms_*.sql.gz.gpg' -mmin -450 2>/dev/null | grep -q . \
    || { echo "no database backup in the last 7.5 hours"; return 1; }
  # An emptied database still dumps "successfully" (58 KB once). Compare the newest backup with the one before it.
  local newest prev a b
  newest=$(ls -1t /var/backups/mypl-cms-drive/mypl_cms_*.sql.gz.gpg 2>/dev/null | sed -n 1p)
  prev=$(ls -1t /var/backups/mypl-cms-drive/mypl_cms_*.sql.gz.gpg 2>/dev/null | sed -n 2p)
  if [ -n "$newest" ] && [ -n "$prev" ]; then
    a=$(stat -c%s "$newest"); b=$(stat -c%s "$prev")
    [ "$a" -ge $((b / 2)) ] || { echo "newest backup is $a bytes vs $b before it (database may have been emptied)"; return 1; }
  fi
}

if [ "$MODE" = "--test-alert" ]; then
  send_alert "TEST: monitor alert delivery" "This is a test of the IPFlow monitor. If you can read this, alerts reach the system admins and directors by email and in-app notification."
  exit 0
fi

run_check portal        "IPFlow portal (login.myipstrategy.com) is not responding"  http_ok https://login.myipstrategy.com/up
run_check api_health    "IPFlow API health check is failing (database or Redis)"    api_health
run_check canpatentit   "canpatentit.com is not responding"                         http_ok https://canpatentit.com/
run_check cpi_data      "canpatentit.com data files are missing"                    http_ok https://canpatentit.com/patent-showcase/index.json
run_check postgres      "PostgreSQL (app database) is not accepting connections"    pg_ok
run_check redis         "Redis (cache, sessions, queue) is not answering"           redis_ok
run_check workers       "IPFlow background workers (Horizon/Reverb/scheduler) are not running" workers_ok
run_check memory        "Server memory is critically low"                           mem_ok
run_check disk          "Server disk is almost full"                                disk_ok
run_check load          "Server CPU load is very high"                              load_ok
run_check phpfpm        "PHP workers are saturated (requests queuing)"              fpm_ok
run_check cert_portal   "TLS certificate for login.myipstrategy.com is expiring"    cert_ok login.myipstrategy.com
run_check cert_cpi      "TLS certificate for canpatentit.com is expiring"           cert_ok canpatentit.com
run_check failed_jobs   "Background jobs are failing repeatedly"                    failed_jobs_ok
run_check queue         "Background job queue is backing up"                        queue_ok
run_check backup        "Database backup is stale"                                  backup_ok

if [ "$MODE" = "--status" ]; then
  printf '%s\n' "${RESULTS[@]}"
  exit 0
fi

# Dead-man's-switch: external service alerts if these pings stop (whole server down).
[ -n "${HEALTHCHECK_URL:-}" ] && curl -fsS -m 10 --retry 2 "$HEALTHCHECK_URL" >/dev/null 2>&1 || true
exit 0
