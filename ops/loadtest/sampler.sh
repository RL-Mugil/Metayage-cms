#!/usr/bin/env bash
# Samples server health every 5 s during the load test. Usage: sampler.sh <seconds> <outfile>
DUR="${1:-1800}"; OUT="${2:-/tmp/loadtest-samples.csv}"
APP=/var/www/mypl-cms/backend
export PGPASSWORD="$(grep ^DB_PASSWORD $APP/.env | cut -d= -f2- | tr -d '\r"')"
U="$(grep ^DB_USERNAME $APP/.env | cut -d= -f2- | tr -d '\r"')"
echo "ts,load1,load5,mem_avail_mb,swap_used_mb,si,so,cpu_idle_pct,fpm_workers,fpm_rss_mb,pg_conns,pg_active,redis_mem_mb,nginx_5xx_cum" > "$OUT"
end=$(( $(date +%s) + DUR ))
while [ "$(date +%s)" -lt "$end" ]; do
  read -r l1 l5 _ < /proc/loadavg
  avail=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)
  swap=$(awk '/SwapTotal/{t=$2} /SwapFree/{f=$2} END{print int((t-f)/1024)}' /proc/meminfo)
  read -r si so idle < <(vmstat 1 2 | tail -1 | awk '{print $7, $8, $15}')
  fw=$(pgrep -fc "php-fpm: pool mypl-cms" || true)
  frss=$(ps -C php-fpm8.4 -o rss= | awk '{s+=$1} END{print int(s/1024)}')
  pgc=$(psql -h 127.0.0.1 -p 5433 -U "$U" -d mypl_cms -Atc "select count(*), count(*) filter (where state='active') from pg_stat_activity where datname='mypl_cms'" 2>/dev/null | tr '|' ',')
  rm=$(redis-cli -h 127.0.0.1 info memory 2>/dev/null | awk -F: '/^used_memory:/{print int($2/1048576)}')
  e5=$(awk '$9 ~ /^5/ {c++} END{print c+0}' /var/log/nginx/access.log)
  echo "$(date +%T),$l1,$l5,$avail,$swap,$si,$so,$idle,$fw,$frss,${pgc:-,},${rm:-},$e5" >> "$OUT"
done
