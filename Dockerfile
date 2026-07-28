FROM dunglas/frankenphp:1-php8.4-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libpq-dev libzip-dev libpng-dev libicu-dev \
      postgresql-client \
    && install-php-extensions \
      pdo_pgsql pgsql zip intl bcmath pcntl redis opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi || true \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

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
      > /usr/local/etc/php/conf.d/zz-opcache.ini

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=cookie \
    QUEUE_CONNECTION=redis \
    CACHE_STORE=redis \
    FILESYSTEM_DISK=local \
    SERVER_NAME=:8080

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app/public"]
