[Tiếng Việt](README.md) | [English](README.en.md)

# Môi trường phát triển PHP với Docker

Repository cung cấp môi trường phát triển cục bộ gồm Nginx, PHP 7.4, PHP 8.2, MySQL, Redis và RabbitMQ. Các image multi-architecture đã được cung cấp sẵn trên Docker Hub; người dùng có thể pull và chạy ngay mà không cần build. Dockerfile vẫn được giữ trong repository để bạn tùy chỉnh và tự build image riêng khi cần. Nginx tự tạo virtual host từ [`env.json`](env.json), cho phép chạy nhiều project với domain và phiên bản PHP khác nhau.

## Dịch vụ và cổng mặc định

| Dịch vụ | Container | Cổng host | Thông tin mặc định |
| --- | --- | --- | --- |
| Nginx | `nginx_container` | `80`, `443` | Phục vụ domain trong `env.json` |
| PHP 8.2 | `php8.2_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| PHP 7.4 | `php7.4_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| MySQL | `mysql_container` | `3306` | User `root`, password `1` |
| Redis | `redis_container` | `6379` | Không có mật khẩu |
| RabbitMQ | `rabbitmq_container` | `5672`, `15672` | User/password: `admin` / `admin` |
| Supervisor | `supervisor_container` | Không public | Chạy background worker bằng PHP 8.2 |

Các image được cung cấp sẵn:

| Service | Image |
| --- | --- |
| `nginx` | `long301001/multi-php-docker:nginx` |
| `php-8.2`, `supervisor` | `long301001/multi-php-docker:php-8.2` |
| `php-7.4` | `long301001/multi-php-docker:php-7.4` |
| `mysql` | `long301001/multi-php-docker:mysql` |
| `redis` | `long301001/multi-php-docker:redis-alpine` |
| `rabbitmq` | `long301001/multi-php-docker:rabbitmq-3-management` |

## Yêu cầu

- Docker Desktop hoặc Docker Engine.
- Docker Compose v2 (lệnh `docker compose`).
- `jq` nếu dùng script tự động thêm domain vào file `hosts`.
- Quyền quản trị để chỉnh file `hosts`.

Kiểm tra môi trường:

```bash
docker --version
docker compose version
```

## Cài đặt và sử dụng

### 1. Clone repository

```bash
git clone <repository-url>
cd <repository-folder>
```

### 2. Đặt source code vào đúng thư mục

- PHP 8.2: `server/source_php8.2/<project-name>`
- PHP 7.4: `server/source_php7.4/<project-name>`

Các thư mục tương ứng bên trong container là `/var/www/source_php8.2` và `/var/www/source_php7.4`.

```text
server/
├── source_php8.2/
│   └── my-php8-app/
└── source_php7.4/
    └── my-php7-app/
```

### 3. Khai báo project trong `env.json`

Mỗi project tương ứng với một mục `SERVER_NAME<N>`:

```json
{
  "SERVER_NAME1": {
    "APP_NAME": "my-php8-app",
    "DOMAIN_NAME": "my-php8-app.test",
    "SERVER_PATH": "/var/www/source_php8.2/my-php8-app/public",
    "CONTAINER_PHP_VERSION": "php8.2_container"
  },
  "SERVER_NAME2": {
    "APP_NAME": "my-php7-app",
    "DOMAIN_NAME": "my-php7-app.test",
    "SERVER_PATH": "/var/www/source_php7.4/my-php7-app/public",
    "CONTAINER_PHP_VERSION": "php7.4_container"
  }
}
```

| Trường | Ý nghĩa |
| --- | --- |
| `APP_NAME` | Tên project và tên file cấu hình Nginx được sinh ra |
| `DOMAIN_NAME` | Domain dùng trên máy local |
| `SERVER_PATH` | Document root tuyệt đối **bên trong container** |
| `CONTAINER_PHP_VERSION` | `php8.2_container` hoặc `php7.4_container` |

Với Laravel hoặc framework có thư mục public riêng, `SERVER_PATH` phải trỏ tới thư mục `public`, `webroot` hoặc thư mục chứa `index.php`.

### 4. Thêm domain vào file `hosts`

Tự động trên macOS, Linux hoặc WSL:

```bash
chmod +x scripts/add_hostname.sh
./scripts/add_hostname.sh
```

Script đọc các `DOMAIN_NAME` trong `env.json` và ánh xạ chúng tới `127.0.0.1`. Nếu không dùng script, thêm thủ công vào:

- macOS/Linux: `/etc/hosts`
- Windows: `C:\Windows\System32\drivers\etc\hosts`

```text
127.0.0.1 my-php8-app.test
127.0.0.1 my-php7-app.test
```

### 5. Pull image và khởi động

Lần chạy đầu tiên, tải các image được cung cấp sẵn rồi tạo container:

```bash
docker compose pull
docker compose up -d
```

Các lần sau chỉ cần:

```bash
docker compose up -d
```

Kiểm tra trạng thái:

```bash
docker compose ps
```

Khi Nginx khởi động, `scripts/auto-add-template.sh` đọc `env.json`, tạo virtual host từ `nginx/examples/server_example.txt` rồi nạp cấu hình. Truy cập project bằng domain đã khai báo, ví dụ `http://my-php8-app.test`.

## Lệnh thường dùng

### Xem log

```bash
docker compose logs -f
docker compose logs -f nginx
docker compose logs -f php-8.2
docker compose logs -f mysql
```

### Dừng, khởi động lại và xóa container

```bash
# Dừng nhưng không xóa container
docker compose stop

# Khởi động các container đã dừng
docker compose start

# Dừng và xóa container/network; named volume vẫn được giữ
docker compose down

# Khởi động lại một dịch vụ
docker compose restart nginx
```

### Cập nhật image từ registry

```bash
# Tải phiên bản mới nhất của các tag hiện tại
docker compose pull
docker compose up -d
```

### Tự build image riêng

Nếu muốn thay đổi extension, package hoặc cấu hình bên trong image, hãy đổi `image` sang tên riêng và thêm `build` cho service tương ứng. Không nên giữ tên `long301001/multi-php-docker:*` cho image tự build.

Ví dụ tự build PHP 8.2:

```yaml
services:
  php-8.2:
    image: my-project/php:8.2-local
    build:
      context: .
      dockerfile: ./docker_files/php8.Dockerfile
    # Giữ nguyên volumes, working_dir và networks hiện có

  supervisor:
    image: my-project/php:8.2-local
    # Không thêm build; Supervisor dùng lại image của php-8.2
```

Build image riêng trước rồi khởi động container:

```bash
docker compose build php-8.2
docker compose up -d php-8.2 supervisor

# Build và chạy một service khác
docker compose build <service-name>
docker compose up -d <service-name>
```

Tên service hợp lệ: `nginx`, `php-8.2`, `php-7.4`, `supervisor`, `mysql`, `redis`, `rabbitmq`.

## Chạy background worker với Supervisor

Hai service `php-8.2` và `supervisor` cùng dùng image có sẵn `long301001/multi-php-docker:php-8.2`. Hai service cũng mount chung source tại `server/source_php8.2` và `php.ini`. Supervisor chạy worker trong container riêng; nó không điều khiển process bên trong container PHP-FPM.

### Tạo cấu hình worker

Sao chép file mẫu:

```bash
cp configs/supervisor.d/worker.conf.example configs/supervisor.d/worker.conf
```

Sửa `directory` và `command` trong `worker.conf` cho đúng project. Ví dụ Laravel:

```ini
[program:app_worker]
directory=/var/www/source_php8.2/my-project
command=php artisan queue:work --sleep=3 --tries=3 --timeout=90
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/app-worker.log
```

Có thể tạo nhiều file `.conf` trong `configs/supervisor.d/` để chạy worker cho nhiều project. Các file có đuôi `.example` không được nạp tự động.

### Khởi động và quản lý worker

```bash
# Khởi động PHP-FPM và Supervisor từ image có sẵn
docker compose up -d php-8.2 supervisor

# Xem trạng thái worker
docker compose exec supervisor supervisorctl status

# Nạp lại cấu hình sau khi thêm hoặc sửa file .conf
docker compose exec supervisor supervisorctl reread
docker compose exec supervisor supervisorctl update

# Khởi động lại tất cả worker
docker compose exec supervisor supervisorctl restart all

# Xem log container và log worker
docker compose logs -f supervisor
ls logs/supervisor
```

Supervisor dùng hostname `mysql`, `redis` và `rabbitmq` để kết nối các dịch vụ trong `app-network`. `depends_on` chỉ đảm bảo container được khởi động theo thứ tự, không đảm bảo dịch vụ đã sẵn sàng nhận kết nối; worker nên có cơ chế retry.

### Dùng Supervisor với phiên bản PHP khác

Mỗi container Supervisor chỉ có một PHP runtime. Vì vậy, nếu project dùng nhiều phiên bản PHP, hãy tạo một service Supervisor cho mỗi phiên bản và cho nó dùng chung image với service PHP-FPM tương ứng:

| PHP-FPM service | Supervisor service | Image dùng chung |
| --- | --- | --- |
| `php-8.2` | `supervisor` | `long301001/multi-php-docker:php-8.2` |
| `php-7.4` | `supervisor-7` | Image PHP 7.4 tùy chỉnh của bạn |
| `php-8-3` | `supervisor-8-3` | `server-php:8.3-local` |

Không khai báo `build` trong service Supervisor. Với image tùy chỉnh, chỉ service PHP-FPM tương ứng khai báo `build`; service Supervisor dùng lại cùng tên image để tránh build lặp lại.

#### Ví dụ Supervisor cho PHP 7.4

Image PHP 7.4 được cung cấp sẵn hiện chưa có Supervisor. Để chạy `supervisor-7`, hãy tạo image tùy chỉnh: thêm `supervisor` vào danh sách package trong `docker_files/php7.Dockerfile`, đổi image của `php-7.4` sang tên riêng và thêm `build` như hướng dẫn ở mục **Tự build image riêng**.

```dockerfile
RUN apt-get update && apt-get install -y \
    supervisor \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    unzip \
    curl
```

Tạo thư mục cấu hình worker riêng:

```bash
mkdir -p configs/supervisor.d/php7.4
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php7.4/worker.conf
```

Thêm service vào `docker-compose.yml`:

```yaml
  supervisor-7:
    image: my-project/php:7.4-local
    container_name: supervisor7_container
    volumes:
      - ./server/source_php7.4:/var/www/source_php7.4
      - ./scripts:/var/scripts
      - ./configs/php7.4/php.ini:/usr/local/etc/php/php.ini
      - ./configs/supervisord.conf:/etc/supervisord.conf:ro
      - ./configs/supervisor.d/php7.4:/etc/supervisor/conf.d:ro
      - ./logs/supervisor-7:/var/log/supervisor
    working_dir: /var/www/source_php7.4
    command: ["/var/scripts/supervisord.sh"]
    depends_on:
      - mysql
      - redis
      - rabbitmq
    networks:
      - app-network
```

Build image PHP 7.4 một lần rồi khởi động cả PHP-FPM và Supervisor:

```bash
docker compose build php-7.4
docker compose up -d php-7.4 supervisor-7
docker compose exec supervisor-7 supervisorctl status
```

#### Ví dụ Supervisor cho PHP 8.3 hoặc phiên bản mới

Nếu Dockerfile của phiên bản mới được sao chép từ `php8.Dockerfile`, image đã có Supervisor. Gán tag image cho service PHP-FPM:

```yaml
  php-8-3:
    image: server-php:8.3-local
    build:
      context: .
      dockerfile: ./docker_files/php8.3.Dockerfile
    # volumes, working_dir và networks...
```

Sau đó thêm service Supervisor dùng lại image:

```yaml
  supervisor-8-3:
    image: server-php:8.3-local
    container_name: supervisor83_container
    volumes:
      - ./server/source_php8.3:/var/www/source_php8.3
      - ./scripts:/var/scripts
      - ./configs/php8.3/php.ini:/usr/local/etc/php/php.ini
      - ./configs/supervisord.conf:/etc/supervisord.conf:ro
      - ./configs/supervisor.d/php8.3:/etc/supervisor/conf.d:ro
      - ./logs/supervisor-8.3:/var/log/supervisor
    working_dir: /var/www/source_php8.3
    command: ["/var/scripts/supervisord.sh"]
    depends_on:
      - mysql
      - redis
      - rabbitmq
    networks:
      - app-network
```

Tạo cấu hình worker và khởi động:

```bash
mkdir -p configs/supervisor.d/php8.3
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.3/worker.conf

docker compose build php-8-3
docker compose up -d php-8-3 supervisor-8-3
docker compose exec supervisor-8-3 supervisorctl status
```

Trong mỗi `worker.conf`, cập nhật `directory` theo đúng source của phiên bản PHP, ví dụ `/var/www/source_php7.4/my-project` hoặc `/var/www/source_php8.3/my-project`. Tách thư mục cấu hình và thư mục log theo phiên bản giúp tránh nạp nhầm worker.

### Chạy lệnh trong container

```bash
docker compose exec php-8.2 sh
docker compose exec php-8.2 php -v
docker compose exec php-7.4 php -v
docker compose exec mysql mysql -uroot -p1
```

## Kết nối dịch vụ từ ứng dụng

Ứng dụng chạy trong container phải dùng tên service Docker làm hostname, không dùng `localhost`:

```dotenv
DB_HOST=mysql
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=1

REDIS_HOST=redis
REDIS_PORT=6379

RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=admin
RABBITMQ_PASSWORD=admin
```

RabbitMQ Management UI: [http://localhost:15672](http://localhost:15672).

Ứng dụng chạy trực tiếp trên máy host dùng `127.0.0.1` và cổng host trong bảng dịch vụ.

## Thêm hoặc thay đổi project

1. Đặt source vào thư mục PHP phù hợp.
2. Thêm hoặc sửa project trong `env.json`.
3. Chạy lại `./scripts/add_hostname.sh` nếu có domain mới.
4. Tạo lại Nginx để sinh lại virtual host:

```bash
docker compose up -d --force-recreate nginx
docker compose exec nginx nginx -t
```

## Thêm một phiên bản PHP khác

Ví dụ dưới đây thêm PHP 8.3. Có thể áp dụng tương tự cho phiên bản khác, nhưng cần kiểm tra tính tương thích của extension và thư viện hệ thống với phiên bản PHP đó.

### 1. Tạo Dockerfile cho PHP 8.3

Sao chép Dockerfile gần nhất làm mẫu:

```bash
cp docker_files/php8.Dockerfile docker_files/php8.3.Dockerfile
```

Trong `docker_files/php8.3.Dockerfile`, đổi base image:

```dockerfile
FROM php:8.3-fpm
```

Giữ lại hoặc điều chỉnh các package và extension trong Dockerfile tùy theo yêu cầu của project. Các extension hiện có ở bản PHP 8.2 gồm `pdo_mysql`, `mysqli`, `gd`, `zip`, `sockets`, `pcntl` và Redis.

### 2. Tạo cấu hình PHP

```bash
mkdir -p configs/php8.3
cp configs/php8/php.ini configs/php8.3/php.ini
```

Chỉnh `configs/php8.3/php.ini` nếu cần thay đổi giới hạn upload, memory hoặc thời gian chạy.

### 3. Tạo thư mục chứa source

```bash
mkdir -p server/source_php8.3
```

### 4. Thêm service vào `docker-compose.yml`

Thêm service mới cùng cấp với `php-8.2` và `php-7.4`:

```yaml
  php-8-3:
    build:
      context: .
      dockerfile: ./docker_files/php8.3.Dockerfile
    container_name: php83_container
    volumes:
      - ./server/source_php8.3:/var/www/source_php8.3
      - ./scripts:/var/scripts
      - ./configs/php8.3/php.ini:/usr/local/etc/php/php.ini
    working_dir: /var/www/source_php8.3
    networks:
      - app-network
```

Không cần public cổng `9000` ra máy host vì Nginx kết nối PHP-FPM qua `app-network`.

Thêm service mới vào `depends_on` của Nginx:

```yaml
  nginx:
    # ...
    depends_on:
      - php-8.2
      - php-7.4
      - php-8-3
```

### 5. Khai báo project sử dụng PHP 8.3

Thêm project vào `env.json`; `CONTAINER_PHP_VERSION` phải trùng với `container_name` vừa khai báo:

```json
{
  "SERVER_NAME3": {
    "APP_NAME": "my-php83-app",
    "DOMAIN_NAME": "my-php83-app.test",
    "SERVER_PATH": "/var/www/source_php8.3/my-php83-app/public",
    "CONTAINER_PHP_VERSION": "php83_container"
  }
}
```

`SERVER_NAME3` chỉ là ví dụ. Hãy dùng số thứ tự chưa tồn tại và giữ lại các mục project hiện có trong `env.json`.

### 6. Build và kiểm tra

```bash
./scripts/add_hostname.sh
docker compose up -d --build php-8-3
docker compose up -d --force-recreate nginx

docker compose exec php-8-3 php -v
docker compose exec nginx nginx -t
```

Sau đó mở `http://my-php83-app.test`. Nếu build extension thất bại, kiểm tra extension đó có hỗ trợ phiên bản PHP mới hay không và cập nhật các package hệ thống trong Dockerfile.

## Sao lưu và khôi phục MySQL

Named volume MySQL có tên `mysql-data`.

### Sao lưu

Dừng MySQL để dữ liệu nhất quán khi sao lưu trực tiếp volume:

```bash
docker compose stop mysql

docker run --rm \
  -v mysql-data:/data:ro \
  -v "$(pwd):/backup" \
  alpine \
  tar czf /backup/mysql-data.tar.gz -C /data .

docker compose start mysql
```

File sao lưu được tạo tại `./mysql-data.tar.gz`.

### Khôi phục

> Khôi phục sẽ ghi dữ liệu từ bản sao lưu vào volume hiện tại. Hãy sao lưu volume hiện tại trước khi thực hiện.

```bash
docker compose stop mysql

docker run --rm \
  -v mysql-data:/data \
  -v "$(pwd):/backup:ro" \
  alpine \
  tar xzf /backup/mysql-data.tar.gz -C /data

docker compose start mysql
```

## Xử lý lỗi thường gặp

### Domain không truy cập được

- Kiểm tra domain đã có trong file `hosts` và trỏ tới `127.0.0.1`.
- Chạy `docker compose ps` để kiểm tra Nginx và PHP.
- Kiểm tra các trường trong `env.json` rồi tạo lại container Nginx.

### Lỗi 404 hoặc `File not found`

- `SERVER_PATH` phải là đường dẫn bên trong container.
- Kiểm tra document root có đúng thư mục chứa `index.php` hay không.
- Kiểm tra source nằm đúng thư mục PHP 7.4 hoặc PHP 8.2.

### Cổng đã được sử dụng

Tắt ứng dụng đang chiếm cổng hoặc đổi cổng host trong `docker-compose.yml`. Ví dụ, đổi `"3306:3306"` thành `"3307:3306"` để MySQL dùng cổng `3307` trên host.

### Không kết nối được MySQL, Redis hoặc RabbitMQ từ PHP

Trong container, dùng hostname `mysql`, `redis`, `rabbitmq`, không dùng `localhost`. Kiểm tra trạng thái bằng `docker compose ps`.

### Build image thất bại

- Xem lỗi đầy đủ bằng `docker compose build --no-cache <service-name>`.
- Kiểm tra kết nối mạng và Docker daemon.
- Kiểm tra đúng tên Dockerfile, đặc biệt RabbitMQ dùng `docker_files/rabbitMQ.Dockerfile`.

## Cấu trúc repository

```text
.
├── configs/                 # Cấu hình PHP và Supervisor
│   └── supervisor.d/        # Cấu hình background worker
├── docker_files/            # Dockerfile để build các dịch vụ
├── mysql/                   # Cấu hình MySQL
├── nginx/
│   ├── examples/            # Virtual host mẫu
│   ├── logs/                # Log Nginx
│   └── templates/           # Cấu hình được sinh từ env.json
├── scripts/                 # Script khởi động và tạo cấu hình
├── server/
│   ├── source_php7.4/       # Source chạy PHP 7.4
│   └── source_php8.2/       # Source chạy PHP 8.2
├── docker-compose.yml
└── env.json                 # Khai báo project và domain
```
