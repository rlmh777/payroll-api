#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
  php artisan migrate --force --no-interaction
fi

# Seeding is optional and must not block the HTTP process on restarts.
if [[ "${RUN_SEEDERS:-false}" == "true" ]]; then
  php artisan db:seed --force --no-interaction || echo "WARNING: db:seed failed (data may already exist); continuing."
fi

exec "$@"
