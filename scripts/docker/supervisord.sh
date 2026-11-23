#!/bin/sh

# Tạo các thư mục cần thiết cho supervisor
mkdir -p /var/log/supervisor
mkdir -p /var/run

# Cấp quyền cho các thư mục
chmod 777 /var/run
chmod -R 777 /var/log/supervisor

# Tin cert tự ký của các site để gọi https://<site>.test
/var/scripts/docker/trust-local-certs.sh

# Khởi động Supervisor
exec /usr/bin/supervisord -c /etc/supervisord.conf
