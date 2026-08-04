#!/bin/sh
# Usage: php-ext-install.sh <container> <extension>
# extension is already validated by caller (curated + [a-z0-9_]+)
set -eu
container="$1"
ext="$2"

run() {
    docker exec "$container" sh -c "$1"
}

apt_install() {
    run "apt-get update && apt-get install -y --no-install-recommends $1 && rm -rf /var/lib/apt/lists/*"
}

case "$ext" in
    redis)
        run "pecl install -f redis && docker-php-ext-enable redis"
        ;;
    imagick)
        apt_install "libmagickwand-dev"
        run "pecl install -f imagick && docker-php-ext-enable imagick"
        ;;
    mongodb)
        run "pecl install -f mongodb && docker-php-ext-enable mongodb"
        ;;
    xdebug)
        run "pecl install -f xdebug && docker-php-ext-enable xdebug"
        ;;
    bcmath|exif)
        run "docker-php-ext-install $ext"
        ;;
    intl)
        apt_install "libicu-dev"
        run "docker-php-ext-install intl"
        ;;
    soap)
        apt_install "libxml2-dev"
        run "docker-php-ext-install soap"
        ;;
    gmp)
        apt_install "libgmp-dev"
        run "docker-php-ext-install gmp"
        ;;
    opcache)
        run "docker-php-ext-install opcache || docker-php-ext-enable opcache"
        ;;
    *)
        echo "unsupported extension: $ext" >&2
        exit 1
        ;;
esac
