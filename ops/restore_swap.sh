#!/usr/bin/env bash
# Swap the restored database (from the 2026-10-15 12:00 UTC backup) in as the live app database.
# The wiped database is kept as mypl_cms_wiped_20261015.
# Run:  ssh root@139.59.85.216 'bash -s' < ops/restore_swap.sh
set -euo pipefail
PSQL() { docker exec mypl-cms-postgres psql -U mypl_cms_user -d postgres -Atc "$1"; }
cd /var/www/mypl-cms/backend

# Sanity: the restore copy must hold the real data before anything is renamed.
n=$(docker exec mypl-cms-postgres psql -U mypl_cms_user -d mypl_cms_restore -Atc "select count(*) from clients")
[ "$n" -gt 600 ] || { echo "ABORT: restore copy has only $n clients"; exit 1; }

PSQL "select pg_terminate_backend(pid) from pg_stat_activity where datname in ('mypl_cms','mypl_cms_restore') and pid <> pg_backend_pid()" >/dev/null
PSQL "alter database mypl_cms rename to mypl_cms_wiped_20261015"
PSQL "alter database mypl_cms_restore rename to mypl_cms"
echo "renamed. databases:"; PSQL "select datname from pg_database where datname like 'mypl_cms%' order by 1"

sudo -u www-data env HOME=/tmp php artisan up | tail -1
supervisorctl start mypl-horizon mypl-scheduler mypl-reverb | tail -3
sudo -u www-data env HOME=/tmp php artisan optimize:clear >/dev/null
sudo -u www-data env HOME=/tmp php artisan optimize >/dev/null
systemctl restart idpd-worker 2>/dev/null || true
sleep 4

export PGPASSWORD="$(grep ^DB_PASSWORD .env | cut -d= -f2- | tr -d '\r')"
U="$(grep ^DB_USERNAME .env | cut -d= -f2- | tr -d '\r')"
psql -h 127.0.0.1 -p 5433 -U "$U" -d mypl_cms -Atc "select (select count(*) from clients) clients, (select count(*) from users) users, (select count(*) from projects) projects, (select count(*) from audit_logs) audit"
curl -s -w " health %{http_code}\n" https://login.myipstrategy.com/api/health
/opt/ipflow_monitor.sh --status | grep -v '^OK' || echo "all monitor checks OK"
