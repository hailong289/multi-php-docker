#!/bin/sh

# Cài đặt Composer dependencies cho các thư mục cụ thể
composer install --working-dir=/var/www/posapp-crm

# Khởi động php-fpm
php-fpm