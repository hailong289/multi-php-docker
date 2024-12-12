#!/bin/sh

# Thay thế biến môi trường trong tất cả các file .template
for template in /etc/nginx/conf.d/*.conf.template; do
    envsubst '${SERVER_NAME1} ${SERVER_NAME2}' < "$template" > "/etc/nginx/conf.d/$(basename "${template%.template}")"
done

# Thay thế biến môi trường trong nginx.conf.template
envsubst '${SERVER_NAME1} ${SERVER_NAME2}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Khởi động Nginx
exec "$@"
