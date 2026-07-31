# Chọn image PHP (ví dụ: php:7.4-fpm hoặc php:8.1-fpm)
FROM php:8.2-fpm

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
    curl \
    supervisor \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer

# Cài đặt các extension PHP cần thiết
RUN docker-php-ext-install pdo_mysql mysqli gd zip sockets pcntl

# Dọn dẹp bộ nhớ cache sau khi cài đặt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*
# Expose port 9000 (if using PHP-FPM)
EXPOSE 9000
# Khởi động PHP-FPM
CMD ["php-fpm"]
