[English](README.md) | [Tiếng Việt](README.vi.md)

# PHP Development Environment with Docker

This repository provides a local development environment with Nginx, PHP 7.4, PHP 8.0–8.5, MySQL, Redis, and RabbitMQ. All PHP 7.4–8.5 versions use ready-to-use multi-architecture images from Docker Hub (`long301001/multi-php-docker`); `docker-compose.yml` does not build those images. PHP 8.5 runs by default; the other PHP versions use separate Compose profiles and remain off by default. Dockerfiles remain in the repository as references or for creating custom images. Nginx generates virtual hosts from a local `env.json` based on [`env.example.json`](env.example.json), allowing multiple projects to use different domains and PHP versions.

## Demo videos

| Clone the source | Create services |
| --- | --- |
| [![Clone the source](https://img.youtube.com/vi/rUI_mtbsIIU/hqdefault.jpg)](https://youtu.be/rUI_mtbsIIU) | [![Create services](https://img.youtube.com/vi/2fw1NnIO-uo/hqdefault.jpg)](https://youtu.be/2fw1NnIO-uo) |
| [Watch on YouTube](https://youtu.be/rUI_mtbsIIU) | [Watch on YouTube](https://youtu.be/2fw1NnIO-uo) |

## Default services and ports

| Service | Container | Host ports | Default configuration |
| --- | --- | --- | --- |
| Nginx | `nginx_container` | `80`, `443` | Serves domains defined in `env.json` |
| PHP 8.5 | `php8.5_container` | Not published | PHP-FPM on port `9000` inside the Docker network |
| PHP 8.4 | `php8.4_container` | Not published | PHP-FPM on port `9000` inside the Docker network (profile) |
| PHP 8.3 | `php8.3_container` | Not published | PHP-FPM on port `9000` inside the Docker network (profile) |
| PHP 8.2 | `php8.2_container` | Not published | PHP-FPM on port `9000` inside the Docker network (profile) |
| PHP 8.1 | `php8.1_container` | Not published | PHP-FPM on port `9000` inside the Docker network (profile) |
| PHP 8.0 | `php8.0_container` | Not published | PHP-FPM on port `9000` inside the Docker network (profile) |
| PHP 7.4 | `php7.4_container` | Not published | PHP-FPM on port `9000` inside the Docker network (profile) |
| MySQL | `mysql_container` | `3306` | User `root`, password `1` |
| Redis | `redis_container` | `6379` | No password |
| RabbitMQ | `rabbitmq_container` | `5672`, `15672` | User/password: `admin` / `admin` |
| Supervisor | `supervisor85_container` | Not published | Runs PHP 8.5 background workers (profile) |
| Server Manager | `manager_container` | `127.0.0.1:8080` | Manages virtual servers in `env.json` |
| PHP Controller | `php_controller_container` | Not published | Controls the allowlisted PHP containers through the Docker socket |
| Env Init | `env_init_container` | Not published | Creates a missing `env.json`, then exits with code `0` |

PHP 8.5 is the default version. `docker compose up -d` starts only PHP 8.5; PHP 7.4, 8.0, 8.1, 8.2, 8.3, and 8.4 are assigned to separate profiles and remain disabled by default. MySQL, Redis, RabbitMQ, and Supervisor also use separate profiles: they are not started until you enable the matching profile.

The following images are provided:

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
| `redis` | `long301001/multi-php-docker:redis-alpine` |
| `rabbitmq` | `long301001/multi-php-docker:rabbitmq-3-management` |
| `env-init` | `alpine:latest` |

## Requirements

- Docker Desktop or Docker Engine.
- Docker Compose v2 (the `docker compose` command).
- `jq` when using the script that automatically updates the `hosts` file.
- Administrator privileges to edit the `hosts` file.

Verify the environment:

```bash
docker --version
docker compose version
```

## Installation and usage

### 1. Clone the repository

```bash
git clone <repository-url>
cd <repository-folder>
```

`env.json` is machine-specific and must not be pushed to Git. Only `env.example.json` is committed as the template. On the first Compose run, `env-init` copies `env.example.json` to `env.json` if the file is missing; an existing file is never overwritten. After the stack is up, add and edit projects in Server Manager instead of editing `env.json` by hand.

### 2. Place the source code in the correct directory

- PHP 8.5: `server/source_php8.5/<project-name>`
- PHP 8.4: `server/source_php8.4/<project-name>`
- PHP 8.3: `server/source_php8.3/<project-name>`
- PHP 8.2: `server/source_php8.2/<project-name>`
- PHP 8.1: `server/source_php8.1/<project-name>`
- PHP 8.0: `server/source_php8.0/<project-name>`
- PHP 7.4: `server/source_php7.4/<project-name>`

Each directory is mounted at the matching `/var/www/source_php<version>` path inside its container.

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

### 3. Pull images and start the environment

On the first run, pull the images and start. Zero-config startup does not require a `.env` file:

```powershell
docker compose pull
docker compose up -d
```

macOS / Linux / WSL:

```bash
docker compose pull
docker compose up -d
```

This starts PHP 8.5, Nginx, Server Manager, and PHP Controller. Optional PHP versions (7.4, 8.0, 8.1, 8.2, 8.3, 8.4), MySQL, Redis, RabbitMQ, and Supervisor stay off until you start them from Server Manager.

`php-controller` infers `HOST_PROJECT_PATH` from the `/project` bind mount. Existing `.env` values remain supported as backward-compatible overrides, but they are not required.

Later starts:

```bash
docker compose up -d
docker compose ps
```

### 4. Open Server Manager

Open:

[http://127.0.0.1:8080/server-manage](http://127.0.0.1:8080/server-manage)

Use this UI to manage virtual servers, PHP versions, hosts, Nginx, MySQL, Redis, RabbitMQ, and Supervisor. First-run order:

1. **Add a server** — application name, domain (for example `my-php85-app.test`), PHP version, and document root. For Laravel or a framework with a public directory, point the document root at `public`, `webroot`, or the folder that contains `index.php`. Manager writes `env.json`; you do not need to edit that file by hand.
2. **Start PHP if needed** — PHP 8.5 is already running. For another version, open **PHP versions** → **Create** → **Start**.
3. **Write hosts** — once per machine, register the helper (not required to start Docker). Then in Manager use **Add domain** / **Write hosts (Admin)**.

Windows (once):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1
```

macOS (once):

```bash
chmod +x scripts/hosts/ensure_hosts_env.sh scripts/hosts/add_hostname.sh scripts/hosts/hosts_protocol_macos.sh
./scripts/hosts/ensure_hosts_env.sh
```

Linux / WSL (watch instead of a browser protocol):

```bash
chmod +x scripts/hosts/add_hostname.sh
./scripts/hosts/add_hostname.sh --watch
```

The browser opens `multi-php-hosts:write` and writes hosts (UAC on Windows / admin prompt on macOS). Allow the app if the browser asks.

4. Click **Apply & Reload Nginx**.
5. Open the domain, for example `http://my-php85-app.test`.

The next section lists everything Server Manager can do. Manual `env.json` format, hosts CLI, and Compose profile commands are optional alternatives after that.

## Manage servers in the web interface

Server Manager can:

- List the virtual servers currently stored in `env.json`.
- Add, edit, and delete servers.
- Open an inline **Terminal** on Home for a shell inside that server’s PHP container (container must be running; Manager needs read-write Docker socket access).
- Select PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, or 8.5.
- Reject duplicate application names and domains.
- Restrict document roots to the selected PHP version's source directory.
- Display the profiles and commands required to apply the configuration.
- Request virtual-host regeneration and an Nginx reload with **Apply & Reload Nginx**.
- Support Vietnamese and English; the first visit follows the browser language and the selected locale is then remembered in the session.
- Support **System**, **Light**, and **Dark** appearances; the selection is stored in the browser, and System mode follows the operating-system preference.
- Manage PHP container state directly with **Create**, **Start**, **Stop**, and **Restart** controls.
- Start and stop **MySQL**, **Redis**, **RabbitMQ**, and **Supervisor** from the UI.
- Open **Details** for each PHP version to list loaded extensions, toggle `extension=` lines in the mounted `php.ini`, install a curated set of extensions into a running container, and edit `php.ini` (after save you can choose to restart PHP-FPM).
- Manage Nginx from a dedicated menu: **Start**, **Stop**, **Restart**, run `nginx -t`, **Apply & Reload**, and inspect up to 200 recent test/reload, error, and access log lines.
- Enable **HTTPS** per site (opt-in). Leave the certificate files empty to generate a self-signed cert, or upload a `.crt`/`.pem` and `.key`. HTTP (port 80) and HTTPS (port 443) both keep serving; browsers warn on self-signed certs. Click **Apply & Reload Nginx** after changing SSL. Certificates are stored in `nginx/ssl/<app-name>/` and are not committed to Git. After pulling this change, recreate Nginx once (`docker compose up -d nginx`) so `./nginx/ssl` is mounted.

The **PHP Versions** card shows `Running`, `Stopped`, `Not created`, or `Processing`. PHP 8.5 is created by default. For an optional PHP container that has never been created, click **Create** in the UI (equivalent to `docker compose --profile … create …`), then use **Start**. You can still create manually:

```bash
docker compose --profile php-8.1 create php-8.1
```

**Add version** opens the Hub catalog. Installing a catalog tag (for example alpine) scaffolds Compose/Dockerfile files and **builds** a local image; on Windows, Docker Desktop DNS can interrupt that build — see **Troubleshooting → Windows: Install / Create PHP version**.
Then refresh Server Manager to use the controls. The controller accepts PHP 8.5–7.4 (`php-8.x` compose services) plus Create (profiled services only), Start, Stop, and Restart; it does not delete containers. The controller infers the repository path on the Docker host from the `/project` mount; `HOST_PROJECT_PATH` in `.env` is only a backward-compatible override.

### PHP extensions from Manager

The PHP version **Details** page can enable/disable `extension=` lines in the mounted `configs/php*/php.ini` and install a curated set of extensions into a *running* container via `php-controller`. Runtime installs do **not** survive container recreate; bake permanent extensions into a custom image/Dockerfile instead.

The `php-controller` service publishes no ports and mounts `/var/run/docker.sock` to run allowlisted Compose actions. Server Manager may also mount the Docker socket **read-only** for live container status. Docker socket access is effectively root-level access to the Docker host, so only run the stack from trusted source and never expose Manager without authentication and HTTPS.

PHP and Nginx container **actions** still go through a fixed allowlist in `php-controller` via a shared runtime directory. When **Apply & Reload Nginx** is clicked, the UI writes a signal file to `runtime/`; a watcher inside the Nginx container regenerates templates, runs `nginx -t`, and reloads only when the configuration is valid. If validation fails, the previous configuration is restored.

By default the UI is published only on `127.0.0.1:8080` (CSRF protection, no login). For optional remote access on a primary server, see **Remote Server Manager** below.

After adding, editing, or deleting a server, click **Apply & Reload Nginx**. PHP 8.5 does not need a container restart. If the server uses an optional PHP version, **Create** and **Start** that version in the UI first (or run the profile command the UI shows). Refresh the page to see the latest result below the button. Detailed errors are written to `runtime/nginx.reload.log`; `runtime/` contains temporary data and is ignored by Git.

Stop the UI separately when it is not needed:

```bash
docker compose stop manager
```

When Nginx starts, `scripts/nginx/auto-add-template.sh` reads `env.json`, generates virtual hosts from `nginx/examples/server_example.txt`, and loads the resulting configuration.

## Optional CLI

Prefer Server Manager for daily work. The commands below are equivalents if you are not using the UI.

### `env.json` format

Each project uses one `SERVER_NAME<N>` entry:

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

| Field | Description |
| --- | --- |
| `APP_NAME` | Project name and generated Nginx configuration filename |
| `DOMAIN_NAME` | Domain used on the local machine |
| `SERVER_PATH` | Absolute document-root path **inside the container** |
| `CONTAINER_PHP_VERSION` | `php8.5_container` … `php8.0_container`, or `php7.4_container` |
| `ENABLED` | When `false`, the site is skipped by Nginx generation |
| `SSL_ENABLED` | When `true`, Nginx also listens on 443 if `nginx/ssl/<APP_NAME>/{cert,key}.pem` exist |
| `SSL_MODE` | `generated` (self-signed) or `uploaded`; only stored when SSL is on |

### Hosts CLI

The stack starts without mounting the OS hosts file. Manager reads `runtime/hosts.status.json` from the optional host helper. Before the helper runs, domains show **Unknown** and the UI still provides a manual fallback. The script only edits the `# multi-php-docker-serve:managed:*` block.

Unregister Windows: `powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1 -UnregisterProtocol`

Unregister macOS: `./scripts/hosts/ensure_hosts_env.sh --unregister-protocol`

One-shot (without Manager):

```bash
./scripts/hosts/add_hostname.sh
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\add_hostname.ps1
```

The script reads every `DOMAIN_NAME` in `env.json` (plus `runtime/hosts.extra.json` if present) and maps it to `127.0.0.1`. To configure domains manually, edit:

- macOS/Linux: `/etc/hosts`
- Windows: `C:\Windows\System32\drivers\etc\hosts`

```text
127.0.0.1 my-php8-app.test
127.0.0.1 my-php7-app.test
```

### PHP, MySQL, Redis, RabbitMQ, and Supervisor profiles

Each optional PHP version has a Compose profile with the same name (`php-8.4`, `php-8.3`, `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4`):

```bash
docker compose --profile php-8.4 up -d
docker compose --profile php-8.3 up -d
docker compose --profile php-8.2 up -d
docker compose --profile php-8.1 up -d
docker compose --profile php-8.0 up -d
docker compose --profile php-7.4 up -d
```

Stop and remove an optional version with:

```bash
docker compose stop php-8.3
docker compose rm -f php-8.3
```

When a project uses an optional PHP container, start that version (from the UI or with the matching profile) before applying Nginx. Otherwise Nginx cannot connect to that PHP upstream.

MySQL, Redis, and RabbitMQ:

```bash
docker compose --profile mysql up -d mysql
docker compose --profile redis up -d redis
docker compose --profile rabbitmq up -d rabbitmq
```

Supervisor (does not start with PHP). From Server Manager: **PHP versions** → **Supervisor**, or:

```bash
docker compose --profile supervisor-8.5 up -d supervisor-8.5
docker compose --profile supervisor-8.4 up -d supervisor-8.4
docker compose --profile supervisor-8.3 up -d supervisor-8.3
docker compose --profile supervisor-8.2 up -d supervisor-8.2
docker compose --profile supervisor-8.1 up -d supervisor-8.1
docker compose --profile supervisor-8.0 up -d supervisor-8.0
docker compose --profile supervisor-7.4 up -d supervisor-7.4
```

The UI supports Create / Start / Stop / Restart and live log viewing under `logs/supervisor*` (manual refresh or Follow).

## Remote Server Manager (opt-in)

By default Manager listens on `127.0.0.1:8080` without login (CSRF only).

To use Manager on a primary server behind Nginx:

1. Copy [`.env.example`](.env.example) to `.env` and set:
   - `MANAGER_REMOTE=1`
   - `MANAGER_USERNAME` / `MANAGER_PASSWORD` (use a strong password)
   - `MANAGER_DOMAIN` (DNS A/AAAA record pointing at the server)
2. Terminate TLS for that domain (certificate on Nginx, a reverse proxy, or a tunnel). The generated vhost listens on port 80 and proxies to `manager:8080` on the Docker network — put HTTPS in front.
3. Recreate services so env is applied:

```bash
docker compose up -d nginx manager
```

4. Open `https://MANAGER_DOMAIN/server-manage` and sign in.

If `MANAGER_DOMAIN` is a bare server IP and that IP is also a site in `env.json`, `/` keeps serving the site and Manager is only at `/server-manage`.

Fail-closed: if `MANAGER_REMOTE=1` but username/password/domain are incomplete, Nginx does **not** write `manager.template`, and the API returns locked/unauthorized for protected routes.

Security notes:

- Remote Manager can control containers and may have read-only Docker socket status — treat credentials like root access.
- Prefer firewall / IP allowlists in addition to login.
- Do **not** publish host port `0.0.0.0:8080`; keep the loopback mapping unless you intentionally add a hardened edge.

## Common commands

### View logs

```bash
docker compose logs -f
docker compose logs -f nginx
docker compose logs -f php-8.5
docker compose logs -f mysql
```

### Stop, restart, and remove containers

```bash
# Stop without removing containers
docker compose stop

# Start stopped containers
docker compose start

# Remove containers and the network; named volumes are preserved
docker compose down

# Restart one service
docker compose restart nginx
```

### Update images from the registry

```bash
# Pull the newest content for the configured tags
docker compose pull
docker compose up -d
```

### Build custom images

To change extensions, packages, or configuration inside an image, change `image` to your own name and add `build` to the corresponding service. Do not keep a `long301001/multi-php-docker:*` name for a custom image.

Example for a custom PHP 8.5 image:

```yaml
services:
  php-8.5:
    image: my-project/php:8.5-local
    build:
      context: .
      dockerfile: ./docker_files/php8.5.Dockerfile
    # Keep the existing volumes, working_dir, and networks

  supervisor-8.5:
    image: my-project/php:8.5-local
    # Do not add build; Supervisor reuses the php-8.5 image
```

Build the custom image before starting the containers:

```bash
docker compose build php-8.5
docker compose --profile supervisor-8.5 up -d supervisor-8.5

# Build and run another service
docker compose build <service-name>
docker compose up -d <service-name>
```

Valid service names: `env-init`, `nginx`, `php-8.5`, `php-8.4`, `php-8.3`, `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4`, `supervisor-8.5`, `supervisor-8.4`, `supervisor-8.3`, `supervisor-8.2`, `supervisor-8.1`, `supervisor-8.0`, `supervisor-7.4`, `manager`, `php-controller`, `mysql`, `redis`, and `rabbitmq`.

## Running background workers with Supervisor

The `php-8.5` and `supervisor-8.5` services both use the provided `long301001/multi-php-docker:php-8.5` image. They also mount the same source directory at `server/source_php8.5` and the same `php.ini`. Supervisor runs workers in its own container; it does not control processes inside the PHP-FPM container.

### Create a worker configuration

Copy the example file:

```bash
cp configs/supervisor.d/worker.conf.example configs/supervisor.d/php8.5/worker.conf
```

Update `directory` and `command` in `worker.conf` for the project. Laravel example:

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

Create multiple `.conf` files in `configs/supervisor.d/php8.5/` to run workers for multiple projects. Files ending in `.example` are not loaded automatically.

### Start and manage workers

```bash
# Start PHP-FPM and Supervisor from the provided image
docker compose --profile supervisor-8.5 up -d supervisor-8.5

# View worker status
docker compose exec supervisor-8.5 supervisorctl status

# Reload configuration after adding or editing .conf files
docker compose exec supervisor-8.5 supervisorctl reread
docker compose exec supervisor-8.5 supervisorctl update

# Restart all workers
docker compose exec supervisor-8.5 supervisorctl restart all

# View container and worker logs
docker compose logs -f supervisor-8.5
ls logs/supervisor-8.5
```

Supervisor uses `mysql`, `redis`, and `rabbitmq` as hostnames inside `app-network`. `depends_on` with `required: false` only orders startup when the MySQL/Redis/RabbitMQ profiles are enabled; it does not guarantee that a dependency is ready to accept connections, so workers should retry failed connections.

### Using Supervisor with other PHP versions

Each Supervisor container contains one PHP runtime. PHP (+ Supervisor) lives in `compose/php-X.Y.yml`. MySQL, Redis, and RabbitMQ are shared and defined in `compose/mysql.yml`, `compose/redis.yml`, and `compose/rabbitmq.yml`. The root `docker-compose.yml` `include`s those files.

| PHP-FPM service | Supervisor service | File | Shared image |
| --- | --- | --- | --- |
| `php-8.5` | `supervisor-8.5` | `compose/php-8.5.yml` | `long301001/multi-php-docker:php-8.5` |
| `php-8.4` | `supervisor-8.4` | `compose/php-8.4.yml` | `long301001/multi-php-docker:php-8.4` |
| `php-8.3` | `supervisor-8.3` | `compose/php-8.3.yml` | `long301001/multi-php-docker:php-8.3` |
| `php-8.2` | `supervisor-8.2` | `compose/php-8.2.yml` | `long301001/multi-php-docker:php-8.2` |
| `php-8.1` | `supervisor-8.1` | `compose/php-8.1.yml` | `long301001/multi-php-docker:php-8.1` |
| `php-8.0` | `supervisor-8.0` | `compose/php-8.0.yml` | `long301001/multi-php-docker:php-8.0` |
| `php-7.4` | `supervisor-7.4` | `compose/php-7.4.yml` | PHP 7.4 image (must include Supervisor) |

Do not add `build` to a Supervisor service. For a custom image, only the matching PHP-FPM service declares `build`; the Supervisor service reuses the same image name to prevent duplicate builds.

Per-version workers: put `.conf` files in `configs/supervisor.d/php8.5` (default PHP), or `php8.4`, `php8.3`, `php8.2`, `php8.1`, `php8.0`, `php7.4`.

#### Supervisor for optional PHP versions (8.4 / 8.3 / 8.2 / 8.1 / 8.0)

```bash
# Example PHP 8.3 — swap 8.3 for 8.4 / 8.2 / 8.1 / 8.0 as needed
cp configs/supervisor.d/worker.conf.example \
   configs/supervisor.d/php8.3/worker.conf
# Edit directory/command in worker.conf

docker compose --profile php-8.3 --profile supervisor-8.3 up -d php-8.3 supervisor-8.3
docker compose exec supervisor-8.3 supervisorctl status
```

Same pattern for profiles `php-8.4` / `supervisor-8.4`, `php-8.2` / `supervisor-8.2`, `php-8.1` / `supervisor-8.1`, and `php-8.0` / `supervisor-8.0`.

#### PHP 7.4 Supervisor example

The provided PHP 7.4 image does not currently include Supervisor. To run `supervisor-7.4`, create a custom image: add `supervisor` to the package list in `docker_files/php7.Dockerfile`, change the image of `php-7.4` (and `supervisor-7.4` in `compose/php-7.4.yml`) to your own name, and add `build` as described under **Build custom images**.

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

Update `directory` in each `worker.conf` to match the PHP version's source path. Separate configuration and log directories per version prevent workers from being loaded by the wrong runtime. To add Supervisor for a PHP version newer than 8.5, see **Adding another PHP version** and copy the pattern from `compose/php-8.5.yml`.

### Run commands inside containers

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
```

## Connecting applications to services

Applications running inside a container must use Docker service names as hostnames instead of `localhost`:

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

Applications running directly on the host should use `127.0.0.1` and the host ports listed in the services table.

## Adding or changing a project

1. Place the source code in the appropriate PHP directory.
2. In Server Manager, add or edit the server, write hosts if the domain is new, then **Apply & Reload Nginx**.

CLI alternative (without the UI):

1. Add or update the project in `env.json`.
2. Run `./scripts/hosts/add_hostname.sh` again when adding a domain.
3. Recreate Nginx to regenerate the virtual hosts:

```bash
docker compose up -d --force-recreate nginx
docker compose exec nginx nginx -t
```

## Adding another PHP version

PHP 7.4 and 8.0–8.5 are already shipped. Use this section for a newer release (for example 8.6) or a custom image. Check extension and system-package compatibility. You can copy `compose/php-8.5.yml` and `docker_files/php8.5.Dockerfile` as templates.

### 1. Create the Dockerfile

```bash
cp docker_files/php8.5.Dockerfile docker_files/php8.6.Dockerfile
```

Change the base image (for example `FROM php:8.6-fpm`). Keep or adjust packages and extensions; current 8.x images typically include `pdo_mysql`, `mysqli`, `gd`, `zip`, `sockets`, `pcntl`, and Redis.

### 2. Create PHP config, source, and Supervisor dirs

```bash
mkdir -p configs/php8.6 server/source_php8.6 configs/supervisor.d/php8.6 logs/supervisor-8.6
cp configs/php8.5/php.ini configs/php8.6/php.ini
cp configs/supervisor.d/worker.conf.example configs/supervisor.d/php8.6/worker.conf
```

### 3. Add a Compose fragment and include

Create `compose/php-8.6.yml` (PHP-FPM + Supervisor, profiles `php-8.6` / `supervisor-8.6`) from `compose/php-8.5.yml`, then add an `include` in `docker-compose.yml` with `project_directory: .`. Do not publish port `9000` to the host — Nginx reaches PHP-FPM on `app-network`.

For a custom Hub image instead of a local build: set `image: <registry>/<name>:php-8.6` and omit `build`. For a local build: declare `build` on the PHP service (Supervisor reuses the same image name).

### 4. Declare the project and Controller allowlist

In `env.json`, `CONTAINER_PHP_VERSION` must match `container_name`. If you use Server Manager / `php-controller`, add the new service to the controller allowlist (same pattern as the existing `php-8.x` services).

### 5. Pull/build and verify

```bash
./scripts/hosts/add_hostname.sh
docker compose --profile php-8.6 pull php-8.6   # or: docker compose build php-8.6
docker compose --profile php-8.6 up -d php-8.6
docker compose up -d --force-recreate nginx

docker compose exec php-8.6 php -v
docker compose exec nginx nginx -t
```

Then open the matching domain. If an extension fails to build, check support on the new PHP version and the system packages in the Dockerfile.

## Backing up and restoring MySQL

The MySQL named volume is `mysql-data`.

### Backup

Stop MySQL to keep the direct volume backup consistent:

```bash
docker compose stop mysql

docker run --rm \
  -v mysql-data:/data:ro \
  -v "$(pwd):/backup" \
  alpine \
  tar czf /backup/mysql-data.tar.gz -C /data .

docker compose start mysql
```

The backup is created as `./mysql-data.tar.gz`.

### Restore

> Restoring writes backup data into the current volume. Back up the current volume before proceeding.

```bash
docker compose stop mysql

docker run --rm \
  -v mysql-data:/data \
  -v "$(pwd):/backup:ro" \
  alpine \
  tar xzf /backup/mysql-data.tar.gz -C /data

docker compose start mysql
```

## Troubleshooting

### A domain cannot be reached

- Verify that the domain exists in the `hosts` file and points to `127.0.0.1`.
- Run `docker compose ps` to check Nginx and PHP.
- Check the fields in `env.json`, then recreate the Nginx container.

### 404 or `File not found`

- `SERVER_PATH` must be a path inside the container.
- Verify that the document root contains `index.php`.
- Confirm that the source is in the correct PHP 7.4 or 8.0–8.5 directory.

### A port is already in use

Stop the application using the port or change the host side of the mapping in `compose/mysql.yml`. For example, change `"3306:3306"` to `"3307:3306"` to expose MySQL on host port `3307`.

### PHP cannot connect to MySQL, Redis, or RabbitMQ

Inside a container, use `mysql`, `redis`, and `rabbitmq` as hostnames instead of `localhost`. Check container status with `docker compose ps`. If a container is not running, enable its profile and start it, for example `docker compose --profile mysql up -d mysql`.

### An image fails to build

- View the full build output with `docker compose build --no-cache <service-name>`.
- Check the network connection and Docker daemon.
- Verify Dockerfile names, especially `docker_files/rabbitMQ.Dockerfile` for RabbitMQ.

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

### Windows: Install / Create PHP version from Server Manager fails

Bundled PHP versions (`php-7.4` … `php-8.5`) use ready-made Hub images and usually only need **Create** → **Start**. Versions you **Install** from the Manager catalog (exact tags such as alpine/trixie) generate a Dockerfile and must **build** a local image (`multi-php-local:…`) before the container can be created. That build pulls a base image from Docker Hub (`php:…-fpm` / `…-fpm-alpine`).

On **Windows Docker Desktop**, this step sometimes fails even when general internet works. Typical log lines (under `php-controller-runtime/status/`):

- `lookup auth.docker.io … network is unreachable`
- `failed to authorize: failed to fetch anonymous token`
- `failed to resolve source metadata for docker.io/library/php:…`

macOS is less affected by this Desktop DNS flakiness.

**What to do:**

1. Confirm Docker can reach Hub from the host:

```powershell
docker pull hello-world
# Or pull the base tag shown in the error / Dockerfile, for example:
docker pull php:8.5.7-fpm-alpine
```

2. If pull succeeds, open Manager again and click **Create** (or **Install** once more). Building can take several minutes the first time.

3. Inspect the last failure:

```powershell
Get-Content .\php-controller-runtime\status\last-create-error.log -Tail 40
Get-Content .\php-controller-runtime\status\<service>.last-install-version.log -Tail 40
```

4. If Desktop DNS keeps failing: restart Docker Desktop, or temporarily change DNS (for example `8.8.8.8` / `1.1.1.1`) in Docker Desktop → Settings → Resources → Network, then retry.

5. Prefer a bundled Hub version when you do not need a specific patch/alpine/trixie tag — no local Dockerfile build is required.

Optional: run `scripts\hosts\ensure_hosts_env.ps1` once so `.env` gets `HOST_PROJECT_PATH` with forward slashes (`D:/…`). That path is used when `php-controller` rewrites bind mounts for Create/Install.

### Windows: `env.json` became a folder

If Docker once bind-mounted a missing `env.json` as a **directory**, delete that folder, then let `env-init` recreate the file (`docker compose up -d` or copy from `env.example.json`). Compose mounts the project directory (not the file alone) to avoid this.

## Repository structure

```text
.
├── compose/                 # Compose fragments (PHP+Supervisor, mysql, redis, rabbitmq)
│   ├── mysql.yml
│   ├── php-7.4.yml
│   ├── php-8.0.yml
│   ├── php-8.1.yml
│   ├── php-8.2.yml
│   ├── php-8.3.yml
│   ├── php-8.4.yml
│   ├── php-8.5.yml
│   ├── rabbitmq.yml
│   └── redis.yml
├── configs/                 # PHP and Supervisor configuration
│   └── supervisor.d/        # Workers per version (php8.5/, php8.4/, …)
├── docker_files/            # Dockerfiles used to build services
├── mysql/                   # MySQL configuration
├── nginx/
│   ├── examples/            # Virtual-host template
│   ├── ssl/                 # Per-site certificates (gitignored)
│   ├── logs/                # Nginx logs
│   └── templates/           # Configuration generated from env.json
├── scripts/                 # Startup and configuration scripts
│   ├── php/                 # php-controller + install/uninstall extension
│   ├── nginx/               # auto-add-template, reload, watch
│   ├── hosts/               # add_hostname, ensure_hosts_env, protocol handlers
│   ├── docker/              # entrypoint, supervisord, compose wrappers
│   └── macos/               # MultiPhpHosts.app (protocol helper, gitignored)
├── server/
│   ├── manager/             # env.json management UI
│   ├── source_php7.4/       # PHP 7.4 projects
│   ├── source_php8.0/       # PHP 8.0 projects
│   ├── source_php8.1/       # PHP 8.1 projects
│   ├── source_php8.2/       # PHP 8.2 projects
│   ├── source_php8.3/       # PHP 8.3 projects
│   ├── source_php8.4/       # PHP 8.4 projects
│   └── source_php8.5/       # PHP 8.5 projects
├── docker-compose.yml       # Root: include + nginx/manager/php-controller/env-init
├── env.example.json         # Committed project/domain template
└── env.json                 # Local configuration ignored by Git
```

## Author

This project is maintained by **Hải Long**.

For inquiries, collaboration, or feedback, please reach out via:

| | |
| --- | --- |
| Email | [longdh2.dev@gmail.com](mailto:longdh2.dev@gmail.com) |
| LinkedIn | [Hải Long](https://www.linkedin.com/in/h%E1%BA%A3i-long-729355219/) |
