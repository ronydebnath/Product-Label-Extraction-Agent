# syntax=docker/dockerfile:1

# One image serves every process type; compose.yaml and docker/entrypoint.sh pick the role.
#
#   base     PHP 8.4-fpm, the extensions this app needs, nginx, supervisor, poppler.
#            Every other stage builds on it, so web and worker are provably the same bits.
#   vendor   composer install --no-dev, run on the *same* PHP build as production so
#            composer's platform checks (ext-pcntl, ext-redis, ...) are checked against reality.
#   assets   node builds the Vite bundle. Node and node_modules never reach the runtime image.
#   runtime  base + vendor/ + public/build + source, running as a non-root user.
#   dev      runtime + dev dependencies (Pest, PHPStan, Pint) + composer.
#            Only compose.override.yaml selects it.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---------------------------------------------------------------------------- base
FROM php:${PHP_VERSION}-fpm-alpine AS base

# install-php-extensions fetches each extension's build deps, compiles, then removes them,
# which keeps this one small layer instead of a hand-maintained apk/docker-php-ext-install list.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql redis pcntl opcache \
    # poppler-utils provides `pdfinfo`: structural PDF validation and page counts without rasterising.
    && apk add --no-cache nginx supervisor poppler-utils

# uid/gid 1000 matches the first user on most Linux hosts and Docker Desktop's mapping,
# so files written through dev bind mounts stay owned by the developer.
RUN addgroup -g 1000 app && adduser -u 1000 -G app -D -h /var/www/html app \
    # nginx and supervisord run as `app`, so their scratch and log dirs must be app-owned.
    && mkdir -p /var/lib/nginx/tmp /var/log/nginx /run/nginx \
    && chown -R app:app /var/lib/nginx /var/log/nginx /run/nginx

WORKDIR /var/www/html

COPY docker/php/php.ini         /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf   /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx/nginx.conf    /etc/nginx/nginx.conf
COPY docker/supervisor/web.conf /etc/supervisor/web.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]

# ---------------------------------------------------------------------------- vendor
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache

# Lock files first, so the slow install layer is reused until dependencies actually change.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
# --no-scripts above skipped package discovery and asset publishing; do them explicitly.
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && mkdir -p public/vendor \
    && php artisan vendor:publish --tag=laravel-assets --force --ansi

# ---------------------------------------------------------------------------- assets
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------- runtime
FROM base AS runtime

ENV APP_ENV=production

COPY --chown=app:app . .
COPY --from=vendor --chown=app:app /var/www/html/vendor          ./vendor
COPY --from=vendor --chown=app:app /var/www/html/bootstrap/cache ./bootstrap/cache
COPY --from=vendor --chown=app:app /var/www/html/public/vendor   ./public/vendor
COPY --from=assets --chown=app:app /app/public/build             ./public/build

USER app
EXPOSE 8080
CMD ["web"]

# ---------------------------------------------------------------------------- dev
FROM runtime AS dev

USER root
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/zz-opcache-dev.ini
# Composer must not write its cache into the bind-mounted project directory.
ENV COMPOSER_HOME=/tmp/composer
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-scripts --prefer-dist --no-interaction --no-progress \
    && chown -R app:app vendor /tmp/composer
USER app
