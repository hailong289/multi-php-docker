FROM php:8.4-fpm-alpine

# Composer from the official image: cached as its own layer, no installer download.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Runtime libraries stay. Compilers are installed in a virtual package and removed
# in this same layer, so they are not part of the image.
RUN apk add --no-cache \
        git curl unzip supervisor \
        freetype libjpeg-turbo libpng libzip libxml2 \
    && apk add --no-cache --virtual .php-build \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        libxml2-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip sockets pcntl \
    && apk del .php-build \
    && rm -rf /tmp/pear \
    && docker-php-source delete

EXPOSE 9000

CMD ["php-fpm"]
