[English](README.md) | [Tiếng Việt](README.vi.md)

# Môi trường phát triển PHP với Docker

Repository cung cấp môi trường phát triển cục bộ gồm Nginx, PHP 7.4, PHP 8.0–8.5, MySQL, PostgreSQL, Redis và RabbitMQ. Tất cả PHP 7.4–8.5 dùng image multi-architecture sẵn trên Docker Hub (`long301001/multi-php-docker`); `docker-compose.yml` không tự build image. PHP 8.5 chạy mặc định; các bản còn lại dùng Compose profile riêng và mặc định tắt. Dockerfile vẫn có trong repository để tham khảo hoặc tạo image tùy chỉnh. Nginx tự tạo virtual host từ file `env.json` local dựa trên mẫu [`env.example.json`](env.example.json), cho phép chạy nhiều project với domain và phiên bản PHP khác nhau.

## Video hướng dẫn

| Clone source | Tạo các dịch vụ |
| --- | --- |
| [![Clone source](https://img.youtube.com/vi/rUI_mtbsIIU/hqdefault.jpg)](https://youtu.be/rUI_mtbsIIU) | [![Tạo các dịch vụ](https://img.youtube.com/vi/2fw1NnIO-uo/hqdefault.jpg)](https://youtu.be/2fw1NnIO-uo) |
| [Xem trên YouTube](https://youtu.be/rUI_mtbsIIU) | [Xem trên YouTube](https://youtu.be/2fw1NnIO-uo) |

## Dịch vụ và cổng mặc định

| Dịch vụ | Container | Cổng host | Thông tin mặc định |
| --- | --- | --- | --- |
| Nginx | `nginx_container` | `80`, `443` | Phục vụ domain trong `env.json` |
| PHP 8.5 | `php8.5_container` | Không public | PHP-FPM cổng `9000` trong Docker network |
| PHP 8.4 | `php8.4_container` | Không public | PHP-FPM cổng `9000` trong Docker network (profile) |
| PHP 8.3 | `php8.3_container` | Không public | PHP-FPM cổng `9000` trong Docker network (profile) |
| PHP 8.2 | `php8.2_container` | Không public | PHP-FPM cổng `9000` trong Docker network (profile) |
| PHP 8.1 | `php8.1_container` | Không public | PHP-FPM cổng `9000` trong Docker network (profile) |
| PHP 8.0 | `php8.0_container` | Không public | PHP-FPM cổng `9000` trong Docker network (profile) |
| PHP 7.4 | `php7.4_container` | Không public | PHP-FPM cổng `9000` trong Docker network (profile) |
| MySQL | `mysql_container` | `3306` | User `root`, password `1` |
| PostgreSQL | `postgres_container` | `5432` | User `postgres`, password `1` |
| Redis | `redis_container` | `6379` | Không có mật khẩu |
| RabbitMQ | `rabbitmq_container` | `5672`, `15672` | User/password: `admin` / `admin` |
| Supervisor | `supervisor85_container` | Không public | Chạy background worker bằng PHP 8.5 (profile) |
| Server Manager | `manager_container` | `127.0.0.1:8080` | Quản lý virtual server trong `env.json` |
| PHP Controller | `php_controller_container` | Không public | Điều khiển allowlist PHP container qua Docker socket |
| Env Init | `env_init_container` | Không public | Tạo `env.json` nếu thiếu rồi thoát với mã `0` |

PHP 8.5 là phiên bản mặc định. `docker compose up -d` chỉ khởi động PHP 8.5; PHP 7.4, 8.0, 8.1, 8.2, 8.3 và 8.4 được đặt trong profile riêng và mặc định không chạy. MySQL, PostgreSQL, Redis, RabbitMQ và Supervisor cũng nằm trong profile riêng: mặc định không khởi động cho đến khi bạn bật profile tương ứng.

Các image được cung cấp sẵn:

| Service | Image |
| --- | --- |
| `nginx` | `long301001/multi-php-docker:nginx` |
| `php-8.0`, `supervisor-8.0` | `long301001/multi-php-docker:php-8.0` |
| `php-8.1`, `supervisor-8.1` | `long301001/multi-php-docker:php-8.1` |
| `php-8.5`, `supervisor-8.5`, `manager` | `long301001/multi-php-docker:php-8.5` |
| `php-8.4`, `supervisor-8.4` | `long301001/multi-php-docker:php-8.4` |
| `php-8.3`, `supervisor-8.3` | `long301001/multi-php-docker:php-8.3` |
| `php-8.2`, `supervisor-8.2` | `long301001/multi-php-docker:php-8.2` |
| `php-7.4` | `long301001/multi-php-docker:php-7.4` |
| `php-controller` | `docker:cli` |
| `mysql` | `long301001/multi-php-docker:mysql` |
| `postgres` | `long301001/multi-php-docker:postgres` |
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

`env.json` là cấu hình riêng của từng máy và không được push lên Git. Chỉ `env.example.json` được commit làm mẫu. Khi chạy Compose lần đầu, `env-init` copy `env.example.json` thành `env.json` nếu file chưa có; file đã tồn tại không bị ghi đè. Sau khi stack chạy, thêm và sửa project trong Server Manager thay vì sửa `env.json` bằng tay.

### 2. Đặt source code vào đúng thư mục

- PHP 8.5: `server/source_php8.5/<project-name>`
- PHP 8.4: `server/source_php8.4/<project-name>`
- PHP 8.3: `server/source_php8.3/<project-name>`
- PHP 8.2: `server/source_php8.2/<project-name>`
- PHP 8.1: `server/source_php8.1/<project-name>`
- PHP 8.0: `server/source_php8.0/<project-name>`
- PHP 7.4: `server/source_php7.4/<project-name>`

Các thư mục được mount vào container tại `/var/www/source_php<version>` tương ứng.

```text
server/
├── source_php8.5/
│   └── my-php85-app/
├── source_php8.4/
│   └── my-php84-app/
├── source_php8.3/
│   └── my-php83-app/
├── source_php8.2/
│   └── my-php82-app/
├── source_php8.1/
│   └── my-php81-app/
├── source_php8.0/
│   └── my-php80-app/
└── source_php7.4/
    └── my-php7-app/
```

### 3. Pull image và khởi động

Lần chạy đầu tiên, tải image rồi khởi động. Không cần tạo `.env`:

```powershell
docker compose pull
docker compose up -d
```

macOS / Linux / WSL:

```bash
docker compose pull
docker compose up -d
```

Lệnh này khởi động PHP 8.5, Nginx, Server Manager và PHP Controller. PHP tùy chọn (7.4, 8.0, 8.1, 8.2, 8.3, 8.4), MySQL, PostgreSQL, Redis, RabbitMQ và Supervisor mặc định tắt — bật chúng từ Server Manager.

`HOST_PROJECT_PATH` được `php-controller` tự suy ra từ bind mount `/project`. `.env` cũ vẫn được hỗ trợ làm override tương thích ngược, nhưng không bắt buộc.

Các lần sau:

```bash
docker compose up -d
docker compose ps
```

### 4. Mở Server Manager

Mở:

[http://127.0.0.1:8080/server-manage](http://127.0.0.1:8080/server-manage)

Dùng UI này để quản lý virtual server, phiên bản PHP, hosts, Nginx, MySQL, PostgreSQL, Redis, RabbitMQ và Supervisor. Thứ tự lần đầu:

1. **Thêm server** — tên ứng dụng, domain (ví dụ `my-php85-app.test`), phiên bản PHP và document root. Với Laravel hoặc framework có thư mục public riêng, trỏ document root tới `public`, `webroot` hoặc thư mục chứa `index.php`. Manager ghi `env.json`; không cần sửa file đó bằng tay.
2. **Khởi động PHP nếu cần** — PHP 8.5 đã chạy. Với phiên bản khác, mở **Các phiên bản PHP** → **Tạo** → **Khởi động**.
3. **Ghi hosts** — đăng ký helper một lần trên máy (không bắt buộc để chạy Docker). Sau đó trong Manager dùng **Thêm domain** / **Ghi hosts (Admin)**.

Windows (một lần):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1
```

macOS (một lần):

```bash
chmod +x scripts/hosts/ensure_hosts_env.sh scripts/hosts/add_hostname.sh scripts/hosts/hosts_protocol_macos.sh
./scripts/hosts/ensure_hosts_env.sh
```

Linux / WSL (watch, không dùng protocol trình duyệt):

```bash
chmod +x scripts/hosts/add_hostname.sh
./scripts/hosts/add_hostname.sh --watch
```

Trình duyệt mở `multi-php-hosts:write` rồi ghi hosts (UAC trên Windows / hộp thoại admin trên macOS). Cho phép mở ứng dụng nếu trình duyệt hỏi.

4. Nhấn **Apply & Reload Nginx**.
5. Mở domain, ví dụ `http://my-php85-app.test`.

Mục tiếp theo liệt kê toàn bộ thao tác trong Server Manager. Định dạng `env.json` thủ công, CLI hosts và lệnh Compose profile là tùy chọn, nằm phía sau.

## Quản lý server bằng giao diện web

Server Manager cho phép:

- Xem các virtual server hiện có trong `env.json`.
- Thêm, sửa và xóa server.
- Mở **Terminal** trên trang Home để vào shell trong container PHP của máy chủ đó (container phải đang chạy; Manager cần Docker socket read-write).
- Chọn PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 hoặc 8.5.
- Kiểm tra trùng application name và domain.
- Giới hạn document root trong thư mục source của PHP version đã chọn.
- Hiển thị profile cần bật và lệnh áp dụng cấu hình.
- Gửi yêu cầu tạo lại virtual host và reload Nginx bằng nút **Apply & Reload Nginx**.
- Hỗ trợ giao diện Tiếng Việt và English; lần truy cập đầu tiên tự nhận ngôn ngữ trình duyệt, sau đó ghi nhớ lựa chọn trong session.
- Hỗ trợ giao diện **Hệ thống**, **Sáng** và **Tối**; lựa chọn được lưu trong trình duyệt và chế độ Hệ thống tự đi theo cài đặt của hệ điều hành.
- Quản lý trực tiếp trạng thái các PHP container bằng các nút **Tạo**, **Khởi động**, **Dừng** và **Khởi động lại**.
- Khởi động và dừng **MySQL**, **PostgreSQL**, **Redis**, **RabbitMQ** và **Supervisor** từ UI.
- Mở **Chi tiết** từng phiên bản PHP để xem extension đã tải, bật/tắt dòng `extension=` trong `php.ini` đã mount, cài một số extension curated vào container đang chạy, và sửa nội dung `php.ini` (sau khi lưu có thể chọn Restart PHP-FPM).
- Quản lý Nginx tại menu riêng: **Khởi động**, **Dừng**, **Khởi động lại**, chạy `nginx -t`, **Apply & Reload**, và xem tối đa 200 dòng log test/reload, error, access gần nhất.
- Bật **HTTPS** từng site (opt-in). Để trống file chứng chỉ để tự sinh self-signed, hoặc upload cặp `.crt`/`.pem` và `.key`. HTTP (cổng 80) và HTTPS (cổng 443) cùng phục vụ; trình duyệt sẽ cảnh báo cert tự ký. Sau khi đổi SSL, nhấn **Apply & Reload Nginx**. Cert nằm trong `nginx/ssl/<app-name>/` và không commit lên Git. Sau khi cập nhật code này, tạo lại Nginx một lần (`docker compose up -d nginx`) để mount `./nginx/ssl`.

Card **Các phiên bản PHP** hiển thị trạng thái `Đang chạy`, `Đã dừng`, `Chưa được tạo` hoặc `Đang xử lý`. PHP 8.5 được tạo mặc định. Với PHP tùy chọn chưa từng được tạo, bấm **Tạo** trong UI (tương đương `docker compose --profile … create …`), rồi dùng **Khởi động**. Vẫn có thể tạo thủ công:

```bash
docker compose --profile php-8.1 create php-8.1
```

**Thêm phiên bản** mở catalog Hub. Cài một tag từ catalog (ví dụ alpine) sẽ sinh file Compose/Dockerfile và **build** image local; trên Windows, DNS của Docker Desktop có thể làm gián đoạn build — xem **Xử lý lỗi → Windows: Cài / Tạo phiên bản PHP**.
Sau đó làm mới Server Manager để dùng các nút điều khiển. Controller chấp nhận PHP 8.5–7.4 (service `php-8.x` trong compose) và các thao tác Create (chỉ bản có profile), Start, Stop, Restart; nó không xóa container. Controller tự suy ra đường dẫn repository trên Docker host từ mount `/project`; `HOST_PROJECT_PATH` trong `.env` chỉ là override tương thích ngược.

### Extension PHP từ Manager

Trang **Chi tiết** của từng phiên bản PHP có thể bật/tắt dòng `extension=` trong `configs/php*/php.ini` đã mount và cài một tập extension curated vào container *đang chạy* qua `php-controller`. Extension cài lúc runtime **không** tồn tại sau khi recreate container; muốn bền vững hãy bake vào Dockerfile/image tùy chỉnh.

Service `php-controller` không public cổng và mount `/var/run/docker.sock` để chạy thao tác Compose trong allowlist. Server Manager cũng có thể mount Docker socket **read-only** để lấy trạng thái container trực tiếp. Docker socket tương đương quyền root trên Docker host, vì vậy chỉ chạy stack từ source tin cậy và không mở Manager ra ngoài khi chưa có HTTPS + đăng nhập.

Thao tác PHP/Nginx vẫn đi qua allowlist của `php-controller` qua thư mục runtime dùng chung. Khi nhấn **Apply & Reload Nginx**, UI ghi tín hiệu trong `runtime/`; watcher trong Nginx sinh lại template, chạy `nginx -t` và chỉ reload khi hợp lệ. Nếu kiểm tra thất bại, cấu hình trước đó được khôi phục.

Mặc định UI chỉ publish trên `127.0.0.1:8080` (có CSRF, không bắt login). Để dùng từ xa trên server chính, xem mục **Server Manager từ xa** bên dưới.

Sau khi thêm, sửa hoặc xóa server, nhấn **Apply & Reload Nginx**. PHP 8.5 không cần restart container. Nếu server dùng PHP tùy chọn, **Tạo** rồi **Khởi động** phiên bản đó trong UI trước (hoặc chạy lệnh profile mà UI hiển thị). Kết quả gần nhất hiện ngay dưới nút sau khi tải lại trang. Chi tiết lỗi nằm trong `runtime/nginx.reload.log`; `runtime/` là dữ liệu tạm và đã được Git bỏ qua.

Nếu không cần UI, có thể dừng riêng service này:

```bash
docker compose stop manager
```

Khi Nginx khởi động, `scripts/nginx/auto-add-template.sh` đọc `env.json`, tạo virtual host từ `nginx/examples/server_example.txt` rồi nạp cấu hình.

## CLI tùy chọn

Nên dùng Server Manager cho công việc hàng ngày. Các lệnh dưới đây là tương đương nếu không dùng UI.

### Định dạng `env.json`

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
| `CONTAINER_PHP_VERSION` | `php8.5_container` … `php8.0_container` hoặc `php7.4_container` |
| `ENABLED` | `false` thì Nginx bỏ qua site |
| `SSL_ENABLED` | `true` thì Nginx listen 443 nếu có `nginx/ssl/<APP_NAME>/{cert,key}.pem` |
| `SSL_MODE` | `generated` (tự ký) hoặc `uploaded`; chỉ ghi khi SSL bật |

### CLI hosts

Stack không cần mount file hosts để khởi động. Manager đọc `runtime/hosts.status.json` do helper trên host ghi. Khi helper chưa chạy, domain hiển thị **Chưa rõ** và UI vẫn cho thêm thủ công. Script chỉ sửa block `# multi-php-docker-serve:managed:*`.

Gỡ protocol Windows: `powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1 -UnregisterProtocol`

Gỡ protocol macOS: `./scripts/hosts/ensure_hosts_env.sh --unregister-protocol`

One-shot (không cần Manager):

```bash
./scripts/hosts/add_hostname.sh
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\add_hostname.ps1
```

Script đọc `DOMAIN_NAME` trong `env.json` (và `runtime/hosts.extra.json` nếu có) rồi ánh xạ tới `127.0.0.1`. Nếu không dùng script, thêm thủ công vào:

- macOS/Linux: `/etc/hosts`
- Windows: `C:\Windows\System32\drivers\etc\hosts`

```text
127.0.0.1 my-php8-app.test
127.0.0.1 my-php7-app.test
```

### Profile PHP, MySQL, PostgreSQL, Redis, RabbitMQ và Supervisor

Mỗi phiên bản PHP tùy chọn có Compose profile cùng tên (`php-8.4`, `php-8.3`, `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4`):

```bash
docker compose --profile php-8.4 up -d
docker compose --profile php-8.3 up -d
docker compose --profile php-8.2 up -d
docker compose --profile php-8.1 up -d
docker compose --profile php-8.0 up -d
docker compose --profile php-7.4 up -d
```

Tắt một phiên bản tùy chọn:

```bash
docker compose stop php-8.3
docker compose rm -f php-8.3
```

Khi project dùng PHP tùy chọn, hãy khởi động phiên bản đó (từ UI hoặc profile tương ứng) trước khi Apply Nginx. Nếu không, Nginx không kết nối được upstream PHP.

MySQL, PostgreSQL, Redis và RabbitMQ:

```bash
docker compose --profile mysql up -d mysql
docker compose --profile postgres up -d postgres
docker compose --profile redis up -d redis
docker compose --profile rabbitmq up -d rabbitmq
```

Supervisor (không chạy cùng PHP). Từ Server Manager: **Các phiên bản PHP** → **Supervisor**, hoặc:

```bash
docker compose --profile supervisor-8.5 up -d supervisor-8.5
docker compose --profile supervisor-8.4 up -d supervisor-8.4
docker compose --profile supervisor-8.3 up -d supervisor-8.3
docker compose --profile supervisor-8.2 up -d supervisor-8.2
docker compose --profile supervisor-8.1 up -d supervisor-8.1
docker compose --profile supervisor-8.0 up -d supervisor-8.0
docker compose --profile supervisor-7.4 up -d supervisor-7.4
```

Trong UI có thể Tạo / Khởi động / Dừng / Khởi động lại và theo dõi log trong `logs/supervisor*` (làm mới thủ công hoặc bật Follow).

## Server Manager từ xa (opt-in)

Mặc định Manager lắng nghe `127.0.0.1:8080` không bắt login (chỉ CSRF).

Để dùng Manager trên server chính qua Nginx:

1. Sao chép [`.env.example`](.env.example) thành `.env` và đặt:
   - `MANAGER_REMOTE=1`
   - `MANAGER_USERNAME` / `MANAGER_PASSWORD` (mật khẩu mạnh)
   - `MANAGER_DOMAIN` (DNS A/AAAA trỏ về server)
2. Kết thúc TLS cho domain đó (cert trên Nginx, reverse proxy hoặc tunnel). Vhost được sinh listen cổng 80 và proxy tới `manager:8080` trên Docker network — hãy đặt HTTPS phía trước.
3. Tạo lại service để nhận env:

```bash
docker compose up -d nginx manager
```

4. Mở `https://MANAGER_DOMAIN/server-manage` và đăng nhập.

Nếu `MANAGER_DOMAIN` là IP và IP đó cũng là site trong `env.json`, `/` vẫn phục vụ website; Manager chỉ ở `/server-manage`.

Fail-closed: nếu `MANAGER_REMOTE=1` nhưng thiếu username/password/domain, Nginx **không** ghi `manager.template`, và API trả locked/unauthorized cho route được bảo vệ.

Lưu ý bảo mật:

- Manager từ xa điều khiển được container và có thể đọc Docker socket (ro) — coi credential như quyền root.
- Nên kèm firewall / IP allowlist ngoài login.
- **Không** publish cổng host `0.0.0.0:8080`; giữ mapping loopback trừ khi bạn chủ động hardening edge.

## Lệnh thường dùng

### Xem log

```bash
docker compose logs -f
docker compose logs -f nginx
docker compose logs -f php-8.5
docker compose logs -f mysql
docker compose logs -f postgres
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

Ví dụ tự build PHP 8.5:

```yaml
services:
  php-8.5:
    image: my-project/php:8.5-local
    build:
      context: .
      dockerfile: ./docker_files/php8.5.Dockerfile
    # Giữ nguyên volumes, working_dir và networks hiện có

  supervisor-8.5:
    image: my-project/php:8.5-local
    # Không thêm build; Supervisor dùng lại image của php-8.5
```

Build image riêng trước rồi khởi động container:

```bash
docker compose build php-8.5
docker compose --profile supervisor-8.5 up -d supervisor-8.5

# Build và chạy một service khác
docker compose build <service-name>
docker compose up -d <service-name>
```

Tên service hợp lệ: `env-init`, `nginx`, `php-8.5`, `php-8.4`, `php-8.3`, `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4`, `supervisor-8.5`, `supervisor-8.4`, `supervisor-8.3`, `supervisor-8.2`, `supervisor-8.1`, `supervisor-8.0`, `supervisor-7.4`, `manager`, `php-controller`, `mysql`, `postgres`, `redis`, `rabbitmq`.

## Chạy background worker với Supervisor

Hai service `php-8.5` và `supervisor-8.5` cùng dùng image có sẵn `long301001/multi-php-docker:php-8.5`. Hai service cũng mount chung source tại `server/source_php8.5` và `php.ini`. Supervisor chạy worker trong container riêng; nó không điều khiển process bên trong container PHP-FPM.

### Tạo cấu hình worker

Sao chép file mẫu:

```bash
cp configs/supervisor.d/worker.conf.example configs/supervisor.d/php8.5/worker.conf
```

Sửa `directory` và `command` trong `worker.conf` cho đúng project. Ví dụ Laravel:

```ini
[program:app_worker]
directory=/var/www/source_php8.5/my-project
command=php artisan queue:work --sleep=3 --tries=3 --timeout=90
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/supervisor/app-worker.log
```

Có thể tạo nhiều file `.conf` trong `configs/supervisor.d/php8.5/` để chạy worker cho nhiều project. Các file có đuôi `.example` không được nạp tự động.

### Khởi động và quản lý worker

```bash
# Khởi động PHP-FPM và Supervisor từ image có sẵn
docker compose --profile supervisor-8.5 up -d supervisor-8.5

# Xem trạng thái worker
docker compose exec supervisor-8.5 supervisorctl status

# Nạp lại cấu hình sau khi thêm hoặc sửa file .conf
docker compose exec supervisor-8.5 supervisorctl reread
docker compose exec supervisor-8.5 supervisorctl update

# Khởi động lại tất cả worker
docker compose exec supervisor-8.5 supervisorctl restart all

# Xem log container và log worker
docker compose logs -f supervisor-8.5
ls logs/supervisor-8.5
```

Supervisor dùng hostname `mysql`, `postgres`, `redis` và `rabbitmq` để kết nối các dịch vụ trong `app-network`. `depends_on` với `required: false` chỉ sắp thứ tự khởi động khi các profile MySQL/PostgreSQL/Redis/RabbitMQ đang bật; không đảm bảo dịch vụ đã sẵn sàng nhận kết nối — worker nên có cơ chế retry.

### Dùng Supervisor với phiên bản PHP khác

Mỗi container Supervisor chỉ có một PHP runtime. PHP (+ Supervisor) nằm trong `compose/php-X.Y.yml`. MySQL, PostgreSQL, Redis và RabbitMQ dùng chung, khai báo ở `compose/mysql.yml`, `compose/postgres.yml`, `compose/redis.yml` và `compose/rabbitmq.yml`. Root `docker-compose.yml` `include` các file đó.

| PHP-FPM service | Supervisor service | File | Image dùng chung |
| --- | --- | --- | --- |
| `php-8.5` | `supervisor-8.5` | `compose/php-8.5.yml` | `long301001/multi-php-docker:php-8.5` |
| `php-8.4` | `supervisor-8.4` | `compose/php-8.4.yml` | `long301001/multi-php-docker:php-8.4` |
| `php-8.3` | `supervisor-8.3` | `compose/php-8.3.yml` | `long301001/multi-php-docker:php-8.3` |
| `php-8.2` | `supervisor-8.2` | `compose/php-8.2.yml` | `long301001/multi-php-docker:php-8.2` |
| `php-8.1` | `supervisor-8.1` | `compose/php-8.1.yml` | `long301001/multi-php-docker:php-8.1` |
| `php-8.0` | `supervisor-8.0` | `compose/php-8.0.yml` | `long301001/multi-php-docker:php-8.0` |
| `php-7.4` | `supervisor-7.4` | `compose/php-7.4.yml` | Image PHP 7.4 (cần có package Supervisor) |

Không khai báo `build` trong service Supervisor. Với image tùy chỉnh, chỉ service PHP-FPM tương ứng khai báo `build`; service Supervisor dùng lại cùng tên image.

Worker theo phiên bản: đặt file `.conf` trong `configs/supervisor.d/php8.5` (PHP mặc định), hoặc `php8.4`, `php8.3`, `php8.2`, `php8.1`, `php8.0`, `php7.4`.

#### Supervisor cho phiên bản PHP tùy chọn (8.4 / 8.3 / 8.2 / 8.1 / 8.0)

```bash
# Ví dụ PHP 8.3 — đổi 8.3 thành 8.4 / 8.2 / 8.1 / 8.0 khi cần
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.3/worker.conf
# Sửa directory/command trong worker.conf

docker compose --profile php-8.3 --profile supervisor-8.3 up -d php-8.3 supervisor-8.3
docker compose exec supervisor-8.3 supervisorctl status
```

Tương tự với profile `php-8.4` / `supervisor-8.4`, `php-8.2` / `supervisor-8.2`, `php-8.1` / `supervisor-8.1`, `php-8.0` / `supervisor-8.0`.

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

Trong mỗi `worker.conf`, cập nhật `directory` theo đúng source của phiên bản PHP. Tách thư mục cấu hình và thư mục log theo phiên bản giúp tránh nạp nhầm worker. Muốn thêm Supervisor cho phiên bản PHP mới hơn 8.5, xem mục **Thêm một phiên bản PHP khác** và sao chép pattern từ `compose/php-8.5.yml`.

### Chạy lệnh trong container

```bash
docker compose exec php-8.5 sh
docker compose exec php-8.5 php -v
docker compose exec php-8.4 php -v
docker compose exec php-8.3 php -v
docker compose exec php-8.2 php -v
docker compose exec php-8.1 php -v
docker compose exec php-8.0 php -v
docker compose exec php-7.4 php -v
docker compose exec mysql mysql -uroot -p1
docker compose exec postgres psql -U postgres
```

## Kết nối dịch vụ từ ứng dụng

Ứng dụng chạy trong container phải dùng tên service Docker làm hostname, không dùng `localhost`:

```dotenv
DB_HOST=mysql
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=1

# PostgreSQL (dùng các dòng này thay cho khối MySQL ở trên)
# DB_CONNECTION=pgsql
# DB_HOST=postgres
# DB_PORT=5432
# DB_DATABASE=postgres
# DB_USERNAME=postgres
# DB_PASSWORD=1

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
2. Trong Server Manager, thêm hoặc sửa server, ghi hosts nếu domain mới, rồi **Apply & Reload Nginx**.

Cách CLI (không dùng UI):

1. Thêm hoặc sửa project trong `env.json`.
2. Chạy lại `./scripts/hosts/add_hostname.sh` nếu có domain mới.
3. Tạo lại Nginx để sinh lại virtual host:

```bash
docker compose up -d --force-recreate nginx
docker compose exec nginx nginx -t
```

## Thêm một phiên bản PHP khác

PHP 7.4 và 8.0–8.5 đã có sẵn. Mục này dành cho phiên bản mới hơn (ví dụ 8.6) hoặc image tùy chỉnh. Kiểm tra tính tương thích extension/package với phiên bản PHP đó. Có thể sao chép từ `compose/php-8.5.yml` và `docker_files/php8.5.Dockerfile` làm mẫu.

### 1. Tạo Dockerfile

```bash
cp docker_files/php8.5.Dockerfile docker_files/php8.6.Dockerfile
```

Đổi base image trong Dockerfile (ví dụ `FROM php:8.6-fpm`). Giữ hoặc điều chỉnh package/extension; các bản 8.x hiện có thường gồm `pdo_mysql`, `mysqli`, `gd`, `zip`, `sockets`, `pcntl` và Redis. PostgreSQL (`pdo_pgsql` / `pgsql`) có thể cài từ Server Manager.

### 2. Tạo cấu hình PHP, source và Supervisor

```bash
mkdir -p configs/php8.6 server/source_php8.6 configs/supervisor.d/php8.6 logs/supervisor-8.6
cp configs/php8.5/php.ini configs/php8.6/php.ini
cp configs/supervisor.d/worker.conf.example configs/supervisor.d/php8.6/worker.conf
```

### 3. Thêm Compose fragment và include

Tạo `compose/php-8.6.yml` (PHP-FPM + Supervisor, profile `php-8.6` / `supervisor-8.6`) theo mẫu `compose/php-8.5.yml`, rồi thêm `include` trong `docker-compose.yml` với `project_directory: .`. Không cần public cổng `9000` ra host — Nginx nối PHP-FPM qua `app-network`.

Để dùng image Hub tùy chỉnh thay vì build local: đặt `image: <registry>/<name>:php-8.6` và bỏ khối `build`. Để build local: khai báo `build` trên service PHP (Supervisor dùng cùng tên image).

### 4. Khai báo project và Controller

Trong `env.json`, `CONTAINER_PHP_VERSION` phải trùng `container_name`. Nếu dùng Server Manager / `php-controller`, bổ sung service mới vào allowlist trong script controller (cùng pattern các `php-8.x` hiện có).

### 5. Pull/build và kiểm tra

```bash
./scripts/hosts/add_hostname.sh
docker compose --profile php-8.6 pull php-8.6   # hoặc: docker compose build php-8.6
docker compose --profile php-8.6 up -d php-8.6
docker compose up -d --force-recreate nginx

docker compose exec php-8.6 php -v
docker compose exec nginx nginx -t
```

Sau đó mở domain tương ứng. Nếu build extension thất bại, kiểm tra hỗ trợ trên phiên bản PHP mới và package hệ thống trong Dockerfile.

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

## Sao lưu và khôi phục PostgreSQL

Named volume PostgreSQL có tên `postgres-data`.

### Sao lưu

Dừng PostgreSQL để dữ liệu nhất quán khi sao lưu trực tiếp volume:

```bash
docker compose stop postgres

docker run --rm \
  -v postgres-data:/data:ro \
  -v "$(pwd):/backup" \
  alpine \
  tar czf /backup/postgres-data.tar.gz -C /data .

docker compose start postgres
```

File sao lưu được tạo tại `./postgres-data.tar.gz`.

### Khôi phục

> Khôi phục sẽ ghi dữ liệu từ bản sao lưu vào volume hiện tại. Hãy sao lưu volume hiện tại trước khi thực hiện.

```bash
docker compose stop postgres

docker run --rm \
  -v postgres-data:/data \
  -v "$(pwd):/backup:ro" \
  alpine \
  tar xzf /backup/postgres-data.tar.gz -C /data

docker compose start postgres
```

## Xử lý lỗi thường gặp

### Domain không truy cập được

- Kiểm tra domain đã có trong file `hosts` và trỏ tới `127.0.0.1`.
- Chạy `docker compose ps` để kiểm tra Nginx và PHP.
- Kiểm tra các trường trong `env.json` rồi tạo lại container Nginx.

### Lỗi 404 hoặc `File not found`

- `SERVER_PATH` phải là đường dẫn bên trong container.
- Kiểm tra document root có đúng thư mục chứa `index.php` hay không.
- Kiểm tra source nằm đúng thư mục PHP 7.4 hoặc 8.0–8.5.

### Cổng đã được sử dụng

Tắt ứng dụng đang chiếm cổng hoặc đổi cổng host trong `compose/mysql.yml` hoặc `compose/postgres.yml`. Ví dụ, đổi `"3306:3306"` thành `"3307:3306"` để MySQL dùng cổng `3307` trên host, hoặc `"5432:5432"` thành `"5433:5432"` cho PostgreSQL.

### Không kết nối được MySQL, PostgreSQL, Redis hoặc RabbitMQ từ PHP

Trong container, dùng hostname `mysql`, `postgres`, `redis`, `rabbitmq`, không dùng `localhost`. Kiểm tra trạng thái bằng `docker compose ps`. Nếu container chưa chạy, bật profile rồi khởi động, ví dụ `docker compose --profile mysql up -d mysql` hoặc `docker compose --profile postgres up -d postgres`.

### Build image thất bại

- Xem lỗi đầy đủ bằng `docker compose build --no-cache <service-name>`.
- Kiểm tra kết nối mạng và Docker daemon.
- Kiểm tra đúng tên Dockerfile, đặc biệt RabbitMQ dùng `docker_files/rabbitMQ.Dockerfile`.

### Windows: Cài / Tạo phiên bản PHP từ Server Manager thất bại

Các bản PHP kèm sẵn (`php-7.4` … `php-8.5`) dùng image Hub sẵn, thường chỉ cần **Tạo** → **Khởi động**. Các bản bạn **Cài đặt** từ catalog Manager (tag cụ thể như alpine/trixie) sẽ sinh Dockerfile và phải **build** image local (`multi-php-local:…`) trước khi tạo container. Build đó kéo base image từ Docker Hub (`php:…-fpm` / `…-fpm-alpine`).

Trên **Windows Docker Desktop**, bước này đôi khi fail dù máy vẫn lên mạng bình thường. Dòng lỗi thường gặp (trong `php-controller-runtime/status/`):

- `lookup auth.docker.io … network is unreachable`
- `failed to authorize: failed to fetch anonymous token`
- `failed to resolve source metadata for docker.io/library/php:…`

macOS ít gặp lỗi DNS kiểu này hơn.

**Cách xử lý:**

1. Kiểm tra Docker host kéo được Hub:

```powershell
docker pull hello-world
# Hoặc đúng tag base trong lỗi / Dockerfile, ví dụ:
docker pull php:8.5.7-fpm-alpine
```

2. Pull thành công thì mở lại Manager và bấm **Tạo** (hoặc **Cài đặt** lại). Lần build đầu có thể mất vài phút.

3. Xem log lần fail gần nhất:

```powershell
Get-Content .\php-controller-runtime\status\last-create-error.log -Tail 40
Get-Content .\php-controller-runtime\status\<service>.last-install-version.log -Tail 40
```

4. Nếu DNS Desktop vẫn lỗi: khởi động lại Docker Desktop, hoặc đổi DNS tạm (ví dụ `8.8.8.8` / `1.1.1.1`) tại Docker Desktop → Settings → Resources → Network rồi thử lại.

5. Nếu không cần đúng patch/alpine/trixie: dùng bản Hub kèm sẵn trong repo — không phải build Dockerfile local.

Tùy chọn: chạy một lần `scripts\hosts\ensure_hosts_env.ps1` để `.env` có `HOST_PROJECT_PATH` dạng slash xuôi (`D:/…`). `php-controller` dùng path này khi rewrite bind mount lúc Tạo/Cài.

### Windows: `env.json` thành thư mục

Nếu Docker từng bind-mount khi chưa có `env.json` và tạo ra **thư mục** cùng tên, hãy xóa thư mục đó rồi để `env-init` tạo lại file (`docker compose up -d` hoặc copy từ `env.example.json`). Compose mount cả thư mục project (không mount riêng file) để tránh lỗi này.

## Cấu trúc repository

```text
.
├── compose/                 # Compose fragments (PHP+Supervisor, mysql, postgres, redis, rabbitmq)
│   ├── mysql.yml
│   ├── php-7.4.yml
│   ├── php-8.0.yml
│   ├── php-8.1.yml
│   ├── php-8.2.yml
│   ├── php-8.3.yml
│   ├── php-8.4.yml
│   ├── php-8.5.yml
│   ├── postgres.yml
│   ├── rabbitmq.yml
│   └── redis.yml
├── configs/                 # Cấu hình PHP và Supervisor
│   └── supervisor.d/        # Worker theo version (php8.5/, php8.4/, …)
├── docker_files/            # Dockerfile để build các dịch vụ
├── mysql/                   # Cấu hình MySQL
├── postgres/                # Cấu hình PostgreSQL
├── nginx/
│   ├── examples/            # Virtual host mẫu
│   ├── ssl/                 # Chứng chỉ từng site (gitignore)
│   ├── logs/                # Log Nginx
│   └── templates/           # Cấu hình được sinh từ env.json
├── scripts/                 # Script khởi động và tạo cấu hình
│   ├── php/                 # php-controller + install/uninstall extension
│   ├── nginx/               # auto-add-template, reload, watch
│   ├── hosts/               # add_hostname, ensure_hosts_env, protocol handlers
│   ├── docker/              # entrypoint, supervisord, compose wrappers
│   └── macos/               # MultiPhpHosts.app (protocol helper, gitignored)
├── server/
│   ├── manager/             # UI quản lý env.json
│   ├── source_php7.4/       # Source chạy PHP 7.4
│   ├── source_php8.0/       # Source chạy PHP 8.0
│   ├── source_php8.1/       # Source chạy PHP 8.1
│   ├── source_php8.2/       # Source chạy PHP 8.2
│   ├── source_php8.3/       # Source chạy PHP 8.3
│   ├── source_php8.4/       # Source chạy PHP 8.4
│   └── source_php8.5/       # Source chạy PHP 8.5
├── docker-compose.yml       # Root: include + nginx/manager/php-controller/env-init
├── env.example.json         # Cấu hình project/domain mẫu được commit
└── env.json                 # Cấu hình local, được Git bỏ qua
```

## Tác giả

Dự án được duy trì bởi **Hải Long**.

Mọi trao đổi, hợp tác hoặc góp ý xin vui lòng liên hệ:

| | |
| --- | --- |
| Email | [longdh2.dev@gmail.com](mailto:longdh2.dev@gmail.com) |
| LinkedIn | [Hải Long](https://www.linkedin.com/in/h%E1%BA%A3i-long-729355219/) |
