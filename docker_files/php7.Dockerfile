FROM php:7.4-fpm-alpine

# Runtime libraries stay. Compilers are installed in a virtual package and removed
# in this same layer, so they are not part of the image.
RUN apk add --no-cache \
        curl unzip \
        freetype libjpeg-turbo libpng libzip libxml2 \
    && apk add --no-cache --virtual .php-build \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip \
    && curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apk del .php-build \
    && rm -rf /tmp/pear \
    && docker-php-source delete

CMD ["php-fpm"]
