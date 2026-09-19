#!/bin/sh
set -e

# storage/ is usually a named volume, and a fresh volume mounts empty over
# whatever the image had there -- recreate the tree Laravel expects before it
# tries to write a log line and dies.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

# Caches are built here rather than at image build time because config:cache
# bakes in .env values, and .env only exists on the server. Note the
# consequence: once config is cached Laravel stops reading .env entirely, so
# changing a value means restarting the container, not just editing the file.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Deliberately no `migrate` here. Running migrations from an entrypoint means
# every container restart races every other one, and a failed migration takes
# the app down instead of failing loudly in a place someone is watching.
# Run it as a deliberate step: docker compose -f docker-compose.prod.yml run --rm php php artisan migrate --force

exec "$@"
