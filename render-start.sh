#!/bin/sh
set -eu

export PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*) echo 'PORT must be numeric.' >&2; exit 1 ;;
esac

# Render generates a 32-byte base64 value. Laravel requires the base64: prefix.
if [ -z "${APP_KEY:-}" ]; then
    : "${APP_KEY_BASE64:?Set APP_KEY_BASE64 to a stable, random base64-encoded 32-byte secret.}"
    export APP_KEY="base64:${APP_KEY_BASE64}"
fi
if [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
    export ASSET_URL="$RENDER_EXTERNAL_URL"
fi

sed -i "s/^Listen 80$/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf

php artisan config:clear --no-interaction
php artisan migrate --force --no-interaction
# Pair after storage is available. Registration is idempotent and stores credentials encrypted.
# An expired setup token should not take the storefront offline.
if [ -n "${CHATTRN_TOKEN:-}" ]; then
    if ! php artisan chattrn:register --no-interaction; then
        echo 'Chattrn pairing did not complete. Check the setup token and public backend URL.' >&2
    fi
fi
if [ "${DEMO_SEED:-false}" = 'true' ]; then
    php artisan commerce:initialize-demo --no-interaction
fi
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground
