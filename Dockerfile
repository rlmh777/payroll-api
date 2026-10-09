# Pull official PHP and Composer from AWS Public ECR so CI never hits Docker Hub rate limits.
FROM public.ecr.aws/docker/library/php:8.4-cli-bookworm

ARG FRANKENPHP_VERSION=1.13.0
ARG FRANKENPHP_SHA256=7751b3feb47cc8e83cba880821b4f99669541a4858a998d3b60080908b8d08c5

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=cookie \
    QUEUE_CONNECTION=redis \
    CACHE_STORE=redis \
    FILESYSTEM_DISK=local \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl git unzip libpq5 postgresql-client \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /config/caddy /data/caddy

RUN curl -fsSL \
      https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
      -o /usr/local/bin/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions \
      pdo_pgsql pgsql zip intl bcmath pcntl redis opcache

COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer

# FrankenPHP 1.13 php-cli requires PHP 8.6+, so use it only as the HTTP server.
RUN curl -fsSL \
      "https://github.com/php/frankenphp/releases/download/v${FRANKENPHP_VERSION}/frankenphp-linux-x86_64-gnu" \
      -o /usr/local/bin/frankenphp \
    && echo "${FRANKENPHP_SHA256}  /usr/local/bin/frankenphp" | sha256sum -c - \
    && chmod +x /usr/local/bin/frankenphp

# Production PHP / OPcache
RUN printf '%s\n' \
      'opcache.enable=1' \
      'opcache.enable_cli=1' \
      'opcache.memory_consumption=256' \
      'opcache.interned_strings_buffer=16' \
      'opcache.max_accelerated_files=20000' \
      'opcache.validate_timestamps=0' \
      'opcache.jit=1255' \
      'opcache.jit_buffer_size=64M' \
      'realpath_cache_size=4096K' \
      'realpath_cache_ttl=600' \
      > "$PHP_INI_DIR/conf.d/zz-opcache.ini"

# GST / QuickBooks workbooks routinely exceed PHP's default 2M upload limit.
RUN printf '%s\n' \
      'upload_max_filesize=32M' \
      'post_max_size=40M' \
      'memory_limit=512M' \
      'max_execution_time=120' \
      > "$PHP_INI_DIR/conf.d/zz-uploads.ini"

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi || true \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /config /data \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app/public"]
