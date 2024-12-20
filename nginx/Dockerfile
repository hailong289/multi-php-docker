FROM nginx:latest

# Cài đặt envsubst (công cụ thay thế biến môi trường)
RUN apt-get update && apt-get install -y gettext-base && rm -rf /var/lib/apt/lists/*

# Copy các template vào container
COPY nginx/templates/*.template /etc/nginx/conf.d/

# Copy script entrypoint
COPY nginx/entrypoint.sh /entrypoint.sh
COPY nginx/ssl /etc/nginx/ssl

# Chỉnh quyền thực thi cho entrypoint script
RUN chmod +x /entrypoint.sh

# Đặt entrypoint mặc định
ENTRYPOINT ["/entrypoint.sh"]

# Chạy Nginx
CMD ["nginx", "-g", "daemon off;"]