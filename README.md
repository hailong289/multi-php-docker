[English](README.md) | [Tiếng Việt](README.vi.md)

# PHP Development Environment with Docker

Local stack with Nginx, PHP 7.4 and 8.0–8.5, plus optional MySQL, PostgreSQL, Redis, RabbitMQ, Kafka, Mailpit, and MinIO. Ready-made images are on Docker Hub (`long301001/multi-php-docker`), including Server Manager. PHP 8.5 starts with the stack; every other PHP version and data service stays off until you enable its profile.

## Demo videos

| Clone the source | Create services |
| --- | --- |
| [![Clone the source](https://img.youtube.com/vi/rUI_mtbsIIU/hqdefault.jpg)](https://youtu.be/rUI_mtbsIIU) | [![Create services](https://img.youtube.com/vi/2fw1NnIO-uo/hqdefault.jpg)](https://youtu.be/2fw1NnIO-uo) |
| [Watch on YouTube](https://youtu.be/rUI_mtbsIIU) | [Watch on YouTube](https://youtu.be/2fw1NnIO-uo) |

## First run

You need Docker Desktop or Docker Engine, and Docker Compose v2 (`docker compose`).

```bash
docker --version
docker compose version
```

### 1. Clone and start

```bash
git clone <repository-url>
cd <repository-folder>
docker compose pull
docker compose up -d
```

This starts PHP 8.5, Nginx, Server Manager, and PHP Controller. No `.env` file is required. On the first run, a helper copies [`env.example.json`](env.example.json) to `env.json` when that file is missing, and never overwrites an existing one. `env.json` stays on your machine.

Later: `docker compose up -d`, then `docker compose ps`.

### 2. Put the project in `server/source`

Every PHP version mounts the same host directory. Inside PHP and Supervisor containers that path is `/var/www/source`.

```text
server/source/<project-name>
```

Picking another PHP version in Server Manager does not move the files. If an older checkout still has `server/source_php*`, run `./scripts/migrate-source-path.sh` once before recreating containers.

### 3. Open Server Manager

The UI is already inside `long301001/multi-php-docker:manager`. Node.js is not required on the host.

[http://127.0.0.1:8080/server-manage](http://127.0.0.1:8080/server-manage)

1. **Add a server** — application name, domain (for example `my-php85-app.test`), PHP version, and document root. For Laravel or any app with a public directory, point the document root at `public`, `webroot`, or the folder that contains `index.php`. Manager writes `env.json`.
2. **Start PHP if needed** — PHP 8.5 is already running. For another version, open **PHP versions** → **Create** → **Start**.
3. **Write hosts** once per machine. This step needs `jq` and administrator rights; starting Docker does not. Then in Manager use **Add domain** / **Write hosts (Admin)**.

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1
```

macOS:

```bash
chmod +x scripts/hosts/ensure_hosts_env.sh scripts/hosts/add_hostname.sh scripts/hosts/hosts_protocol_macos.sh
./scripts/hosts/ensure_hosts_env.sh
```

Linux / WSL (a watcher, no browser protocol):

```bash
chmod +x scripts/hosts/add_hostname.sh
./scripts/hosts/add_hostname.sh --watch
```

The browser opens `multi-php-hosts:write` and writes the hosts file (UAC on Windows, an admin prompt on macOS). Allow the app if the browser asks.

4. Click **Apply & Reload Nginx**.
5. Open the domain, for example `http://my-php85-app.test`.

If reload fails, read `runtime/nginx.reload.log`.

The same UI can open a shell in that site’s PHP container (the container must be running), switch language, and switch light, dark, or system appearance.

## Services and ports

| Service | Host ports | Defaults |
| --- | --- | --- |
| Nginx | `80`, `443` | Domains from `env.json` |
| PHP 8.5 | Not published | Always started. PHP-FPM on port `9000` inside the Docker network |
| PHP 7.4, 8.0–8.4 | Not published | Off until you start the matching profile |
| Server Manager | `127.0.0.1:8080` | [http://127.0.0.1:8080/server-manage](http://127.0.0.1:8080/server-manage) |
| MySQL | `3306` | User `root`, password `1` |
| PostgreSQL | `5432` | User `postgres`, password `1`, database `postgres` |
| Redis | `6379` | No password |
| RabbitMQ | `5672`, `15672` | `admin` / `admin`. UI [http://localhost:15672](http://localhost:15672) |
| Kafka | `9092` | Broker `kafka_container:29092` (Docker) / `localhost:9092` (host) |
| Mailpit | `1025`, `8025` | SMTP `mailpit_container:1025`. UI [http://localhost:8025](http://localhost:8025) |
| MinIO | `9000`, `9001` | `minioadmin` / `minioadmin`. API `minio_container:9000`. Console [http://localhost:9001](http://localhost:9001) |
| Supervisor | Not published | Background workers, one container per PHP version, off by default |

## Daily use

### Optional services

Start a profile when a project needs it. Nginx can reach only a PHP container that is already running.

```bash
docker compose --profile <name> up -d <name>
```

Profile names: `php-8.4`, `php-8.3`, `php-8.2`, `php-8.1`, `php-8.0`, `php-7.4`, `mysql`, `postgres`, `redis`, `rabbitmq`, `kafka`, `mailpit`, `minio`, `supervisor-8.5`, `supervisor-8.4`, `supervisor-8.3`, `supervisor-8.2`, `supervisor-8.1`, `supervisor-8.0`, `supervisor-7.4`.

Server Manager can Create, Start, Stop, and Restart these. **Add version** installs a Hub catalog tag (for example alpine) and builds a local image. On Windows, see [Troubleshooting](#windows-install--create-php-version-fails) if that build cannot reach Docker Hub.

```bash
docker compose stop php-8.3
```

`php-controller` reads the repository path from the `/project` mount. A `HOST_PROJECT_PATH` value in `.env` still works as an override.

### Connect from the application

Inside a container, use the container name: the service name plus `_container`. On the host, use `127.0.0.1` and the host ports in the table above.

```dotenv
DB_HOST=mysql_container
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=1

# PostgreSQL — use this block when the app uses Postgres
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

# From PHP containers use kafka_container:29092; from the host use localhost:9092
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

### Logs, shell, and lifecycle

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

`docker compose down` removes containers and the network and keeps named volumes. Swap `php-8.5` for another running PHP service when you need a shell there.

## Occasional tasks

### HTTPS

Turn on HTTPS per site in Server Manager. Leave the certificate files empty to generate a self-signed cert, or upload a `.crt`/`.pem` and a `.key`. Ports 80 and 443 both keep serving. Browsers warn on a self-signed cert. Click **Apply & Reload Nginx** afterward. Files are stored in `nginx/ssl/<app-name>/` and are not committed. After pulling this feature, run `docker compose up -d nginx` once so `./nginx/ssl` is mounted.

### PHP extensions

Open **Details** for a PHP version to toggle `extension=` lines in the mounted `configs/php*/php.ini`, install a curated set into the running container, and edit `php.ini`. After saving `php.ini` you can restart PHP-FPM. A runtime install is gone after the container is recreated; bake lasting extensions into a custom image under [Advanced](#custom-images).

### Supervisor workers

Supervisor uses the same image, `server/source`, and `php.ini` as the matching PHP-FPM service, in its own container.

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

Files ending in `.example` are skipped. Add more `.conf` files in the same folder for more projects.

```bash
docker compose --profile supervisor-8.5 up -d supervisor-8.5
docker compose exec supervisor-8.5 supervisorctl status
docker compose exec supervisor-8.5 supervisorctl reread
docker compose exec supervisor-8.5 supervisorctl update
```

For another PHP version, change `8.5` and `php8.5` in the path and profile, and start that PHP version as well. From the UI: **PHP versions** → **Supervisor**. Logs are under `logs/supervisor*`.

The published PHP 7.4 image does not include Supervisor. Install the `supervisor` package in a [custom image](#custom-images), then use profile `supervisor-7.4`.

Workers should retry connections to MySQL, PostgreSQL, Redis, RabbitMQ, Kafka, Mailpit, and MinIO. Startup order applies only when those profiles are enabled.

### Add or change a project

1. Put the source in `server/source/<project-name>`.
2. In Server Manager, add or edit the server and write hosts when the domain is new.
3. Click **Apply & Reload Nginx**.

## Troubleshooting

### A domain cannot be reached

- The domain is in the hosts file and points to `127.0.0.1`.
- `docker compose ps` shows Nginx and that site’s PHP container as running.
- Apply the server again with **Apply & Reload Nginx**.

### 404 or `File not found`

The document root is the folder that contains `index.php`, as a path inside the container (`/var/www/source/...`). Project files live in `server/source/<project-name>` for every PHP version.

### A port is already in use

Stop the other process, or change the host side of the port mapping. In `compose/mysql.yml`, `"3306:3306"` can become `"3307:3306"`. In `compose/postgres.yml`, `"5432:5432"` can become `"5433:5432"`.

### PHP cannot reach MySQL, PostgreSQL, Redis, RabbitMQ, Kafka, Mailpit, or MinIO

Use the container name (`mysql_container`, `postgres_container`, `redis_container`, `rabbitmq_container`, `kafka_container`, `mailpit_container`, `minio_container`). Start the matching profile when `docker compose ps` does not list it. From PHP, the Kafka broker is `kafka_container:29092`.

### Windows: Install / Create PHP version fails

Bundled versions (`php-7.4` through `php-8.5`) use Hub images: **Create**, then **Start**. A catalog install (alpine, trixie, or another exact tag) builds a local image and pulls a base `php:…-fpm` image. Docker Desktop on Windows can fail that pull even when the machine has internet. Typical lines in `php-controller-runtime/status/`:

- `lookup auth.docker.io … network is unreachable`
- `failed to authorize: failed to fetch anonymous token`
- `failed to resolve source metadata for docker.io/library/php:…`

1. From the host, `docker pull hello-world`, or pull the base tag named in the error.
2. In Manager, click **Create** or **Install** again. The first build can take several minutes.
3. Read `php-controller-runtime\status\last-create-error.log`.
4. Restart Docker Desktop, or set DNS to `8.8.8.8` / `1.1.1.1` under Settings → Resources → Network.
5. Use a bundled Hub version when you do not need that exact tag.

Running `scripts\hosts\ensure_hosts_env.ps1` once also writes `HOST_PROJECT_PATH` with forward slashes (`D:/…`) for Create and Install.

### Windows: `env.json` became a folder

Delete that folder, then run `docker compose up -d` so the helper recreates the file. You can also copy `env.example.json` to `env.json`.

### An image fails to build

Run `docker compose build --no-cache <service-name>`. Check the network, the Docker daemon, and the Dockerfile path. RabbitMQ uses `docker_files/rabbitMQ.Dockerfile`.

## Advanced

### `env.json`

Server Manager is the usual way to edit sites. Each project is one `SERVER_NAME<N>` object:

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

| Field | Meaning |
| --- | --- |
| `APP_NAME` | Project name and generated Nginx config filename |
| `DOMAIN_NAME` | Domain on this machine |
| `SERVER_PATH` | Absolute document root inside the container |
| `CONTAINER_PHP_VERSION` | `php8.5_container` through `php8.0_container`, or `php7.4_container` |
| `ENABLED` | `false` skips the site when Nginx configs are generated |
| `SSL_ENABLED` | `true` also listens on 443 when `nginx/ssl/<APP_NAME>/{cert,key}.pem` exist |
| `SSL_MODE` | `generated` or `uploaded`; stored only when SSL is on |

Without the UI: edit `env.json`, run `./scripts/hosts/add_hostname.sh` for a new domain, then recreate Nginx:

```bash
docker compose up -d --force-recreate nginx
docker compose exec nginx nginx -t
```

On startup, `scripts/nginx/auto-add-template.sh` reads `env.json` and generates virtual hosts from `nginx/examples/server_example.txt`. A failed **Apply & Reload Nginx** restores the previous config; details go to `runtime/nginx.reload.log`.

### Hosts CLI

The stack starts without mounting the OS hosts file. Until the helper runs, Manager shows domains as **Unknown**. The script edits only the `# multi-php-docker-serve:managed:*` block and maps every `DOMAIN_NAME` in `env.json` (and `runtime/hosts.extra.json` when that file exists) to `127.0.0.1`.

```bash
./scripts/hosts/add_hostname.sh
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\add_hostname.ps1
```

Unregister the browser protocol on Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1 -UnregisterProtocol
```

Unregister on macOS: `./scripts/hosts/ensure_hosts_env.sh --unregister-protocol`

To edit hosts yourself, add `127.0.0.1 <domain>` lines to `/etc/hosts` (macOS and Linux) or `C:\Windows\System32\drivers\etc\hosts` (Windows).

### Custom images

Give the image your own name and add `build` on the PHP service. Leave the `long301001/multi-php-docker:*` name for unmodified Hub images. Supervisor reuses that image and does not get its own `build`.

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

After changing Manager PHP or Vue source, rebuild it (Node runs inside the image build):

```bash
docker compose build manager
docker compose up -d manager
```

Publish amd64 and arm64 with the same flow as the other Hub tags: `docker compose build --push manager`.

`php-controller` mounts the Docker socket and runs an allowlist of Compose actions. That socket is root-level access to the Docker host, so run this stack only from source you trust. Manager listens on `127.0.0.1:8080` only. Stop the UI with `docker compose stop manager`.

### Add another PHP version

PHP 7.4 and 8.0–8.5 are already included. For a newer release such as 8.6, copy `docker_files/php8.5.Dockerfile`, `configs/php8.5/php.ini`, and `compose/php-8.5.yml`.

1. Copy the Dockerfile and set the base image, for example `FROM php:8.6-fpm`. Current 8.x images usually include `pdo_mysql`, `mysqli`, `gd`, `zip`, `sockets`, `pcntl`, and Redis. Install `pdo_pgsql` / `pgsql` from Server Manager when the project uses PostgreSQL.
2. Create `configs/php8.6`, `configs/supervisor.d/php8.6`, and `logs/supervisor-8.6`. Copy `php.ini` and a worker `.conf`.
3. Add `compose/php-8.6.yml` with profiles `php-8.6` and `supervisor-8.6`, and `include` it from `docker-compose.yml` with `project_directory: .`. Leave port `9000` on the Docker network. For a Hub image, set `image` and omit `build`. For a local build, declare `build` only on the PHP service.
4. Set `CONTAINER_PHP_VERSION` to the new `container_name`, and add the service to the `php-controller` allowlist using the same pattern as the existing `php-8.x` services.
5. Start it with `docker compose --profile php-8.6 up -d php-8.6` (pull or `docker compose build php-8.6` first), then `docker compose up -d --force-recreate nginx`.

### Backup and restore MySQL

The MySQL volume is `mysql-data`. PostgreSQL uses `postgres-data` the same way: swap the service name and the archive name.

```bash
docker compose stop mysql
docker run --rm \
  -v mysql-data:/data:ro \
  -v "$(pwd):/backup" \
  alpine \
  tar czf /backup/mysql-data.tar.gz -C /data .
docker compose start mysql
```

Restore replaces the current volume. Take a backup first.

```bash
docker compose stop mysql
docker run --rm \
  -v mysql-data:/data \
  -v "$(pwd):/backup:ro" \
  alpine \
  tar xzf /backup/mysql-data.tar.gz -C /data
docker compose start mysql
```

### Repository layout

```text
.
├── compose/            # PHP, Supervisor, and optional services
├── configs/            # php.ini and supervisor.d/<version>/
├── docker_files/       # Dockerfiles for custom images
├── nginx/              # vhost template, ssl/, logs/, generated configs
├── scripts/            # nginx, hosts, php-controller
├── server/manager/     # Manager source; the UI ships inside the image
├── server/source/      # Your projects
├── docker-compose.yml
├── env.example.json
└── env.json            # local, not committed
```

### Contributing

Open a bug on [Issues](https://github.com/hailong289/multi-php-docker/issues) with steps to reproduce, expected and actual behavior, your OS, `docker --version`, `docker compose version`, and relevant logs (`docker compose logs`, the Manager UI, or files under `runtime/` and `php-controller-runtime/status/`). Leave out passwords, tokens, and private project paths.

Check out the latest `develop`, create your branch from it, and do the work on that branch:

```bash
git checkout develop
git pull origin develop
git checkout -b fix/short-description
```

Name the branch with one of these prefixes, then a short description:

| Prefix | Use when |
| --- | --- |
| `fix/` | You are fixing a bug. Example: `fix/nginx-reload-timeout` |
| `feat/` | You are adding a feature. Example: `feat/add-php-8.6` |
| `docs/` | You are changing documentation only. Example: `docs/readme-hosts` |
| `chore/` | You are changing build, CI, or tooling, with no product behavior change. Example: `chore/update-ci` |

Push the branch and open a pull request into `develop`. Link the issue, for example `Fixes #123`.

## A coffee, if you like

Take it and use it. If it helps you out, buy me a coffee — that would mean a lot.

- [Buy Me a Coffee](https://www.buymeacoffee.com/hailong289)
- [PayPal](https://paypal.me/LongHai2)

## Author

This project is maintained by **Hải Long**.

| | |
| --- | --- |
| Email | [longdh2.dev@gmail.com](mailto:longdh2.dev@gmail.com) |
| LinkedIn | [Hải Long](https://www.linkedin.com/in/h%E1%BA%A3i-long-729355219/) |
