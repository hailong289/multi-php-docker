# Sử dụng image Node.js chính thức để làm base image
FROM node:20-alpine

# Set thư mục làm việc trong container
#WORKDIR /var/www/spa-fnb-retail-frontend-sale

#COPY ../www/spa-fnb-retail-frontend-sale /var/www/spa-fnb-retail-frontend-sale

# Cài đặt pnpm
RUN npm install -g pnpm

# Cài đặt các dependencies của dự án Angular
# RUN pnpm install

# Copy toàn bộ mã nguồn vào container


# Build ứng dụng Angular
#RUN pnpm run build

# Mở port ứng dụng Angular (thường port 4200 cho Angular)
#EXPOSE 4200
#
## Chạy ứng dụng Angular
#CMD ["pnpm", "start"]
