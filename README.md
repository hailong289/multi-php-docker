[Tiếng Việt](README.md) | [English](README.en.md)

# Môi trường phát triển PHP với Docker

Repository cung cấp môi trường phát triển cục bộ gồm Nginx, PHP 7.4, PHP 8.0, PHP 8.1, PHP 8.2, MySQL, Redis và RabbitMQ. Tất cả service mặc định dùng image multi-architecture được cung cấp sẵn trên Docker Hub; `docker-compose.yml` không tự build image. Các Dockerfile vẫn có trong repository để tham khảo hoặc tạo image tùy chỉnh. Nginx tự tạo virtual host từ file `env.json` local dựa trên mẫu [`env.example.json`](env.example.json), cho phép chạy nhiều project với domain và phiên bản PHP khác nhau.

## Dịch vụ và cổng mặc định

| Dịch vụ | Container | Cổng host | Thông tin mặc định |
| --- | --- | --- | --- |
| Nginx | `nginx_container` | `80`, `443` | Phục vụ domain trong `env.json` |
| PHP 8.0 | `php8.0_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| PHP 8.1 | `php8.1_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| PHP 8.2 | `php8.2_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| PHP 7.4 | `php7.4_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| MySQL | `mysql_container` | `3306` | User `root`, password `1` |
| Redis | `redis_container` | `6379` | Không có mật khẩu |
| RabbitMQ | `rabbitmq_container` | `5672`, `15672` | User/password: `admin` / `admin` |
| Supervisor | `supervisor_container` | Không public | Chạy background worker bằng PHP 8.2 |
| Server Manager | `manager_container` | `127.0.0.1:8080` | Quản lý virtual server trong `env.json` |
| PHP Controller | `php_controller_container` | Không public | Điều khiển allowlist PHP container qua Docker socket |
| Env Init | `env_init_container` | Không public | Tạo `env.json` nếu thiếu rồi thoát với mã `0` |

PHP 8.2 là phiên bản mặc định. `docker compose up -d` chỉ khởi động PHP 8.2; PHP 7.4, 8.0 và 8.1 được đặt trong profile riêng và mặc định không chạy.

Các image được cung cấp sẵn:

| Service | Image |
| --- | --- |
| `nginx` | `long301001/multi-php-docker:nginx` |
| `php-8.0` | `long301001/multi-php-docker:php-8.0` |
| `php-8.1` | `long301001/multi-php-docker:php-8.1` |
| `php-8.2`, `supervisor`, `manager` | `long301001/multi-php-docker:php-8.2` |
| `php-7.4` | `long301001/multi-php-docker:php-7.4` |
| `php-controller` | `docker:cli` |
| `mysql` | `long301001/multi-php-docker:mysql` |
| `redis` | `long301001/multi-php-docker:redis-alpine` |
| `rabbitmq` | `long301001/multi-php-docker:rabbitmq-3-management` |
| `env-init` | `alpine:latest` |

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

`env.json` chứa cấu hình project riêng của từng máy và không được push lên Git. Chỉ `env.example.json` được commit làm cấu hình mẫu. Khi chạy Compose lần đầu, service `env-init` tự copy `env.example.json` thành `env.json`; file đã tồn tại sẽ luôn được giữ nguyên. Compose mount thư mục project (không mount trực tiếp file `env.json`) để tránh lỗi bind-mount khác nhau giữa Windows và macOS.

Nếu muốn tạo file trước khi chạy Docker, có thể thực hiện thủ công:

```bash
cp env.example.json env.json
```

### 2. Đặt source code vào đúng thư mục

- PHP 8.0: `server/source_php8.0/<project-name>`
- PHP 8.1: `server/source_php8.1/<project-name>`
- PHP 8.2: `server/source_php8.2/<project-name>`
- PHP 7.4: `server/source_php7.4/<project-name>`

Các thư mục được mount vào container tại `/var/www/source_php<version>` tương ứng.

```text
server/
├── source_php8.0/
│   └── my-php80-app/
├── source_php8.1/
│   └── my-php81-app/
├── source_php8.2/
│   └── my-php82-app/
└── source_php7.4/
    └── my-php7-app/
```

### 3. Khai báo project trong `env.json`



Mỗi project tương ứng với một mục `SERVER_NAME<N>`:

```json
{
  "SERVER_NAME1": {
    "APP_NAME": "my-php80-app",
    "DOMAIN_NAME": "my-php80-app.test",
    "SERVER_PATH": "/var/www/source_php8.0/my-php80-app/public",
    "CONTAINER_PHP_VERSION": "php8.0_container"
  },
  "SERVER_NAME2": {
    "APP_NAME": "my-php81-app",
    "DOMAIN_NAME": "my-php81-app.test",
    "SERVER_PATH": "/var/www/source_php8.1/my-php81-app/public",
    "CONTAINER_PHP_VERSION": "php8.1_container"
  },
  "SERVER_NAME3": {
    "APP_NAME": "my-php82-app",
    "DOMAIN_NAME": "my-php82-app.test",
    "SERVER_PATH": "/var/www/source_php8.2/my-php82-app/public",
    "CONTAINER_PHP_VERSION": "php8.2_container"
  },
  "SERVER_NAME4": {
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
| `CONTAINER_PHP_VERSION` | `php8.0_container`, `php8.1_container`, `php8.2_container` hoặc `php7.4_container` |

Với Laravel hoặc framework có thư mục public riêng, `SERVER_PATH` phải trỏ tới thư mục `public`, `webroot` hoặc thư mục chứa `index.php`.

### 4. Thêm domain vào file `hosts`

**Đồng bộ trạng thái từ file hosts (không cần admin):**

1. Chạy script nhận diện OS để ghi `HOSTS_FILE` và `HOST_PROJECT_PATH` vào `.env` (Compose tự đọc):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\ensure_hosts_env.ps1
```

macOS / Linux / WSL:

```bash
chmod +x scripts/ensure_hosts_env.sh
./scripts/ensure_hosts_env.sh
```

2. Mở Server Manager → **Quản lý domain**.
3. Bấm **Đồng bộ domain từ file hosts**. Manager đọc file hosts đã mount và cập nhật badge đã đồng bộ / thiếu.

Nếu đổi máy / OS, chạy lại `ensure_hosts_env` rồi `docker compose up -d manager --force-recreate`.

**Ghi hosts (cần script trên host + quyền admin):**

1. Trên Windows, chạy một lần (ghi `HOSTS_FILE` + đăng ký protocol `multi-php-hosts:`):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\ensure_hosts_env.ps1
```

Gỡ protocol: `powershell -ExecutionPolicy Bypass -File .\scripts\ensure_hosts_env.ps1 -UnregisterProtocol`

2. Trong Manager dùng **Thêm domain** / **Ghi hosts (Admin)**. Trình duyệt mở `multi-php-hosts:write` → chạy `add_hostname.ps1` (có UAC). Cho phép mở ứng dụng nếu trình duyệt hỏi.

macOS / Linux / WSL (không dùng protocol Windows):

```bash
chmod +x scripts/add_hostname.sh
./scripts/add_hostname.sh --watch
```

Script chỉ sửa block `# multi-php-docker-serve:managed:*` trong file hosts.

**One-shot (không cần Manager):**

```bash
./scripts/add_hostname.sh
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\add_hostname.ps1
```

Script đọc `DOMAIN_NAME` trong `env.json` (và `runtime/hosts.extra.json` nếu có) rồi ánh xạ tới `127.0.0.1`. Nếu không dùng script, thêm thủ công vào:

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

Lệnh trên khởi động PHP 8.2 cùng Nginx, MySQL, Redis, RabbitMQ, Supervisor, Server Manager và PHP Controller. Các PHP version cũ không được khởi động.

### Bật phiên bản PHP tùy chọn

Mỗi phiên bản cũ có một Compose profile cùng tên:

```bash
# Bật thêm PHP 8.1
docker compose --profile php-8.1 up -d

# Bật thêm PHP 8.0
docker compose --profile php-8.0 up -d

# Bật thêm PHP 7.4
docker compose --profile php-7.4 up -d
```

Có thể bật nhiều phiên bản cùng lúc:

```bash
docker compose \
  --profile php-8.0 \
  --profile php-8.1 \
  up -d
```

Tắt một phiên bản tùy chọn:

```bash
docker compose stop php-8.1
docker compose rm -f php-8.1
```

Khi một project trong `env.json` dùng `php8.0_container`, `php8.1_container` hoặc `php7.4_container`, hãy bật profile tương ứng trước khi khởi động/tạo lại Nginx. Nếu không, Nginx không thể kết nối tới upstream PHP đó.

## Quản lý server bằng giao diện web

Sau khi chạy `docker compose up -d`, mở:

[http://127.0.0.1:8080](http://127.0.0.1:8080)

Server Manager cho phép:

- Xem các virtual server hiện có trong `env.json`.
- Thêm, sửa và xóa server.
- Chọn PHP 7.4, 8.0, 8.1 hoặc 8.2.
- Kiểm tra trùng application name và domain.
- Giới hạn document root trong thư mục source của PHP version đã chọn.
- Hiển thị profile cần bật và lệnh áp dụng cấu hình.
- Gửi yêu cầu tạo lại virtual host và reload Nginx bằng nút **Apply & Reload Nginx**.
- Hỗ trợ giao diện Tiếng Việt và English; lần truy cập đầu tiên tự nhận ngôn ngữ trình duyệt, sau đó ghi nhớ lựa chọn trong session.
- Hỗ trợ giao diện **Hệ thống**, **Sáng** và **Tối**; lựa chọn được lưu trong trình duyệt và chế độ Hệ thống tự đi theo cài đặt của hệ điều hành.
- Quản lý trực tiếp trạng thái các PHP container bằng các nút **Tạo**, **Khởi động**, **Dừng** và **Khởi động lại**.

Card **Các phiên bản PHP** hiển thị trạng thái `Đang chạy`, `Đã dừng`, `Chưa được tạo` hoặc `Đang xử lý`. PHP 8.2 được tạo mặc định. Với PHP tùy chọn chưa từng được tạo, bấm **Tạo** trong UI (tương đương `docker compose --profile … create …`), rồi dùng **Khởi động**. Vẫn có thể tạo thủ công:

```bash
docker compose --profile php-8.1 create php-8.1
```

Sau đó làm mới Server Manager để dùng các nút điều khiển. Controller chấp nhận PHP 8.2, 8.1, 8.0, 7.4 và các thao tác Create (chỉ bản có profile), Start, Stop, Restart; nó không xóa container. `ensure_hosts_env` cũng ghi `HOST_PROJECT_PATH` vào `.env` để `php-controller` chạy compose create với bind mount đúng đường dẫn host — sau khi đổi máy, chạy lại script rồi `docker compose up -d php-controller --force-recreate`.

Service `php-controller` không public cổng và là container duy nhất được mount `/var/run/docker.sock`. Docker socket có quyền tương đương root trên Docker host, vì vậy chỉ chạy stack từ source tin cậy và không mở Server Manager ra mạng công cộng.

Nếu `env.json` chưa tồn tại, service `env-init` sẽ tự tạo nó từ `env.example.json` trước khi Server Manager và Nginx khởi động. Service này chỉ chạy trong thời gian ngắn và không ghi đè cấu hình hiện có.

UI chỉ được bind vào `127.0.0.1`, không mở trực tiếp ra mạng LAN. Docker socket không được mount vào Server Manager. Yêu cầu điều khiển PHP được gửi qua thư mục runtime riêng tới `php-controller`. Khi nhấn **Apply & Reload Nginx**, UI tạo một file tín hiệu trong `runtime/`; tiến trình theo dõi bên trong container Nginx sẽ sinh lại template, chạy `nginx -t` và chỉ reload khi cấu hình hợp lệ. Nếu kiểm tra thất bại, cấu hình trước đó được khôi phục.

Với PHP 8.2 mặc định, sau khi thêm, sửa hoặc xóa server, nhấn **Apply & Reload Nginx** để áp dụng mà không cần restart container.

Nút reload không tự khởi động PHP profile. Nếu server dùng PHP 8.1, 8.0 hoặc 7.4, hãy chạy lệnh profile mà UI hiển thị trước. Ví dụ với PHP 8.1:

```bash
docker compose --profile php-8.1 up -d
```

Sau đó nhấn **Apply & Reload Nginx**. Kết quả gần nhất được hiển thị ngay dưới nút sau khi tải lại trang. Có thể xem chi tiết lỗi trong `runtime/nginx.reload.log`; `runtime/` là dữ liệu tạm và đã được bỏ qua khỏi Git.

Domain mới vẫn cần được thêm vào file `hosts`:

```bash
./scripts/add_hostname.sh
```

Nếu không cần UI, có thể dừng riêng service này:

```bash
docker compose stop manager
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

Tên service hợp lệ: `env-init`, `nginx`, `php-8.0`, `php-8.1`, `php-8.2`, `php-7.4`, `supervisor`, `supervisor-8.1`, `supervisor-8.0`, `supervisor-7.4`, `manager`, `php-controller`, `mysql`, `redis`, `rabbitmq`.

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

Mỗi container Supervisor chỉ có một PHP runtime. PHP (+ Supervisor) nằm trong `compose/php-X.Y.yml`. Redis và RabbitMQ dùng chung, khai báo ở `compose/redis.yml` và `compose/rabbitmq.yml`. Root `docker-compose.yml` `include` các file đó.

| PHP-FPM service | Supervisor service | File | Image dùng chung |
| --- | --- | --- | --- |
| `php-8.2` | `supervisor` | `compose/php-8.2.yml` | `long301001/multi-php-docker:php-8.2` |
| `php-8.1` | `supervisor-8.1` | `compose/php-8.1.yml` | `long301001/multi-php-docker:php-8.1` |
| `php-8.0` | `supervisor-8.0` | `compose/php-8.0.yml` | `long301001/multi-php-docker:php-8.0` |
| `php-7.4` | `supervisor-7.4` | `compose/php-7.4.yml` | Image PHP 7.4 (cần có package Supervisor) |

Không khai báo `build` trong service Supervisor. Với image tùy chỉnh, chỉ service PHP-FPM tương ứng khai báo `build`; service Supervisor dùng lại cùng tên image.

Worker theo phiên bản: đặt file `.conf` trong `configs/supervisor.d/` (PHP 8.2 mặc định) hoặc `configs/supervisor.d/php8.1`, `php8.0`, `php7.4`.

#### Supervisor cho PHP 8.1 / 8.0 (đã có sẵn trong compose)

```bash
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.1/worker.conf
# Sửa directory/command trong worker.conf

docker compose --profile php-8.1 up -d php-8.1 supervisor-8.1
docker compose exec supervisor-8.1 supervisorctl status
```

Tương tự với profile `php-8.0` / service `supervisor-8.0`.

#### Supervisor cho PHP 7.4

Image PHP 7.4 được cung cấp sẵn hiện chưa có Supervisor. Để chạy `supervisor-7.4`, hãy tạo image tùy chỉnh: thêm `supervisor` vào danh sách package trong `docker_files/php7.Dockerfile`, đổi image của `php-7.4` (và `supervisor-7.4` trong `compose/php-7.4.yml`) sang tên riêng và thêm `build` như hướng dẫn ở mục **Tự build image riêng**.

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

```bash
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php7.4/worker.conf

docker compose build php-7.4
docker compose --profile php-7.4 up -d php-7.4 supervisor-7.4
docker compose exec supervisor-7.4 supervisorctl status
```

#### Thêm Supervisor cho PHP 8.3 hoặc phiên bản mới

Tạo `compose/php-8.3.yml` (PHP-FPM + Supervisor dùng chung image), rồi thêm `include` tương ứng trong `docker-compose.yml` với `project_directory: .`. Ví dụ service Supervisor:

```yaml
  supervisor-8.3:
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

```bash
mkdir -p configs/supervisor.d/php8.3
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.3/worker.conf

docker compose build php-8.3
docker compose up -d php-8.3 supervisor-8.3
docker compose exec supervisor-8.3 supervisorctl status
```

Trong mỗi `worker.conf`, cập nhật `directory` theo đúng source của phiên bản PHP. Tách thư mục cấu hình và thư mục log theo phiên bản giúp tránh nạp nhầm worker.

### Chạy lệnh trong container

```bash
docker compose exec php-8.2 sh
docker compose exec php-8.0 php -v
docker compose exec php-8.1 php -v
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

Thêm service mới cùng cấp với `php-8.0`, `php-8.1`, `php-8.2` và `php-7.4`:

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
- Kiểm tra source nằm đúng thư mục PHP 7.4, 8.0, 8.1 hoặc 8.2.

### Cổng đã được sử dụng

Tắt ứng dụng đang chiếm cổng hoặc đổi cổng host trong `docker-compose.yml`. Ví dụ, đổi `"3306:3306"` thành `"3307:3306"` để MySQL dùng cổng `3307` trên host.

### Không kết nối được MySQL, Redis hoặc RabbitMQ từ PHP

Trong container, dùng hostname `mysql`, `redis`, `rabbitmq`, không dùng `localhost`. Kiểm tra trạng thái bằng `docker compose ps`.

### Build image thất bại

- Xem lỗi đầy đủ bằng `docker compose build --no-cache <service-name>`.
- Kiểm tra kết nối mạng và Docker daemon.
- Kiểm tra đúng tên Dockerfile, đặc biệt RabbitMQ dùng `docker_files/rabbitMQ.Dockerfile`.

## Build multi-architecture image với Docker Buildx

Phần này dành cho trường hợp cần publish image hỗ trợ đồng thời:

- `linux/amd64`: máy Intel/AMD, phần lớn máy Windows/Linux.
- `linux/arm64`: Apple Silicon và máy ARM64.

Nếu chỉ chạy local, không cần cấu hình multi-architecture. Hãy dùng `docker compose build` để Docker tự build theo kiến trúc native của máy.

### 1. Khai báo platform trong `docker-compose.yml`

Thêm `platforms` bên trong `build` của từng service cần publish. Đồng thời, `image` phải là tên đầy đủ trên Docker Hub hoặc registry mà bạn có quyền push:

```yaml
services:
  nginx:
    image: <dockerhub-username>/server-nginx:v1.0.0
    build:
      context: .
      dockerfile: ./docker_files/nginx.Dockerfile
      platforms:
        - linux/amd64
        - linux/arm64

  php-8.2:
    image: <dockerhub-username>/server-php:8.2-v1.0.0
    build:
      context: .
      dockerfile: ./docker_files/php8.Dockerfile
      platforms:
        - linux/amd64
        - linux/arm64
```

Áp dụng cùng cấu trúc cho `php-8.0`, `php-8.1`, `php-7.4`, `mysql`, `redis` và `rabbitmq`. Service `supervisor` không có `build`; nó tiếp tục dùng cùng image với `php-8.2`.

> Không dùng các tag local như `server-php:8.2-local` khi push. Hãy đổi sang `<registry>/<namespace>/<image>:<tag>`, ví dụ `docker.io/my-user/server-php:8.2-v1.0.0`.

### 2. Tạo Buildx builder

Chỉ cần thực hiện một lần trên mỗi máy:

```bash
docker buildx create \
  --name multiarch-builder \
  --driver docker-container \
  --use

docker buildx inspect --bootstrap
```

Kiểm tra builder đang được chọn và các platform được hỗ trợ:

```bash
docker buildx ls
```

### 3. Đăng nhập registry

Với Docker Hub:

```bash
docker login
```

Với registry khác:

```bash
docker login <registry-domain>
```

### 4. Build và push toàn bộ image

Docker Compose v2 sử dụng BuildKit/Buildx cho quá trình build. Khi đã chọn builder ở bước trên, chạy:

```bash
docker compose build --push
```

Build và push riêng một service:

```bash
docker compose build --push php-8.2
```

Các biến sau chỉ cần thiết khi dùng Docker Compose cũ; với Docker Compose v2 hiện đại thường không cần thiết lập thủ công:

```bash
export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1
```

### 5. Kiểm tra image đã publish

```bash
docker buildx imagetools inspect \
  <dockerhub-username>/server-php:8.2-v1.0.0
```

Hoặc:

```bash
docker manifest inspect \
  <dockerhub-username>/server-php:8.2-v1.0.0
```

Kết quả cần chứa manifest cho cả `linux/amd64` và `linux/arm64`.

### Lưu ý khi build đa kiến trúc

- Dùng `--push` để đẩy kết quả đa kiến trúc thẳng lên registry. Docker image store kiểu cũ không thể nạp nhiều platform dưới cùng một tag local.
- Cross-build có thể dùng QEMU và chậm hơn native build, đặc biệt ở các bước compile PHP extension.
- Base image và package được cài trong Dockerfile phải tồn tại trên cả hai kiến trúc.
- Không chạy `docker compose up` trực tiếp với cấu hình publish nếu các tag image trên registry chưa tồn tại.
- Không dùng cùng một tag cho các nội dung release khác nhau; nên dùng tag phiên bản rõ ràng như `v1.0.0`.

Tham khảo tài liệu chính thức: [Docker Compose Build Specification](https://docs.docker.com/reference/compose-file/build/) và [Docker multi-platform builds](https://docs.docker.com/build/building/multi-platform/).

## Cấu trúc repository

```text
.
├── compose/                 # Compose fragments (PHP+Supervisor, redis, rabbitmq)
│   ├── php-7.4.yml
│   ├── php-8.0.yml
│   ├── php-8.1.yml
│   ├── php-8.2.yml
│   ├── rabbitmq.yml
│   └── redis.yml
├── configs/                 # Cấu hình PHP và Supervisor
│   └── supervisor.d/        # Worker mặc định (8.2); php8.1/, php8.0/, php7.4/ theo version
├── docker_files/            # Dockerfile để build các dịch vụ
├── mysql/                   # Cấu hình MySQL
├── nginx/
│   ├── examples/            # Virtual host mẫu
│   ├── logs/                # Log Nginx
│   └── templates/           # Cấu hình được sinh từ env.json
├── scripts/                 # Script khởi động và tạo cấu hình
├── server/
│   ├── manager/             # UI quản lý env.json
│   ├── source_php7.4/       # Source chạy PHP 7.4
│   ├── source_php8.0/       # Source chạy PHP 8.0
│   ├── source_php8.1/       # Source chạy PHP 8.1
│   └── source_php8.2/       # Source chạy PHP 8.2
├── docker-compose.yml       # Root: include + nginx/mysql/manager/php-controller/env-init
├── env.example.json         # Cấu hình project/domain mẫu được commit
└── env.json                 # Cấu hình local, được Git bỏ qua
```
