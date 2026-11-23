#!/usr/bin/env bash
set -e

echo 'Caching config, routes, and views...'
php artisan config:clear
php artisan optimize
php artisan storage:link || true

echo 'Running database migrations...'
php artisan migrate --force

echo 'Starting background queue worker...'
php artisan queue:work --tries=3 --sleep=3 --max-time=3600 &

echo 'Starting Laravel server...'
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
