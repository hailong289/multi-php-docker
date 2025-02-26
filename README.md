# Docker with nginx, php, redis, mysql
A repo containing Dockerfiles to build images for nginx, php, redis, mysql

## Cách sử dụng
### 1 clone về máy 
### 2 Tạo domain và cấu hình nginx
Nếu các bạn muốn sử dụng domain thì cần thêm domain vào file hosts của máy tính
1. Mở file hosts
    - Windows: `C:\Windows\System32\drivers\etc\hosts`
    - Linux: `sudo nano /etc/hosts`
    - Mac: `sudo nano /etc/hosts`
    - Thêm dòng sau vào cuối file hosts. Ví dụ mình thêm một domain với server1.test thì sẽ chèn vô file host
```cmd
127.0.0.1     server1.test
```
### 3. Tạo 1 folder với tên web và chứa các project
### 4. Cấu hình nginx
Một domain sẽ tương ứng với 1 file cấu hình trong thư mục nginx/templates
Ở file docker file mình đã cấu hình sẵn cho các bạn 1 domain là server1.test
Xem bên trong file nginx/Dockerfile để biết cách cấu hình domain
### 5. Cấu hình php
Ở folder php_config có các version php bạn có thể thêm hoặc sử dụng version php mình đã cấu hình
Trong file php_configs/php8/php8.Dockerfile mình đã cấu hình sẵn cho các bạn
### 6. Chạy lệnh để build và chạy các container

```cmd
  docker-compose up -d
```

Xoá và dừng các container
```cmd
  docker-compose down
```

Chỉ dừng các container
```cmd
  docker-compose stop
```

### 7. Backup và khôi phục volumn mysql

```cmd
  docker run --rm \
    -v mysql-data:/data \
    -v $(pwd):/backup \
    alpine \
    tar czf /backup/mysql-data.tar.gz -C /data .
```

Khôi phục
```cmd
docker run --rm \
    -v mysql-data:/data \
    -v $(pwd):/backup \
    alpine \
    tar xzf /backup/mysql-data.tar.gz -C /data
```

### 8 rebuild container
```cmd
  docker-compose build <container>
```