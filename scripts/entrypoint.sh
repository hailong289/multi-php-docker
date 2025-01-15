#!/bin/sh
while [ ! -f "/var/scripts/entrypoint.sh" ]; do
  echo "Waiting for /var/scripts/entrypoint.sh to be available..."
  sleep 1
done

variables=$(env | grep '^SERVER_NAME' | cut -d= -f1 | sed 's/^/${/;s/$/}/' | tr '\n' ' ');
# Thay thế biến môi trường trong tất cả các file .template
for template in /etc/nginx/templates/*.conf.template; do
    [ -e "$template" ] || break  # Thoát nếu không có tệp nào
    envsubst "$variables" \
    < "$template" > "/etc/nginx/conf.d/$(basename "${template%.template}")"
done

# Thay thế biến môi trường trong nginx.conf
#envsubst '${SERVER_NAME1} ${SERVER_NAME2} ${SERVER_NAME3}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Khởi động Nginx
exec nginx -g 'daemon off;'