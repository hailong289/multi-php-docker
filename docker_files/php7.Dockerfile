# Chọn image PHP (ví dụ: php:7.4-fpm)
FROM php:7.4-fpm

# Cài đặt các thư viện phụ thuộc cho các extension PHP
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    unzip \
    curl \
    && curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer \
    && rm -rf /var/lib/apt/lists/*
# Cài đặt các extension PHP cần thiết
RUN docker-php-ext-install pdo_mysql mysqli gd zip
# Khởi động PHP-FPM
CMD ["php-fpm"]