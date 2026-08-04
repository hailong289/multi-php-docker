#!/bin/sh
# Usage: php-ext-install.sh <container> <extension>
# extension is already validated by caller (curated + [a-z0-9_]+)
set -eu
container="$1"
ext="$2"

run() {
    docker exec "$container" sh -c "$1"
}

case "$ext" in
    redis)
        run "pecl install -f redis && docker-php-ext-enable redis"
        ;;
    imagick)
        run "apt-get update && apt-get install -y --no-install-recommends libmagickwand-dev && pecl install -f imagick && docker-php-ext-enable imagick && rm -rf /var/lib/apt/lists/*"
        ;;
    mongodb)
        run "pecl install -f mongodb && docker-php-ext-enable mongodb"
        ;;
    xdebug)
        run "pecl install -f xdebug && docker-php-ext-enable xdebug"
        ;;
    bcmath|intl|soap|exif|gmp)
        run "docker-php-ext-install $ext"
        ;;
    opcache)
        run "docker-php-ext-install opcache || docker-php-ext-enable opcache"
        ;;
    *)
        echo "unsupported extension: $ext" >&2
        exit 1
        ;;
esac
