# Môi trường phát triển PHP với Docker

Repository cung cấp môi trường phát triển cục bộ gồm Nginx, PHP 7.4, PHP 8.2, MySQL, Redis và RabbitMQ. Các image được build trực tiếp từ Dockerfile trong repository. Nginx tự tạo virtual host từ [`env.json`](env.json), cho phép chạy nhiều project với domain và phiên bản PHP khác nhau.

## Dịch vụ và cổng mặc định

| Dịch vụ | Container | Cổng host | Thông tin mặc định |
| --- | --- | --- | --- |
| Nginx | `nginx_container` | `80`, `443` | Phục vụ domain trong `env.json` |
| PHP 8.2 | `php8_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| PHP 7.4 | `php7_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| MySQL | `mysql_container` | `3306` | User `root`, password `1` |
| Redis | `redis_container` | `6379` | Không có mật khẩu |
| RabbitMQ | `rabbitmq_container` | `5672`, `15672` | User/password: `admin` / `admin` |

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
    "CONTAINER_PHP_VERSION": "php8_container"
  },
  "SERVER_NAME2": {
    "APP_NAME": "my-php7-app",
    "DOMAIN_NAME": "my-php7-app.test",
    "SERVER_PATH": "/var/www/source_php7.4/my-php7-app/public",
    "CONTAINER_PHP_VERSION": "php7_container"
  }
}
```

| Trường | Ý nghĩa |
| --- | --- |
| `APP_NAME` | Tên project và tên file cấu hình Nginx được sinh ra |
| `DOMAIN_NAME` | Domain dùng trên máy local |
| `SERVER_PATH` | Document root tuyệt đối **bên trong container** |
| `CONTAINER_PHP_VERSION` | `php8_container` hoặc `php7_container` |

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

### 5. Build và khởi động

Lần chạy đầu tiên cần build các image từ thư mục `docker_files/`:

```bash
docker compose up -d --build
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
docker compose logs -f php-8
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

### Build lại image

```bash
# Build toàn bộ image và tạo lại container
docker compose up -d --build

# Build và chạy lại một service
docker compose build <service-name>
docker compose up -d <service-name>
```

Tên service hợp lệ: `nginx`, `php-8`, `php-7`, `mysql`, `redis`, `rabbitmq`.

### Chạy lệnh trong container

```bash
docker compose exec php-8 sh
docker compose exec php-8 php -v
docker compose exec php-7 php -v
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

Thêm service mới cùng cấp với `php-8` và `php-7`:

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
      - php-8
      - php-7
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
