FROM nginx:latest

# Cài đặt envsubst (công cụ thay thế biến môi trường)
RUN apt-get update && apt-get install -y gettext-base jq && apt-get clean && rm -rf /var/lib/apt/lists/*
# Tạo thư mục cho các file cấu hình
# Copy các template vào container
COPY nginx/examples /etc/nginx/examples

# Sao chép script vào container
COPY env.example.json /var/environment/env.json
COPY scripts/ /var/scripts/
# Chạy Nginx
CMD ["nginx", "-g", "daemon off;"]