#!/bin/sh
# Runs each time an app, queue or scheduler container starts.
set -e

# storage/ is mounted from the server, so make sure Laravel's folders exist
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
         storage/framework/views storage/logs

# cache config, routes, views and events from the real environment
php artisan optimize

exec "$@"
