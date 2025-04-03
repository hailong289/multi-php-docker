FROM nginx:latest

# Cài đặt envsubst (công cụ thay thế biến môi trường)
RUN apt-get update && apt-get install -y \
    gettext-base \
    jq \

RUN apt-get clean && rm -rf /var/lib/apt/lists/*
# Copy các template vào container
COPY nginx/templates/ /etc/nginx/templates/
COPY nginx/conf.d/ /etc/nginx/conf.d/

# Sao chép script vào container
COPY .env /var/environment/
COPY scripts/entrypoint.sh /var/scripts/entrypoint.sh
RUN chmod +x /var/scripts/entrypoint.sh

# Chạy Nginx
CMD ["nginx", "-g", "daemon off;"]