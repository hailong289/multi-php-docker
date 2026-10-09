[English](README.md) | [Tiếng Việt](README.vi.md)

# Môi trường phát triển PHP với Docker

Môi trường local gồm Nginx, PHP 7.4 và 8.0–8.5, cùng các dịch vụ tùy chọn MySQL, PostgreSQL, Redis, RabbitMQ, Kafka, Mailpit và MinIO. Image có sẵn trên Docker Hub (`long301001/multi-php-docker`), kể cả Server Manager. Chạy `docker compose up -d` chỉ khởi động Nginx, Server Manager và PHP Controller. Mọi phiên bản PHP và dịch vụ dữ liệu đều tắt cho đến khi bạn bật profile hoặc dùng giao diện Manager.

## Video hướng dẫn

| Clone source | Tạo các dịch vụ |
| --- | --- |
| [![Clone source](https://img.youtube.com/vi/rUI_mtbsIIU/hqdefault.jpg)](https://youtu.be/rUI_mtbsIIU) | [![Tạo các dịch vụ](https://img.youtube.com/vi/2fw1NnIO-uo/hqdefault.jpg)](https://youtu.be/2fw1NnIO-uo) |
| [Xem trên YouTube](https://youtu.be/rUI_mtbsIIU) | [Xem trên YouTube](https://youtu.be/2fw1NnIO-uo) |

## Chạy lần đầu

Cần Docker Desktop hoặc Docker Engine, và Docker Compose v2 (lệnh `docker compose`).

```bash
docker --version
docker compose version
```

### 1. Clone và khởi động

```bash
git clone <repository-url>
cd <repository-folder>
docker compose pull
docker compose up -d
```

Lệnh này khởi động Nginx, Server Manager và PHP Controller. Không cần file `.env`. Lần chạy đầu, một helper copy [`env.example.json`](env.example.json) thành `env.json` nếu file đó chưa có, và không ghi đè file đã tồn tại. `env.json` nằm trên máy của bạn.

Phiên bản PHP và dịch vụ dữ liệu mặc định tắt. Khởi động từ **Các phiên bản PHP** → **Tạo** → **Khởi động** trong Manager, hoặc dùng `docker compose --profile <tên> up -d`.

Các lần sau: `docker compose up -d`, rồi `docker compose ps`.

### 2. Đặt project vào `server/source`

Mọi phiên bản PHP mount chung một thư mục trên host. Trong container PHP và Supervisor, đường dẫn đó là `/var/www/source`.

```text
server/source/<tên-project>
```

Đổi phiên bản PHP trong Server Manager không chuyển file. Checkout cũ còn `server/source_php*` thì chạy `./scripts/migrate-source-path.sh` một lần trước khi tạo lại container.

### 3. Mở Server Manager

Giao diện đã nằm trong `long301001/multi-php-docker:manager`. Không cần cài Node.js trên máy.

Mở [http://127.0.0.1:8080/](http://127.0.0.1:8080/) để xem trang giới thiệu (VI/EN). Manager ở [http://127.0.0.1:8080/server-manage](http://127.0.0.1:8080/server-manage). Khi `env.json` chưa có site, Nginx cổng 80 cũng hiển thị trang chào mừng thay vì trả về 404.

1. **Thêm server** — tên ứng dụng, domain (ví dụ `my-php85-app.test`), phiên bản PHP và document root. Với Laravel hoặc app có thư mục public riêng, trỏ document root tới `public`, `webroot` hoặc thư mục chứa `index.php`. Manager ghi `env.json`.
2. **Khởi động PHP** — mở **Các phiên bản PHP** → **Tạo** → **Khởi động** cho phiên bản cần dùng. Mọi phiên bản PHP kể cả 8.5 đều phải khởi động theo cách này.
3. **Ghi hosts** một lần trên mỗi máy. Bước này cần `jq` và quyền quản trị; khởi động Docker thì không. Sau đó trong Manager dùng **Thêm domain** / **Ghi hosts (Admin)**.

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1
```

macOS:

```bash
chmod +x scripts/hosts/ensure_hosts_env.sh scripts/hosts/add_hostname.sh scripts/hosts/hosts_protocol_macos.sh
./scripts/hosts/ensure_hosts_env.sh
```

Linux / WSL (chạy watcher, không dùng protocol trình duyệt):

```bash
chmod +x scripts/hosts/add_hostname.sh
./scripts/hosts/add_hostname.sh --watch
```

Trình duyệt mở `multi-php-hosts:write` rồi ghi file hosts (UAC trên Windows, hộp thoại admin trên macOS). Cho phép mở ứng dụng nếu trình duyệt hỏi.

4. Nhấn **Apply & Reload Nginx**.
5. Mở domain, ví dụ `http://my-php85-app.test`.

Nếu reload lỗi, xem `runtime/nginx.reload.log`.

Cùng giao diện này có thể mở shell trong container PHP của site (container phải đang chạy), đổi ngôn ngữ, và chọn giao diện sáng, tối hoặc theo hệ thống.

## Dịch vụ và cổng

| Dịch vụ | Cổng host | Mặc định |
| --- | --- | --- |
| Nginx | `80`, `443` | Domain trong `env.json` |
| PHP 7.4, 8.0–8.5 | Không public | Tắt cho đến khi bạn bật profile tương ứng. PHP-FPM cổng `9000` trong Docker network |
| Server Manager | `127.0.0.1:8080` | [http://127.0.0.1:8080/server-manage](http://127.0.0.1:8080/server-manage) |
| MySQL | `3306` | User `root`, password `1` |
| PostgreSQL | `5432` | User `postgres`, password `1`, database `postgres` |
| Redis | `6379` | Không mật khẩu |
| RabbitMQ | `5672`, `15672` | `admin` / `admin`. UI [http://localhost:15672](http://localhost:15672) |
| Kafka | `9092` | Broker `kafka_container:29092` (Docker) / `localhost:9092` (host) |
| Mailpit | `1025`, `8025` | SMTP `mailpit_container:1025`. UI [http://localhost:8025](http://localhost:8025) |
| MinIO | `9000`, `9001` | `minioadmin` / `minioadmin`. API `minio_container:9000`. Console [http://localhost:9001](http://localhost:9001) |
| Supervisor | Không public | Worker nền, một container mỗi phiên bản PHP, mặc định tắt |

## Việc hàng ngày

### Dịch vụ tùy chọn

Bật profile khi project cần. Nginx chỉ nối được container PHP đang chạy.

```bash
docker compose --profile <tên> up -d <tên>
```

Tên profile: `php-8.5`, `php-8.4`, `php-8.3`, `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4`, `mysql`, `postgres`, `redis`, `rabbitmq`, `kafka`, `mailpit`, `minio`, `supervisor-8.5`, `supervisor-8.4`, `supervisor-8.3`, `supervisor-8.2`, `supervisor-8.1`, `supervisor-8.0`, `supervisor-7.4`.

Server Manager có thể Tạo, Khởi động, Dừng và Khởi động lại các dịch vụ này. **Thêm phiên bản** cài một tag từ catalog Hub (ví dụ alpine) và build image local. Trên Windows, xem [Xử lý lỗi](#windows-cài--tạo-phiên-bản-php-thất-bại) nếu build không tới được Docker Hub.

```bash
docker compose stop php-8.3
```

`php-controller` lấy đường dẫn repository từ mount `/project`. Giá trị `HOST_PROJECT_PATH` trong `.env` vẫn dùng được như một override.

### Container đã ghim và khởi động/tắt lần lượt

Ghim bất kỳ Nginx, PHP hoặc dịch vụ nào lên trang chủ bằng nút **Ghim** trong màn hình chi tiết. Card **Đã ghim** xuất hiện trên trang chủ với nút Start / Stop / Restart nhanh.

Header card có ba nút: **Cài đặt**, **Khởi động lần lượt** và **Tắt lần lượt**.

- **Cài đặt** mở dialog với hai danh sách riêng: một cho thứ tự khởi động, một cho thứ tự tắt. Cột **Có thể thêm** chỉ liệt kê service đang ghim. Thêm, bỏ và sắp xếp từng danh sách độc lập, sau đó **Lưu**.
- **Khởi động lần lượt** chạy danh sách khởi động từ trên xuống, bỏ qua service đã chạy.
- **Tắt lần lượt** chạy danh sách tắt từ trên xuống (thứ tự riêng, không phụ thuộc danh sách khởi động), bỏ qua service đã tắt.

Gặp lỗi thì dừng các mục còn lại trong chuỗi.

### Kết nối từ ứng dụng

Trong container, dùng tên container: tên service cộng thêm `_container`. Trên host, dùng `127.0.0.1` và cổng host trong bảng phía trên.

```dotenv
DB_HOST=mysql_container
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=1

# PostgreSQL — dùng khối này khi app dùng Postgres
# DB_CONNECTION=pgsql
# DB_HOST=postgres_container
# DB_PORT=5432
# DB_DATABASE=postgres
# DB_USERNAME=postgres
# DB_PASSWORD=1

REDIS_HOST=redis_container
REDIS_PORT=6379

RABBITMQ_HOST=rabbitmq_container
RABBITMQ_PORT=5672
RABBITMQ_USER=admin
RABBITMQ_PASSWORD=admin

# Trong container PHP dùng kafka_container:29092; trên host dùng localhost:9092
KAFKA_BROKERS=kafka_container:29092

MAIL_MAILER=smtp
MAIL_HOST=mailpit_container
MAIL_PORT=1025

AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=local
AWS_ENDPOINT=http://minio_container:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Log, shell và vòng đời

```bash
docker compose ps
docker compose logs -f nginx
docker compose exec php-8.5 sh
docker compose exec mysql mysql -uroot -p1
docker compose exec -e PGPASSWORD=1 postgres psql -U postgres

docker compose stop
docker compose start
docker compose down
docker compose restart nginx

docker compose pull
docker compose up -d
```

`docker compose down` xóa container và network, giữ named volume. Đổi `php-8.5` sang service PHP khác đang chạy khi cần shell ở đó.

## Việc thỉnh thoảng

### HTTPS

Bật HTTPS từng site trong Server Manager. Để trống file chứng chỉ để sinh cert tự ký, hoặc upload `.crt`/`.pem` và `.key`. Cổng 80 và 443 vẫn phục vụ song song. Trình duyệt cảnh báo với cert tự ký. Sau đó nhấn **Apply & Reload Nginx**. File nằm trong `nginx/ssl/<app-name>/` và không được commit. Sau khi kéo tính năng này về, chạy `docker compose up -d nginx` một lần để mount `./nginx/ssl`.

### Extension PHP

Mở **Chi tiết** của một phiên bản PHP để bật/tắt dòng `extension=` trong `configs/php*/php.ini` đã mount, cài một tập extension có sẵn vào container đang chạy, và sửa `php.ini`. Sau khi lưu `php.ini` có thể khởi động lại PHP-FPM. Extension cài lúc chạy sẽ mất khi container được tạo lại; muốn giữ lâu dài thì đưa vào image tùy chỉnh ở mục [Nâng cao](#tự-build-image).

### Worker Supervisor

Supervisor dùng cùng image, `server/source` và `php.ini` với service PHP-FPM tương ứng, trong container riêng.

```bash
cp configs/supervisor.d/worker.conf.example configs/supervisor.d/php8.5/worker.conf
```

```ini
[program:app_worker]
directory=/var/www/source/my-project
command=php artisan queue:work --sleep=3 --tries=3 --timeout=90
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/app-worker.log
```

File có đuôi `.example` được bỏ qua. Thêm file `.conf` khác trong cùng thư mục cho các project còn lại.

```bash
docker compose --profile supervisor-8.5 up -d supervisor-8.5
docker compose exec supervisor-8.5 supervisorctl status
docker compose exec supervisor-8.5 supervisorctl reread
docker compose exec supervisor-8.5 supervisorctl update
```

Với phiên bản PHP khác, đổi `8.5` và `php8.5` trong đường dẫn và profile, đồng thời khởi động phiên bản PHP đó. Từ UI: **Các phiên bản PHP** → **Supervisor**. Log nằm trong `logs/supervisor*`.

Image PHP 7.4 được phát hành chưa có Supervisor. Cài package `supervisor` trong [image tùy chỉnh](#tự-build-image), rồi dùng profile `supervisor-7.4`.

Worker nên tự thử lại khi nối MySQL, PostgreSQL, Redis, RabbitMQ, Kafka, Mailpit và MinIO. Thứ tự khởi động chỉ có hiệu lực khi các profile đó đang bật.

### Thêm hoặc đổi project

1. Đặt source vào `server/source/<tên-project>`.
2. Trong Server Manager, thêm hoặc sửa server và ghi hosts khi domain là mới.
3. Nhấn **Apply & Reload Nginx**.

## Xử lý lỗi

### Domain không vào được

- Domain có trong file hosts và trỏ tới `127.0.0.1`.
- `docker compose ps` cho thấy Nginx và container PHP của site đang chạy.
- Áp lại server bằng **Apply & Reload Nginx**.

### 404 hoặc `File not found`

Document root là thư mục chứa `index.php`, theo đường dẫn bên trong container (`/var/www/source/...`). File project nằm trong `server/source/<tên-project>` với mọi phiên bản PHP.

### Cổng đã được dùng

Tắt tiến trình đang chiếm cổng, hoặc đổi phía host của ánh xạ cổng. Trong `compose/mysql.yml`, `"3306:3306"` có thể thành `"3307:3306"`. Trong `compose/postgres.yml`, `"5432:5432"` có thể thành `"5433:5432"`.

### PHP không tới được MySQL, PostgreSQL, Redis, RabbitMQ, Kafka, Mailpit hoặc MinIO

Dùng tên container (`mysql_container`, `postgres_container`, `redis_container`, `rabbitmq_container`, `kafka_container`, `mailpit_container`, `minio_container`). Bật profile tương ứng khi `docker compose ps` không liệt kê dịch vụ đó. Từ PHP, broker Kafka là `kafka_container:29092`.

### Windows: Cài / Tạo phiên bản PHP thất bại

Các bản đi kèm (`php-7.4` đến `php-8.5`) dùng image Hub: **Tạo**, rồi **Khởi động**. Cài từ catalog (alpine, trixie hoặc một tag cụ thể khác) sẽ build image local và kéo image gốc `php:…-fpm`. Docker Desktop trên Windows có thể fail bước kéo đó dù máy vẫn có internet. Dòng thường gặp trong `php-controller-runtime/status/`:

- `lookup auth.docker.io … network is unreachable`
- `failed to authorize: failed to fetch anonymous token`
- `failed to resolve source metadata for docker.io/library/php:…`

1. Trên host, chạy `docker pull hello-world`, hoặc pull đúng tag gốc ghi trong lỗi.
2. Trong Manager, bấm **Tạo** hoặc **Cài đặt** lại. Lần build đầu có thể mất vài phút.
3. Đọc `php-controller-runtime\status\last-create-error.log`.
4. Khởi động lại Docker Desktop, hoặc đặt DNS `8.8.8.8` / `1.1.1.1` tại Settings → Resources → Network.
5. Dùng bản Hub đi kèm khi bạn không cần đúng tag đó.

Chạy `scripts\hosts\ensure_hosts_env.ps1` một lần cũng ghi `HOST_PROJECT_PATH` dạng slash xuôi (`D:/…`) cho bước Tạo và Cài đặt.

### Windows: `env.json` thành thư mục

Xóa thư mục đó, rồi chạy `docker compose up -d` để helper tạo lại file. Có thể copy `env.example.json` thành `env.json`.

### Build image thất bại

Chạy `docker compose build --no-cache <tên-service>`. Kiểm tra mạng, Docker daemon và đường dẫn Dockerfile. RabbitMQ dùng `docker_files/rabbitMQ.Dockerfile`.

## Nâng cao

### `env.json`

Server Manager là cách thường dùng để sửa site. Mỗi project là một object `SERVER_NAME<N>`:

```json
{
  "SERVER_NAME1": {
    "APP_NAME": "my-php85-app",
    "DOMAIN_NAME": "my-php85-app.test",
    "SERVER_PATH": "/var/www/source/my-php85-app/public",
    "CONTAINER_PHP_VERSION": "php8.5_container"
  }
}
```

| Trường | Ý nghĩa |
| --- | --- |
| `APP_NAME` | Tên project và tên file cấu hình Nginx được sinh ra |
| `DOMAIN_NAME` | Domain trên máy này |
| `SERVER_PATH` | Document root tuyệt đối bên trong container |
| `CONTAINER_PHP_VERSION` | `php8.5_container` đến `php8.0_container`, hoặc `php7.4_container` |
| `ENABLED` | `false` thì bỏ qua site khi sinh cấu hình Nginx |
| `SSL_ENABLED` | `true` thì cũng listen 443 khi có `nginx/ssl/<APP_NAME>/{cert,key}.pem` |
| `SSL_MODE` | `generated` hoặc `uploaded`; chỉ lưu khi SSL đang bật |

Không dùng UI: sửa `env.json`, chạy `./scripts/hosts/add_hostname.sh` khi có domain mới, rồi tạo lại Nginx:

```bash
docker compose up -d --force-recreate nginx
docker compose exec nginx nginx -t
```

Khi khởi động, `scripts/nginx/auto-add-template.sh` đọc `env.json` và sinh virtual host từ `nginx/examples/server_example.txt`. **Apply & Reload Nginx** thất bại sẽ khôi phục cấu hình trước đó; chi tiết nằm trong `runtime/nginx.reload.log`.

### CLI hosts

Stack khởi động mà không mount file hosts của hệ điều hành. Trước khi helper chạy, Manager hiển thị domain là **Chưa rõ**. Script chỉ sửa block `# multi-php-docker-serve:managed:*` và ánh xạ mọi `DOMAIN_NAME` trong `env.json` (và `runtime/hosts.extra.json` nếu file đó có) tới `127.0.0.1`.

```bash
./scripts/hosts/add_hostname.sh
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\add_hostname.ps1
```

Gỡ protocol trình duyệt trên Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1 -UnregisterProtocol
```

Gỡ trên macOS: `./scripts/hosts/ensure_hosts_env.sh --unregister-protocol`

Muốn tự sửa hosts, thêm dòng `127.0.0.1 <domain>` vào `/etc/hosts` (macOS và Linux) hoặc `C:\Windows\System32\drivers\etc\hosts` (Windows).

### Tự build image

Đặt tên image của bạn và thêm `build` trên service PHP. Giữ tên `long301001/multi-php-docker:*` cho image Hub chưa sửa. Supervisor dùng lại image đó và không có `build` riêng.

```yaml
services:
  php-8.5:
    image: my-project/php:8.5-local
    build:
      context: .
      dockerfile: ./docker_files/php8.5.Dockerfile
  supervisor-8.5:
    image: my-project/php:8.5-local
```

```bash
docker compose build php-8.5
docker compose --profile supervisor-8.5 up -d supervisor-8.5
```

Sau khi sửa source PHP hoặc Vue của Manager, build lại (Node chạy trong lúc build image):

```bash
docker compose build manager
docker compose up -d manager
```

Đẩy bản amd64 và arm64 cùng cách với các tag Hub khác: `docker compose build --push manager`.

`php-controller` mount Docker socket và chạy một allowlist các thao tác Compose. Socket đó là quyền mức root trên Docker host, vì vậy chỉ chạy stack này từ source bạn tin. Manager chỉ lắng nghe `127.0.0.1:8080`. Dừng UI bằng `docker compose stop manager`.

### Thêm một phiên bản PHP khác

PHP 7.4 và 8.0–8.5 đã có sẵn. Với bản mới hơn, ví dụ 8.6, copy `docker_files/php8.5.Dockerfile`, `configs/php8.5/php.ini` và `compose/php-8.5.yml`.

1. Copy Dockerfile và đặt base image, ví dụ `FROM php:8.6-fpm`. Các image 8.x hiện thường có `pdo_mysql`, `mysqli`, `gd`, `zip`, `sockets`, `pcntl` và Redis. Cài `pdo_pgsql` / `pgsql` từ Server Manager khi project dùng PostgreSQL.
2. Tạo `configs/php8.6`, `configs/supervisor.d/php8.6` và `logs/supervisor-8.6`. Copy `php.ini` và một file worker `.conf`.
3. Thêm `compose/php-8.6.yml` với profile `php-8.6` và `supervisor-8.6`, rồi `include` từ `docker-compose.yml` với `project_directory: .`. Để cổng `9000` trong Docker network. Với image Hub, đặt `image` và bỏ `build`. Với build local, khai báo `build` chỉ trên service PHP.
4. Đặt `CONTAINER_PHP_VERSION` trùng `container_name` mới, và thêm service vào allowlist của `php-controller` theo cùng pattern các service `php-8.x` hiện có.
5. Khởi động bằng `docker compose --profile php-8.6 up -d php-8.6` (pull hoặc `docker compose build php-8.6` trước), rồi `docker compose up -d --force-recreate nginx`.

### Sao lưu và khôi phục MySQL

Volume MySQL là `mysql-data`. PostgreSQL dùng `postgres-data` theo cùng cách: đổi tên service và tên file nén.

```bash
docker compose stop mysql
docker run --rm \
  -v mysql-data:/data:ro \
  -v "$(pwd):/backup" \
  alpine \
  tar czf /backup/mysql-data.tar.gz -C /data .
docker compose start mysql
```

Khôi phục sẽ thay volume hiện tại. Hãy sao lưu trước.

```bash
docker compose stop mysql
docker run --rm \
  -v mysql-data:/data \
  -v "$(pwd):/backup:ro" \
  alpine \
  tar xzf /backup/mysql-data.tar.gz -C /data
docker compose start mysql
```

### Cấu trúc repository

```text
.
├── compose/            # PHP, Supervisor và dịch vụ tùy chọn
├── configs/            # php.ini và supervisor.d/<version>/
├── docker_files/       # Dockerfile cho image tùy chỉnh
├── nginx/              # template vhost, ssl/, logs/, cấu hình đã sinh
├── scripts/            # nginx, hosts, php-controller
├── server/manager/     # Source Manager; UI nằm trong image
├── server/source/      # Project của bạn
├── docker-compose.yml
├── env.example.json
└── env.json            # local, không commit
```

### Đóng góp

Mở lỗi trên [Issues](https://github.com/hailong289/multi-php-docker/issues) với các bước tái hiện, kết quả mong đợi và kết quả thực tế, hệ điều hành, `docker --version`, `docker compose version`, và log liên quan (`docker compose logs`, UI Manager, hoặc file trong `runtime/` và `php-controller-runtime/status/`). Không đưa mật khẩu, token hay đường dẫn project riêng tư.

Checkout `develop` mới nhất, tạo nhánh từ đó, rồi làm việc trên nhánh mới:

```bash
git checkout develop
git pull origin develop
git checkout -b fix/mo-ta-ngan
```

Đặt tên nhánh bằng một trong các tiền tố sau, rồi thêm mô tả ngắn:

| Tiền tố | Dùng khi |
| --- | --- |
| `fix/` | Sửa lỗi. Ví dụ: `fix/nginx-reload-timeout` |
| `feat/` | Thêm tính năng. Ví dụ: `feat/add-php-8.6` |
| `docs/` | Chỉ sửa tài liệu. Ví dụ: `docs/readme-hosts` |
| `chore/` | Sửa build, CI hoặc tooling, không đổi hành vi sản phẩm. Ví dụ: `chore/update-ci` |

Push nhánh và mở pull request vào `develop`. Gắn issue, ví dụ `Fixes #123`.

## Mời một ly cà phê

Lấy về dùng thoải mái nhé. Nếu nó giúp được bạn, mời mình một ly cà phê nha.

- [Buy Me a Coffee](https://www.buymeacoffee.com/hailong289)
- [PayPal](https://paypal.me/LongHai2)

## Tác giả

Dự án được duy trì bởi **Hải Long**.

| | |
| --- | --- |
| Email | [longdh2.dev@gmail.com](mailto:longdh2.dev@gmail.com) |
| LinkedIn | [Hải Long](https://www.linkedin.com/in/h%E1%BA%A3i-long-729355219/) |
