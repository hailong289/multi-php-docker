#!/bin/sh

# Xóa cấu hình runtime từ lần chạy trước. Các file hiện tại sẽ được tạo lại
# hoàn toàn từ /etc/nginx/templates ngay bên dưới.
find /etc/nginx/conf.d -maxdepth 1 -type f -name '*.conf' -delete

for template in /etc/nginx/templates/*.template; do
    [ -e "$template" ] || break  # Thoát nếu không có tệp nào
    # Sao chép tệp từ /etc/nginx/templates/ vào /etc/nginx/conf.d/ mà không giữ phần mở rộng .template
    cp "$template" "/etc/nginx/conf.d/$(basename "${template%.template}.conf")"
done

# Theo dõi yêu cầu reload từ Server Manager trong background.
sh /var/scripts/nginx/watch-nginx-reload.sh &

exec nginx -g 'daemon off;'
