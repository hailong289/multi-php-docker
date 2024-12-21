# Chọn image PHP (ví dụ: php:7.4-fpm hoặc php:8.1-fpm)
FROM php:8.3-fpm

# Cài đặt các thư viện phụ thuộc cho các extension PHP
RUN apt-get update && apt-get install -y \
    git \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    unzip \
    libz-dev \
    libssl-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install grpc \
    && docker-php-ext-enable grpc

# Cài đặt các extension PHP cần thiết
RUN docker-php-ext-install pdo_mysql mysqli gd zip sockets
# Nếu bạn cần sử dụng Xdebug, cài đặt qua PECL
#RUN pecl install xdebug \
#    && docker-php-ext-enable xdebug \
# cài dặt composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
# Dọn dẹp bộ nhớ cache sau khi cài đặt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*
# Cấu hình PHP (nếu cần thiết)
COPY ./php_configs/php8/php.ini /usr/local/etc/php/
# Copy source code vào container
#COPY ./web /var/www
# Cài đặt các thư viện phụ thuộc (không cần nữa do chạy ở bên ngoài file docker compose)
# RUN composer install --working-dir=/var/www/hola-framework
# RUN composer install --working-dir=/var/www/spa-fnb-retail
# Expose port 9000 (if using PHP-FPM)
EXPOSE 9000
# Khởi động PHP-FPM
CMD ["php-fpm"]