#!/usr/bin/env bash
set -euo pipefail

cd /app

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  if [[ "${TENANCY_ENABLED:-false}" == "true" ]]; then
    php artisan tenants:install-platform --no-interaction || true
    php artisan tenants:migrate --force --no-interaction
  else
    php artisan migrate --force --no-interaction
  fi
fi

if [[ "${RUN_SEEDERS:-false}" == "true" ]]; then
  if [[ "${TENANCY_ENABLED:-false}" == "true" ]]; then
    php artisan tenants:migrate --seed --force --no-interaction || echo "WARNING: tenant seed failed (data may already exist); continuing."
  else
    php artisan db:seed --force --no-interaction || echo "WARNING: db:seed failed (data may already exist); continuing."
  fi
fi

php artisan config:cache --no-interaction || true
php artisan route:cache --no-interaction || true
php artisan view:cache --no-interaction || true
php artisan event:cache --no-interaction || true

exec "$@"
