#!/usr/bin/env bash
# Run migrations and tests against a THROWAWAY Postgres container, never the production database.
#   - copy of the code WITHOUT .env and WITHOUT bootstrap/cache (a cached config makes artisan ignore env vars)
#   - its own database on 127.0.0.1:55432 (tmpfs, destroyed at the end)
#   - hard check of the effective DB host/port/name before anything runs; abort if it is not the throwaway one
# Run:  ssh root@139.59.85.216 'bash -s' < ops/isolated_test.sh        (optional arg: phpunit filter)
set -uo pipefail
FILTER="${1:-}"
SRC=/var/www/mypl-cms/backend
ISO=/root/iso
PORT=55432
NAME=iso-pg-test
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1; rm -rf "$ISO"; }
trap cleanup EXIT
cleanup
IMAGE=$(docker inspect mypl-cms-postgres --format '{{.Config.Image}}')

docker run -d --name "$NAME" --tmpfs /var/lib/postgresql/data -e POSTGRES_PASSWORD=iso -e POSTGRES_DB=iso_test \
  -p 127.0.0.1:$PORT:5432 "$IMAGE" >/dev/null || { echo "could not start throwaway postgres"; exit 1; }
for i in $(seq 1 30); do docker exec "$NAME" pg_isready -U postgres -d iso_test >/dev/null 2>&1 && break; sleep 1; done

mkdir -p "$ISO"
EXCL=(--exclude='.env' --exclude='.env.*' --exclude='bootstrap/cache/*.php' --exclude='storage/logs/*'   --exclude='storage/app' --exclude='storage/framework/cache' --exclude='storage/framework/sessions')
if [ -f /tmp/iso-overlay/artisan ]; then
  # full local source (git HEAD + uncommitted work) -- take only vendor/ and similar from the server tree
  rsync -a "${EXCL[@]}" --exclude='/app' --exclude='/tests' --exclude='/database' --exclude='/routes' --exclude='/config'     --exclude='/resources' --exclude='/bootstrap/app.php' --exclude='/bootstrap/providers.php' --exclude='/phpunit.xml'     --exclude='/composer.json' --exclude='/composer.lock' --exclude='/artisan' "$SRC/" "$ISO/"
else
  rsync -a "${EXCL[@]}" "$SRC/" "$ISO/"
fi
[ -d /tmp/iso-overlay ] && rsync -a /tmp/iso-overlay/ "$ISO/"   # local files (full source, or just the not-yet-deployed ones)
cd "$ISO"
mkdir -p bootstrap/cache storage/logs storage/framework/{cache,sessions,views} storage/app
rm -f bootstrap/cache/*.php

cat > .env <<EOF
APP_NAME=iso
APP_ENV=testing
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=true
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=$PORT
DB_DATABASE=iso_test
DB_USERNAME=postgres
DB_PASSWORD=iso
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
EOF
# phpunit.xml pins DB_* itself; point it at the throwaway instance too
sed -i -E "s#(name=\"DB_PORT\" value=\")[0-9]+#\1$PORT#; s#(name=\"DB_DATABASE\" value=\")[^\"]+#\1iso_test#; s#(name=\"DB_PASSWORD\" value=\")[^\"]+#\1iso#" phpunit.xml

# HARD GUARD: resolve the effective connection the way a test run would and refuse unless it is the throwaway one
GUARD=$(APP_ENV=testing php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $c = config("database.connections.pgsql");
  echo $c["host"].":".$c["port"]."/".$c["database"];
')
echo "effective database: $GUARD"
[ "$GUARD" = "127.0.0.1:$PORT/iso_test" ] || { echo "ABORT: effective database is not the throwaway one"; exit 2; }
ls /root/iso/.env >/dev/null && ! ls bootstrap/cache/config.php >/dev/null 2>&1 || { echo "ABORT: cached config present"; exit 2; }

echo "=== migrate from scratch ==="
php artisan migrate --force 2>&1 | tail -15
echo "migrate exit: ${PIPESTATUS[0]}"
echo "zoho_api_budgets columns:"
docker exec "$NAME" psql -U postgres -d iso_test -Atc "select column_name from information_schema.columns where table_name='zoho_api_budgets' order by ordinal_position" | tr '\n' ' '; echo

echo "=== tests ==="
if [ "$FILTER" = "__list" ]; then php artisan test 2>&1 | grep -E "FAILED|Tests:" | sed "s/\[[0-9;]*m//g" | sort | uniq; elif [ -n "$FILTER" ]; then php artisan test --filter="$FILTER" 2>&1 | tail -n 2000; else php artisan test 2>&1 | tail -n 2000; fi
