#!/bin/bash
# deploy-rebuild.sh — Deploy Inertia rebuild to production server
# Run from the local MYPL-CMS directory

SERVER="root@139.59.85.216"
DEPLOY_PATH="/var/www/mypl-cms"
KEY="~/.ssh/id_ed25519"

echo "=== Packaging backend ==="
cd "$(dirname "$0")"

tar --exclude='backend/vendor' \
    --exclude='backend/node_modules' \
    --exclude='backend/public/build' \
    --exclude='backend/.env' \
    --exclude='backend/storage/logs/*.log' \
    -czf /tmp/mypl-cms-rebuild.tar.gz \
    backend/ nginx-mypl-cms.conf

echo "=== Uploading to server ==="
scp -i $KEY /tmp/mypl-cms-rebuild.tar.gz $SERVER:/tmp/mypl-cms-rebuild.tar.gz
scp -i $KEY nginx-mypl-cms.conf $SERVER:/tmp/nginx-mypl-cms-new.conf

echo "=== Deploying on server ==="
ssh -i $KEY $SERVER << 'ENDSSH'
set -e

echo "--- Extracting files ---"
cd /var/www/mypl-cms
tar -xzf /tmp/mypl-cms-rebuild.tar.gz --overwrite

echo "--- Updating Nginx config ---"
cp /tmp/nginx-mypl-cms-new.conf /etc/nginx/sites-available/mypl-cms
nginx -t && systemctl reload nginx

echo "--- Installing PHP dependencies (locked versions) ---"
cd /var/www/mypl-cms/backend
# install (not update) so production uses the committed composer.lock and
# never drifts to newer dependency versions on a deploy. Fall back to update
# only if the lock is out of sync with composer.json.
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev \
  || composer update --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "--- Installing Node.js dependencies ---"
npm install --legacy-peer-deps

echo "--- Building frontend assets ---"
npm run build

echo "--- Updating .env: Sanctum stateful domains ---"
if ! grep -q "SANCTUM_STATEFUL_DOMAINS" .env; then
    echo "" >> .env
    echo "SANCTUM_STATEFUL_DOMAINS=mypl-cms.139-59-85-216.sslip.io" >> .env
    echo "SESSION_DRIVER=file" >> .env
    echo "SESSION_LIFETIME=120" >> .env
fi

echo "--- Running migrations ---"
php artisan migrate --force

echo "--- Clearing caches ---"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan optimize

echo "--- Publishing Horizon assets ---"
php artisan horizon:publish --ansi || true

echo "--- Installing Reverb ---"
php artisan reverb:install --no-interaction || true

echo "--- Fixing permissions ---"
chown -R www-data:www-data /var/www/mypl-cms/backend/storage
chown -R www-data:www-data /var/www/mypl-cms/backend/bootstrap/cache
chmod -R 775 /var/www/mypl-cms/backend/storage
chmod -R 775 /var/www/mypl-cms/backend/bootstrap/cache

echo "--- Stopping PM2 frontend (no longer needed) ---"
pm2 stop mypl-cms-frontend || true
pm2 delete mypl-cms-frontend || true
pm2 save || true

echo "--- Restarting PHP-FPM ---"
systemctl reload php8.4-fpm

echo "--- Setting up Horizon via Supervisor ---"
cat > /etc/supervisor/conf.d/mypl-horizon.conf << 'SUPEOF'
[program:mypl-horizon]
process_name=%(program_name)s
command=php /var/www/mypl-cms/backend/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/mypl-horizon.log
stopwaitsecs=3600
SUPEOF
supervisorctl reread || true
supervisorctl update || true
supervisorctl start mypl-horizon || supervisorctl restart mypl-horizon || true

echo ""
echo "=== Rebuild deployment complete! ==="
echo "=== Visit: https://mypl-cms.139-59-85-216.sslip.io ==="
ENDSSH

rm -f /tmp/mypl-cms-rebuild.tar.gz
echo "Done!"
