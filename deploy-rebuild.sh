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
scp -i $KEY nginx-security-headers.conf $SERVER:/tmp/nginx-security-headers-new.conf

echo "=== Deploying on server ==="
ssh -i $KEY $SERVER << 'ENDSSH'
set -e

echo "--- Extracting files ---"
cd /var/www/mypl-cms
tar -xzf /tmp/mypl-cms-rebuild.tar.gz --overwrite

echo "--- Updating Nginx config ---"
cp /tmp/nginx-mypl-cms-new.conf /etc/nginx/sites-available/mypl-cms
cp /tmp/nginx-security-headers-new.conf /etc/nginx/snippets/security-headers.conf
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

echo "--- Configuring the public Reverb endpoint ---"
set_env() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '\n%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env REVERB_SERVER_HOST 127.0.0.1
set_env REVERB_SERVER_PORT 8080
set_env REVERB_HOST myipstrategy.com
set_env REVERB_PORT 443
set_env REVERB_SCHEME https
set_env REVERB_ALLOWED_ORIGINS myipstrategy.com,www.myipstrategy.com
set_env VITE_REVERB_HOST myipstrategy.com
set_env VITE_REVERB_PORT 443
set_env VITE_REVERB_SCHEME https

# Never ship the placeholder Reverb secret. If the secret is missing or still the
# .env.example default, rotate it to a strong random value. The secret is
# server-side only (used to sign broadcast auth); clients use the app KEY, so
# rotating it is transparent and needs no client rebuild.
CURRENT_SECRET="$(grep -E '^REVERB_APP_SECRET=' .env | head -1 | cut -d= -f2-)"
if [ -z "$CURRENT_SECRET" ] || [ "$CURRENT_SECRET" = "change-me-in-production" ]; then
    NEW_SECRET="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    set_env REVERB_APP_SECRET "$NEW_SECRET"
    echo "⚠️  REVERB_APP_SECRET was missing/placeholder — rotated to a strong random value."
fi

echo "--- Building frontend assets ---"
npm run build

echo "--- Updating .env: Sanctum stateful domains ---"
if ! grep -q "SANCTUM_STATEFUL_DOMAINS" .env; then
    echo "" >> .env
    echo "SANCTUM_STATEFUL_DOMAINS=myipstrategy.com,www.myipstrategy.com" >> .env
    echo "SESSION_DRIVER=file" >> .env
    echo "SESSION_LIFETIME=120" >> .env
fi

echo "--- Groq AI key ---"
# Groq API key must be set in production .env manually or via deployment secrets.
# NEVER hardcode credentials in version control. If GROQ_API_KEY is not set,
# the AI module will fail gracefully at runtime (read-only operations continue).
if ! grep -q "GROQ_API_KEY" .env; then
    echo "⚠️  WARNING: GROQ_API_KEY not found in .env. AI queries will fail."
    echo "   Set GROQ_API_KEY in production .env before enabling AI features."
fi
if ! grep -q "GROQ_MODEL" .env; then
    echo "" >> .env
    echo "GROQ_MODEL=meta-llama/llama-4-scout-17b-16e-instruct" >> .env
fi

echo "--- Email configuration note ---"
echo "Password resets require SMTP setup. Configure in .env:"
echo "  MAIL_MAILER=smtp"
echo "  MAIL_HOST=sandbox.smtp.mailtrap.io (or your provider)"
echo "  MAIL_PORT=2525"
echo "  MAIL_USERNAME=your-username"
echo "  MAIL_PASSWORD=your-password"

echo "--- Running migrations ---"
php artisan migrate --force

echo "--- Seeding module demo data (idempotent) ---"
php artisan db:seed --class=DemoModulesSeeder --force || true
echo "--- Seeding public holidays (idempotent) ---"
php artisan db:seed --class=PublicHolidaysSeeder --force || true

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
echo "--- Setting up Laravel scheduler via Supervisor ---"
# Runs php artisan schedule:work (a long-lived process that ticks every minute),
# so scheduled commands like reminders:send-deadlines actually fire in production.
cat > /etc/supervisor/conf.d/mypl-scheduler.conf << 'SCHEDEOF'
[program:mypl-scheduler]
process_name=%(program_name)s
command=php /var/www/mypl-cms/backend/artisan schedule:work
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/mypl-scheduler.log
stopwaitsecs=60
SCHEDEOF

echo "--- Setting up Reverb WebSocket server via Supervisor ---"
# Long-lived WebSocket daemon on 127.0.0.1:8080. Nginx proxies /app and /apps
# to it (see nginx-mypl-cms.conf). Powers real-time case chat (typing, read
# receipts, instant delivery) and live notification toasts. Without this
# process the app still works over HTTP but nothing pushes in real time.
cat > /etc/supervisor/conf.d/mypl-reverb.conf << 'REVERBEOF'
[program:mypl-reverb]
process_name=%(program_name)s
command=php /var/www/mypl-cms/backend/artisan reverb:start --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/mypl-reverb.log
stopwaitsecs=10
REVERBEOF

supervisorctl reread || true
supervisorctl update || true
supervisorctl start mypl-horizon || supervisorctl restart mypl-horizon || true
supervisorctl start mypl-scheduler || supervisorctl restart mypl-scheduler || true
# Restart Reverb so it picks up the freshly-built app/config on every deploy.
supervisorctl restart mypl-reverb || supervisorctl start mypl-reverb || true

echo ""
echo "=== Rebuild deployment complete! ==="
echo "=== Visit: https://myipstrategy.com ==="
ENDSSH

rm -f /tmp/mypl-cms-rebuild.tar.gz
echo "Done!"
