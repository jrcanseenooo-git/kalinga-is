#!/bin/sh
set -eu

php artisan migrate --force
php artisan kalinga:lock-down-database-api
php artisan db:seed --class=Database\\Seeders\\ReferenceDataSeeder --force
php artisan kalinga:provision-initial-admin
# Fictional presentation data, only when DEMO_SEED=true (never with real cases).
php artisan kalinga:seed-demo || echo "Demo data not loaded: check DEMO_PASSWORD and DEMO_ACCOUNT_EMAIL."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Substitute $PORT into the nginx config
envsubst '${PORT}' < /etc/nginx/nginx.conf > /tmp/nginx.conf
cp /tmp/nginx.conf /etc/nginx/nginx.conf

# Start php-fpm in the background, nginx in the foreground
php-fpm -D

# Single-instance pilot: run Laravel's scheduler (upload scans, deadline alerts,
# MDT access expiry) inside this container instead of a separate cron service.
if [ "${RUN_SCHEDULER:-false}" = "true" ]; then
    (while true; do php artisan schedule:run --no-interaction >/dev/null 2>&1 || true; sleep 60; done) &
fi
exec nginx -g 'daemon off;'
