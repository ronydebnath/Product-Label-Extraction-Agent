#!/bin/sh
# Single entrypoint for every process type built from this image.
#   entrypoint web        nginx + php-fpm under supervisord   (compose service: web)
#   entrypoint horizon    Laravel Horizon queue worker         (compose service: worker)
#   entrypoint <cmd...>   anything else runs as-is             (migrate, tests, artisan)
set -eu
cd /var/www/html

if [ "${APP_ENV:-production}" = "local" ]; then
    # Dev only: the bind mount hides the image's vendor/ on a fresh checkout.
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist --no-progress
    fi
    # A previous production-style boot may have left cached config that would shadow .env edits.
    php artisan config:clear --quiet
    php artisan route:clear --quiet
else
    # Production. Caches are built at container start, not at image build: the values come from
    # the environment, and baking them into a layer would freeze secrets into the image.
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan view:cache --quiet
    php artisan event:cache --quiet
fi

require_app_key() {
    if [ -z "${APP_KEY:-}" ] && ! grep -qs '^APP_KEY=.\+' .env; then
        echo "APP_KEY is empty. Generate one with:" >&2
        echo "  docker compose run --rm --no-deps web php artisan key:generate" >&2
        exit 1
    fi
}

case "${1:-web}" in
    web)     require_app_key; exec supervisord -c /etc/supervisor/web.conf ;;
    horizon) require_app_key; exec php artisan horizon ;;
    *)       exec "$@" ;;
esac
