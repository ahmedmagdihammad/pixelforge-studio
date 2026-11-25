#!/bin/sh
# Starts everything the app needs in one container.
set -e

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Queue worker: submits generations to Pixelforge and retries with backoff. Restarted if it ever exits.
(while true; do php artisan queue:work --sleep=1 --max-time=3600 || true; sleep 1; done) &

# Scheduler: runs generations:sync every minute (the fallback for lost webhooks).
(while true; do php artisan schedule:work || true; sleep 1; done) &

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}" --no-reload
