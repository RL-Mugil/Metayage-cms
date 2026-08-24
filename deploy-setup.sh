#!/usr/bin/env bash
set -euo pipefail

DEPLOY_DIR="/var/www/mypl-cms"
DB_NAME="mypl_cms"
DB_USER="mypl_cms_user"
DB_PASS="MYPLcms_D0_s3cur3_2024!"
DB_PORT="5433"
DOMAIN="myipstrategy.com"
FPM_SOCK="/run/php/php8.4-fpm-mypl-cms.sock"
NODE_PORT="3000"

echo ">>> [1/9] Creating deploy directory..."
mkdir -p "$DEPLOY_DIR"

echo ">>> [2/9] Extracting archive..."
tar -xzf /tmp/mypl-cms.tar.gz -C /tmp
rsync -a --delete /tmp/MYPL-CMS/ "$DEPLOY_DIR/"
chown -R www-data:www-data "$DEPLOY_DIR"
chmod -R 755 "$DEPLOY_DIR"

echo ">>> [3/9] Starting isolated PostgreSQL container for MYPL-CMS..."
if docker ps -a --format '{{.Names}}' | grep -q '^mypl-cms-postgres$'; then
  echo "    Container exists, skipping create."
else
  docker run -d \
    --name mypl-cms-postgres \
    --restart unless-stopped \
    -e POSTGRES_DB="$DB_NAME" \
    -e POSTGRES_USER="$DB_USER" \
    -e POSTGRES_PASSWORD="$DB_PASS" \
    -p 127.0.0.1:${DB_PORT}:5432 \
    -v mypl-cms-postgres-data:/var/lib/postgresql/data \
    postgres:16
  echo "    Waiting 10s for PostgreSQL to initialise..."
  sleep 10
fi

echo ">>> [4/9] Writing Laravel production .env..."
cat > "$DEPLOY_DIR/backend/.env" <<EOF
APP_NAME="MYPL CMS"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${DOMAIN}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@myipstrategy.com"
MAIL_FROM_NAME="MYPL CMS"

VITE_APP_NAME="MYPL CMS"
EOF

echo ">>> [5/9] Installing backend dependencies and migrating..."
cd "$DEPLOY_DIR/backend"
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan db:seed --force --class=DatabaseSeeder 2>/dev/null || echo "    No seeders / already seeded."
chown -R www-data:www-data "$DEPLOY_DIR/backend/storage" "$DEPLOY_DIR/backend/bootstrap/cache"
chmod -R 775 "$DEPLOY_DIR/backend/storage" "$DEPLOY_DIR/backend/bootstrap/cache"

echo ">>> [6/9] Installing frontend dependencies and building..."
cd "$DEPLOY_DIR"
npm ci --prefer-offline 2>/dev/null || npm install
NITRO_PRESET=node-server npm run build
chown -R www-data:www-data "$DEPLOY_DIR/.output" 2>/dev/null || true

echo ">>> [7/9] Setting up PHP-FPM isolated pool for MYPL-CMS..."
cat > /etc/php/8.4/fpm/pool.d/mypl-cms.conf <<EOF2
[mypl-cms]
user = www-data
group = www-data
listen = ${FPM_SOCK}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

php_admin_value[error_log] = /var/log/php8.4-fpm-mypl-cms.log
php_admin_flag[log_errors] = on
EOF2
systemctl reload php8.4-fpm

echo ">>> [8/9] Installing PM2 and starting frontend..."
npm install -g pm2 2>/dev/null || true
pm2 delete mypl-cms-frontend 2>/dev/null || true
cd "$DEPLOY_DIR"
pm2 start .output/server/index.mjs \
  --name mypl-cms-frontend \
  --env production \
  -- --port $NODE_PORT
pm2 save
pm2 startup systemd -u root --hp /root 2>/dev/null || true

echo ">>> [9/9] Writing Nginx virtualhost..."
cat > /etc/nginx/sites-available/mypl-cms <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name myipstrategy.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name myipstrategy.com;

    ssl_certificate     /etc/letsencrypt/live/myipstrategy.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/myipstrategy.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    include snippets/security-headers.conf;

    # Laravel API backend
    location /api/ {
        root /var/www/mypl-cms/backend/public;
        try_files $uri $uri/ /index.php?$query_string;
        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.4-fpm-mypl-cms.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/mypl-cms/backend/public/index.php;
            include fastcgi_params;
            fastcgi_hide_header X-Powered-By;
            fastcgi_buffers 32 32k;
            fastcgi_buffer_size 64k;
            fastcgi_read_timeout 360;
        }
    }

    # TanStack Start (Node.js SSR frontend)
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
        proxy_read_timeout 60;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/mypl-cms /etc/nginx/sites-enabled/mypl-cms

echo ">>> Getting Let's Encrypt SSL certificate..."
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m mugilvannan@myipstrategy.com --redirect 2>&1 || \
  certbot certonly --standalone -d "$DOMAIN" --non-interactive --agree-tos -m mugilvannan@myipstrategy.com 2>&1

nginx -t && systemctl reload nginx

echo ""
echo "=============================="
echo " MYPL-CMS DEPLOYMENT COMPLETE"
echo "=============================="
echo " URL  : https://${DOMAIN}"
echo " DB   : postgres://127.0.0.1:${DB_PORT}/${DB_NAME}  (isolated Docker container)"
echo " FPM  : ${FPM_SOCK}  (isolated pool)"
echo " Node : 127.0.0.1:${NODE_PORT}"
echo "=============================="
