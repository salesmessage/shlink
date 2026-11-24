#!/usr/bin/env sh
# Boot script of the micro-shortener service of sm-tool, run from the bind mount at /app (see AGENTS.md)
set -e

cd /app

if [ ! -d ./vendor ]; then
    composer install --no-interaction
fi

if [ ! -x ./bin/rr ]; then
    php vendor/bin/rr get --no-interaction --no-config --location bin/ && chmod +x bin/rr
fi

# A cached config would keep an old BASE_PATH or set of routes after the .env or the code changes
rm -f data/cache/fastroute_cached_routes.php data/cache/app_config.php

flags="--no-interaction --clear-db-cache --skip-download-geolite"
# Creates the key only when the database has no API key yet
if [ -n "${LOCAL_API_KEY}" ]; then
    flags="${flags} --initial-api-key=${LOCAL_API_KEY}"
fi

# Creates the database if needed, migrates it, generates the ORM proxies and clears the caches
php vendor/bin/shlink-installer init ${flags}

exec ./bin/rr serve -c config/roadrunner/.rr.yml
