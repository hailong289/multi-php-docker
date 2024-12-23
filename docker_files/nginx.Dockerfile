FROM nginx:latest

# Cài đặt envsubst (công cụ thay thế biến môi trường)
RUN apt-get update && apt-get install -y gettext-base && rm -rf /var/lib/apt/lists/*

# Copy các template vào container
COPY nginx/conf.d/ /etc/nginx/conf.d/

# Sao chép script vào container
COPY scripts/ /var/scripts/
# Chỉnh quyền thực thi cho script
RUN chmod -R +x /var/scripts/*

# Chạy Nginx
CMD ["nginx", "-g", "daemon off;"]