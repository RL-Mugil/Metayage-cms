#!/usr/bin/env bash
# Step 2 (run on the server):  ssh root@139.59.85.216 'bash -s' < ops/deploy_daily_report.sh
# Installs the one-email-a-day health report: new app files, the ops_incidents table, the quiet monitor scripts, and
# routes the backup scripts' failure emails into the same report. Backs up everything it replaces first.
set -euo pipefail
B=/var/www/mypl-cms/backend
D=/tmp/dr
BK=/root/backup-ops-20261015/daily-report
[ -d "$D/app" ] || { echo "run ops/deploy_daily_report_push.sh first"; exit 1; }
mkdir -p "$BK"

for f in $(cd $D && find app routes database -type f); do
  php -l "$D/$f" >/dev/null
  [ -f "$B/$f" ] && { mkdir -p "$BK/$(dirname $f)"; cp "$B/$f" "$BK/$f"; }
  mkdir -p "$B/$(dirname $f)"
  install -o www-data -g www-data -m 644 "$D/$f" "$B/$f"
done
python3 -c "import ast;ast.parse(open('$D/ipflow_alert.py').read())"; bash -n "$D/ipflow_monitor.sh"
cp /opt/ipflow_alert.py /opt/ipflow_monitor.sh "$BK/"
install -m 755 "$D/ipflow_alert.py" /opt/ipflow_alert.py
install -m 755 "$D/ipflow_monitor.sh" /opt/ipflow_monitor.sh

cd $B
pending=$(sudo -u www-data env HOME=/tmp php artisan migrate:status --pending 2>/dev/null | grep -c "Pending" || true)
[ "$pending" -le 1 ] || { echo "ABORT: $pending migrations are pending, expected only the ops_incidents one"; exit 1; }
sudo -u www-data env HOME=/tmp php artisan migrate --force | tail -3

# The backup scripts used to email on their own. Make them record into the same morning report instead.
python3 - <<'PY'
import re, shutil
call = 'python3 /opt/ipflow_alert.py "DOWN: Database backup is stale" "$1" >/dev/null 2>&1 || true'
p = '/usr/local/bin/backup-mypl-cms-to-drive.sh'
s = open(p).read()
if 'ipflow_alert.py' not in s:
    shutil.copy(p, '/root/backup-ops-20261015/daily-report/backup-mypl-cms-to-drive.sh')
    s2 = re.sub(r'alert\(\)\{.*?\n\}\n', 'alert(){\n    # Recorded for the morning health report (no email of its own).\n    ' + call + '\n}\n', s, count=1, flags=re.S)
    assert s2 != s, 'backup alert() not found'
    open(p, 'w').write(s2)
p = '/opt/mypl_cms_backup_monitor.sh'
s = open(p).read()
if 'ipflow_alert.py' not in s:
    shutil.copy(p, '/root/backup-ops-20261015/daily-report/mypl_cms_backup_monitor.sh')
    s2 = re.sub(r'printf \'%s\' "\$MSG" > "\$TMP/msg\.txt"\n.*?\|\| true\n', 'python3 /opt/ipflow_alert.py "DOWN: Database backup is stale" "$MSG" >/dev/null 2>&1 || true\n', s, count=1, flags=re.S)
    assert s2 != s, 'backup monitor mail block not found'
    open(p, 'w').write(s2)
print('backup scripts now report into the morning report')
PY
bash -n /usr/local/bin/backup-mypl-cms-to-drive.sh && bash -n /opt/mypl_cms_backup_monitor.sh

sudo -u www-data env HOME=/tmp php artisan optimize:clear >/dev/null
sudo -u www-data env HOME=/tmp php artisan optimize >/dev/null
supervisorctl restart mypl-horizon | tail -1
rm -rf "$D"
echo "--- scheduled:"; sudo -u www-data env HOME=/tmp php artisan schedule:list | grep -E "ops:|ops-heartbeat"
echo "--- preview of the report (nothing is emailed):"; sudo -u www-data env HOME=/tmp php artisan ops:daily-report --dry-run | head -40
curl -s https://login.myipstrategy.com/api/health; echo
