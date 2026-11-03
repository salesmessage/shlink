ARG VER=8.2
FROM php:${VER}-cli-alpine

RUN apk add --no-cache bash git libgcc

# add extensions installer
ADD --checksum=sha256:9c26dd516b8147235c4e924c52e2f076fb2b3f278f5457e88918518ebfee9e01 \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.12.3/install-php-extensions /usr/local/bin/
RUN chmod -R 0755 /usr/local/bin/

# install extensions
# @see https://github.com/mlocati/docker-php-extension-installer/blob/master/data/supported-extensions
RUN install-php-extensions gd redis curl pdo_pgsql mbstring zip ffi @composer

# Set PHP memory limit for CLI
RUN echo "memory_limit = 1024M" > /usr/local/etc/php/conf.d/memory-limit.ini

WORKDIR /app

# shlink: sockets, as in its CI job
RUN install-php-extensions sockets
