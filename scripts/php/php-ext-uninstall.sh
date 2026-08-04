#!/bin/sh
# Usage: php-ext-uninstall.sh <container> <extension>
# Removes enable-ini snippets and compiled .so when possible; pecl uninstall for PECL packages.
set -eu
container="$1"
ext="$2"

run() {
    docker exec "$container" sh -c "$1"
}

# Always try to disable conf.d snippets and delete the .so from the active extension_dir.
disable_and_unlink() {
    name="$1"
    run "rm -f /usr/local/etc/php/conf.d/*${name}*.ini /usr/local/etc/php/conf.d/docker-php-ext-${name}.ini 2>/dev/null || true"
    run "dir=\$(php -r 'echo ini_get(\"extension_dir\");') && rm -f \"\$dir/${name}.so\" 2>/dev/null || true"
}

case "$ext" in
    redis|imagick|mongodb|xdebug)
        run "pecl uninstall -r $ext 2>/dev/null || pecl uninstall $ext 2>/dev/null || true"
        disable_and_unlink "$ext"
        ;;
    bcmath|intl|soap|exif|gmp|opcache)
        disable_and_unlink "$ext"
        ;;
    *)
        echo "unsupported extension: $ext" >&2
        exit 1
        ;;
esac

# Fail if still loaded after cleanup (baked into binary / still enabled).
if docker exec "$container" php -r "exit(extension_loaded('$ext') ? 0 : 1);" >/dev/null 2>&1; then
    echo "extension still loaded after uninstall: $ext" >&2
    exit 1
fi
