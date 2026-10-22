#!/usr/bin/env bash
# Install the booking upgrade (time zones, reschedule, staff assignment, join link) on the server.
#   1) (repo root)  bash ops/deploy_booking_upgrade.sh push     copies the backend files to /tmp/bk on the server
#   2)              bash ops/deploy_booking_upgrade.sh install  installs them, runs the one new migration, restarts workers
set -euo pipefail
FILES="database/migrations/2026_10_19_000001_add_reschedule_and_staff_to_cpi_bookings.php app/Models/CpiBooking.php app/Services/CpiBookingService.php
app/Mail/CpiBookingMail.php app/Http/Controllers/CpiBookingController.php app/Console/Commands/SendSampleBookingEmails.php routes/api.php"
case "${1:-}" in
  push)
    ssh root@139.59.85.216 'rm -rf /tmp/bk && mkdir -p /tmp/bk'
    (cd backend && tar czf - $FILES) | ssh root@139.59.85.216 'tar xzf - -C /tmp/bk'
    echo "pushed $(ssh root@139.59.85.216 'find /tmp/bk -type f | wc -l') files" ;;
  install)
    ssh root@139.59.85.216 'bash -s' <<'REMOTE'
set -euo pipefail
B=/var/www/mypl-cms/backend; D=/tmp/bk; BK=/root/backup-ops-20261015/booking-upgrade
[ -d $D/app ] || { echo "run the push step first"; exit 1; }
mkdir -p $BK
for f in $(cd $D && find app routes database -type f); do
  php -l $D/$f >/dev/null
  [ -f $B/$f ] && { mkdir -p $BK/$(dirname $f); cp $B/$f $BK/$f; }
  mkdir -p $B/$(dirname $f); install -o www-data -g www-data -m 644 $D/$f $B/$f
done
rm -rf $D
cd $B
pending=$(sudo -u www-data env HOME=/tmp php artisan migrate:status --pending 2>/dev/null | grep -c "Pending" || true)
[ "$pending" -le 1 ] || { echo "ABORT: $pending migrations pending, expected only the booking one"; exit 1; }
sudo -u www-data env HOME=/tmp php artisan migrate --force | tail -3
sudo -u www-data env HOME=/tmp php artisan optimize:clear >/dev/null
sudo -u www-data env HOME=/tmp php artisan optimize >/dev/null
supervisorctl restart mypl-horizon | tail -1
systemctl reload php8.4-fpm   # opcache keeps old route and class files until PHP-FPM reloads
curl -s https://login.myipstrategy.com/api/health; echo
curl -s https://login.myipstrategy.com/api/public/cpi/range | head -c 200; echo
REMOTE
    ;;
  *) echo "usage: $0 push|install"; exit 1 ;;
esac
