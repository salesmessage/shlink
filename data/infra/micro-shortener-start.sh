#!/usr/bin/env sh
set -e

cd /app

if [ ! -d ./vendor ]; then
    composer install --no-interaction
fi

# The FastRoute cache is enabled unconditionally and does not track BASE_PATH or route changes
rm -f data/cache/fastroute_cached_routes.php data/cache/app_config.php

echo "Creating fresh database if needed..."
php bin/cli db:create -n

echo "Updating database..."
php bin/cli db:migrate -n

echo "Generating proxies..."
php bin/doctrine orm:generate-proxies -n

echo "Clearing entities cache..."
php bin/doctrine orm:clear-cache:metadata -n

# INITIAL_API_KEY is deliberately not passed to the server: it makes the openswoole master query the database
# before forking, and every worker then inherits that one connection and breaks on its first query
if [ -n "${LOCAL_API_KEY}" ]; then
    echo "Ensuring local API key exists..."
    php -r '
        $container = require "config/container.php";
        $container->get(Doctrine\ORM\EntityManager::class)
                  ->getRepository(Shlinkio\Shlink\Rest\Entity\ApiKey::class)
                  ->createInitialApiKey(getenv("LOCAL_API_KEY"));
    '
fi

# openswoole can believe it is still running after an unclean stop, so retry until it starts
until php vendor/bin/laminas mezzio:swoole:start; do sleep 1 ; done
