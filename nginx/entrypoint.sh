#!/bin/sh
# Thay thế biến môi trường trong tất cả các file .template
for template in /etc/nginx/conf.d/*.conf.template; do
    [ -e "$template" ] || break  # Thoát nếu không có tệp nào
    envsubst '${SERVER_NAME1} ${SERVER_NAME2} ${SERVER_NAME3} ${SERVER_NAME4}' \
    < "$template" > "/etc/nginx/conf.d/$(basename "${template%.template}")"
done

# Thay thế biến môi trường trong nginx.conf
#envsubst '${SERVER_NAME1} ${SERVER_NAME2} ${SERVER_NAME3}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Khởi động Nginx
exec "$@"
