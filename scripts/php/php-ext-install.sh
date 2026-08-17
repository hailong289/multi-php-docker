#!/bin/sh
# Usage: php-ext-install.sh <container> <extension>
# extension is already validated by caller ([a-z][a-z0-9_]*)
set -eu
container="$1"
ext="$2"

run() {
    docker exec "$container" sh -c "$1"
}

already_loaded() {
    # PHP 8.5+ reports the Zend extension as "Zend OPcache", not "opcache".
    docker exec "$container" php -r "exit((extension_loaded('$ext') || ('$ext' === 'opcache' && extension_loaded('Zend OPcache'))) ? 0 : 1);" >/dev/null 2>&1
}

is_alpine() {
    run "test -f /etc/alpine-release"
}

ensure_build_deps() {
    if is_alpine; then
        run 'apk add --no-cache $PHPIZE_DEPS'
    else
        run 'apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS && rm -rf /var/lib/apt/lists/*'
    fi
}

pkg_install() {
    debian_pkgs="$1"
    alpine_pkgs="${2:-$1}"
    if is_alpine; then
        run "apk add --no-cache $alpine_pkgs"
    else
        run "apt-get update && apt-get install -y --no-install-recommends $debian_pkgs && rm -rf /var/lib/apt/lists/*"
    fi
}

install_pecl() {
    name="$1"
    ensure_build_deps
    run "pecl install -f $name && docker-php-ext-enable $name"
}

install_builtin() {
    run "docker-php-ext-install $1"
}

if already_loaded; then
    echo "already loaded: $ext"
    exit 0
fi

case "$ext" in
    redis|mongodb|xdebug)
        install_pecl "$ext"
        ;;
    imagick)
        pkg_install "libmagickwand-dev" "imagemagick-dev"
        install_pecl imagick
        ;;
    bcmath|exif)
        install_builtin "$ext"
        ;;
    intl)
        pkg_install "libicu-dev" "icu-dev"
        install_builtin intl
        ;;
    soap)
        pkg_install "libxml2-dev" "libxml2-dev"
        install_builtin soap
        ;;
    gmp)
        pkg_install "libgmp-dev" "gmp-dev"
        install_builtin gmp
        ;;
    opcache)
        # Many images compile Zend OPcache in; otherwise enable the .so.
        run "docker-php-ext-enable opcache || docker-php-ext-install opcache"
        ;;
    *)
        if run "docker-php-ext-install $ext"; then
            :
        else
            install_pecl "$ext"
        fi
        ;;
esac

if ! already_loaded; then
    echo "extension not loaded after install: $ext" >&2
    exit 1
fi
